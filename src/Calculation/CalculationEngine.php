<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\CommissionComponentParameters;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Commission\CommissionTrace;
use PandaBear\Mlm\Exceptions\ConflictingCalculationReplay;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Planning\PlanVersionStatus;

/**
 * Calculates one commission component, chosen by the caller, of a validated
 * plan version, over a closed range, and stores the result (ADR-018).
 *
 * - The component, its version, plan and program, and its source account
 *   are read from the database. The component must be a
 *   `commission.strategy` of a validated version — validated, published,
 *   active, superseded or archived; never a draft — and its source account
 *   an existing system account of the program, in the component's
 *   currency. Nothing is opened on the caller's behalf.
 * - A request under an idempotency key already used in the program returns
 *   the run stored under it, without calculating again — even if the data
 *   has changed since — if it asks for the same component and range; it is
 *   refused otherwise. A new calculation needs a new key.
 * - Otherwise the strategy calculates inside one read-snapshot transaction
 *   the engine owns — it refuses to start inside a caller's — and every
 *   candidate is checked before anything is written: the run and all its
 *   commissions, CALCULATED, are stored together, or nothing is. A stored
 *   run is a successful one.
 *
 * It opens no wallet, approves nothing and moves no money.
 */
final readonly class CalculationEngine
{
    private const RUNS = 'mlm_calculation_runs';

    private const COMMISSIONS = 'mlm_commissions';

    public function __construct(
        private CommissionStrategyRegistry $strategies,
        private CommissionComponentDriver $driver,
        private PlanDefinitionValidator $definitions,
    ) {}

    /**
     * @throws InvalidCalculationRun
     * @throws ConflictingCalculationReplay
     * @throws InvalidCommissionCandidate
     */
    public function calculate(PlanComponent $component, CalculationContext $context): CalculationRun
    {
        $db = $component->getConnection();

        if ($db->transactionLevel() > 0) {
            throw InvalidCalculationRun::insideTransaction((string) $db->getName());
        }

        $request = $this->load($db, (string) $component->getKey());
        $existing = $this->findByKey($db, $request, $context);

        if ($existing !== null) {
            return $this->replay($existing, $request, $context);
        }

        try {
            return ReadSnapshot::run($db, fn (): CalculationRun => $this->run($db, $this->load($db, (string) $component->getKey()), $context));
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race to the same key: the other calculation's run stands.
            return $this->replay($this->findByKey($db, $request, $context) ?? throw $exception, $request, $context);
        }
    }

    private function run(Connection $db, CalculationRequest $request, CalculationContext $context): CalculationRun
    {
        if (! $this->strategies->has($request->parameters->strategy)) {
            throw InvalidCalculationRun::unknownStrategy($request->where, $request->parameters->strategy);
        }

        $strategy = $this->strategies->get($request->parameters->strategy);

        // The stored definition, held to the rules it was validated under.
        try {
            $component = $this->componentDefinition($request);
            $this->driver->validate($component);
            $definition = $this->driver->definition($component);
        } catch (InvalidPlanDefinition $exception) {
            throw InvalidCalculationRun::invalidDefinition($request->where, $exception);
        }

        $candidates = $this->candidates($db, $request->program, $strategy->calculate(new CommissionCalculationContext(
            program: $request->program,
            definition: $definition,
            from: $context->from,
            until: $context->until,
            connection: (string) $db->getName(),
        )));

        return $this->insert($db, $request, $context, $candidates);
    }

    /**
     * The component as stored, and everything a calculation of it needs,
     * checked.
     */
    private function load(Connection $db, string $componentId): CalculationRequest
    {
        $connection = $db->getName();

        $component = PlanComponent::on($connection)->find($componentId) ?? throw InvalidCalculationRun::missing('plan component', $componentId);
        $version = PlanVersion::on($connection)->find($component->plan_version_id) ?? throw InvalidCalculationRun::missing('plan version', $component->plan_version_id);
        $plan = Plan::on($connection)->find($version->plan_id) ?? throw InvalidCalculationRun::missing('plan', $version->plan_id);
        $program = Program::on($connection)->find($plan->program_id) ?? throw InvalidCalculationRun::missing('program', $plan->program_id);

        $where = sprintf('plan version [%s] (version %d), component "%s"', $version->getKey(), $version->version, $component->key);

        if ($component->driver !== CommissionComponentDriver::KEY) {
            throw InvalidCalculationRun::notACommissionComponent($where, $component->driver);
        }

        if ($version->status === PlanVersionStatus::Draft) {
            throw InvalidCalculationRun::draft($where);
        }

        try {
            $parameters = CommissionComponentParameters::parse($component->parameters);
        } catch (InvalidPlanDefinition $exception) {
            throw InvalidCalculationRun::invalidDefinition($where, $exception);
        }

        $currency = $parameters->currency->value();

        // Resolved, never opened: a mistyped key must not create an account.
        $source = LedgerAccount::on($connection)
            ->where('program_id', $program->getKey())
            ->where('currency', $currency)
            ->where('key', $parameters->sourceAccount)
            ->first()
            ?? throw InvalidCalculationRun::sourceAccount($where, $parameters->sourceAccount, $currency, "does not exist in program [{$program->getKey()}]; open it as a system account before calculating.");

        if ($source->wallet_id !== null) {
            throw InvalidCalculationRun::sourceAccount($where, $parameters->sourceAccount, $currency, "is the account of wallet [{$source->wallet_id}], not a system account.");
        }

        return new CalculationRequest($component, $version, $program, $parameters, $source, $where);
    }

    private function componentDefinition(CalculationRequest $request): PlanComponentDefinition
    {
        foreach ($this->definitions->definition($request->version) as $component) {
            if ($component->key === $request->component->key) {
                return $component;
            }
        }

        throw InvalidCalculationRun::missing('plan component', (string) $request->component->getKey());
    }

    /**
     * Every candidate, checked, in candidate key order: the order a strategy
     * yields them in carries no meaning.
     *
     * @param  iterable<mixed>  $produced
     * @return list<CommissionCandidate>
     */
    private function candidates(Connection $db, Program $program, iterable $produced): array
    {
        $candidates = [];

        foreach ($produced as $candidate) {
            if (! $candidate instanceof CommissionCandidate) {
                throw InvalidCommissionCandidate::notACandidate($candidate);
            }

            if (isset($candidates[$candidate->key])) {
                throw InvalidCommissionCandidate::duplicateKey($candidate->key);
            }

            $candidates[$candidate->key] = $candidate;
        }

        $candidates = array_values($candidates);
        usort($candidates, static fn (CommissionCandidate $a, CommissionCandidate $b): int => strcmp($a->key, $b->key));

        // Members as stored: an instance changed in memory cannot move a
        // commission into another program.
        $members = [];
        $ids = array_values(array_unique(array_map(static fn (CommissionCandidate $candidate): string => (string) $candidate->member->getKey(), $candidates)));

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Member::on($db->getName())->whereKey($chunk)->get() as $member) {
                $members[(string) $member->getKey()] = $member;
            }
        }

        foreach ($candidates as $candidate) {
            $id = (string) $candidate->member->getKey();
            $member = $members[$id] ?? throw InvalidCommissionCandidate::missingMember($candidate->key, $id);

            if ($member->program_id !== $program->getKey()) {
                throw InvalidCommissionCandidate::otherProgram($candidate->key, $id, $member->program_id, (string) $program->getKey());
            }
        }

        return $candidates;
    }

    /**
     * @param  list<CommissionCandidate>  $candidates
     */
    private function insert(Connection $db, CalculationRequest $request, CalculationContext $context, array $candidates): CalculationRun
    {
        $id = (new CalculationRun)->newUniqueId();
        $now = (new CalculationRun)->freshTimestamp();
        $currency = $request->parameters->currency->value();

        $db->table(self::RUNS)->insert([
            'id' => $id,
            'program_id' => $request->program->getKey(),
            'plan_version_id' => $request->version->getKey(),
            'plan_component_id' => $request->component->getKey(),
            'strategy' => $request->parameters->strategy,
            'currency' => $currency,
            'source_ledger_account_id' => $request->source->getKey(),
            'from_at' => $context->from,
            'until_at' => $context->until,
            'idempotency_key' => $context->idempotencyKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = array_map(static fn (CommissionCandidate $candidate): array => [
            'id' => (new Commission)->newUniqueId(),
            'calculation_run_id' => $id,
            'program_id' => $request->program->getKey(),
            'member_id' => $candidate->member->getKey(),
            'candidate_key' => $candidate->key,
            'currency' => $currency,
            'amount_millionths' => $candidate->amount->toMillionths(),
            'earned_at' => $candidate->earnedAt,
            'trace' => CommissionTrace::encode($candidate->trace),
            'status' => CommissionStatus::Calculated->value,
            // Provenance, both or neither, as the candidate carries it.
            'source_type' => $candidate->source?->type,
            'source_id' => $candidate->source?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $candidates);

        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table(self::COMMISSIONS)->insert($chunk);
        }

        return CalculationRun::on($db->getName())->findOrFail($id);
    }

    private function findByKey(Connection $db, CalculationRequest $request, CalculationContext $context): ?CalculationRun
    {
        return CalculationRun::on($db->getName())
            ->where('program_id', $request->program->getKey())
            ->where('idempotency_key', $context->idempotencyKey)
            ->first();
    }

    /**
     * The run already stored under the key, if the request matches it; it is
     * returned as stored, never recalculated.
     */
    private function replay(CalculationRun $existing, CalculationRequest $request, CalculationContext $context): CalculationRun
    {
        $stored = [
            'plan_component' => $existing->plan_component_id,
            'from' => $existing->from_at->format('Y-m-d H:i:s'),
            'until' => $existing->until_at->format('Y-m-d H:i:s'),
            'plan_version' => $existing->plan_version_id,
            'strategy' => $existing->strategy,
            'currency' => $existing->currency,
            'source_account' => $existing->source_ledger_account_id,
        ];

        $requested = [
            'plan_component' => (string) $request->component->getKey(),
            'from' => $context->from->format('Y-m-d H:i:s'),
            'until' => $context->until->format('Y-m-d H:i:s'),
            'plan_version' => (string) $request->version->getKey(),
            'strategy' => $request->parameters->strategy,
            'currency' => $request->parameters->currency->value(),
            'source_account' => (string) $request->source->getKey(),
        ];

        $conflicts = array_keys(array_diff_assoc($requested, $stored));

        if ($conflicts !== []) {
            throw ConflictingCalculationReplay::forKey($existing, $conflicts);
        }

        return $existing;
    }
}
