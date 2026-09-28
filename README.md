# Panda MLM

A configurable MLM engine for [Panda Panel](https://github.com/chocoalano/panda-panel), part of the pandabear.asia ecosystem.

> **Status: early development.** This package currently provides the Panda Panel plugin, the technical package configuration and the core domain — programs and their members. Plans, genealogy and compensation are **not implemented yet** (see [Roadmap](#roadmap)).

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

The design decisions are recorded in [`docs/adr`](docs/adr).

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

Planned, **not implemented**: plans and plan versions, sponsor and placement genealogy, network types, performance and qualification, ranks, commissions and bonuses, wallets, ledger and payouts, and the Panda Panel screens for all of it.

## Testing

```bash
composer test
vendor/bin/pint --test config src tests database
```

The suite runs the package migrations against an in-memory SQLite database.

## License

MIT
