<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionSourceReference;
use PandaBear\Mlm\Exceptions\InvalidCommissionSource;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Commissions keep, as relational data beside their trace, the business
 * record they were earned from — when their strategy names it.
 */
final class CommissionProvenanceTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    public function test_a_source_reference_is_a_type_and_an_id_taken_exactly(): void
    {
        $reference = CommissionSourceReference::of('volume-entry', '01ABC');

        $this->assertSame(['volume-entry', '01ABC'], [$reference->type, $reference->id]);
        $this->assertSame('42', CommissionSourceReference::of('acme.order', 42)->id);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidReferences(): array
    {
        return [
            'an uppercase type' => ['Volume-Entry', '01ABC', 'source type is 1–64'],
            'a class name as type' => ['App\\Models\\Order', '01ABC', 'source type is 1–64'],
            'a type with a trailing newline' => ["volume-entry\n", '01ABC', 'source type is 1–64'],
            'a type too long' => [str_repeat('t', 65), '01ABC', 'source type is 1–64'],
            'an empty id' => ['volume-entry', '', 'source id is 1–191'],
            'a padded id' => ['volume-entry', ' 01ABC', 'source id is 1–191'],
            'an id with a newline' => ['volume-entry', "01ABC\n", 'source id is 1–191'],
            'an id too long' => ['volume-entry', str_repeat('i', 192), 'source id is 1–191'],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function test_anything_else_is_refused_not_rewritten(string $type, string $id, string $reason): void
    {
        $this->expectException(InvalidCommissionSource::class);
        $this->expectExceptionMessage($reason);

        CommissionSourceReference::of($type, $id);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function builtInStrategies(): array
    {
        return [
            'direct-sponsor.fixed' => ['direct-sponsor.fixed', 'direct'],
            'direct-sponsor.proportional' => ['direct-sponsor.proportional', 'direct-proportional'],
            'unilevel.fixed' => ['unilevel.fixed', 'unilevel'],
            'unilevel.proportional' => ['unilevel.proportional', 'unilevel-proportional'],
        ];
    }

    #[DataProvider('builtInStrategies')]
    public function test_every_built_in_strategy_records_the_entry_it_paid_on(string $strategy, string $shape): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'ALICE', 'BOB', 'CHARLIE');
        $this->sponsorAt($members['BOB'], $members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($members['CHARLIE'], $members['BOB'], '2026-01-01 00:00:00');
        $first = $this->sale($members['CHARLIE'], '150', '2026-01-10', 'order:A');
        $second = $this->sale($members['CHARLIE'], '200', '2026-01-11', 'order:B');
        $parameters = match ($shape) {
            'direct' => $this->directParameters(),
            'direct-proportional' => $this->directProportionalParameters(),
            'unilevel' => $this->unilevelParameters(),
            'unilevel-proportional' => $this->unilevelProportionalParameters(),
        };

        $commissions = $this->monthly($this->fixedComponent($strategy, $parameters, $plan), '2026-01')->commissions()->get();

        $this->assertNotEmpty($commissions);

        foreach ($commissions as $commission) {
            $this->assertSame('volume-entry', $commission->source_type);
            $this->assertSame($commission->trace['source']['volume_entry_id'], $commission->source_id);
            $this->assertContains($commission->source_id, [$first->id, $second->id]);
        }
    }

    public function test_an_applications_strategy_records_provenance_only_when_it_names_it(): void
    {
        $strategy = $this->scriptedStrategy();
        $component = $this->commissionComponent(['strategy' => 'test.scripted', 'parameters' => []]);
        $member = Member::factory()->for($component->planVersion->plan->program)->create();
        $entry = $this->record($member, '10', 'order:A');
        $strategy->script = static fn (): iterable => [
            // As strategies were written before provenance: positionally, no source.
            new CommissionCandidate('plain', $member, '1', CarbonImmutable::parse('2026-06-30'), ['note' => 'no source']),
            new CommissionCandidate('sourced', $member, '2', CarbonImmutable::parse('2026-06-30'), source: CommissionSourceReference::volumeEntry($entry)),
            new CommissionCandidate('elsewhere', $member, '3', CarbonImmutable::parse('2026-06-30'), source: CommissionSourceReference::of('acme.invoice', 'INV-7')),
        ];

        $sources = $this->calculate($component)->commissions()->get()
            ->mapWithKeys(static fn (Commission $commission): array => [$commission->candidate_key => [$commission->source_type, $commission->source_id]])
            ->all();

        $this->assertSame([
            'elsewhere' => ['acme.invoice', 'INV-7'],
            'plain' => [null, null],
            'sourced' => ['volume-entry', $entry->id],
        ], $sources);
        $this->assertInstanceOf(VolumeEntry::class, $entry);
    }
}
