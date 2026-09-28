# Panda MLM

A configurable MLM engine for [Panda Panel](https://github.com/chocoalano/panda-panel), part of the pandabear.asia ecosystem.

> **Status: foundation only.** This package currently provides the Panda Panel plugin, the Laravel service provider and the technical package configuration. The MLM domain — programs, members, plans, genealogy, compensation plans and commissions — is under development and **not available yet**.

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

## Testing

```bash
composer test
vendor/bin/pint --test config src tests
```

## License

MIT
