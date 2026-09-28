<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Database;

use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Exceptions\InvalidVolumeReversal;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Tests\DatabaseTestCase;
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
    use BuildsGenealogies;
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
