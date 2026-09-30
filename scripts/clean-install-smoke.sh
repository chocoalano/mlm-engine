#!/usr/bin/env bash
#
# Installs this working tree into a fresh Laravel application in a temporary
# directory and checks what a consumer gets: the Composer install, provider
# discovery, configuration, migrations, the plugin on a panel, and the main
# services resolving. Development only — not part of the package archive.
#
#   scripts/clean-install-smoke.sh 13     # Laravel 13
#   scripts/clean-install-smoke.sh 12     # Laravel 12
#
set -euo pipefail

laravel="${1:-13}"
package="$(cd "$(dirname "$0")/.." && pwd)"
app="$(mktemp -d)/app"

echo "Laravel ${laravel} into ${app}"
composer create-project "laravel/laravel:^${laravel}.0" "$app" --no-interaction --prefer-dist --quiet

cd "$app"
composer config repositories.mlm "{\"type\": \"path\", \"url\": \"${package}\", \"options\": {\"symlink\": false}}"
composer require "pandabear/mlm:*@dev" --no-interaction --quiet

# SQLite, so the smoke needs no server.
touch database/database.sqlite
sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env

php artisan package:discover --ansi | grep -q 'pandabear/mlm' || { echo 'provider not discovered'; exit 1; }
php artisan migrate --force --no-interaction

php artisan tinker --execute='
use PandaBear\Mlm\PandaMlmPlugin;
use PandaPanel\Core\Panel;

$checks = [
    "config" => config("mlm.queue.name") === "mlm",
    "migrations" => Illuminate\Support\Facades\Schema::hasTable("mlm_payout_batch_items"),
    "translations" => __("mlm::mlm.navigation.group") === "Network & Compensation",
    "plugin" => Panel::make("smoke")->path("smoke")->plugins([PandaMlmPlugin::make()])->hasPlugin("panda-mlm"),
    "services" => app(PandaBear\Mlm\Payout\PayoutManager::class) instanceof PandaBear\Mlm\Payout\PayoutManager
        && app(PandaBear\Mlm\Commission\HybridCalculationEngine::class) instanceof PandaBear\Mlm\Commission\HybridCalculationEngine,
    "program" => (new PandaBear\Mlm\Program\ProgramManager)->create("SMOKE", "Smoke")->exists,
];

foreach ($checks as $name => $ok) { echo str_pad($name, 14), $ok ? "ok" : "FAILED", PHP_EOL; }
if (in_array(false, $checks, true)) { exit(1); }
'

echo "Clean install on Laravel ${laravel}: passed. (${app})"
