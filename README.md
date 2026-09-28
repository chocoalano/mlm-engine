# Panda MLM

A configurable MLM engine for [Panda Panel](https://github.com/chocoalano/panda-panel), part of the pandabear.asia ecosystem.

> **Status: early development.** This package currently provides the Panda Panel plugin, the technical package configuration, the core domain — programs and their members — plan versioning, the sponsor and placement genealogies, volume entries with idempotent recording and explicit reversal, and a metrics foundation. Plan rules, network metrics, qualification, rank, commission, wallets and ledgers, binary and matrix positioning, and automatic placement are **not implemented yet** (see [Roadmap](#roadmap)).

## Requirements

- PHP ^8.2
- Laravel 12 or 13
- Panda Panel (`chocoalano/panel`) ^0.5.7

## Installation

The package is not on Packagist yet. Add the repository to the application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/chocoalano/mlm-engine"
    }
]
```

Then require it:

```bash
composer require pandabear/mlm:dev-main
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
| `mlm_genealogy_paths` | every ancestor/descendant pair of either tree: `tree_type`, `ancestor_id`, `descendant_id`, `depth` |
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

Plan versions do not carry any rules yet: there is nothing to calculate with.

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
```

- A member has at most one placement parent, and a member without one is a placement root. A program may have many.
- A parent may have any number of members placed under it. There is no capacity, position or slot in this generic layer.
- Member and parent must be in the same program. A member cannot be placed under itself or under anyone in its own placement subtree. Cycle checks only see placement, so placement may even run opposite to sponsorship.
- A placement is made once. There is no move or removal yet.
- A member that already has members placed under it can still receive its first placement parent; its whole placement subtree is attached beneath that parent.
- Refused placements throw `InvalidPlacementAssignment` and change nothing. Every placement runs in one transaction.

Placement paths share the closure table with sponsor paths under their own `tree_type`, and never leak into sponsor queries. **Binary positioning (left/right), matrix slots, spillover and automatic placement strategies are not implemented yet.** A member taking part in the placement tree cannot be deleted.

Both genealogies are written only through their services. Raw query-builder or SQL writes to the edge or path tables bypass every graph rule: the database backs local invariants — one direct edge per member, one path per pair, foreign keys — but not acyclicity or consistency between edges and paths.

The design decisions are recorded in [`docs/adr`](docs/adr).

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

A range counts entries by `effective_at` from `from` (inclusive) to `until` (exclusive); with both bounds, `from` must come before `until`. Totals cover the member's own entries only. **Network totals, running balances, qualification, rank, commission and wallets are not implemented yet.**

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

**Network metrics are intentionally not implemented yet.** Genealogy paths describe the current structure, not who was below whom at a past moment, so a historical team or downline figure cannot be computed honestly until that is designed. Qualification, rank and commission are not implemented either.

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

Planned, **not implemented**: temporal genealogy, network metrics, metric projections, running-balance projections, qualification rules, sponsor reassignment and correction, placement moves and removal, placement positions and slots, automatic placement strategies, plan components, rules and parameters, network types (binary, matrix, unilevel, hybrid), performance and qualification, ranks, commissions and bonuses, wallets, ledger and payouts, and the Panda Panel screens for all of it.

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

Tests in the `concurrency` group run package operations in separate PHP processes, each with its own database session, and check how they interleave: racing cycle checks, sponsor against placement writes, and racing volume records and reversals. They are skipped on SQLite. To run only them:

```bash
composer test:mysql -- --group concurrency
```

## License

MIT
