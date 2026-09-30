<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Database;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\ConflictingCalculationBatch;
use PandaBear\Mlm\Exceptions\ConflictingCalculationReplay;
use PandaBear\Mlm\Exceptions\ConflictingLedgerReplay;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingRange;
use PandaBear\Mlm\Exceptions\InvalidBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Exceptions\InvalidLedgerReversal;
use PandaBear\Mlm\Exceptions\InvalidMatrixNetwork;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Exceptions\InvalidVolumeReversal;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Exceptions\UnresolvedBinaryCorrection;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Fixtures\SnapshotProbeStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * Real concurrency, on a real row-locking database: each competing
 * operation runs in its own PHP process with its own database session
 * (worker.php), calling the package's public services — no hooks in the
 * package, no simulated interleaving.
 *
 * The ordering is made deterministic from outside. A third session closes
 * a "gate" — it holds a lock that makes inserts into one table wait — and
 * the test reads the database's own lock-wait view until the expected
 * sessions are provably blocked, then opens the gate. Where the order two
 * sessions win a lock genuinely races, the assertions are on the outcome's
 * shape — exactly one succeeds, the other is refused for the right reason —
 * not on which of them won.
 */
#[Group('database-integration')]
#[Group('concurrency')]
final class RealDatabaseConcurrencyTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    /**
     * @var list<array{process: resource, stdout: resource, stderr: resource, output: string}>
     */
    private array $workers = [];

    protected function setUp(): void
    {
        if (ExternalDatabase::selected() === null) {
            $this->markTestSkipped('Real concurrency needs MySQL or PostgreSQL: set MLM_TEST_DATABASE=mysql|pgsql and its MLM_TEST_* variables.');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }

            fclose($worker['stdout']);
            fclose($worker['stderr']);
            proc_close($worker['process']);
        }

        $this->workers = [];

        if (isset($this->app)) {
            foreach ([$this->gateConnection(), $this->monitorConnection()] as $name) {
                $connection = DB::connection($name);

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }

                DB::purge($name);
            }
        }

        parent::tearDown();
    }

    /**
     * Two more sessions on the same database: one to hold the gate, one to
     * watch the lock waits.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $database = ExternalDatabase::selected();

        if ($database !== null) {
            $config = $app->make('config');
            $config->set("database.connections.{$database->connection}_gate", $database->config());
            $config->set("database.connections.{$database->connection}_monitor", $database->config());
        }
    }

    public function test_concurrent_sponsor_assignments_cannot_close_a_cycle(): void
    {
        // A -> B and C -> D. "B sponsors C" and "D sponsors A" are each valid
        // alone; together they make A -> B -> C -> D -> A.
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');
        $this->sponsorTree($members, ['A' => ['B'], 'C' => ['D']]);

        $this->closeGate('mlm_sponsor_edges');
        $first = $this->start(['op' => 'sponsor', 'member' => $members['C']->id, 'sponsor' => $members['B']->id]);
        $second = $this->start(['op' => 'sponsor', 'member' => $members['A']->id, 'sponsor' => $members['D']->id]);

        // One holds the program lock and waits to write its edge; the other
        // waits for the program lock — before its cycle check.
        $this->awaitWaitingOn(['mlm_sponsor_edges', 'mlm_programs']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            InvalidSponsorAssignment::class,
            'would make a cycle',
        );
        $this->assertSame(3, DB::table('mlm_sponsor_edges')->count());
        $this->assertTreeConsistent('sponsor', 'mlm_sponsor_edges', 'sponsor_id', 'assigned_at');
    }

    public function test_concurrent_placements_cannot_close_a_cycle(): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');
        $this->placementTree($members, ['A' => ['B'], 'C' => ['D']]);

        $this->closeGate('mlm_placement_edges');
        $first = $this->start(['op' => 'place', 'member' => $members['C']->id, 'parent' => $members['B']->id]);
        $second = $this->start(['op' => 'place', 'member' => $members['A']->id, 'parent' => $members['D']->id]);

        $this->awaitWaitingOn(['mlm_placement_edges', 'mlm_programs']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            InvalidPlacementAssignment::class,
            'would make a cycle',
        );
        $this->assertSame(3, DB::table('mlm_placement_edges')->count());
        $this->assertTreeConsistent('placement', 'mlm_placement_edges', 'parent_id', 'placed_at');
    }

    public function test_racing_placements_onto_one_binary_side_take_it_once_and_leave_the_loser_unplaced(): void
    {
        $members = $this->members(Program::factory()->create(), 'P', 'A', 'B');

        $this->closeGate('mlm_placement_edges');
        $first = $this->start(['op' => 'binary_place', 'member' => $members['A']->id, 'parent' => $members['P']->id, 'side' => 'left']);
        $second = $this->start(['op' => 'binary_place', 'member' => $members['B']->id, 'parent' => $members['P']->id, 'side' => 'left']);

        $this->awaitWaitingOn(['mlm_placement_edges', 'mlm_programs']);

        $this->openGate();

        $results = [$this->finish($first), $this->finish($second)];
        $this->assertOneSucceededOneRefused($results, InvalidBinaryPlacement::class, 'The left of member');

        // The winner is placed and on the left; the loser is not placed at
        // all, generically or in the binary tree.
        [$winner, $loser] = $results[0]['ok'] ? [$members['A'], $members['B']] : [$members['B'], $members['A']];
        $this->assertSame([$winner->id], DB::table('mlm_placement_edges')->pluck('member_id')->all());
        $this->assertSame([['left', $members['P']->id]], DB::table('mlm_binary_placement_positions')->get(['side', 'parent_id'])->map(static fn (object $row): array => [$row->side, $row->parent_id])->all());
        $this->assertSame(0, DB::table('mlm_genealogy_paths')->where('descendant_id', $loser->id)->count());
        $this->assertTreeConsistent('placement', 'mlm_placement_edges', 'parent_id', 'placed_at');
        $this->assertBinaryTreeConsistent();
    }

    public function test_racing_placements_onto_opposite_binary_sides_both_take_theirs(): void
    {
        $members = $this->members(Program::factory()->create(), 'P', 'A', 'B');

        $this->closeGate('mlm_placement_edges');
        $left = $this->start(['op' => 'binary_place', 'member' => $members['A']->id, 'parent' => $members['P']->id, 'side' => 'left']);
        $right = $this->start(['op' => 'binary_place', 'member' => $members['B']->id, 'parent' => $members['P']->id, 'side' => 'right']);

        $this->awaitWaitingOn(['mlm_placement_edges', 'mlm_programs']);

        $this->openGate();

        [$a, $b] = [$this->finish($left), $this->finish($right)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame('A', $this->binaryTree()->child($members['P'], BinarySide::Left)?->member_code);
        $this->assertSame('B', $this->binaryTree()->child($members['P'], BinarySide::Right)?->member_code);
        $this->assertTreeConsistent('placement', 'mlm_placement_edges', 'parent_id', 'placed_at');
        $this->assertBinaryTreeConsistent();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function adoptionSides(): array
    {
        return ['the same side' => ['left', true], 'opposite sides' => ['right', false]];
    }

    #[DataProvider('adoptionSides')]
    public function test_racing_adoptions_of_one_edge_enrol_it_once(string $secondSide, bool $bothSucceed): void
    {
        $members = $this->members(Program::factory()->create(), 'P', 'A');
        $edge = $this->placement()->place($members['A'], $members['P']);

        $this->closeGate('mlm_binary_placement_positions');
        $first = $this->start(['op' => 'binary_adopt', 'edge' => $edge->id, 'side' => 'left']);
        $second = $this->start(['op' => 'binary_adopt', 'edge' => $edge->id, 'side' => $secondSide]);

        $this->awaitWaitingOn(['mlm_binary_placement_positions', 'mlm_programs']);

        $this->openGate();

        $results = [$this->finish($first), $this->finish($second)];
        $position = DB::table('mlm_binary_placement_positions')->sole();

        if ($bothSucceed) {
            $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame([$position->id, $position->id], [$results[0]['id'], $results[1]['id']]);
        } else {
            $this->assertOneSucceededOneRefused($results, InvalidBinaryPlacement::class, 'a binary side is assigned once and never moves');
        }

        $this->assertSame($edge->id, $position->placement_edge_id);
        $this->assertBinaryTreeConsistent();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function pairingRaces(): array
    {
        return [
            'the same range under two keys' => ['2026-01-01 00:00:00', '2026-02-01 00:00:00', 'jan:b'],
            'the next range' => ['2026-02-01 00:00:00', '2026-03-01 00:00:00', 'feb'],
            'the same range under the same key' => ['2026-01-01 00:00:00', '2026-02-01 00:00:00', 'jan'],
        ];
    }

    /**
     * Two binary pairing runs of one component, the second started while
     * the first holds its state uncommitted: serialized, the second either
     * replays the first, continues from it, or is refused as stale — carry
     * is never consumed twice.
     */
    #[DataProvider('pairingRaces')]
    public function test_racing_pairing_runs_of_one_component_never_consume_carry_twice(string $from, string $until, string $key): void
    {
        $component = $this->pairingRace();

        $this->closeGate('mlm_binary_pairing_results');
        $first = $this->start(['op' => 'calculate', 'component' => $component->id, 'from' => '2026-01-01 00:00:00', 'until' => '2026-02-01 00:00:00', 'key' => 'jan']);
        $this->awaitWaitingOn(['mlm_binary_pairing_results']);
        $second = $this->start(['op' => 'calculate', 'component' => $component->id, 'from' => $from, 'until' => $until, 'key' => $key]);
        $this->awaitWaitingOn(['mlm_binary_pairing_results', 'mlm_']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $shown = json_encode([$a, $b], JSON_THROW_ON_ERROR);
        $this->assertTrue($a['ok'], $shown);
        $january = CalculationRun::query()->where('idempotency_key', 'jan')->sole();

        match ($key) {
            'jan' => $this->assertSame([true, $a['id']], [$b['ok'], $b['id']], $shown),
            'feb' => $this->assertTrue($b['ok'], $shown),
            default => $this->assertSame([false, InvalidBinaryPairingRange::class], [$b['ok'], $b['exception'] ?? null], $shown),
        };

        // January paired once: one lot drawn per side, one commission.
        $this->assertSame(['1'], DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $january->id)->pluck('pair_count')->all());
        $this->assertSame(2, DB::table('mlm_binary_pairing_allocations')->whereIn('binary_pairing_result_id', DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $january->id)->select('id'))->count());
        $this->assertSame(1, DB::table('mlm_binary_pairing_cursors')->count());

        if ($key === 'feb') {
            // February started from January's carry.
            $february = DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $b['id'])->sole();
            $this->assertSame(['150', '20', '1', '50', '100'], [$february->left_carry_before, $february->right_carry_before, $february->pair_count, $february->left_carry_after, $february->right_carry_after]);
            $this->assertSame('2026-03-01 00:00:00', (string) DB::table('mlm_binary_pairing_cursors')->value('through_at'));
        } else {
            $this->assertSame([1, 1, 2], [DB::table('mlm_calculation_runs')->count(), DB::table('mlm_commissions')->count(), DB::table('mlm_binary_carry_lots')->count()]);
            $this->assertSame('2026-02-01 00:00:00', (string) DB::table('mlm_binary_pairing_cursors')->value('through_at'));
        }

        fwrite(STDERR, sprintf("[pairing race %s] %s => %s\n", ExternalDatabase::selected()?->engine ?? '?', $key, $b['ok'] ? 'ok' : $b['exception']));
    }

    /**
     * Two runs of one range that undoes an earlier pair (ADR-025): the
     * pair is undone, and its carry given back, once.
     */
    public function test_racing_runs_that_undo_a_pair_undo_it_once(): void
    {
        $component = $this->pairingRace();
        $this->calculate($component, '2026-01-01 00:00:00', '2026-02-01 00:00:00', 'jan');
        $this->reverse(VolumeEntry::query()->where('idempotency_key', 'l1')->sole(), 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $this->closeGate('mlm_binary_pairing_results');
        $first = $this->start(['op' => 'calculate', 'component' => $component->id, 'from' => '2026-02-01 00:00:00', 'until' => '2026-03-01 00:00:00', 'key' => 'feb:a']);
        $this->awaitWaitingOn(['mlm_binary_pairing_results']);
        $second = $this->start(['op' => 'calculate', 'component' => $component->id, 'from' => '2026-02-01 00:00:00', 'until' => '2026-03-01 00:00:00', 'key' => 'feb:b']);
        $this->awaitWaitingOn(['mlm_binary_pairing_results', 'mlm_']);

        $this->openGate();

        $this->assertOneSucceededOneRefused([$this->finish($first), $this->finish($second)], InvalidBinaryPairingRange::class, 'already calculated through');
        $this->assertSame([1, 1], [DB::table('mlm_binary_pairing_corrections')->count(), DB::table('mlm_binary_pairing_restorations')->count()]);
        $this->assertSame('100000000', (string) DB::table('mlm_binary_pairing_corrections')->value('quantity_millionths'));
        // The reversed source holds nothing; R's 120 and 180 are carry again.
        $source = DB::table('mlm_binary_carry_lots')->where('source_volume_entry_id', VolumeEntry::query()->where('idempotency_key', 'l1')->value('id'))->sole();
        $this->assertSame(['0', true], [(string) $source->remaining_millionths, $source->reversed_by_volume_entry_id !== null]);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function matrixConfigurations(): array
    {
        return ['the same width' => [3, true], 'another width' => [4, false]];
    }

    /**
     * Two first configurations of one program's matrix serialize on the
     * program's row (ADR-027): the same width converges on one network, and
     * another width is refused — never two networks, never a raw key error.
     */
    #[DataProvider('matrixConfigurations')]
    public function test_racing_first_configurations_of_a_matrix_make_one_network(int $secondWidth, bool $bothSucceed): void
    {
        $program = Program::factory()->create();

        $this->holdRow('mlm_programs', $program->id);
        $first = $this->start(['op' => 'matrix_configure', 'program' => $program->id, 'width' => 3]);
        $this->awaitWaitingOn(['mlm_programs']);
        $second = $this->start(['op' => 'matrix_configure', 'program' => $program->id, 'width' => $secondWidth]);
        $this->awaitWaitingOn(['mlm_programs', 'mlm_programs']);

        $this->openGate();

        $results = [$this->finish($first), $this->finish($second)];

        if ($bothSucceed) {
            $this->assertSame([true, true], [$results[0]['ok'], $results[1]['ok']], json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame($results[0]['id'], $results[1]['id']);
        } else {
            $this->assertOneSucceededOneRefused($results, InvalidMatrixNetwork::class, 'a matrix width is configured once and never changes');
        }

        $this->assertSame(1, DB::table('mlm_matrix_networks')->where('program_id', $program->id)->count());
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function matrixSlotRaces(): array
    {
        return ['the same slot' => [1, false], 'another slot' => [2, true]];
    }

    /**
     * Two placements under one matrix parent serialize on the program's row.
     * Into one slot, one wins and the other is refused with nothing of its
     * generic placement left; into two slots, both are placed.
     */
    #[DataProvider('matrixSlotRaces')]
    public function test_racing_matrix_placements_under_one_parent(int $secondSlot, bool $bothSucceed): void
    {
        $members = $this->members(Program::factory()->create(), 'P', 'A', 'B');
        $this->matrixNetworks()->configure($members['P']->program, 3);

        $this->holdRow('mlm_programs', $members['P']->program_id);
        $first = $this->start(['op' => 'matrix_place', 'member' => $members['A']->id, 'parent' => $members['P']->id, 'slot' => 1]);
        $second = $this->start(['op' => 'matrix_place', 'member' => $members['B']->id, 'parent' => $members['P']->id, 'slot' => $secondSlot]);
        $this->awaitWaitingOn(['mlm_programs', 'mlm_programs']);

        $this->openGate();

        $results = [$this->finish($first), $this->finish($second)];

        if ($bothSucceed) {
            $this->assertSame([true, true], [$results[0]['ok'], $results[1]['ok']], json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(['A #1', 'B #2'], $this->matrixSlots());
            $this->assertSame(2, DB::table('mlm_placement_edges')->count());
        } else {
            $this->assertOneSucceededOneRefused($results, InvalidMatrixPlacement::class, 'Matrix slot 1 of member');
            // The loser's generic placement went with it.
            $this->assertCount(1, $this->matrixSlots());
            $this->assertSame(1, DB::table('mlm_placement_edges')->count());
            $this->assertSame(3, DB::table('mlm_genealogy_paths')->where('tree_type', 'placement')->count());
        }

        $this->assertSame(DB::table('mlm_genealogy_paths')->where('tree_type', 'placement')->count(), DB::table('mlm_genealogy_paths')->where('tree_type', 'matrix')->count());
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function matrixAdoptions(): array
    {
        return ['the same slot' => [2, true], 'another slot' => [3, false]];
    }

    /**
     * Two adoptions of one edge serialize on the program's row: into one
     * slot both resolve the same position; into another, one is refused.
     */
    #[DataProvider('matrixAdoptions')]
    public function test_racing_adoptions_of_one_edge_into_the_matrix_adopt_it_once(int $secondSlot, bool $bothSucceed): void
    {
        $members = $this->members(Program::factory()->create(), 'P', 'A');
        $this->matrixNetworks()->configure($members['P']->program, 3);
        $edge = $this->placement()->place($members['A'], $members['P']);

        $this->holdRow('mlm_programs', $members['P']->program_id);
        $first = $this->start(['op' => 'matrix_adopt', 'edge' => $edge->id, 'slot' => 2]);
        $second = $this->start(['op' => 'matrix_adopt', 'edge' => $edge->id, 'slot' => $secondSlot]);
        $this->awaitWaitingOn(['mlm_programs', 'mlm_programs']);

        $this->openGate();

        $results = [$this->finish($first), $this->finish($second)];

        if ($bothSucceed) {
            $this->assertSame([true, true], [$results[0]['ok'], $results[1]['ok']], json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame($results[0]['id'], $results[1]['id']);
        } else {
            $this->assertOneSucceededOneRefused($results, InvalidMatrixPlacement::class, 'a matrix slot is assigned once and never moves');
        }

        $this->assertCount(1, $this->matrixSlots());
        $this->assertSame(3, DB::table('mlm_genealogy_paths')->where('tree_type', 'matrix')->count());
    }

    /**
     * Two identical hybrid requests (ADR-028) serialize on the program's row
     * to open one batch, then both calculate its components under the same
     * derived keys: one run per component, the binary state moved once,
     * and both callers resolve the same completed batch.
     */
    public function test_racing_identical_hybrid_requests_make_one_batch_and_one_run_per_component(): void
    {
        [$version, $account] = $this->hybridRace();
        $job = ['op' => 'hybrid', 'version' => $version->id, 'from' => '2026-01-01 00:00:00', 'until' => '2026-02-01 00:00:00', 'key' => 'hybrid:jan', 'account' => $account->id];

        $this->holdRow('mlm_programs', $account->program_id);
        $first = $this->start($job);
        $second = $this->start($job);
        $this->awaitWaitingOn(['mlm_programs', 'mlm_programs']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $shown = json_encode([$a, $b], JSON_THROW_ON_ERROR);
        $this->assertSame([true, true], [$a['ok'], $b['ok']], $shown);
        $this->assertSame($a['id'], $b['id'], $shown);
        $this->assertSame('completed', DB::table('mlm_calculation_batches')->where('id', $a['id'])->value('status'));
        $this->assertSame([1, 2, 2], [DB::table('mlm_calculation_batches')->count(), DB::table('mlm_calculation_batch_items')->whereNotNull('calculation_run_id')->count(), DB::table('mlm_calculation_runs')->count()]);
        // The binary pair was made once; the sponsor was paid once.
        $this->assertSame([1, 2, 2], [DB::table('mlm_binary_pairing_results')->count(), DB::table('mlm_binary_pairing_allocations')->count(), DB::table('mlm_commissions')->count()]);
    }

    /**
     * Two hybrid requests under one key but for different ranges: one opens
     * the batch, the other is refused and calculates nothing.
     */
    public function test_racing_conflicting_hybrid_requests_under_one_key_open_one_batch(): void
    {
        [$version, $account] = $this->hybridRace();
        $job = ['op' => 'hybrid', 'version' => $version->id, 'from' => '2026-01-01 00:00:00', 'key' => 'hybrid:jan', 'account' => $account->id];

        $this->holdRow('mlm_programs', $account->program_id);
        $first = $this->start([...$job, 'until' => '2026-02-01 00:00:00']);
        $second = $this->start([...$job, 'until' => '2026-03-01 00:00:00']);
        $this->awaitWaitingOn(['mlm_programs', 'mlm_programs']);

        $this->openGate();

        $this->assertOneSucceededOneRefused([$this->finish($first), $this->finish($second)], ConflictingCalculationBatch::class, 'differs in until');
        $this->assertSame([1, 2, 1], [DB::table('mlm_calculation_batches')->count(), DB::table('mlm_calculation_runs')->count(), DB::table('mlm_binary_pairing_results')->count()]);
    }

    public function test_sponsor_and_placement_writes_in_one_program_run_one_at_a_time(): void
    {
        $program = $this->members(Program::factory()->create(), 'M1', 'M2', 'M3', 'M4');
        $elsewhere = $this->members(Program::factory()->create(), 'N1', 'N2');

        $this->closeGate('mlm_sponsor_edges');
        $sponsor = $this->start(['op' => 'sponsor', 'member' => $program['M2']->id, 'sponsor' => $program['M1']->id]);
        $this->awaitWaitingOn(['mlm_sponsor_edges']);

        // Disjoint members, and placement edges are not gated: only the
        // program lock the sponsor assignment holds can stop this one.
        $placement = $this->start(['op' => 'place', 'member' => $program['M4']->id, 'parent' => $program['M3']->id]);
        $otherProgram = $this->start(['op' => 'place', 'member' => $elsewhere['N2']->id, 'parent' => $elsewhere['N1']->id]);

        // Another program is not held up.
        $this->assertTrue($this->finish($otherProgram)['ok']);

        $this->awaitWaitingOn(['mlm_sponsor_edges', 'mlm_programs']);
        $this->assertSame(0, DB::table('mlm_placement_edges')->where('member_id', $program['M4']->id)->count());

        $this->openGate();

        $this->assertTrue($this->finish($sponsor)['ok']);
        $this->assertTrue($this->finish($placement)['ok']);
    }

    public function test_two_writes_naming_the_same_members_in_opposite_order_do_not_deadlock(): void
    {
        $members = $this->members(Program::factory()->create(), 'X', 'Y');
        [$low, $high] = strcmp($members['X']->id, $members['Y']->id) < 0
            ? [$members['X'], $members['Y']]
            : [$members['Y'], $members['X']];

        // Hold the lower-keyed member, so both writes queue on it.
        DB::connection($this->gateConnection())->beginTransaction();
        DB::connection($this->gateConnection())->select('SELECT id FROM mlm_members WHERE id = ? FOR UPDATE', [$low->id]);

        $sponsor = $this->start(['op' => 'sponsor', 'member' => $low->id, 'sponsor' => $high->id]);
        $placement = $this->start(['op' => 'place', 'member' => $high->id, 'parent' => $low->id]);

        $this->awaitWaitingOn(['mlm_members', 'mlm_members']);

        $this->openGate();

        $this->assertTrue($this->finish($sponsor)['ok']);
        $this->assertTrue($this->finish($placement)['ok']);
    }

    public function test_a_definition_edit_and_a_validation_never_cross(): void
    {
        $version = $this->app->make(PlanVersionLifecycle::class)->draft(Plan::factory()->create());

        // Hold the version row: both the edit and the validation lock it.
        DB::connection($this->gateConnection())->beginTransaction();
        DB::connection($this->gateConnection())->select('SELECT id FROM mlm_plan_versions WHERE id = ? FOR UPDATE', [$version->id]);

        // A component whose driver no worker registers: if it lands first,
        // validation must refuse the version.
        $edit = $this->start(['op' => 'plan_add_component', 'version' => $version->id, 'key' => 'late', 'driver' => 'acme.unregistered', 'name' => 'Late']);
        $this->awaitWaitingOn(['mlm_plan_versions']);
        $validate = $this->start(['op' => 'plan_validate', 'version' => $version->id]);
        $this->awaitWaitingOn(['mlm_plan_versions', 'mlm_plan_versions']);

        $this->openGate();

        [$edited, $validated] = [$this->finish($edit), $this->finish($validate)];
        $shown = json_encode([$edited, $validated], JSON_THROW_ON_ERROR);
        $status = DB::table('mlm_plan_versions')->where('id', $version->id)->value('status');
        $components = DB::table('mlm_plan_components')->where('plan_version_id', $version->id)->count();

        // Never a validated version with an edit committed after its check.
        $this->assertFalse($status === 'validated' && $components > 0, $shown);

        if ($edited['ok']) {
            // The edit went first; the validation saw it and refused.
            $this->assertFalse($validated['ok'], $shown);
            $this->assertSame(InvalidPlanDefinition::class, $validated['exception'], $shown);
            $this->assertSame(['draft', 1], [$status, $components]);
        } else {
            // The validation went first; the edit found the version locked.
            $this->assertTrue($validated['ok'], $shown);
            $this->assertSame(PlanVersionNotMutable::class, $edited['exception'], $shown);
            $this->assertSame(['validated', 0], [$status, $components]);
        }
    }

    public function test_identical_volume_commands_racing_record_one_entry(): void
    {
        $member = Member::factory()->create();
        $command = $this->recordJob($member, '25.5');

        $this->closeGate('mlm_volume_entries');
        $first = $this->start($command);
        $second = $this->start($command);

        // Both got past the key check and wait to insert.
        $this->awaitWaitingOn(['mlm_volume_entries', 'mlm_volume_entries']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, DB::table('mlm_volume_entries')->count());
    }

    public function test_conflicting_volume_commands_racing_record_one_and_refuse_the_other(): void
    {
        $member = Member::factory()->create();

        $this->closeGate('mlm_volume_entries');
        $first = $this->start($this->recordJob($member, '25.5'));
        $second = $this->start($this->recordJob($member, '99'));
        $this->awaitWaitingOn(['mlm_volume_entries', 'mlm_volume_entries']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            ConflictingVolumeReplay::class,
            'differs in quantity',
        );
        $this->assertSame(1, DB::table('mlm_volume_entries')->count());
    }

    public function test_racing_reversals_of_one_entry_reverse_it_once(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'order:ORD-1');
        $before = $this->volumeRows()[$original->id];

        $this->closeGate('mlm_volume_entries');
        $first = $this->start($this->reverseJob($original, 'refund:RF-1'));
        $second = $this->start($this->reverseJob($original, 'cancellation:CN-1'));
        $this->awaitWaitingOn(['mlm_volume_entries', 'mlm_volume_entries']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            InvalidVolumeReversal::class,
            'already reversed',
        );
        $this->assertSame(1, DB::table('mlm_volume_entries')->where('reversal_of_id', $original->id)->count());
        $this->assertSame($before, $this->volumeRows()[$original->id]);
    }

    public function test_a_race_lost_inside_a_callers_transaction_resolves_and_leaves_it_usable(): void
    {
        $member = Member::factory()->create();
        $winner = (new VolumeEntry)->newUniqueId();

        // Another session writes the same command's entry and holds it
        // uncommitted until this session is waiting on it.
        $holder = $this->start(['op' => 'hold', 'row' => [
            'id' => $winner,
            'program_id' => $member->program_id,
            'member_id' => $member->id,
            'type' => 'sales',
            'quantity_millionths' => '25500000',
            'source_type' => 'order',
            'source_id' => 'ORD-1',
            'idempotency_key' => 'order:ORD-1',
            'effective_at' => '2026-06-01 12:00:00',
            'created_at' => '2026-06-01 12:00:00',
            'updated_at' => '2026-06-01 12:00:00',
        ]]);
        $this->awaitEvent($holder, 'held');

        DB::beginTransaction();

        try {
            // Waits on the held row, then loses to it on the unique key.
            $entry = $this->record($member, '25.5', 'order:ORD-1', sourceId: 'ORD-1');

            // The caller's transaction is still usable after the failed
            // insert: on PostgreSQL only because it ran in a savepoint.
            $this->assertSame(1, DB::table('mlm_programs')->where('id', $member->program_id)->count());

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->assertSame($winner, $entry->id);
        $this->assertTrue($this->finish($holder)['ok']);
        $this->assertSame(1, DB::table('mlm_volume_entries')->count());
    }

    public function test_a_reversal_race_lost_inside_a_callers_transaction_is_refused_as_already_reversed(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'order:ORD-1');
        $reversal = (new VolumeEntry)->newUniqueId();

        // Another session reverses the entry under a key of its own and holds
        // that uncommitted until this session is waiting on it.
        $holder = $this->start(['op' => 'hold', 'row' => [
            'id' => $reversal,
            'program_id' => $original->program_id,
            'member_id' => $original->member_id,
            'type' => 'sales',
            'quantity_millionths' => '-10000000',
            'source_type' => 'refund',
            'source_id' => 'RF-1',
            'idempotency_key' => 'refund:RF-1',
            'effective_at' => '2026-06-15 12:00:00',
            'reversal_of_id' => $original->id,
            'created_at' => '2026-06-15 12:00:00',
            'updated_at' => '2026-06-15 12:00:00',
        ]]);
        $this->awaitEvent($holder, 'held');

        DB::beginTransaction();

        try {
            try {
                // Waits on the held reversal, then loses to it on reversal_of_id.
                $this->reverse($original, 'cancellation:CN-1', sourceType: 'cancellation', sourceId: 'CN-1');
                $this->fail('The entry was reversed twice.');
            } catch (InvalidVolumeReversal $exception) {
                $this->assertStringContainsString('already reversed', $exception->getMessage());
            }

            $this->assertSame(1, DB::table('mlm_programs')->where('id', $original->program_id)->count());
        } finally {
            DB::rollBack();
        }

        $this->assertTrue($this->finish($holder)['ok']);
        $this->assertSame($reversal, DB::table('mlm_volume_entries')->where('reversal_of_id', $original->id)->value('id'));
    }

    public function test_racing_opens_of_one_wallet_open_it_once(): void
    {
        $member = Member::factory()->create();
        $job = ['op' => 'wallet_open', 'member' => $member->id, 'currency' => 'IDR'];

        $this->closeGate('mlm_wallets');
        $first = $this->start($job);
        $second = $this->start($job);
        $this->awaitWaitingOn(['mlm_wallets', 'mlm_wallets']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame([1, 1], [DB::table('mlm_wallets')->count(), DB::table('mlm_ledger_accounts')->count()]);
        $this->assertSame($a['id'], DB::table('mlm_ledger_accounts')->value('wallet_id'));
    }

    public function test_identical_ledger_posts_racing_post_one_transaction(): void
    {
        [$program, $clearing, $wallet] = $this->ledgerAccounts();
        $job = $this->postJob($program, $clearing, $wallet, '100');

        $this->closeGate('mlm_ledger_transactions');
        $first = $this->start($job);
        $second = $this->start($job);
        $this->awaitWaitingOn(['mlm_ledger_transactions', 'mlm_ledger_transactions']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame([1, 2], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_ledger_postings')->count()]);
    }

    public function test_conflicting_ledger_posts_racing_post_one_and_refuse_the_other(): void
    {
        [$program, $clearing, $wallet] = $this->ledgerAccounts();

        $this->closeGate('mlm_ledger_transactions');
        $first = $this->start($this->postJob($program, $clearing, $wallet, '100'));
        $second = $this->start($this->postJob($program, $clearing, $wallet, '250.5'));
        $this->awaitWaitingOn(['mlm_ledger_transactions', 'mlm_ledger_transactions']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            ConflictingLedgerReplay::class,
            'differs in postings',
        );
        $this->assertSame([1, 2], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_ledger_postings')->count()]);
        $this->assertSame('0', (string) DB::table('mlm_ledger_postings')->sum('amount_millionths'));
    }

    public function test_racing_reversals_of_one_ledger_transaction_reverse_it_once(): void
    {
        [$program, $clearing, $wallet] = $this->ledgerAccounts();
        $original = $this->app->make(LedgerRecorder::class)->post($this->postCommand($program, [[$clearing, '-100'], [$wallet, '100']]));
        $before = $this->ledgerRows();

        $this->closeGate('mlm_ledger_transactions');
        $first = $this->start($this->reverseLedgerJob($original, 'reversal:REV-1'));
        $second = $this->start($this->reverseLedgerJob($original, 'reversal:REV-2'));
        $this->awaitWaitingOn(['mlm_ledger_transactions', 'mlm_ledger_transactions']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            InvalidLedgerReversal::class,
            'already reversed',
        );
        $this->assertSame(1, DB::table('mlm_ledger_transactions')->where('reversal_of_id', $original->id)->count());
        $this->assertSame(4, DB::table('mlm_ledger_postings')->count());
        $this->assertSame($before['mlm_ledger_transactions'], array_values(array_filter($this->ledgerRows()['mlm_ledger_transactions'], static fn (array $row): bool => $row['id'] === $original->id)));
    }

    public function test_a_ledger_race_lost_inside_a_callers_transaction_replays_the_winner(): void
    {
        [$program, $clearing, $wallet] = $this->ledgerAccounts();
        $winner = (new LedgerTransaction)->newUniqueId();

        // Another session posts the same command's transaction and holds it
        // uncommitted until this session is waiting on it.
        $holder = $this->start(['op' => 'hold_rows', 'rows' => [
            ['mlm_ledger_transactions', [
                'id' => $winner,
                'program_id' => $program->id,
                'currency' => 'IDR',
                'type' => 'adjustment',
                'source_type' => 'manual',
                'source_id' => 'ADJ-1',
                'idempotency_key' => 'adjustment:ADJ-1',
                'occurred_at' => '2026-06-01 12:00:00',
                'created_at' => '2026-06-01 12:00:00',
                'updated_at' => '2026-06-01 12:00:00',
            ]],
            ...array_map(static fn (array $line): array => ['mlm_ledger_postings', [
                'id' => (new LedgerPosting)->newUniqueId(),
                'ledger_transaction_id' => $winner,
                'ledger_account_id' => $line[0],
                'amount_millionths' => $line[1],
                'created_at' => '2026-06-01 12:00:00',
                'updated_at' => '2026-06-01 12:00:00',
            ]], [[$clearing->id, '-100000000'], [$wallet->id, '100000000']]),
        ]]);
        $this->awaitEvent($holder, 'held');

        DB::beginTransaction();

        try {
            // Reads first — on MySQL that fixes this transaction's snapshot —
            // then waits on the held key, loses to it, and must still see the
            // winner's postings to recognise the replay.
            $transaction = $this->app->make(LedgerRecorder::class)->post($this->postCommand($program, [[$wallet, '100'], [$clearing, '-100']]));

            $this->assertSame(1, DB::table('mlm_programs')->where('id', $program->id)->count());

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->assertSame($winner, $transaction->id);
        $this->assertTrue($this->finish($holder)['ok']);
        $this->assertSame([1, 2], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_ledger_postings')->count()]);
    }

    public function test_a_ledger_reversal_race_lost_inside_a_callers_transaction_is_refused_as_already_reversed(): void
    {
        [$program, $clearing, $wallet] = $this->ledgerAccounts();
        $original = $this->app->make(LedgerRecorder::class)->post($this->postCommand($program, [[$clearing, '-100'], [$wallet, '100']]));
        $reversal = (new LedgerTransaction)->newUniqueId();

        $holder = $this->start(['op' => 'hold_rows', 'rows' => [
            ['mlm_ledger_transactions', [
                'id' => $reversal,
                'program_id' => $program->id,
                'currency' => 'IDR',
                'type' => 'adjustment',
                'source_type' => 'manual',
                'source_id' => 'REV-1',
                'idempotency_key' => 'reversal:REV-1',
                'occurred_at' => '2026-06-15 12:00:00',
                'reversal_of_id' => $original->id,
                'created_at' => '2026-06-15 12:00:00',
                'updated_at' => '2026-06-15 12:00:00',
            ]],
            ...array_map(static fn (array $line): array => ['mlm_ledger_postings', [
                'id' => (new LedgerPosting)->newUniqueId(),
                'ledger_transaction_id' => $reversal,
                'ledger_account_id' => $line[0],
                'amount_millionths' => $line[1],
                'created_at' => '2026-06-15 12:00:00',
                'updated_at' => '2026-06-15 12:00:00',
            ]], [[$clearing->id, '100000000'], [$wallet->id, '-100000000']]),
        ]]);
        $this->awaitEvent($holder, 'held');

        DB::beginTransaction();

        try {
            try {
                $this->app->make(LedgerRecorder::class)->reverse($this->reverseCommand($original, 'reversal:REV-2', sourceId: 'REV-2'));
                $this->fail('The transaction was reversed twice.');
            } catch (InvalidLedgerReversal $exception) {
                $this->assertStringContainsString("already reversed by transaction [{$reversal}]", $exception->getMessage());
            }

            $this->assertSame(1, DB::table('mlm_programs')->where('id', $program->id)->count());
        } finally {
            DB::rollBack();
        }

        $this->assertTrue($this->finish($holder)['ok']);
        $this->assertSame($reversal, DB::table('mlm_ledger_transactions')->where('reversal_of_id', $original->id)->value('id'));
    }

    public function test_a_wallet_opened_by_another_session_is_usable_inside_a_callers_transaction(): void
    {
        $member = Member::factory()->create();
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');
        [$wallet, $account] = [(new Wallet)->newUniqueId(), (new LedgerAccount)->newUniqueId()];

        $holder = $this->start(['op' => 'hold_rows', 'rows' => [
            ['mlm_wallets', ['id' => $wallet, 'program_id' => $member->program_id, 'member_id' => $member->id, 'currency' => 'IDR', 'created_at' => '2026-06-01 12:00:00', 'updated_at' => '2026-06-01 12:00:00']],
            ['mlm_ledger_accounts', ['id' => $account, 'program_id' => $member->program_id, 'wallet_id' => $wallet, 'currency' => 'IDR', 'key' => 'wallet.'.$wallet, 'created_at' => '2026-06-01 12:00:00', 'updated_at' => '2026-06-01 12:00:00']],
        ]]);
        $this->awaitEvent($holder, 'held');

        DB::beginTransaction();

        try {
            // Loses the race to open the wallet, then posts to its account in
            // the same transaction: on MySQL both rows are newer than this
            // transaction's snapshot.
            $opened = $this->wallets()->open($member, 'IDR');
            $this->assertSame([$wallet, $account], [$opened->id, $opened->account?->id]);

            $posted = $this->app->make(LedgerRecorder::class)->post($this->postCommand($member->program, [[$clearing, '-10'], [$opened->account, '10']]));

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->assertTrue($this->finish($holder)['ok']);
        $this->assertSame(2, DB::table('mlm_ledger_postings')->where('ledger_transaction_id', $posted->id)->count());
        $this->assertSame('10', $this->balances()->forAccount(LedgerAccount::query()->findOrFail($account))->value());
    }

    public function test_a_calculation_reads_one_snapshot_while_another_session_commits(): void
    {
        $this->strategy(new SnapshotProbeStrategy($this->app->make(MetricEngine::class), static function (): void {}));
        $plan = Plan::factory()->create();
        $alice = Member::factory()->for($plan->program)->create(['member_code' => 'ALICE']);
        $bob = Member::factory()->for($plan->program)->create(['member_code' => 'BOB']);
        $this->record($alice, '100', 'alice-1', at: CarbonImmutable::parse('2026-06-10'));
        $component = $this->commissionComponent(['strategy' => 'test.snapshot', 'parameters' => []], $plan);
        $signal = tempnam(sys_get_temp_dir(), 'mlm-snapshot-');
        unlink($signal);

        $worker = $this->start(['op' => 'calculate_snapshot', 'component' => $component->id, 'from' => '2026-06-01 00:00:00', 'until' => '2026-07-01 00:00:00', 'key' => 'snapshot:1', 'signal' => $signal]);
        $this->awaitEvent($worker, 'first-read');

        // Committed by this session while the calculation is between its
        // two reads: more volume, and a new member.
        $this->record($alice, '50', 'alice-2', at: CarbonImmutable::parse('2026-06-11'));
        $this->record($bob, '70', 'bob-1', at: CarbonImmutable::parse('2026-06-12'));
        Member::factory()->for($plan->program)->create(['member_code' => 'CAROL']);
        touch($signal);

        $result = $this->finish($worker);
        unlink($signal);

        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
        $commissions = Commission::query()->where('calculation_run_id', $result['id'])->orderBy('candidate_key')->get();
        $this->assertSame(['member:ALICE', 'member:BOB'], $commissions->pluck('candidate_key')->all());

        foreach ($commissions as $commission) {
            $this->assertSame(['members' => 2, 'volume' => ['ALICE' => '100', 'BOB' => '0']], $commission->trace['first']);
            $this->assertSame($commission->trace['first'], $commission->trace['second']);
        }
    }

    public function test_identical_calculations_racing_store_one_run(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->count(2)->create();
        $job = $this->calculateJob($component, '2026-07-01 00:00:00');

        $this->closeGate('mlm_calculation_runs');
        $first = $this->start($job);
        $second = $this->start($job);
        $this->awaitWaitingOn(['mlm_calculation_runs', 'mlm_calculation_runs']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame([1, 2], [DB::table('mlm_calculation_runs')->count(), DB::table('mlm_commissions')->count()]);
    }

    public function test_conflicting_calculations_racing_store_one_and_refuse_the_other(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->count(2)->create();

        $this->closeGate('mlm_calculation_runs');
        $first = $this->start($this->calculateJob($component, '2026-07-01 00:00:00'));
        $second = $this->start($this->calculateJob($component, '2026-06-16 00:00:00'));
        $this->awaitWaitingOn(['mlm_calculation_runs', 'mlm_calculation_runs']);

        $this->openGate();

        $this->assertOneSucceededOneRefused(
            [$this->finish($first), $this->finish($second)],
            ConflictingCalculationReplay::class,
            'which differs in until',
        );
        $this->assertSame([1, 2], [DB::table('mlm_calculation_runs')->count(), DB::table('mlm_commissions')->count()]);
    }

    public function test_racing_posts_of_one_commission_move_its_money_once(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $commission = $this->approved($this->calculate($component)->commissions()->sole());

        $this->holdRow('mlm_commissions', $commission->id);
        $first = $this->start(['op' => 'commission_post', 'commission' => $commission->id]);
        $second = $this->start(['op' => 'commission_post', 'commission' => $commission->id]);
        $this->awaitWaitingOn(['mlm_commissions', 'mlm_commissions']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));

        $posted = Commission::query()->findOrFail($commission->id);
        $this->assertSame(CommissionStatus::Posted, $posted->status);
        $this->assertSame(1, DB::table('mlm_ledger_transactions')->where('source_type', 'commission')->where('source_id', $commission->id)->count());
        $this->assertSame('10.5', $this->balances()->forWallet(Wallet::query()->sole())->value());
    }

    public function test_racing_reversals_of_one_commission_reverse_it_once(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $commission = $this->poster()->post($this->approved($this->calculate($component)->commissions()->sole()));
        $job = ['op' => 'commission_reverse', 'commission' => $commission->id, 'occurred_at' => '2026-08-01 12:00:00'];

        $this->holdRow('mlm_commissions', $commission->id);
        $first = $this->start($job);
        $second = $this->start($job);
        $this->awaitWaitingOn(['mlm_commissions', 'mlm_commissions']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));

        $this->assertSame(CommissionStatus::Reversed, Commission::query()->findOrFail($commission->id)->status);
        $this->assertSame(1, DB::table('mlm_ledger_transactions')->where('reversal_of_id', $commission->ledger_transaction_id)->count());
        $this->assertSame('0', $this->balances()->forWallet(Wallet::query()->sole())->value());
    }

    public function test_racing_clawbacks_of_one_reversal_correct_each_commission_once(): void
    {
        [$entry, $run] = $this->sourcedCommissions();
        $posted = $this->poster()->post($this->approved($run->commissions()->firstOrFail()));
        $reversal = $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));

        $this->holdRow('mlm_volume_entries', $reversal->id);
        $first = $this->start(['op' => 'clawback', 'reversal' => $reversal->id]);
        $second = $this->start(['op' => 'clawback', 'reversal' => $reversal->id]);
        $this->awaitWaitingOn(['mlm_volume_entries', 'mlm_volume_entries']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $this->assertTrue($a['ok'], json_encode($a, JSON_THROW_ON_ERROR));
        $this->assertTrue($b['ok'], json_encode($b, JSON_THROW_ON_ERROR));
        $this->assertSame(['2', '2'], [$a['id'], $b['id']]);
        $this->assertSame(2, DB::table('mlm_commission_adjustments')->count());
        $this->assertSame(1, DB::table('mlm_ledger_transactions')->where('reversal_of_id', $posted->ledger_transaction_id)->count());
        $this->assertEqualsCanonicalizing(['reversed', 'cancelled'], DB::table('mlm_commissions')->pluck('status')->all());
        $this->assertSame('0', $this->balances()->forWallet(Wallet::query()->sole())->value());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function postAndClawbackOrders(): array
    {
        return ['the post waits first' => [true], 'the clawback waits first' => [false]];
    }

    /**
     * Whichever wins the commission's lock, money moves at most once and the
     * clawback always leaves it corrected: cancelled before any posting, or
     * reversed after it — never posted and left standing.
     */
    #[DataProvider('postAndClawbackOrders')]
    public function test_a_post_racing_a_clawback_never_leaves_the_commission_paid(bool $postFirst): void
    {
        [$entry, $run] = $this->sourcedCommissions();
        $commission = $this->approved($run->commissions()->firstOrFail());
        $reversal = $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));
        $jobs = [
            'post' => ['op' => 'commission_post', 'commission' => $commission->id],
            'clawback' => ['op' => 'clawback', 'reversal' => $reversal->id],
        ];

        $this->holdRow('mlm_commissions', $commission->id);
        $workers = [];

        foreach ($postFirst ? ['post', 'clawback'] : ['clawback', 'post'] as $index => $name) {
            $workers[$name] = $this->start($jobs[$name]);
            $this->awaitWaitingOn(array_fill(0, $index + 1, 'mlm_commissions'));
        }

        $this->openGate();

        [$post, $clawback] = [$this->finish($workers['post']), $this->finish($workers['clawback'])];
        $shown = json_encode([$post, $clawback], JSON_THROW_ON_ERROR);
        $status = DB::table('mlm_commissions')->where('id', $commission->id)->value('status');
        $adjustment = DB::table('mlm_commission_adjustments')->where('commission_id', $commission->id)->sole();

        $this->assertTrue($clawback['ok'], $shown);

        if ($status === 'cancelled') {
            // The clawback went first: nothing was ever posted.
            $this->assertFalse($post['ok'], $shown);
            $this->assertStringContainsString('is cancelled and cannot become posted', $post['message'], $shown);
            $this->assertSame(['cancelled', null], [$adjustment->outcome, $adjustment->ledger_transaction_id]);
            $this->assertSame(0, DB::table('mlm_ledger_transactions')->where('source_type', 'commission')->count());
        } else {
            // The post went first: the clawback reversed it.
            $this->assertSame('reversed', $status, $shown);
            $this->assertTrue($post['ok'], $shown);
            $this->assertSame('reversed', $adjustment->outcome);
            $this->assertSame(2, DB::table('mlm_ledger_transactions')->where('source_type', 'commission')->count());
            $this->assertSame('0', $this->balances()->forWallet(Wallet::query()->sole())->value());
        }

        fwrite(STDERR, sprintf("\n[race %s] %s => %s\n", ExternalDatabase::selected()?->engine, $postFirst ? 'post waited first' : 'clawback waited first', $status));
    }

    /**
     * Two processors of one binary reversal (ADR-026) serialize on the
     * reversal's row: the posted commission is corrected once, and its
     * share moves back once.
     */
    public function test_racing_binary_corrections_of_one_reversal_move_its_share_back_once(): void
    {
        [$commission, $reversal] = $this->binaryCorrection(partial: true, posted: true);

        $this->holdRow('mlm_volume_entries', $reversal->id);
        $first = $this->start(['op' => 'binary_clawback', 'reversal' => $reversal->id]);
        $second = $this->start(['op' => 'binary_clawback', 'reversal' => $reversal->id]);
        $this->awaitWaitingOn(['mlm_volume_entries', 'mlm_volume_entries']);

        $this->openGate();

        [$a, $b] = [$this->finish($first), $this->finish($second)];
        $shown = json_encode([$a, $b], JSON_THROW_ON_ERROR);
        $this->assertSame([true, '1', true, '1'], [$a['ok'], $a['id'] ?? null, $b['ok'], $b['id'] ?? null], $shown);
        $adjustment = DB::table('mlm_commission_adjustments')->sole();
        $this->assertSame(['-30000000', 'adjusted'], [(string) $adjustment->amount_millionths, $adjustment->outcome]);
        $this->assertSame(1, DB::table('mlm_ledger_transactions')->where('type', 'commission-adjustment')->count());
        $this->assertSame('posted', DB::table('mlm_commissions')->where('id', $commission->id)->value('status'));
        $this->assertSame('70', $this->balances()->forWallet(Wallet::query()->sole())->value());
    }

    /**
     * Two reversals undoing one approved commission's pair between them
     * serialize on the commission's row, and the second sees what the
     * first recorded: whichever goes first, the one that leaves nothing
     * cancels the commission.
     */
    public function test_racing_corrections_of_two_reversals_of_one_commission_see_each_other(): void
    {
        [$commission, $first] = $this->binaryCorrection(partial: true, second: true);
        $second = VolumeEntry::query()->where('idempotency_key', 'l2-refund')->sole();

        $this->holdRow('mlm_commissions', $commission->id);
        $a = $this->start(['op' => 'binary_clawback', 'reversal' => $first->id]);
        $this->awaitWaitingOn(['mlm_commissions']);
        $b = $this->start(['op' => 'binary_clawback', 'reversal' => $second->id]);
        $this->awaitWaitingOn(['mlm_commissions', 'mlm_commissions']);

        $this->openGate();

        [$a, $b] = [$this->finish($a), $this->finish($b)];
        $shown = json_encode([$a, $b], JSON_THROW_ON_ERROR);
        $this->assertTrue($a['ok'] && $b['ok'], $shown);

        $adjustments = DB::table('mlm_commission_adjustments')->orderBy('created_at')->orderBy('id')->get()
            ->mapWithKeys(static fn (object $row): array => [$row->source_id === $first->id ? 'l1' : 'l2' => [(string) $row->amount_millionths, $row->outcome]])
            ->all();
        ksort($adjustments);

        $this->assertContains($adjustments, [
            ['l1' => ['-30000000', 'recorded'], 'l2' => ['-70000000', 'cancelled']],
            ['l1' => ['-30000000', 'cancelled'], 'l2' => ['-70000000', 'recorded']],
        ], $shown);
        $this->assertSame('cancelled', DB::table('mlm_commissions')->where('id', $commission->id)->value('status'));
        $this->assertSame(0, DB::table('mlm_ledger_transactions')->count());
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function postAndBinaryCorrectionOrders(): array
    {
        return [
            'partial, the post waits first' => [true, true],
            'partial, the correction waits first' => [true, false],
            'full, the post waits first' => [false, true],
            'full, the correction waits first' => [false, false],
        ];
    }

    /**
     * A post racing a binary correction of the same approved commission
     * serializes on the commission's row. A post that wins it finds the
     * correction unresolved and is refused; one that loses posts what is
     * left, or finds the commission cancelled. Either way, once posting is
     * retried, the money moved is the same, and moved once.
     */
    #[DataProvider('postAndBinaryCorrectionOrders')]
    public function test_a_post_racing_a_binary_correction_ends_with_the_same_money_moved(bool $partial, bool $postFirst): void
    {
        [$commission, $reversal] = $this->binaryCorrection($partial);
        $jobs = [
            'post' => ['op' => 'commission_post', 'commission' => $commission->id],
            'correction' => ['op' => 'binary_clawback', 'reversal' => $reversal->id],
        ];

        $this->holdRow('mlm_commissions', $commission->id);
        $workers = [];

        foreach ($postFirst ? ['post', 'correction'] : ['correction', 'post'] as $index => $name) {
            $workers[$name] = $this->start($jobs[$name]);
            $this->awaitWaitingOn(array_fill(0, $index + 1, 'mlm_commissions'));
        }

        $this->openGate();

        [$post, $correction] = [$this->finish($workers['post']), $this->finish($workers['correction'])];
        $shown = json_encode([$post, $correction], JSON_THROW_ON_ERROR);
        $this->assertTrue($correction['ok'], $shown);

        if (! $post['ok']) {
            $this->assertContains($post['exception'], [UnresolvedBinaryCorrection::class, InvalidCommissionTransition::class], $shown);
        }

        $retry = null;

        if ($partial && ! $post['ok']) {
            $retry = $this->poster()->post(Commission::query()->findOrFail($commission->id));
        }

        $adjustment = DB::table('mlm_commission_adjustments')->where('commission_id', $commission->id)->sole();
        $status = DB::table('mlm_commissions')->where('id', $commission->id)->value('status');

        if ($partial) {
            $this->assertSame(['-30000000', 'recorded', null], [(string) $adjustment->amount_millionths, $adjustment->outcome, $adjustment->ledger_transaction_id], $shown);
            $this->assertSame(['posted', '70000000'], [$status, (string) DB::table('mlm_commissions')->where('id', $commission->id)->value('posted_amount_millionths')], $shown);
            $this->assertSame(1, DB::table('mlm_ledger_transactions')->count(), $shown);
            $this->assertSame('70', $this->balances()->forWallet(Wallet::query()->sole())->value(), $shown);
        } else {
            $this->assertFalse($post['ok'], $shown);
            $this->assertSame(['-100000000', 'cancelled', 'cancelled'], [(string) $adjustment->amount_millionths, $adjustment->outcome, $status], $shown);
            $this->assertSame(0, DB::table('mlm_ledger_transactions')->count(), $shown);
        }

        fwrite(STDERR, sprintf("\n[binary race %s] %s, %s => post %s%s\n", ExternalDatabase::selected()?->engine, $partial ? 'partial' : 'full', $postFirst ? 'post waited first' : 'correction waited first', $post['ok'] ? 'ok' : $post['exception'], $retry === null ? '' : ', retried'));
    }

    /**
     * An approved binary commission of 100 on one pair of 100 — left l1 30
     * and l2 70 when `$partial`, l1 100 otherwise; right r1 100 — posted if
     * asked before anything is undone, and a reversal of l1 the next
     * month's run has taken in: its pair is undone by 30, or wholly. With
     * `$second`, l2 is reversed in that run too, a day later.
     *
     * @return array{Commission, VolumeEntry}
     */
    private function binaryCorrection(bool $partial, bool $posted = false, bool $second = false): array
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'P', 'L', 'R');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['L'], $members['P'], BinarySide::Left);
        $this->binary()->place($members['R'], $members['P'], BinarySide::Right);
        $this->travelBack();
        $source = $this->sale($members['L'], $partial ? '30' : '100', '2026-01-10', 'l1');

        $rest = $partial ? $this->sale($members['L'], '70', '2026-01-11', 'l2') : null;

        $this->sale($members['R'], '100', '2026-01-10', 'r1');
        $component = $this->fixedComponent('binary.pairing.fixed', ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '100'], $plan);
        $commission = $this->approved($this->calculate($component, '2026-01-01 00:00:00', '2026-02-01 00:00:00', 'jan')->commissions()->sole());

        if ($posted) {
            $commission = $this->poster()->post($commission);
        }

        $reversal = $this->reverse($source, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        if ($second && $rest !== null) {
            $this->reverse($rest, 'l2-refund', at: CarbonImmutable::parse('2026-02-06'));
        }

        $this->calculate($component, '2026-02-01 00:00:00', '2026-03-01 00:00:00', 'feb');

        return [$commission, $reversal];
    }

    /**
     * An original entry, and a run paying two sponsors on it.
     *
     * @return array{VolumeEntry, CalculationRun}
     */
    private function sourcedCommissions(): array
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'ALICE', 'BOB', 'CHARLIE');
        $this->sponsorAt($members['BOB'], $members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($members['CHARLIE'], $members['BOB'], '2026-01-01 00:00:00');
        $entry = $this->sale($members['CHARLIE'], '150', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(['levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']]]), $plan);

        return [$entry, $this->monthly($component, '2026-01')];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * A binary pairing component over P, with L on its left and R on its
     * right: January brings 250 left and 120 right, February 180 right.
     */
    private function pairingRace(): PlanComponent
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'P', 'L', 'R');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['L'], $members['P'], BinarySide::Left);
        $this->binary()->place($members['R'], $members['P'], BinarySide::Right);
        $this->travelBack();
        $this->sale($members['L'], '250', '2026-01-10', 'l1');
        $this->sale($members['R'], '120', '2026-01-11', 'r1');
        $this->sale($members['R'], '180', '2026-02-11', 'r2');

        return $this->fixedComponent('binary.pairing.fixed', ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10'], $plan);
    }

    private function calculateJob(PlanComponent $component, string $until): array
    {
        return ['op' => 'calculate', 'component' => $component->id, 'from' => '2026-06-01 00:00:00', 'until' => $until, 'key' => 'run:2026-06'];
    }

    /**
     * A validated plan version composing binary pairing — P with L left and
     * R right, 100 each — and unilevel — S sponsoring L — and the source
     * account both are funded from.
     *
     * @return array{PlanVersion, LedgerAccount}
     */
    private function hybridRace(): array
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'P', 'L', 'R', 'S');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['L'], $members['P'], BinarySide::Left);
        $this->binary()->place($members['R'], $members['P'], BinarySide::Right);
        $this->genealogy()->assignSponsor($members['L'], $members['S']);
        $this->travelBack();
        $this->sale($members['L'], '100', '2026-01-10', 'l1');
        $this->sale($members['R'], '100', '2026-01-10', 'r1');
        $account = $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'binary.pairing.fixed', 'parameters' => ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10']]), 'binary');
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'unilevel.fixed', 'parameters' => $this->unilevelParameters(['levels' => [['depth' => 1, 'amount' => '3']]])]), 'unilevel');
        $this->lifecycle()->markValidated($draft);

        return [PlanVersion::query()->findOrFail($draft->id), $account];
    }

    /**
     * Every matrix position as "member #slot", by member code, sorted.
     *
     * @return list<string>
     */
    private function matrixSlots(): array
    {
        $codes = Member::query()->pluck('member_code', 'id');

        return DB::table('mlm_matrix_placement_positions as positions')
            ->join('mlm_placement_edges as edges', 'edges.id', '=', 'positions.placement_edge_id')
            ->get(['edges.member_id', 'positions.slot'])
            ->map(static fn (object $position): string => "{$codes[$position->member_id]} #{$position->slot}")
            ->sort()->values()->all();
    }

    /**
     * Holds one row locked, so every session that locks it waits.
     */
    private function holdRow(string $table, string $id): void
    {
        DB::connection($this->gateConnection())->beginTransaction();
        DB::connection($this->gateConnection())->select("SELECT id FROM {$table} WHERE id = ? FOR UPDATE", [$id]);
    }

    /**
     * A program with a clearing account and one member's wallet account.
     *
     * @return array{Program, LedgerAccount, LedgerAccount}
     */
    private function ledgerAccounts(): array
    {
        $member = Member::factory()->create();

        return [
            $member->program,
            $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing'),
            $this->walletAccount($member),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postJob(Program $program, LedgerAccount $clearing, LedgerAccount $wallet, string $amount): array
    {
        return [
            'op' => 'ledger_post',
            'program' => $program->id,
            'currency' => 'IDR',
            'type' => 'adjustment',
            'source_type' => 'manual',
            'source_id' => 'ADJ-1',
            'key' => 'adjustment:ADJ-1',
            'occurred_at' => '2026-06-01 12:00:00',
            'postings' => [[$clearing->id, '-'.$amount], [$wallet->id, $amount]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverseLedgerJob(LedgerTransaction $transaction, string $key): array
    {
        return [
            'op' => 'ledger_reverse',
            'transaction' => $transaction->id,
            'source_type' => 'manual',
            'source_id' => $key,
            'key' => $key,
            'occurred_at' => '2026-06-15 12:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordJob(Member $member, string $quantity): array
    {
        return [
            'op' => 'record',
            'member' => $member->id,
            'type' => 'sales',
            'quantity' => $quantity,
            'source_type' => 'order',
            'source_id' => 'ORD-1',
            'key' => 'order:ORD-1',
            'effective_at' => '2026-06-01 12:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverseJob(VolumeEntry $entry, string $key): array
    {
        return [
            'op' => 'reverse',
            'entry' => $entry->id,
            'source_type' => 'refund',
            'source_id' => $key,
            'key' => $key,
            'effective_at' => '2026-06-15 12:00:00',
        ];
    }

    /**
     * Starts one operation in its own process and database session.
     *
     * @param  array<string, mixed>  $job
     */
    private function start(array $job): int
    {
        $job = ['engine' => ExternalDatabase::selected()?->engine, ...$job];

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/worker.php', json_encode($job, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Could not start a worker process.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'output' => '', 'finished' => false];

        return array_key_last($this->workers);
    }

    /**
     * The worker's result, once it has exited.
     *
     * @return array<string, mixed>
     */
    private function finish(int $worker, float $timeout = 40.0): array
    {
        $deadline = microtime(true) + $timeout;

        while (proc_get_status($this->workers[$worker]['process'])['running']) {
            $this->read($worker);

            if (microtime(true) > $deadline) {
                $this->fail("Worker {$worker} did not finish within {$timeout}s. Output: ".$this->workers[$worker]['output']);
            }

            usleep(20_000);
        }

        $this->read($worker);
        $this->workers[$worker]['finished'] = true;

        foreach (array_reverse($this->lines($worker)) as $line) {
            if (array_key_exists('ok', $line)) {
                return $line;
            }
        }

        $this->fail("Worker {$worker} printed no result. Output: ".$this->workers[$worker]['output'].' '.stream_get_contents($this->workers[$worker]['stderr']));
    }

    private function awaitEvent(int $worker, string $event, float $timeout = 20.0): void
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->read($worker);

            foreach ($this->lines($worker) as $line) {
                if (($line['event'] ?? null) === $event) {
                    return;
                }

                if (array_key_exists('ok', $line)) {
                    $this->fail("Worker {$worker} finished before \"{$event}\": ".json_encode($line, JSON_THROW_ON_ERROR));
                }
            }

            usleep(20_000);
        }

        $this->fail("Worker {$worker} did not report \"{$event}\" within {$timeout}s.");
    }

    /**
     * Waits until each table is named by the statement of a different
     * session blocked on a lock, as the database itself reports it. It keeps
     * polling until then, rather than judging one reading: a reading can
     * catch a wait just starting or ending.
     *
     * @param  list<string>  $tables
     */
    private function awaitWaitingOn(array $tables, float $timeout = 20.0): void
    {
        $query = ExternalDatabase::selected()?->waitingStatementsQuery() ?? '';
        $deadline = microtime(true) + $timeout;

        do {
            $statements = array_map(
                static fn (object $row): string => (string) $row->statement,
                DB::connection($this->monitorConnection())->select($query),
            );

            if (self::eachNamedOnce($statements, $tables)) {
                return;
            }

            foreach ($this->workers as $number => $worker) {
                if (! $worker['finished'] && ! proc_get_status($worker['process'])['running']) {
                    $this->read($number);
                    $this->fail("Worker {$number} finished instead of waiting: ".$this->workers[$number]['output']);
                }
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Expected sessions waiting on '.implode(', ', $tables)." within {$timeout}s. Waiting: ".implode(' | ', $statements));
    }

    /**
     * Whether each table is named by a different one of the statements.
     *
     * @param  list<string>  $statements
     * @param  list<string>  $tables
     */
    private static function eachNamedOnce(array $statements, array $tables): bool
    {
        foreach ($tables as $table) {
            $match = array_key_first(array_filter($statements, static fn (string $statement): bool => str_contains($statement, $table)));

            if ($match === null) {
                return false;
            }

            unset($statements[$match]);
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertOneSucceededOneRefused(array $results, string $exception, string $reason): void
    {
        $succeeded = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $refused = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));
        $shown = json_encode($results, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $succeeded, $shown);
        $this->assertCount(1, $refused, $shown);
        $this->assertSame($exception, $refused[0]['exception'], $shown);
        $this->assertStringContainsString($reason, $refused[0]['message'], $shown);
    }

    /**
     * Recomputes the whole closure from the direct edges and compares it
     * with what is stored — and fails on any cycle. Every path must also
     * carry its moment: the latest edge on its chain, or for a self path the
     * member's first edge.
     */
    private function assertTreeConsistent(string $tree, string $edges, string $parentColumn, string $atColumn): void
    {
        /** @var array<string, string> $parents */
        $parents = DB::table($edges)->pluck($parentColumn, 'member_id')->all();
        /** @var array<string, string> $at */
        $at = DB::table($edges)->pluck($atColumn, 'member_id')->map(static fn (mixed $moment): string => (string) $moment)->all();

        $this->assertPathsMatch($tree, $parents, $at);
    }

    /**
     * The binary tree's paths against its positions, as for the other trees:
     * each position's member is its edge's.
     */
    private function assertBinaryTreeConsistent(): void
    {
        $positions = DB::table('mlm_binary_placement_positions as positions')
            ->join('mlm_placement_edges as edges', 'edges.id', '=', 'positions.placement_edge_id')
            ->get(['edges.member_id', 'edges.parent_id as edge_parent_id', 'positions.parent_id', 'positions.assigned_at']);

        foreach ($positions as $position) {
            $this->assertSame($position->edge_parent_id, $position->parent_id);
        }

        $this->assertPathsMatch(
            'binary',
            $positions->pluck('parent_id', 'member_id')->all(),
            $positions->pluck('assigned_at', 'member_id')->map(static fn (mixed $moment): string => (string) $moment)->all(),
        );
    }

    /**
     * @param  array<string, string>  $parents
     * @param  array<string, string>  $at
     */
    private function assertPathsMatch(string $tree, array $parents, array $at): void
    {
        $expected = [];

        foreach (array_unique([...array_keys($parents), ...array_values($parents)]) as $member) {
            $first = min(array_filter([$at[$member] ?? null, ...array_map(
                static fn (string $child): string => $at[$child],
                array_keys($parents, $member, true),
            )]));
            $expected[] = "{$member}>{$member}@0 from {$first}";
            $seen = [$member => true];
            $current = $member;
            $depth = 0;
            $latest = '';

            while (isset($parents[$current])) {
                $latest = max($latest, $at[$current]);
                $current = $parents[$current];
                $depth++;

                $this->assertArrayNotHasKey($current, $seen, "The {$tree} tree has a cycle through {$current}.");
                $seen[$current] = true;
                $expected[] = "{$current}>{$member}@{$depth} from {$latest}";
            }
        }

        $stored = DB::table('mlm_genealogy_paths')->where('tree_type', $tree)->get()
            ->map(static fn (object $path): string => "{$path->ancestor_id}>{$path->descendant_id}@{$path->depth} from {$path->effective_from}")
            ->all();

        sort($expected);
        sort($stored);

        $this->assertSame($expected, $stored);
    }

    /**
     * Holds a lock that makes every insert into the table wait.
     */
    private function closeGate(string $table): void
    {
        $gate = DB::connection($this->gateConnection());

        $gate->beginTransaction();
        $gate->statement(ExternalDatabase::selected()?->insertGateStatement($table) ?? '');
    }

    private function openGate(): void
    {
        DB::connection($this->gateConnection())->rollBack();
    }

    private function gateConnection(): string
    {
        return ExternalDatabase::selected()?->connection.'_gate';
    }

    private function monitorConnection(): string
    {
        return ExternalDatabase::selected()?->connection.'_monitor';
    }

    private function read(int $worker): void
    {
        $chunk = stream_get_contents($this->workers[$worker]['stdout']);

        if (is_string($chunk)) {
            $this->workers[$worker]['output'] .= $chunk;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(int $worker): array
    {
        $lines = [];

        foreach (explode("\n", trim($this->workers[$worker]['output'])) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }
}
