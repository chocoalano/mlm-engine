<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * `mlm.database.connection` names a connection other than the default, and
 * the migrations, the models, the plan lifecycle, both genealogies, the
 * volume history and the metrics read from it all follow it.
 */
final class ConfiguredConnectionTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'];

    private const MODELS = [Program::class, Member::class, Plan::class, PlanVersion::class, SponsorEdge::class, PlacementEdge::class, VolumeEntry::class, PlanComponent::class, PlanRule::class, Wallet::class, LedgerAccount::class, LedgerTransaction::class, LedgerPosting::class];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('database.connections.mlm', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('mlm.database.connection', 'mlm');
    }

    public function test_the_migrations_build_on_the_configured_connection(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::connection('mlm')->hasTable($table), "{$table} is not on [mlm].");
            $this->assertFalse(Schema::connection('testing')->hasTable($table), "{$table} is on the default connection.");
        }

        // The upgrade ran there too, and left nothing behind anywhere.
        $this->assertTrue(Schema::connection('mlm')->hasColumn('mlm_genealogy_paths', 'effective_from'));
        $this->assertFalse(Schema::connection('mlm')->hasTable('mlm_genealogy_paths_replay_000009'));
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_genealogy_paths_replay_000009'));
    }

    public function test_the_models_use_the_configured_connection(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('mlm', (new $model)->getConnectionName(), $model);
        }

        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();

        $this->assertDatabaseHas('mlm_programs', ['id' => $program->id], 'mlm');
        $this->assertDatabaseHas('mlm_members', ['id' => $member->id], 'mlm');
        $this->assertTrue($program->members()->sole()->is($member));
    }

    public function test_the_lifecycle_runs_on_the_configured_connection(): void
    {
        $lifecycle = $this->app->make(PlanVersionLifecycle::class);
        $plan = Plan::factory()->create();

        $v1 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));
        $v2 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));

        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v1->id, 'status' => 'superseded'], 'mlm');
        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v2->id, 'status' => 'active'], 'mlm');
        $this->assertTrue($plan->currentActiveVersion()?->is($v2));
    }

    public function test_the_sponsor_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->sponsorTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->genealogyState('mlm')['edges']);
        $this->assertCount(6, $this->genealogyState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->genealogy()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->genealogy()->ancestors($members['Charlie'])));
        $this->assertTrue($this->genealogy()->directSponsor($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->genealogy()->directMembers($members['Bob'])->pluck('member_code')->all());
    }

    public function test_sponsor_history_is_written_and_read_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->sponsorTree($members, ['Bob' => ['Charlie']]);

        $this->assertSame('2026-03-01 00:00:00', $this->pathMoments('sponsor', 'mlm')['Alice > Charlie @2']);

        $february = CarbonImmutable::parse('2026-02-01 00:00:00');
        $this->assertSame(['Bob@1'], $this->relatives($this->genealogy()->descendantsAt($members['Alice'], $february)));
        $this->assertSame([], $this->relatives($this->genealogy()->ancestorsAt($members['Charlie'], $february)));
        $this->assertNull($this->genealogy()->directSponsorAt($members['Charlie'], $february));
        $this->assertSame(['Bob'], $this->genealogy()->directMembersAt($members['Alice'], $february)->pluck('member_code')->all());
    }

    public function test_placement_history_is_written_and_read_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->placementTree($members, ['Alice' => ['Bob']]);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->placementTree($members, ['Bob' => ['Charlie']]);

        $this->assertSame('2026-03-01 00:00:00', $this->pathMoments('placement', 'mlm')['Alice > Charlie @2']);

        $february = CarbonImmutable::parse('2026-02-01 00:00:00');
        $this->assertSame(['Bob@1'], $this->relatives($this->placement()->descendantsAt($members['Alice'], $february)));
        $this->assertSame([], $this->relatives($this->placement()->ancestorsAt($members['Charlie'], $february)));
        $this->assertNull($this->placement()->directParentAt($members['Charlie'], $february));
        $this->assertSame(['Bob'], $this->placement()->directChildrenAt($members['Alice'], $february)->pluck('member_code')->all());
    }

    public function test_the_placement_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->placementTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->placementState('mlm')['edges']);
        $this->assertCount(6, $this->placementState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->placement()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->placement()->ancestors($members['Charlie'])));
        $this->assertTrue($this->placement()->directParent($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->placement()->directChildren($members['Bob'])->pluck('member_code')->all());
    }

    public function test_volume_is_recorded_reversed_and_totalled_on_the_configured_connection(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');

        $entry = $this->record($alice, '20', 'order:ORD-1');
        $this->record($alice, '5', 'order:ORD-2');
        $this->reverse($entry, 'refund:RF-1');

        $this->assertCount(3, $this->volumeRows('mlm'));
        $this->assertSame('5', $this->totals()->forMember($alice, 'sales')->value());
        $this->assertTrue($this->record($alice, '20', 'order:ORD-1')->is($entry));
    }

    public function test_metrics_resolve_from_the_configured_connection(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');
        $this->record($alice, '20', 'order:ORD-1');
        $this->reverse($this->record($alice, '5', 'order:ORD-2'), 'refund:RF-1');

        // The volume table exists only on [mlm]: a query on the default
        // connection would fail, not return zero.
        $value = $this->app->make(MetricEngine::class)->resolve('member.volume', new MetricContext($alice, ['type' => 'sales']));

        $this->assertSame('20', $value->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_volume_entries'));
    }

    public function test_network_metrics_resolve_from_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->placementTree($members, ['Alice' => ['Charlie']]);

        $this->record($members['Bob'], '20', 'order:ORD-1', at: CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->reverse($this->record($members['Bob'], '5', 'order:ORD-2', at: CarbonImmutable::parse('2026-02-01 00:00:00')), 'refund:RF-1');
        $this->record($members['Charlie'], '7', 'order:ORD-3', at: CarbonImmutable::parse('2026-02-01 00:00:00'));

        // Genealogy and volume exist only on [mlm]: a query on the default
        // connection would fail, not return zero.
        $engine = $this->app->make(MetricEngine::class);

        $this->assertSame('20', $engine->resolve('sponsor.network.volume', new MetricContext($members['Alice'], ['type' => 'sales']))->value());
        $this->assertSame('7', $engine->resolve('placement.network.volume', new MetricContext($members['Alice'], ['type' => 'sales', 'max_depth' => 1]))->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_genealogy_paths'));
    }

    public function test_plan_definitions_are_edited_validated_and_cloned_on_the_configured_connection(): void
    {
        $version = $this->validDraft();
        $component = $version->components()->sole();

        $this->assertSame('mlm', $component->getConnectionName());
        $this->assertSame('mlm', $component->rules()->sole()->getConnectionName());
        $this->assertSame($this->qualifyingRule()->toArray(), $component->rules()->sole()->definition->toArray());

        $validated = $this->lifecycle()->markValidated($version);
        $clone = $this->cloner()->cloneToNewDraft($validated);

        $this->assertSame($this->storedDefinition($validated), $this->storedDefinition($clone));
        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $clone->id, 'status' => 'draft', 'version' => 2], 'mlm');
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_components')->count());
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_rules')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_components'));
    }

    public function test_a_rule_is_evaluated_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $member = Member::factory()->for($plan->program)->create();
        $this->record($member, '150', 'order:ORD-1');
        $rule = $this->validatedRule(RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')), $plan);

        $decision = $this->app->make(QualificationEngine::class)->evaluate($rule, new QualificationContext($member));

        $this->assertTrue($decision->qualified);
        $this->assertSame('150', $decision->toArray()['trace']['children'][0]['value']);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_rules'));
    }

    public function test_a_rank_ladder_is_defined_and_evaluated_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $member = Member::factory()->for($plan->program)->create();
        $this->record($member, '150', 'order:ORD-1');
        $ladder = $this->validatedLadder([
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '200'))],
        ], $plan);

        $decision = $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($member));

        $this->assertSame('mlm', $ladder->getConnectionName());
        $this->assertSame('bronze', $decision->selectedRank?->key);
        $this->assertSame('150', $decision->toArray()['ranks'][1]['trace']['children'][0]['value']);
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_rules')->where('plan_component_id', $ladder->id)->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_components'));
    }

    public function test_the_ledger_opens_posts_reverses_and_reads_on_the_configured_connection(): void
    {
        $member = Member::factory()->create();
        $wallet = $this->wallets()->open($member, 'IDR');
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');
        $account = $wallet->account;
        $this->assertNotNull($account);

        $credit = $this->postLedger($member->program, [[$clearing, '-100'], [$account, '100']]);
        $this->postLedger($member->program, [[$clearing, '-25.5'], [$account, '25.5']], 'adjustment:ADJ-2', 'ADJ-2');
        $this->reverseLedger($credit);

        $this->assertSame('mlm', $credit->getConnectionName());
        $this->assertSame('25.5', $this->balances()->forWallet($wallet)->value());
        $this->assertSame('-25.5', $this->balances()->forAccount($clearing)->value());
        $this->assertSame([1, 2, 3, 6], [
            DB::connection('mlm')->table('mlm_wallets')->count(),
            DB::connection('mlm')->table('mlm_ledger_accounts')->count(),
            DB::connection('mlm')->table('mlm_ledger_transactions')->count(),
            DB::connection('mlm')->table('mlm_ledger_postings')->count(),
        ]);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_ledger_postings'));
    }

    public function test_a_connection_set_on_the_model_still_wins(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('testing', (new $model)->setConnection('testing')->getConnectionName(), $model);
        }
    }
}
