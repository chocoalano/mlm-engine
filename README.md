# Panda MLM

A configurable MLM engine for [Panda Panel](https://github.com/chocoalano/panda-panel), part of the pandabear.asia ecosystem.

> **Status: early development, pre-1.0.** It provides the Panda Panel plugin and its technical configuration; the core domain — programs and their members; plans with versioning, a version lifecycle and versioned definitions — components and rules in a safe rule language, validated before use; qualification — one chosen rule evaluated for a member, with a complete trace; ranks — one chosen rank ladder evaluated for a member, the highest qualifying rank selected and every rank explained; the sponsor and placement genealogies, each readable as it stands or as it stood at any past moment, an explicit binary left/right overlay on placement, and an explicit matrix overlay of numbered slots up to a program's fixed width; an exact, immutable volume history with idempotent recording, explicit reversal and member totals; a financial ledger — member wallets and program system accounts, balanced single-currency transactions, idempotent posting, reversal and exact derived balances; a commission core — registered calculation strategies, audited snapshot-consistent calculation runs, a review lifecycle and posting through the ledger — with built-in direct-sponsor, depth-based, matrix and binary pairing strategies, composed into hybrid calculation batches, and commission periods that calculate, close, hold and release a program's commissions before posting, and payouts that reserve wallet funds through the ledger at approval, record the provider's settlement, refund failures and group requests into batches, fixed or proportional with explicit rounding, binary carry kept source by source, clawback of commissions whose source is later reversed, and binary reversal correction — undone pairs, restored carry and the exact financial share of each commission, before or after posting; and metrics — a registry, an engine and the built-in `member.volume`, `sponsor.network.volume`, `placement.network.volume`, `binary.left.volume`, `binary.right.volume` and `matrix.network.volume`, network and leg volume read through the genealogy as it was when each activity happened. The suite runs on SQLite, MySQL and PostgreSQL, including real concurrent database sessions. Until 1.0 the API may still change between minor versions. Manual and positive commission adjustments, rank persistence and promotion, payout provider integrations, cross-component hybrid rules, automatic placement and matrix spillover, and the Panda Panel administration screens are **not implemented yet** (see [Roadmap](#roadmap)).

## Requirements

- PHP ^8.2
- Laravel 12 or 13
- Panda Panel (`chocoalano/panel`) ^0.5.7

## Installation

Install the package with Composer:

```bash
composer require pandabear/mlm:^0.1
```

Laravel's package discovery registers `PandaBear\Mlm\PandaMlmServiceProvider` automatically.

## Panel registration

The plugin is never added to a panel automatically. Install it in the panel provider that should have it:

```php
use PandaBear\Mlm\PandaMlmPlugin;

$panel->plugins([
    PandaMlmPlugin::make(),
]);
```

Confirm it is registered:

```bash
php artisan panel:plugins
```

## Panda Panel

`PandaMlmPlugin::make()` registers the operational surface on the panel it is installed in, under one sidebar group, **Network & Compensation** (*Jaringan & Kompensasi* in Indonesian). The panel is an adapter (ADR-031): it reads the domain's records and runs lifecycle steps through the domain services — it never writes a record itself, never offers a status field, and never deletes financial history.

| Screen | Offers |
| --- | --- |
| MLM Overview | four counts: active programs, commission periods awaiting action, commissions available to post, payout requests awaiting action |
| Programs | list and detail, with their members, plans and commission periods |
| Members | list and detail, with the current sponsor, placement, binary and matrix position, and wallets |
| Plans | list and detail, with versions; each version's components, strategies, parameters and rules, read-only |
| Commission periods | list and detail with their runs; **Calculate**, **Finalize**, **Release** |
| Calculation runs | list and detail: what each belongs to (period, hybrid batch or neither) and its commissions |
| Commissions | list and detail: status, trace, ledger transaction and adjustments |
| Wallets | list and detail: the exact balance read from the ledger, and the ledger postings |
| Payout requests | list and detail; **Approve**, **Start processing**, **Settle**, **Mark failed**, **Cancel** |
| Payout batches | list and detail with their requests and exact totals; **Seal**, **Start processing**, **Complete**, **Cancel batch** |

Each action calls one service — `CommissionPeriodCalculator`, `CommissionPeriodFinalizer`, `CommissionPeriodReleaser`, `PayoutManager` or `PayoutBatchManager` — in its own transaction, and a refusal is shown to the operator with the service's reason. Creating programs, members, plans and payout requests, the Plan Builder, genealogy assignment and tree views, and batch composition are not part of this surface yet.

### Capabilities

Every screen and action asks a capability through Laravel's Gate — never a role. Grant them however the application grants abilities: `Gate::define()`, a `Gate::before()` hook, or a permission package whose `can()` answers for them.

```php
use PandaBear\Mlm\Panel\MlmPermission;

MlmPermission::all();     // every capability, for seeding a permission store
MlmPermission::view();    // mlm.dashboard.view, mlm.programs.view, … mlm.payouts.view
MlmPermission::operate(); // mlm.periods.operate, mlm.payouts.operate
```

`view` capabilities open lists and details (`mlm.ledger.view` adds ledger postings and transaction references); `mlm.periods.operate` and `mlm.payouts.operate` run the lifecycle actions. An operator needs both the view and the operate capability of a screen.

### Words and icons

The surface speaks English and Indonesian and follows the application's locale; override any sentence under `lang/vendor/mlm/{locale}/mlm.php`. Nothing is published. Its icons are ones Panda Panel itself already declares, so `php artisan panel:icons` — which scans the application and the framework, not plugins — keeps every one of them in the registry.

## Core domain

### Database

The service provider registers the package migrations, so the tables are created by the application's own migrate command:

```bash
php artisan migrate
```

| Table | Holds |
| --- | --- |
| `mlm_programs` | programs: `id`, `code`, `name` |
| `mlm_members` | members: `id`, `program_id`, `member_code`, `external_type`, `external_id`, `joined_at` |
| `mlm_plans` | plans: `id`, `program_id`, `code`, `name` |
| `mlm_plan_versions` | plan versions: `id`, `plan_id`, `version`, `status`, and one timestamp per lifecycle step |
| `mlm_sponsor_edges` | direct sponsorships: `id`, `member_id`, `sponsor_id`, `assigned_at` |
| `mlm_genealogy_paths` | every ancestor/descendant pair of each tree — sponsor, placement, binary: `tree_type`, `ancestor_id`, `descendant_id`, `depth`, `effective_from` |
| `mlm_placement_edges` | direct placements: `id`, `member_id`, `parent_id`, `placed_at` |
| `mlm_volume_entries` | immutable volume history: `program_id`, `member_id`, `type`, `quantity_millionths`, `source_type`, `source_id`, `idempotency_key`, `effective_at`, `reversal_of_id` |

Primary keys are ULIDs. The tables and the models use the connection named by `mlm.database.connection` — the application's default when it is not set.

### Program

A program is one independent MLM business — a distributor network, a reseller program — and the boundary everything else belongs to. One installation can run several. Its `code` is unique; its `name` is not.

```php
use PandaBear\Mlm\Models\Program;

$program = Program::create([
    'code' => 'MAIN',
    'name' => 'Main Distributor Program',
]);
```

### Member

A member is a participant in exactly one program. It is not the application's user model. Members join through their program:

```php
$member = $program->members()->create([
    'member_code' => 'MBR-000001',
    'joined_at' => now(),
]);
```

`program_id` is not mass assignable, and a program cannot be deleted while it still has members.

### Program-scoped membership

A `member_code` is unique within its program, and the same code may be used in another program. The package never picks a program from the request, the session or the signed-in user — the program is always passed explicitly.

### External identity

A member can point at whatever the application calls its people, without the package depending on that model:

```php
$program->members()->create([
    'member_code' => 'MBR-000002',
    'external_type' => 'customer',   // a label the application chooses
    'external_id' => 'CUS-00192',    // integer, UUID, ULID or business code
    'joined_at' => now(),
]);
```

Both fields are optional, but they are set together or not at all. One external identity is at most one member per program, and may be a member of several programs.

## Planning

### Plan

A plan is the stable identity of a business plan inside a program. Its `code` is unique within the program. A plan holds no rules — everything that changes between revisions belongs to its versions.

```php
$plan = $program->plans()->create([
    'code' => 'STANDARD',
    'name' => 'Standard Plan',
]);
```

### Plan versioning

A plan version is one numbered revision of a plan: `1`, `2`, `3`, unique within the plan and allocated for you. Versions are created and moved only through `PandaBear\Mlm\Planning\PlanVersionLifecycle`, resolved from the container:

```php
use PandaBear\Mlm\Planning\PlanVersionLifecycle;

$lifecycle = app(PlanVersionLifecycle::class);

$version = $lifecycle->draft($plan);          // version 1, draft
$lifecycle->markValidated($version);
$lifecycle->publish($version);
$lifecycle->activate($version);

$plan->currentActiveVersion();                // version 1, or null when none is active
```

### Plan version lifecycle

```text
draft → validated → published → active → superseded → archived
```

- The lifecycle only moves forward, one step at a time. An operation out of order throws `InvalidPlanVersionTransition`.
- Activating a newer published version supersedes the active one in the same transaction, so a plan has at most one active version. An older version can never replace a newer active one. Superseding is not a separate operation.
- Only a superseded version can be archived.
- Each step records its moment once: `validated_at`, `published_at`, `activated_at`, `superseded_at`, `archived_at`.
- Only a draft is mutable. From `validated` on, a version is locked: `isMutable()` is false, `assertMutable()` throws `PlanVersionNotMutable`, and it cannot be deleted.
- `status` cannot be mass assigned, and a status or timestamp set directly on the model is refused on save. Raw query-builder writes bypass these guards, as they bypass every Eloquent rule.

### Plan definition

A version's definition is its **components**, each with **rules**. A draft is edited; from `validated` on, the definition never changes.

- A component selects a **driver** by key: trusted code the application registers. The database stores the key, never a class name. The package ships two, `rank.ladder` (see [Rank](#rank)) and `commission.strategy` (see [Calculation runs](#calculation-runs)); applications register their own beside them.
- A component's parameters are plain JSON data for its driver. Nothing in them is ever run.
- A rule is a tree in a small, safe language: groups (`all`, `any`) of conditions on registered metrics, with the operators `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in` and `between` (both bounds included) and exact decimal operands. No expressions, formulas, SQL or code.
- A rule may only name a metric that implements `PlanConfigurableMetric` — the three built-ins do — so its parameters can be checked before anything is resolved.

```php
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriver;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;

final class AcmeCriteriaDriver implements PlanComponentDriver
{
    public function key(): string
    {
        return 'acme.criteria';
    }

    public function validate(PlanComponentDefinition $component): void
    {
        if ($component->rules === []) {
            throw InvalidPlanDefinition::input('rules', 'acme.criteria needs at least one rule.');
        }
    }
}

// In a service provider's register():
$this->callAfterResolving(PlanComponentDriverRegistry::class, function (PlanComponentDriverRegistry $drivers): void {
    $drivers->register(new AcmeCriteriaDriver);
});
```

```php
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleGroup;

$editor = app(PlanDefinitionEditor::class);

$draft = $lifecycle->draft($plan);
$entry = $editor->addComponent($draft, 'entry', 'acme.criteria', 'Entry criteria');

$editor->addRule($entry, 'active', 'Active member', RuleDefinition::all(
    MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
    RuleGroup::any(
        MetricCondition::of('sponsor.network.volume', ['type' => 'sales', 'max_depth' => 3], '>=', '500'),
        MetricCondition::of('placement.network.volume', ['type' => 'sales'], 'between', '400', '900'),
    ),
));

$lifecycle->markValidated($draft);   // checks the whole definition, or throws InvalidPlanDefinition

$next = app(PlanDefinitionCloner::class)->cloneToNewDraft($draft);   // the next version, a draft holding a copy
```

- `PlanDefinitionEditor` is the only way to change a definition. It adds, updates and removes components and rules, and works on drafts only — deciding from the stored, locked version, never from a model in memory. Changing a locked version throws `PlanVersionNotMutable`.
- `markValidated()` checks the whole definition first: drivers registered and satisfied, rules well-formed, metrics registered, plan-configurable and given valid parameters. If anything fails, the version stays a draft and nothing changes. An empty definition is valid.
- To change a validated, published or active definition, clone it into a new draft with `PlanDefinitionCloner`, edit the draft and validate it.
- Read a definition through `$version->components`, `$component->rules` and `$rule->definition`. Components and rules are read-only through Eloquent.

Rules are evaluated one at a time by the qualification engine below, and a rank ladder's rules by the rank engine after it. Commission is not implemented.

## Qualification

The qualification engine evaluates **one stored rule, chosen by you**, for one member over an optional effective range `[from, until)`:

```php
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;

$decision = app(QualificationEngine::class)->evaluate(
    $rule,                                   // a PlanRule of a validated version
    new QualificationContext(
        member: $member,
        from: $start,                        // optional, included
        until: $until,                       // optional, excluded
    ),
);

$decision->qualified;                        // true or false
$decision->toArray();                        // identities, range and the full trace
```

- You select the rule. The engine never picks a plan, the active version, or a rule, and gives no meaning to several rules in one component.
- A draft's rule cannot be evaluated. A rule of any validated version — validated, published, active, superseded or archived — can, so past decisions can be reproduced.
- The member must belong to the rule's program. Rule, version and member are read from the database, not from the instances you pass.
- Every condition's metric is resolved through the metric engine with the same range, and compared exactly. `between` includes both bounds. Every condition is evaluated and appears in the trace, with its value, even when the result was already decided.
- `qualified` is false only when the rule was evaluated and does not hold. A draft, another program's member, a missing metric or a failing one throws `QualificationEvaluationException` instead.
- Results are not stored, and nothing is written. Commission is not implemented.

## Rank

A **rank ladder** is a plan component with the built-in driver `rank.ladder`. Each of its rules is one rank: the rule's key is the rank's key, its name the rank's name, its position the rank's order — a larger position is a higher rank — and its definition what the rank requires. It is built with the editor, like any component:

```php
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

$ladder = $editor->addComponent($draft, key: 'career-ranks', driver: 'rank.ladder', name: 'Career Ranks', parameters: []);

$editor->addRule($ladder, key: 'bronze', name: 'Bronze', position: 10, definition: RuleDefinition::all(
    MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
));
$editor->addRule($ladder, key: 'silver', name: 'Silver', position: 20, definition: RuleDefinition::all(
    MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
    MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '1000'),
));

$lifecycle->markValidated($draft);
```

- A ladder takes no parameters, needs at least one rank, and no two of its ranks may share a position; gaps are fine. A draft may break these while it is edited — `markValidated()` refuses it.
- There is no rank table, rank editor or rank cloner: ladders are stored, edited, validated and cloned as plan definitions.

The rank engine evaluates **one stored ladder, chosen by you**, for one member over an optional effective range `[from, until)`:

```php
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;

$decision = app(RankEngine::class)->evaluate(
    $ladder,                                 // a rank.ladder PlanComponent of a validated version
    new RankContext(
        member: $member,
        from: $start,                        // optional, included
        until: $until,                       // optional, excluded
    ),
);

$decision->selectedRank;                     // SelectedRank (key, name, position), or null
$decision->toArray();                        // identities, range, selected rank, and every rank with its trace
```

- You select the ladder. The engine never picks a plan, the active version or a ladder, and never merges two ladders. A component of another driver is refused.
- Every rank is qualified through the qualification engine, lowest position first, all with the same range — every one of them, whatever the others give — and each keeps its qualification trace.
- The selected rank is the qualifying rank with the highest position. Ranks are independent: a higher rank does not need the lower ones — write their requirements into it if it should.
- No qualifying rank gives `selectedRank` null: a decision, not an error. A draft ladder, another program's member, a broken ladder or a rank that cannot be evaluated throws `RankEvaluationException` instead — never a lower rank or null.
- A ladder of any validated version — superseded and archived included — can be evaluated, and gives the rank as that version defines it.
- A rank is derived, never stored: there is no current-rank field, rank history, promotion or demotion yet, and nothing is written.

## Sponsor genealogy

The sponsor genealogy records who sponsored whom within a program. It is not placement — see [Placement genealogy](#placement-genealogy).

### Direct sponsor

```php
use PandaBear\Mlm\Genealogy\SponsorGenealogy;

$genealogy = app(SponsorGenealogy::class);

$genealogy->assignSponsor($bob, $alice);   // Alice sponsored Bob

$genealogy->directSponsor($bob);           // Alice, or null for a root
$genealogy->directMembers($alice);         // everyone Alice sponsored directly
```

- A member has at most one sponsor, and a member without one is a root. A program may have many roots.
- A sponsor may sponsor any number of members.
- The member and its sponsor must be in the same program. A member cannot sponsor itself.
- A sponsor is assigned once. There is no reassignment or removal yet.
- A member that already sponsors others can still receive its first sponsor; its whole subtree is attached beneath that sponsor.

### Ancestors and descendants

```php
$genealogy->ancestors($diana);                 // Bob (depth 1), Alice (depth 2)
$genealogy->descendants($alice);               // nearest first
$genealogy->descendants($alice, maxDepth: 1);  // direct members only
```

Both return `SponsorRelative` objects — the `member` and its `depth`, the number of sponsorship steps between them — nearest first. The member itself is never included. Results stay within the member's program.

### History

The queries above read the sponsor tree as it stands. Each has an `…At()` counterpart that reads it as it stood at a moment:

```php
use Carbon\CarbonImmutable;

$march = CarbonImmutable::parse('2026-03-01 00:00:00');

$genealogy->directSponsorAt($bob, $march);       // null if Bob was not sponsored yet
$genealogy->directMembersAt($alice, $march);     // those Alice had sponsored by then
$genealogy->ancestorsAt($diana, $march);         // her sponsor line on that day
$genealogy->descendantsAt($alice, $march, maxDepth: 1);
```

- A sponsorship counts from the second it was assigned, inclusive, and never ends: sponsors are not reassigned.
- A line is complete from the moment its last link was made. If Bob sponsored Charlie in January and Alice sponsored Bob in March, Charlie is below Alice from March, not January.
- A moment is compared as the same instant in the application's timezone, to the second, as volume's effective moments are.
- The history comes from the moment each assignment was made. There is no way to backdate one.

### Cycle prevention

A member cannot be sponsored by anyone in its own sponsor subtree: if Alice sponsored Bob and Bob sponsored Charlie, Charlie cannot sponsor Alice. Refused assignments throw `InvalidSponsorAssignment` and change nothing. Every assignment runs in one transaction.

Ancestry is read from a closure table, never walked recursively. A member taking part in the sponsor tree cannot be deleted.

## Placement genealogy

| | Answers | Service |
| --- | --- | --- |
| Sponsor genealogy | who introduced whom | `SponsorGenealogy` |
| Placement genealogy | where a member is structurally placed | `PlacementGenealogy` |

The two are independent graphs over the same members. A member can be sponsored by Alice and placed under Bob; sponsoring never places, and placing never sponsors.

```php
use PandaBear\Mlm\Genealogy\PlacementGenealogy;

$placement = app(PlacementGenealogy::class);

$placement->place($charlie, $bob);          // Charlie is placed under Bob

$placement->directParent($charlie);         // Bob, or null for a placement root
$placement->directChildren($bob);           // members placed directly under Bob, in placement order
$placement->ancestors($charlie);            // PlacementRelative: member + depth, nearest first
$placement->descendants($bob, maxDepth: 1);

// As the placement tree stood at a moment, as for sponsorship:
$placement->directParentAt($charlie, CarbonImmutable::parse('2026-06-01 00:00:00'));
$placement->directChildrenAt($bob, CarbonImmutable::parse('2026-06-01 00:00:00'));
$placement->ancestorsAt($charlie, CarbonImmutable::parse('2026-06-01 00:00:00'));
$placement->descendantsAt($bob, CarbonImmutable::parse('2026-06-01 00:00:00'), maxDepth: 1);
```

- A member has at most one placement parent, and a member without one is a placement root. A program may have many.
- A parent may have any number of members placed under it. There is no capacity, position or slot in this generic layer.
- Member and parent must be in the same program. A member cannot be placed under itself or under anyone in its own placement subtree. Cycle checks only see placement, so placement may even run opposite to sponsorship.
- A placement is made once. There is no move or removal yet.
- Placement keeps its own history: a member sponsored in January and placed in March was sponsored, but not yet placed, in February.
- A member that already has members placed under it can still receive its first placement parent; its whole placement subtree is attached beneath that parent.
- Refused placements throw `InvalidPlacementAssignment` and change nothing. Every placement runs in one transaction.

Placement paths share the closure table with sponsor paths under their own `tree_type`, and never leak into sponsor queries. Binary left/right positions ([Binary placement foundation](#binary-placement-foundation)) and matrix slots ([Matrix foundation](#matrix-foundation)) are explicit overlays on these edges; **spillover and automatic placement strategies are not implemented yet.** A member taking part in the placement tree cannot be deleted.

Both genealogies are written only through their services. Raw query-builder or SQL writes to the edge or path tables bypass every graph rule: the database backs local invariants — one direct edge per member, one path per pair, foreign keys — but not acyclicity or consistency between edges and paths.

The design decisions are recorded in [`docs/adr`](docs/adr).

## Binary placement foundation

Binary is an **overlay** on the generic placement tree, not a replacement for it. Generic placement stays unlimited and positionless: a parent may still have any number of children. Only an edge explicitly given a side — `BinarySide::Left` or `BinarySide::Right` — is in the binary tree, and a parent has at most one binary child on each side.

```php
use PandaBear\Mlm\Binary\BinaryGenealogy;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;

// A new placement with its side: placed through PlacementGenealogy, and
// given the side, together or not at all.
$position = app(BinaryPlacementManager::class)->place(
    $member,
    $parent,
    BinarySide::Left,
);

// An existing generic placement given a side, from now on.
$position = app(BinaryPlacementManager::class)->adopt(
    $placementEdge,
    BinarySide::Right,
);

$binary = app(BinaryGenealogy::class);

$binary->child($parent, BinarySide::Left);     // the left child, or null
$binary->directParent($member);                 // the binary parent, or null for a binary root
$binary->positionOf($member);                   // BinaryPlacementPosition: parent, side, assigned_at
$binary->ancestors($member);                    // BinaryRelative: member + depth, nearest first
$binary->descendants($parent, maxDepth: 2);

// As the binary tree stood at a moment:
$binary->childAt($parent, BinarySide::Left, CarbonImmutable::parse('2026-06-01 00:00:00'));
$binary->descendantsAt($parent, CarbonImmutable::parse('2026-06-01 00:00:00'));
```

- Sides are exactly `left` and `right`, assigned explicitly — never taken from the order children were placed in. There is no automatic placement: no spillover, next free slot or weaker-side rule.
- **Only binary edges belong to the binary tree.** It keeps its own paths (`tree_type` `binary`), so a member placed only generically under a binary member stays out of every leg until its own edge is given a side. A member without a binary position is a binary root, even when it has a generic placement parent.
- **Adoption is not retroactive.** An edge placed in January and adopted in March is binary from March: earlier activity never becomes binary activity. An adopted member brings its whole binary subtree with it.
- Adopting an edge again on the same side returns its position; the other side is refused. `place()` is not a replay: a member that is already placed is refused, as placement refuses it — adopt its edge instead.
- A taken side is refused with `InvalidBinaryPlacement`, and a refused `place()` leaves no generic placement behind. A side never changes: there is no move or removal.
- Binary never reads or writes sponsorship. There is one binary tree per program; several independent binary trees in one program are not supported yet.
- Positions are written only by `BinaryPlacementManager`. A stored position that disagrees with its placement edge is refused with `CorruptBinaryPlacement` rather than read.

Existing placements are **not** adopted by the migration: the package cannot know which should be left, right or not binary at all. Pairing commissions on this structure are described in [Binary pairing & carry](#binary-pairing--carry).

## Volume

Volume is a quantified business measurement attributed to a member — sales, points, any value a plan will later read. It is not money and not commission: recording it produces nothing else.

### Recording

```php
use Carbon\CarbonImmutable;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;

$entry = app(VolumeRecorder::class)->record(new RecordVolume(
    member: $member,
    type: 'sales',                          // your own identifier; the package defines none
    quantity: Quantity::of('25.5'),         // a decimal string or an integer — never a float
    sourceType: 'order',
    sourceId: 'ORD-123',
    idempotencyKey: 'order:ORD-123:sales',
    effectiveAt: CarbonImmutable::parse('2026-06-30 18:00'),
));

$entry->quantity->value();                  // "25.5"
```

- `type` and `sourceType` are 1–64 lowercase letters, digits, `.`, `-` or `_`. Invalid input is refused, never rewritten.
- Quantities are exact to six decimal places. A seventh is refused, not rounded. A recorded quantity must be positive, with at most 12 integer digits; totals may be larger and stay exact.
- `effective_at` is when the activity counts, kept in the application's timezone; `created_at` is when it was stored.
- The program is taken from the stored member.

### Idempotent recording

The idempotency key identifies the request within its program. Recording the same key again with the same member, type, quantity, source and effective moment returns the entry already recorded. The same key with anything different throws `ConflictingVolumeReplay`. The source alone is not unique: one order may produce several entries.

### Explicit reversal

Entries are immutable: they cannot be updated or deleted, not even through the model. A correction is a reversal — a second entry with the negated quantity, pointing at the original:

```php
use PandaBear\Mlm\Volume\ReverseVolume;

app(VolumeRecorder::class)->reverse(new ReverseVolume(
    entry: $entry,
    sourceType: 'refund',
    sourceId: 'RF-9',
    idempotencyKey: 'refund:RF-9',
    effectiveAt: CarbonImmutable::parse('2026-07-02 09:00'),
));
```

An entry is reversed at most once, and a reversal cannot be reversed. Replaying the same reversal returns it.

### Totals

```php
use PandaBear\Mlm\Volume\VolumeTotals;

$totals = app(VolumeTotals::class);

$totals->forMember($member, 'sales');                          // Quantity, reversals netted
$totals->forMember($member, 'sales', from: $june1, until: $july1);
```

A range counts entries by `effective_at` from `from` (inclusive) to `until` (exclusive); with both bounds, `from` must come before `until`. Totals cover the member's own entries only. **Network totals and running balances are not implemented yet; money lives in the [financial ledger](#financial-ledger--wallet).**

Raw query-builder or SQL writes to `mlm_volume_entries` bypass every rule above. The database backs only its local invariants — one entry per key and program, one reversal per entry, foreign keys.

## Metrics

A metric is a named numeric fact about a member, computed on demand from the package's data — never stored, and never a pass/fail judgement. Metrics are resolved by key through the `MetricEngine`:

```php
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;

$value = app(MetricEngine::class)->resolve('member.volume', new MetricContext(
    member: $member,
    parameters: ['type' => 'sales'],
    from: $june1,                 // optional; [from, until), as for volume totals
    until: $july1,
));

$value->value();                  // "1250.5" — an exact string, never a float
```

### Built-in: `member.volume`

The member's own net volume of one type — reversals included — over the optional range. It requires the `type` parameter and refuses any other parameter. It never looks at the member's genealogy.

### Built-in: `sponsor.network.volume` and `placement.network.volume`

The net volume of one type recorded by the members **below** the member — in the sponsor tree or in the placement tree — over the optional range. The member's own volume is not included; that is `member.volume`. The two networks are independent: sponsoring someone adds nothing to your placement network.

```php
app(MetricEngine::class)->resolve('sponsor.network.volume', new MetricContext(
    member: $alice,
    parameters: ['type' => 'sales', 'max_depth' => 3],   // max_depth is optional
    from: $june1,
    until: $july1,
));
```

- `type` is required, as for `member.volume`. `max_depth`, an integer of 1 or more, counts only members at most that many steps down; without it, everyone below counts. No other parameter is accepted.
- **Network volume uses the genealogy that applied when the original activity happened.** An entry counts for Alice only if its member was already below Alice at the entry's `effective_at`. If Charlie sells in January and Alice sponsors Charlie in March, Alice's network never receives that January sale — not even when asked in April.
- **A reversal follows the activity it reverses.** It reaches exactly the uplines the original sale reached, and appears in the period of its own `effective_at`. If Bob sponsored Charlie before his January sale, Alice joined above Bob in March, and the sale is refunded in April, Bob's network shows +100 in January and −100 in April; Alice's shows neither.
- The range `[from, until)` selects entries by their own `effective_at`, as everywhere else.
- Computed on demand, exactly, in one query; nothing is stored.

These are generic graph figures, not a compensation plan: there are no generations, legs, sides, slots or pairing.

### Built-in: `binary.left.volume` and `binary.right.volume`

The net volume of one type in one binary leg of the member: its child on that side and everyone below that child **in the binary tree**, over the optional range. The member's own volume, its other leg and members placed only generically never count, and an empty side is `0`.

```php
app(MetricEngine::class)->resolve('binary.left.volume', new MetricContext(
    member: $alice,
    parameters: ['type' => 'sales', 'max_depth' => 3],   // max_depth is optional
    from: $june1,
    until: $july1,
));
```

- `type` is required and checked as for the other volume metrics. `max_depth` counts from the member: `1` is the child on that side alone, `2` adds its binary children, and so on.
- An entry counts only if, when the activity happened, the side had already been assigned and its member was already in that leg. A side assigned — or a subtree adopted — later never captures earlier activity.
- A reversal follows the activity it reverses, into the same leg or into none, and appears in the period of its own `effective_at`. An activity and its reversal in one period net out.
- Both are plan-configurable: a rule, and so a qualification or rank ladder, can use them.

These are structural figures; for pairing commissions and carry, see [Binary pairing & carry](#binary-pairing--carry).

### Built-in: `matrix.network.volume`

The net volume of one type recorded by the members below the member **in the matrix**, over the optional range — see [Matrix foundation](#matrix-foundation). The member's own volume, generic-only members, sponsorship and the binary tree never count; `max_depth` counts from the member (`1` is its direct matrix children). An entry counts only if its member was in the member's matrix when the activity happened, and a reversal follows the activity it reverses — so an edge adopted later never captures earlier activity. Plan-configurable, like the other network metrics.

### Your own metrics

Implement `PandaBear\Mlm\Metrics\Metric` and register it from a service provider:

```php
use PandaBear\Mlm\Metrics\MetricRegistry;

public function register(): void
{
    $this->callAfterResolving(MetricRegistry::class, function (MetricRegistry $metrics): void {
        $metrics->register(new AcmeRetentionMetric);   // key(): "acme.retention"
    });
}
```

- Keys are 1–100 lowercase letters, digits, `.`, `-` or `_`. Use your own namespace.
- A key is registered once: a second registration throws `DuplicateMetric` instead of replacing the first.
- Resolving an unknown key throws `UnknownMetric`; it never answers zero.
- Metrics are trusted code registered at boot. Nothing — no class name, formula or SQL — is ever loaded from the database.

Metrics only answer "what is the value"; qualification and rank build on them. Commission is not implemented.

## Financial ledger & wallet

Money lives in a double-entry ledger. A **wallet** is one member's money in one currency; a **system account** is a program's own account under a key it chooses. Both are ledger accounts, and value moves between them only as **balanced transactions**: signed postings, one per account, summing to exactly zero, in one currency.

```php
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Finance\WalletManager;

$wallet = app(WalletManager::class)->open($member, 'IDR');     // opens it with its account, or returns it
$clearing = app(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'adjustment.clearing');

$transaction = app(LedgerRecorder::class)->post(new PostLedgerTransaction(
    program: $program,
    currency: 'IDR',
    type: 'adjustment',
    sourceType: 'manual',
    sourceId: 'ADJ-1',
    idempotencyKey: 'adjustment:ADJ-1',
    occurredAt: now(),
    postings: [
        LedgerPostingInput::of($clearing, '-100'),
        LedgerPostingInput::of($wallet->account, '100'),
    ],
));

app(LedgerBalanceReader::class)->forWallet($wallet);           // FinancialAmount "100"

app(LedgerRecorder::class)->reverse(new ReverseLedgerTransaction(
    transaction: $transaction,
    sourceType: 'manual',
    sourceId: 'ADJ-1-REVERSAL',
    idempotencyKey: 'reversal:ADJ-1',
    occurredAt: now(),
));                                                             // the wallet is back to "0"
```

- **Balances are derived.** No wallet or account stores a balance: `LedgerBalanceReader` sums the postings on every read, exactly, at any size. A balance may be negative — the ledger sets no floor; spending rules belong to the domains that spend.
- **Transactions are immutable.** A correction is a reversal: a new transaction with every posting negated, under its own source, key and moment. The original is never changed, is reversed at most once, and a reversal is not reversed. Wallets, accounts, transactions and postings are read-only through Eloquent.
- **Exact amounts.** `FinancialAmount` holds six decimal places, never rounds and never accepts a float. One posting holds at most 9,223,372,036,854.775807 either way, so it can always be reversed; a balance has no limit.
- **Replays are safe.** A request under an idempotency key already used in the program returns the stored transaction if it is identical — postings in any order — and throws `ConflictingLedgerReplay` otherwise. A rejected request writes nothing.
- Every account is read from the database: it must belong to the transaction's program and hold its currency. A currency is three uppercase letters; the package keeps no currency list.
- No transfer, pending balance, fee, tax or rounding policy yet: those are later phases that post through this ledger. Commissions reach wallets through it — see [Commission core](#commission-core) — and leave through [payouts](#payout--settlement).

## Calculation runs

A **calculation run** is one successful calculation of one commission component, chosen by you, of a validated plan version, over a closed range `[from, until)`. A component with the built-in driver `commission.strategy` selects a **commission strategy** — trusted code you register — and configures it:

```php
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\Member;

final class AcmeFlatRewardStrategy implements CommissionStrategy
{
    public function key(): string
    {
        return 'acme.flat-reward';
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        if (array_keys($definition->parameters) !== ['amount']) {
            throw InvalidPlanDefinition::input('acme.flat-reward', 'it takes one "amount".');
        }
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        foreach (Member::on($context->connection)->where('program_id', $context->program->id)->get() as $member) {
            yield new CommissionCandidate(
                key: "member:{$member->member_code}",
                member: $member,
                amount: $context->definition->parameters['amount'],
                earnedAt: $context->until->subSecond(),
                trace: ['member_code' => $member->member_code],
            );
        }
    }
}

// In a service provider's register():
$this->callAfterResolving(CommissionStrategyRegistry::class, function (CommissionStrategyRegistry $strategies): void {
    $strategies->register(new AcmeFlatRewardStrategy);
});
```

```php
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;

app(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'commission.payable');

$component = $editor->addComponent($draft, key: 'monthly-reward', driver: 'commission.strategy', name: 'Monthly reward', parameters: [
    'strategy' => 'acme.flat-reward',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => ['amount' => '25'],
]);
$lifecycle->markValidated($draft);

$run = app(CalculationEngine::class)->calculate($component, new CalculationContext(
    from: $start,                            // required, included
    until: $end,                             // required, excluded
    idempotencyKey: 'monthly-reward:2026-06',
));

$run->commissions;                           // CALCULATED commissions, in candidate key order
```

- The package ships four strategies — `direct-sponsor.fixed`, `unilevel.fixed` (see [Built-in strategies](#built-in-strategies)), `direct-sponsor.proportional` and `unilevel.proportional` (see [Proportional commission math](#proportional-commission-math)) — and registers your own beside them. There is no formula language: strategies are code, configured by versioned parameters and rules.
- The component's parameters are exactly `strategy`, `currency`, `source_account` and `parameters`. An unknown strategy, currency, account key or field blocks `markValidated()`.
- The source account must already exist as a system account of the program in that currency — it is never created for you — and is fixed on the run.
- **Only successful runs are stored**, whole: a failing strategy or any invalid candidate — zero or negative amount, too large for one posting, duplicate key, another program's member, a trace that is not inert JSON — stores nothing.
- A calculation runs in its own transaction with one read snapshot, so every read of a strategy agrees; it refuses to start inside your transaction.
- **Replaying an idempotency key returns the stored run without calculating again**, even if data changed; the same key with another component or range throws `ConflictingCalculationReplay`. A new calculation needs a new key.
- Calculating moves no money, opens no wallet and approves nothing.

## Built-in strategies

Both pay a **fixed award per eligible original business entry**, to sponsors of the entry's member **as the sponsor line stood when the entry took effect**.

```php
$editor->addComponent($draft, key: 'direct-sponsor', driver: 'commission.strategy', name: 'Direct sponsor award', parameters: [
    'strategy' => 'direct-sponsor.fixed',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',          // entries of this volume type...
        'source_type' => 'order',          // ...from this source type...
        'minimum_quantity' => '100',       // ...of at least this quantity
        'amount' => '10',                  // paid to the direct sponsor, per entry
    ],
]);

$editor->addComponent($draft, key: 'depth-awards', driver: 'commission.strategy', name: 'Depth awards', parameters: [
    'strategy' => 'unilevel.fixed',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'source_type' => 'order',
        'minimum_quantity' => '100',
        'levels' => [
            ['depth' => 1, 'amount' => '10'],   // the direct sponsor
            ['depth' => 2, 'amount' => '5'],    // their sponsor
            ['depth' => 4, 'amount' => '1'],    // depth 3 earns nothing
        ],
    ],
]);

$lifecycle->markValidated($draft);

$run = app(CalculationEngine::class)->calculate($component, new CalculationContext($start, $end, 'direct-sponsor:2026-06'));
```

- **Fixed.** The entry's quantity only makes it eligible; the award is the configured amount, whatever the quantity. For awards that follow the quantity, see [Proportional commission math](#proportional-commission-math).
- **Eligible entries** are original volume entries of the run's program, of the configured volume and source types, effective in the run's range, with at least the minimum quantity (compared exactly; `0` admits every entry).
- **Historical sponsors.** A sponsor assigned after the entry, or an upline joined above it later, earns nothing from it. The placement tree is never read.
- **Depths are physical sponsor depths**: larger is further up. Only configured depths earn; there is no compression, and the order levels are listed in does not matter.
- One commission per entry and depth, keyed `volume-entry:<entry id>:depth:<d>`, earned at the entry's moment, with a trace back to the entry and the sponsorship.
- **Reversals as of the run's cutoff.** An entry reversed before the run's `until` earns nothing; a reversal at `until` or later belongs to a later range and changes nothing in this one. Commissions already calculated from it are corrected by an explicit clawback — see [Commission adjustments](#commission-adjustments--source-reversal-clawback).
- Neither strategy takes rules: a component with rules is refused rather than having them ignored.

## Proportional commission math

`direct-sponsor.proportional` and `unilevel.proportional` pay **the entry's quantity × a `unit_amount`**: money, in the component's currency, per one unit of the business measurement. It is **not a percentage** — a volume entry measures business, it is not money. Eligibility, historical sponsors, depths, reversal cutoffs and candidate keys are exactly those of the fixed strategies, which are unchanged.

```php
[
    'strategy' => 'direct-sponsor.proportional',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'source_type' => 'order',
        'minimum_quantity' => '1',
        'unit_amount' => '1.25',            // per unit of quantity
        'rounding' => 'half_even',          // required: no default
    ],
]

[
    'strategy' => 'unilevel.proportional',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'source_type' => 'order',
        'minimum_quantity' => '1',
        'rounding' => 'half_even',          // one mode for every depth
        'levels' => [
            ['depth' => 1, 'unit_amount' => '1.25'],
            ['depth' => 2, 'unit_amount' => '0.75'],
            ['depth' => 3, 'unit_amount' => '0.5'],
        ],
    ],
]
```

A quantity and a `unit_amount` each have up to six decimal places, so their product may have twelve; money keeps six. The product is computed exactly — no floats, no extension — and then rounded by the plan's **mandatory** `rounding`:

| Mode | Rule | 0.0000005 | 0.0000015 |
| --- | --- | --- | --- |
| `toward_zero` | drop what is below a millionth | 0 | 0.000001 |
| `away_from_zero` | add a millionth whenever anything remains | 0.000001 | 0.000002 |
| `half_up` | nearest; an exact half goes up | 0.000001 | 0.000002 |
| `half_even` | nearest; an exact half goes to the even millionth | 0 | 0.000002 |

- **Rounding happens per candidate** — separately for every source entry and depth — never after adding awards up.
- An award that rounds to zero is no commission. `unit_amount` is a rate, not bounded by one posting; the rounded award is, and one too large fails the whole run.
- Rounding is part of the versioned plan definition: never a package default, never chosen by currency. Every currency keeps six financial decimals.
- Each commission's trace records `quantity`, `unit_amount`, the unrounded `exact_amount`, `rounding`, whether it was `rounded`, and the final `amount`.
- A later reversal is clawed back by the stored, already-rounded amount — never recalculated.

## Binary pairing & carry

Two built-in strategies pay on the [binary legs](#binary-placement-foundation). A **pair** takes the same `pair_quantity` from the left and the right leg of a member; what cannot pair yet is **carried** to the component's next run.

```php
// A fixed amount for every whole pair.
$editor->addComponent($draft, key: 'binary', driver: 'commission.strategy', name: 'Binary pairing', parameters: [
    'strategy' => 'binary.pairing.fixed',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'pair_quantity' => '100',
        'amount_per_pair' => '10',
    ],
]);

// Or: the quantity the pairs consumed, times an amount per unit, rounded once.
$editor->addComponent($draft, key: 'binary', driver: 'commission.strategy', name: 'Binary pairing', parameters: [
    'strategy' => 'binary.pairing.proportional',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'pair_quantity' => '100',
        'unit_amount' => '0.1',
        'rounding' => 'half_even',
    ],
]);

$engine->calculate($component, new CalculationContext($january1, $february1, 'binary:2026-01'));
$engine->calculate($component, new CalculationContext($february1, $march1, 'binary:2026-02'));
```

- **Pairing at the run's close.** A run takes in every original entry of the volume type effective in its range, then pairs once per binary member: available = carry + added − reversed on each side; pairs = ⌊min(left, right) ÷ `pair_quantity`⌋, a whole number of any size; each side gives up pairs × `pair_quantity`. With 250 left and 120 right and a pair quantity of 100: one pair, carry 150 and 20. `pair_quantity` is exact and may be fractional (`2.5`).
- **The award.** Fixed: pairs × `amount_per_pair`, exactly. Proportional: consumed quantity × `unit_amount`, rounded by the explicit mode ([Proportional commission math](#proportional-commission-math)). At most one commission per member and run, keyed `binary-pairing:<member id>`, **earned at the run's `until`**, CALCULATED like every commission, with a trace of the pairing and carry. A proportional award that rounds to zero creates no commission, but its pairs still consume carry and are recorded. An award larger than one ledger posting fails the run.
- **Left/right carry, source by source.** Carry is kept as FIFO **lots**: one original entry, in one side of one member's legs, with its quantity and what remains unpaired. The side is the binary tree's at the entry's own moment: generic-only placement, later adoption and the sponsor tree play no part, and a member's own activity is never in its own legs. Pairs consume the oldest source first, and every draw is recorded as an allocation, so a commission leads back to the entries it was paid on. Each run records a pairing result per member: carry before, added, reversed, available, pairs, consumed and carry after.
- **State belongs to the plan component.** The first run starts the component's state at its `from`; each later run must start exactly where the last ended — an overlapping, repeated or gapped range is refused with `InvalidBinaryPairingRange`, and the same idempotency key replays the stored run without touching state. A **new plan version starts with no carry**; the old version's component keeps its own. Nothing is carried between versions automatically.
- **Atomic, serializable runs.** The run, its commissions and its carry changes are committed together or not at all, in a serializable transaction retried when the database reports a conflict: two runs of one component never consume the same carry. Calling a pairing strategy's `calculate()` directly only previews; authoritative results go through `CalculationEngine`.
- **Reversals.** An entry reversed before its run ends never pairs. A reversal of unpaired carry takes that carry back. A reversal of carry that was **already paired** is corrected in the run its moment falls in: the source's remaining carry is taken back, the pairs it fed are undone, and the same quantity returns to the other side's carry — newest source first — where it can pair again. Results record it as `left_restored`/`right_restored`; earlier results, allocations and commissions never change, and an immutable journal (`BinaryPairingCorrection`, `BinaryPairingRestoration`) explains the correction. The commissions of undone pairs are then corrected financially by an explicit call — see [Binary reversal correction](#binary-reversal-correction). `BinaryReversalImpactAnalyzer::analyze($reversal)` shows, read-only, what a reversal reaches — allocated, invalidated, restored and net consumed quantity per lot, with the pairing results, runs and commissions involved.
- No rules, carry expiry, pair caps or automatic placement yet.

## Matrix foundation

A matrix is an **overlay** on the generic placement tree, like binary, but with numbered slots. A program has one matrix network whose **width** — how many slots every matrix parent has — is set once and never changes:

```php
use PandaBear\Mlm\Matrix\MatrixGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;

$network = app(MatrixNetworkManager::class)->configure($program, 3);   // slots 1, 2 and 3

$matrix = app(MatrixPlacementManager::class);
$matrix->place($memberA, $parent, 1);       // a generic placement and its slot, together
$matrix->place($memberB, $parent, 2);
$matrix->adopt($existingPlacementEdge, 3);  // an existing generic edge, from now on

$tree = app(MatrixGenealogy::class);
$tree->child($parent, 2);                   // the member in slot 2, or null
$tree->positionOf($memberA);                // MatrixPlacementPosition: network, parent, slot, assigned_at
$tree->ancestors($memberA);                 // MatrixRelative: member + depth, nearest first
$tree->descendantsAt($parent, $june1, maxDepth: 2);
```

- **Width is structure.** A PHP integer from 1 to 100, configured once: the same width again returns the network, another width is refused (`InvalidMatrixNetwork`). It is not a commission parameter; depth limits belong to metrics and strategies, and the matrix itself has no maximum depth.
- **Slots are explicit.** A slot is a PHP integer from 1 to the width, holds one member, and is assigned once — never moved or removed. A taken slot is refused (`InvalidMatrixPlacement`), and a refused `place()` leaves no generic placement behind. **There is no automatic spillover or free-slot search yet**: you choose the parent and the slot.
- **Generic placement is untouched.** A parent keeps any number of generic children beside its full matrix; they are simply not in it. A generic-only edge below a matrix member stays outside the matrix until it is adopted itself.
- **History-aware.** A slot takes effect with its placement, an adoption from the moment of adoption — never before the edge was placed. Adopting an edge brings the member's own matrix subtree along from then on. Earlier activity never becomes matrix activity.
- **Its own paths**, under `tree_type` `matrix`: independent of sponsorship, generic placement and binary. Stored rows that disagree with their edge, network or width are refused with `CorruptMatrixPlacement`. Existing placements are **not** adopted by the migration.

**Compensation.** `matrix.fixed` and `matrix.proportional` pay the members above the member of each eligible sale, at physical matrix depths, as the matrix stood when the sale took effect:

```php
$editor->addComponent($draft, key: 'matrix', driver: 'commission.strategy', name: 'Matrix', parameters: [
    'strategy' => 'matrix.fixed',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'source_type' => 'order',
        'minimum_quantity' => '1',
        'levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']],
    ],
]);

$editor->addComponent($draft, key: 'matrix', driver: 'commission.strategy', name: 'Matrix', parameters: [
    'strategy' => 'matrix.proportional',
    'currency' => 'IDR',
    'source_account' => 'commission.payable',
    'parameters' => [
        'volume_type' => 'sales',
        'source_type' => 'order',
        'minimum_quantity' => '1',
        'rounding' => 'half_even',
        'levels' => [['depth' => 1, 'unit_amount' => '0.1'], ['depth' => 2, 'unit_amount' => '0.05']],
    ],
]);
```

- Eligibility, levels, gaps without compression, rounding per entry and depth, and the reversal cutoff are exactly those of the [depth-based strategies](#built-in-strategies) — read through the matrix instead of sponsorship. `unit_amount` is money per unit of quantity, not a percentage.
- Each commission records its source entry (`volume-entry`), so a later reversal is clawed back by `CommissionAdjustmentEngine::processVolumeReversal()` like any source-entry commission.
- Stateless: no carry, cycles or boards. Rules are refused.

## Hybrid composition

A hybrid plan is **composition**: a plan version with two or more `commission.strategy` components — say `binary.pairing.proportional`, `matrix.fixed` and `unilevel.fixed` — calculated together as one batch. Hybrid does not create another genealogy, metric or strategy: each component pays through its own network, exactly as when calculated on its own.

```php
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Commission\HybridCalculationEngine;

$result = app(HybridCalculationEngine::class)->calculate(
    $planVersion,
    new CalculationContext($january1, $february1, 'hybrid:2026-01'),
    $commissionPayable,                     // the system account every component is funded from
);

$result->batch;                             // CalculationBatch: program, version, range, account, key, status
$result->components();                      // the commission components, by position then id
$result->runs();                            // one CalculationRun per component, in that order
$result->commissionsOf($result->runs()[0]); // that run's commissions — never merged with another's
```

- **One run per component**, through the unchanged `CalculationEngine`, over the batch's range and under a key derived from the batch and the component — so binary pairing keeps its own serializable transaction, carry and cursor, and stateless strategies stay stateless. Other components of the version, such as a rank ladder, are not calculated. A version with fewer than two commission components is refused.
- **Resumable, not one transaction.** Each component commits its own run. If one fails, the batch stays `open` with the runs before it; calling again with the same request resumes it — no run is calculated twice, and a run committed before a crash is found again under its key. Once every component's run is linked the batch is `completed`; calling again returns it as stored. The same key with another version, range or account is refused.
- **Nothing is posted from an open batch**: `CommissionPoster::post()` refuses its commissions with `IncompleteCalculationBatch` until the batch completes, then posts as ever — binary correction guards and net amounts included. Runs calculated on their own post as before.
- **Commissions stay separate.** Two components paying one member make two commissions, each with its own provenance. Later reversals are corrected by each domain's own engine — `processVolumeReversal()` for source-entry strategies, the binary correction and `processBinaryReversal()` for pairing — never by a hybrid one.
- Every component must be funded from the one source account given. Cross-component rules — matching, caps, "the greater of" — are not implemented.

## Commission periods

A commission period is a program's calculation, review and release boundary: its **active** plan version, one range [from, until), one source account, and the earliest moment its commissions may become available. A program's periods never overlap.

```php
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;

$period = app(CommissionPeriodManager::class)->create($program, $activeVersion, $commissionPayable, $january1, $february1, releaseAt: $february15, idempotencyKey: 'period:2026-01');

app(CommissionPeriodCalculator::class)->calculate($period);   // one run per commission component

// Review the commissions: CALCULATED -> PENDING -> APPROVED (or CANCELLED).

app(CommissionPeriodFinalizer::class)->finalize($period);     // APPROVED -> HELD
app(CommissionPeriodReleaser::class)->release($period, $february15);   // HELD -> AVAILABLE

app(CommissionPoster::class)->post($commission);              // AVAILABLE -> POSTED: the wallet is credited
```

- **Status**: `open → calculated → finalized → released`, each step once. One commission component is calculated by `CalculationEngine`, two or more as a [hybrid batch](#hybrid-composition); a failed calculation leaves the period open and calling again resumes it.
- **Calculating closes the range.** From the moment a period's calculation begins, a new business entry — original or reversal — whose own moment falls in its range is refused with `FinalizedCommissionPeriod`, so no calculated result goes stale. A later reversal of one of its entries, dated after the period, is recorded as ever.
- **Finalize and release never move money.** Finalizing needs every commission approved or cancelled, and no binary correction left unprocessed; releasing needs the release moment. `CommissionPoster` is still the ledger boundary: a period's commission posts only when AVAILABLE in a released period (`CommissionPeriodNotReleased` otherwise); commissions calculated outside periods still post straight from APPROVED.
- **Corrections before posting**: a held or available commission is corrected like any unposted one — a partial binary share is recorded and posting pays what is left; a full correction cancels it.
- `CommissionPeriodTotals::of($period)` gives calculated, adjustment, net and posted totals, exactly. A commission has no paid status: [payouts](#payout--settlement) spend the wallet, not commissions.

## Commission core

A commission is reviewed, then posted to the member's wallet through the ledger:

```php
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;

$commission = $run->commissions->first();          // CALCULATED

$commission = app(CommissionLifecycle::class)->markPending($commission);
$commission = app(CommissionLifecycle::class)->approve($commission);
$commission = app(CommissionPoster::class)->post($commission);     // POSTED: the wallet is credited

$commission = app(CommissionPoster::class)->reverse($commission, now());   // REVERSED: the credit is undone
```

- `CALCULATED → PENDING → APPROVED → POSTED → REVERSED`, and `CANCELLED` from any status before `POSTED`. Commissions of a [commission period](#commission-periods) pass `APPROVED → HELD → AVAILABLE` first. Nothing else: approval is explicit, and posting a commission that is not approved is refused. One commission at a time.
- **Posting moves money only through the ledger**: one balanced transaction debiting the run's source account and crediting the member's wallet — opened if need be — occurring when it was earned. It moves the commission's **net amount**: its calculated amount less any binary correction recorded before posting — the whole amount when there is none — and records it as `posted_amount` (`$commission->postedAmount`). Posting again returns the commission and moves nothing.
- A commission a binary reversal has partly undone is **not posted** until that correction is processed (`UnresolvedBinaryCorrection`), and one with nothing left to post is never posted for zero.
- **Reversal** reverses that ledger transaction — exactly what was posted — at the moment you give; the original is untouched, and a commission is reversed once. A commission already partly corrected through the ledger is not reversed whole.
- What was calculated — member, amount, currency, earned-at, trace — never changes, and runs and commissions are read-only through Eloquent.
- Posted means the ledger credited the wallet; there is no paid status and no batch approval. Paying the wallet out is a [payout](#payout--settlement).

## Commission adjustments & source reversal clawback

When a business entry that commissions were paid on is reversed later, claw them back explicitly:

```php
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;

$reversal = $volumeRecorder->reverse(new ReverseVolume(entry: $sale, sourceType: 'refund', sourceId: 'RF-1', idempotencyKey: 'refund:RF-1', occurredAt: now()));

$result = app(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);

$result->adjustments;                              // one CommissionAdjustment per affected commission
$result->count(CommissionAdjustmentOutcome::Reversed);
```

- **CALCULATED, PENDING or APPROVED → cancelled.** No money had moved, so none is debited.
- **POSTED → reversed** through `CommissionPoster`, at the reversal's moment: the ledger reversal returns the money and the commission becomes REVERSED.
- Already **CANCELLED** or **REVERSED** commissions are left as they are, and recorded as `already_cancelled` / `already_reversed`.
- **Nothing is recalculated.** A clawback never runs genealogy, strategy configuration, quantity-to-money conversion or rounding again: it negates the stored commission amount, for the commission's own member. What was calculated is never rewritten; each correction is an immutable `CommissionAdjustment`.
- **Idempotent, and meant to be rerun.** Calling it again returns the same adjustments and moves nothing. A historical run whose cutoff preceded the reversal may create commissions for the entry after an earlier call; calling again finds and corrects them.
- One call corrects everything it finds, or nothing.
- **Provenance.** The built-in source-entry strategies record `source_type = volume-entry` and the entry's id on each commission; the trace stays audit output and is not the lookup. Your own strategy opts in by passing `source: CommissionSourceReference::volumeEntry($entry)` to a candidate earned wholly from that entry. Commissions created before provenance existed are given it by the migration.
- Recording volume never triggers this: call it where your application reverses volume.

## Binary reversal correction

A binary commission is earned from many sources on both legs, so a reversal of one of them undoes only **part** of it. The correction happens in three explicit steps, and nothing already recorded is rewritten:

```php
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;

// January: P's left leg sells 30 and 70, the right leg 100. One pair of 100
// pays 100; the commission is approved and posted.
$january = $calculationEngine->calculate($binaryComponent, new CalculationContext($january1, $february1, 'binary:2026-01'));
$commission = $poster->post($lifecycle->approve($lifecycle->markPending($january->commissions->first())));

// February 5: the 30 sale is refunded.
$reversal = $volumeRecorder->reverse(new ReverseVolume(entry: $sale30, sourceType: 'refund', sourceId: 'RF-1', idempotencyKey: 'refund:RF-1', effectiveAt: $february5));

// The run the reversal falls in corrects binary carry and pairing: the 30
// is taken back, 30 of January's pair is undone, and the right side gets 30
// of carry back. January's run, results, allocations and commission stay
// as they were; a journal records the correction.
$calculationEngine->calculate($binaryComponent, new CalculationContext($february1, $march1, 'binary:2026-02'));

// The financial correction: 30 of the 100 paired was undone, so 30 of the
// 100 paid comes back — here from the wallet, since it was posted.
$result = app(CommissionAdjustmentEngine::class)->processBinaryReversal($reversal);

$result->adjustments[0]->amount;      // FinancialAmount -30
$result->adjustments[0]->outcome;     // CommissionAdjustmentOutcome::Adjusted
```

- **The share.** With A the commission's stored amount, Q the quantity its pairing consumed and I the quantity reversals have undone of it, everything undone through a reversal is worth ⌊A × I ÷ Q⌋ financial millionths; the reversal's correction is that less what the reversals before it (by moment, then id) were worth. So corrections never add up to more than A, and exactly to A once the whole pairing is undone — three reversals of a third of 100 correct 33.333333, 33.333333 and 33.333334. The strategy is never run again: a fixed commission is not re-counted in whole pairs, and a proportional one is corrected from its rounded stored amount.
- **Before posting** (CALCULATED, PENDING, APPROVED): the correction is `recorded` and moves no money; posting later pays what is left, and records it as `posted_amount`. When nothing is left, the commission is `cancelled`.
- **After posting**: part of it moves back from the wallet to the run's source account in a ledger transaction of its own — `adjusted` — and the commission stays POSTED, its original posting untouched. When a correction leaves nothing and none has moved money back before, the posting is reversed — `reversed`. When an earlier correction already moved part back, the rest moves back the same way, and the original is never reversed as well. The wallet may go negative.
- **CANCELLED or REVERSED** commissions are left as they are: `already_cancelled`, `already_reversed`.
- One `CommissionAdjustment` per commission and reversal — source `binary-volume-reversal` — even when the share is **zero** (under a millionth): the reversal is then known to be processed. A pairing whose award rounded to nothing has no commission and needs no adjustment.
- **Call it after the pairing run.** Called before the run has taken the reversal in, it finds nothing and fakes nothing; calling again later finds the correction. Calling it again returns the same adjustments and moves nothing. One call corrects everything it finds, or nothing, and two calls — or a call and a post — racing on one commission serialize safely.
- **Posting waits for it.** A commission with a binary correction not yet processed is refused by `CommissionPoster::post()` with `UnresolvedBinaryCorrection`, so a stale binary commission is never paid by accident.
- The calculation engine never touches the ledger: the financial step is yours to orchestrate and retry.

## Payout & settlement

A payout takes money out of a member's wallet: requested, approved — which reserves the funds through the ledger — processed by your provider, then settled or failed.

```php
use PandaBear\Mlm\Payout\PayoutManager;

$payouts = app(PayoutManager::class);
$settlement = app(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'payout.settlement');

$request = $payouts->request($member, $wallet, $settlement, '40', 'bank-account', 'dest:8f2c', $requestedAt, idempotencyKey: 'payout:W-1');

$payouts->approve($request, $approvedAt);

// Wallet funds are now reserved: the wallet holds 40 less.

$payouts->startProcessing($request, $processingAt);

// Your provider or host performs the transfer.

$payouts->settle(
    $request,
    settlementReference: $providerReference,
    at: $settledAt,
);
```

When the transfer fails instead:

```php
$payouts->fail(
    $request,
    reason: 'provider-rejected',
    at: $failedAt,
);
```

- **Status**: `REQUESTED → APPROVED → PROCESSING → SETTLED`, `REQUESTED → CANCELLED`, and `APPROVED` or `PROCESSING → FAILED`. Each step once, at the moment you give; repeating a step with the same facts returns the request.
- **The wallet is the source of truth.** Approval reads the wallet's ledger balance under the wallet's lock and refuses more than it holds (`InsufficientPayoutBalance`), so two payouts never spend the same funds; what is left stays in the wallet. Payouts never read commissions, periods, runs or the genealogy, and a commission has no paid status.
- **Approval moves the money once**: one ledger transaction debiting the wallet and crediting your program's settlement **system account** of the same currency. Settlement records the provider's reference — unique in the program — and moves nothing more.
- **Failure restores the reserved funds to the wallet** by reversing that transaction. Cancelling, before approval, moves nothing.
- The destination is opaque — a type and a reference your host resolves. Never store credentials in it.
- A commission clawed back after its funds were paid out leaves the payout as it was: the wallet may go negative.

Group approved requests of one program and currency into a batch:

```php
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchTotals;

$batches = app(PayoutBatchManager::class);

$batch = $batches->create($program, 'IDR', idempotencyKey: 'batch:2026-03-01');
$batches->add($batch, $approvedRequest);         // approved requests only, one batch each
$batches->add($batch, $otherApprovedRequest);
$batches->seal($batch, $sealedAt);
$batches->startProcessing($batch, $processingAt); // every request moves to PROCESSING together

$payouts->settle($approvedRequest, settlementReference: 'BANK-123', at: $settledAt);
$payouts->fail($otherApprovedRequest, reason: 'account-closed', at: $failedAt);

$batches->complete($batch, $completedAt);         // once no request is still processing

PayoutBatchTotals::of($batch);                    // counts; requested, reserved, settled and failed totals
```

- **A batch groups; it never moves money.** Every movement is its requests' own reservation or refund. `OPEN → SEALED → PROCESSING → COMPLETED`, or `OPEN → CANCELLED`; a batched request is processed with its batch, then settles or fails on its own.
- Provider integrations, webhooks, bank exports, fees, taxes, thresholds, scheduled payouts and currency conversion are not implemented.

## Configuration

`config/mlm.php` holds **technical** settings only — where the package stores, queues and caches. Business plan rules such as pairing ratios, matrix sizes, commission percentages and rank requirements will never live in this file; they belong to versioned plans in the database.

| Key | Environment variable | Default |
| --- | --- | --- |
| `mlm.database.connection` | `MLM_DB_CONNECTION` | application default |
| `mlm.queue.connection` | `MLM_QUEUE_CONNECTION` | application default |
| `mlm.queue.name` | `MLM_QUEUE_NAME` | `mlm` |
| `mlm.cache.store` | `MLM_CACHE_STORE` | application default |
| `mlm.cache.prefix` | `MLM_CACHE_PREFIX` | `mlm` |

Configure it through the environment variables above. The config file is not publishable yet. An empty connection or store means the application's default; an empty queue name or cache prefix is rejected with `PandaBear\Mlm\Exceptions\InvalidMlmConfiguration`.

Inside the package, read these values through `PandaBear\Mlm\Support\PandaMlmConfig`, resolved from the container, rather than through `config()`.

## Roadmap

Planned, **not implemented**: percentage-of-money commissions, currency settlement precision, manual and positive commission adjustments, automatic clawback orchestration, qualified or ranked recipients for the built-in strategies, paid commissions, batch review, persisted qualification results, persisted ranks and rank history, rank promotion, demotion and maintenance, scheduled calculation periods, rules combined within a component other than a rank ladder, cross-component hybrid rules and caps, matching, generation, pool, leadership and fast-start bonuses, metric projections, running-balance projections, qualification rules, sponsor reassignment and correction, placement moves and removal, automatic placement strategies and matrix spillover, matrix cycling and re-entry, compressed matrices, matrix completion bonuses and boards, several matrix networks per program, matrix width changes, negative-balance and debt recovery after corrections, late binary events, binary carry expiry, pair caps and carry transfer between plan versions, multiple binary trees per program, business component drivers, performance and qualification, payment provider integrations, webhooks, bank exports and reconciliation, payout thresholds, scheduled payouts and currency conversion, transfers, pending and available balances, fees, taxes and rounding policies, balance projections, and the remaining Panda Panel screens — the Plan Builder, genealogy operations and tree views, program, member and payout request creation, and payout batch composition.

## Testing

```bash
composer test
vendor/bin/pint --test config src tests database
```

By default the suite runs the package migrations against an in-memory SQLite database. It needs nothing else.

### Against MySQL or PostgreSQL

The same suite runs against a real MySQL or PostgreSQL database when you opt in. Every test starts by dropping every table in that database, so give it an empty database made for the purpose: its name must contain `test`, and the suite refuses any other. The suite never creates or drops the database itself.

Connection details come from the environment only, never from a committed file:

| Variable | Required | Default |
| --- | --- | --- |
| `MLM_TEST_MYSQL_DATABASE`, `MLM_TEST_PGSQL_DATABASE` | yes | |
| `MLM_TEST_MYSQL_USERNAME`, `MLM_TEST_PGSQL_USERNAME` | yes | |
| `MLM_TEST_MYSQL_PASSWORD`, `MLM_TEST_PGSQL_PASSWORD` | no | empty |
| `MLM_TEST_MYSQL_HOST`, `MLM_TEST_PGSQL_HOST` | no | `127.0.0.1` |
| `MLM_TEST_MYSQL_PORT`, `MLM_TEST_PGSQL_PORT` | no | `3306`, `5432` |

```bash
export MLM_TEST_MYSQL_DATABASE=mlm_engine_test MLM_TEST_MYSQL_USERNAME=mlm
composer test:mysql

export MLM_TEST_PGSQL_DATABASE=mlm_engine_test MLM_TEST_PGSQL_USERNAME=mlm
composer test:pgsql
```

`composer test:mysql` and `composer test:pgsql` set `MLM_TEST_DATABASE` to `mysql` or `pgsql` for you. The database user needs to create and drop tables there. On MySQL the concurrency tests also read `performance_schema.data_lock_waits` and `performance_schema.threads` to see which sessions are waiting on a lock.

Tests in the `concurrency` group run package operations in separate PHP processes, each with its own database session, and check how they interleave: racing cycle checks, sponsor against placement writes, racing volume records and reversals, pairing runs, commission posts and clawbacks — binary corrections included. They are skipped on SQLite. To run only them:

```bash
composer test:mysql -- --group concurrency
```

## License

MIT
