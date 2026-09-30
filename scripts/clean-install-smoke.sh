#!/usr/bin/env bash
#
# Installs the package the way a consumer receives it — the `git archive` of
# the working tree, with `.gitattributes` applied, as Packagist serves it —
# into a fresh Laravel application in a temporary directory, and checks what
# that consumer gets: the Composer install, provider discovery, configuration,
# every migration, the plugin on a real panel and its routes, the translations,
# the public services, a backend payout cycle with no panel and no signed-in
# user, the configuration and route caches, an optimized autoloader, and a
# non-default `mlm.database.connection`. Development only — not part of the
# package archive.
#
#   scripts/clean-install-smoke.sh 13
#   scripts/clean-install-smoke.sh 12 --php /opt/homebrew/opt/php@8.2/bin/php --panel 0.5.7
#
# Options:
#   --php <binary>     the PHP that runs Composer and the application (default: php)
#   --panel <version>  pin chocoalano/panel (default: the newest the constraint allows)
#   --archive <zip>    install this archive instead of exporting the working tree
#   --keep             keep the temporary application for inspection
#
set -euo pipefail

laravel="${1:-13}"
[ $# -gt 0 ] && shift
php_bin="php"
panel=""
archive=""
keep=0

while [ $# -gt 0 ]; do
    case "$1" in
        --php) php_bin="$2"; shift 2 ;;
        --panel) panel="$2"; shift 2 ;;
        --archive) archive="$2"; shift 2 ;;
        --keep) keep=1; shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

package="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
app="$work/app"
composer_bin="$(command -v composer)"

cleanup() { if [ "$keep" = 1 ]; then echo "Kept ${work}"; else rm -rf "$work"; fi; }
trap cleanup EXIT

composer() { "$php_bin" "$composer_bin" "$@"; }
artisan() { "$php_bin" artisan "$@"; }
smoke() { "$php_bin" mlm-smoke.php "$@"; }

# The archive: tracked files as they stand, uncommitted edits included
# (`git stash create` writes a commit object and touches nothing else).
if [ -z "$archive" ]; then
    archive="$work/pandabear-mlm.zip"
    tree="$(git -C "$package" stash create)"
    git -C "$package" archive --format=zip -o "$archive" "${tree:-HEAD}"
fi

if unzip -Z1 "$archive" | grep -E -q '^(tests|scripts|vendor)/|^(composer\.lock|phpunit\.xml|pint\.json)$'; then
    echo 'The archive ships development material.' >&2
    exit 1
fi

echo "Laravel ${laravel}, $("$php_bin" -r 'echo PHP_VERSION;'), into ${app}"
composer create-project "laravel/laravel:^${laravel}.0" "$app" --no-interaction --prefer-dist --quiet

cd "$app"

# A package repository serving the archive, the way Packagist serves a dist.
"$php_bin" -r '
    [, $archive] = $argv;
    $package = json_decode(shell_exec("unzip -p ".escapeshellarg($archive)." composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $package["version"] = "dev-archive";
    $package["dist"] = ["type" => "zip", "url" => "file://".$archive];
    $composer = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $composer["repositories"] = [["type" => "package", "package" => $package], ...($composer["repositories"] ?? [])];
    file_put_contents("composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
' "$archive"

composer require ${panel:+"chocoalano/panel:${panel}"} "pandabear/mlm:dev-archive" --no-interaction --quiet
composer show | grep -E '^(laravel/framework|chocoalano/panel|pandabear/mlm) '

# SQLite, so the smoke needs no server.
touch database/database.sqlite
sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env && rm .env.bak

artisan --version
artisan package:discover | grep -q 'pandabear/mlm' || { echo 'Provider not discovered.' >&2; exit 1; }

# A panel of the application's own, carrying the plugin, registered the way
# Panda Panel's installer registers one.
artisan vendor:publish --tag=panda-panel-config --no-interaction --quiet
mkdir -p app/Panels/Mlm
cat > app/Panels/Mlm/MlmPanelProvider.php <<'PHP'
<?php

namespace App\Panels\Mlm;

use PandaBear\Mlm\PandaMlmPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class MlmPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->path('mlm')->plugins([PandaMlmPlugin::make()]);
    }
}
PHP
"$php_bin" -r '
    $config = file_get_contents("config/panda-panel.php");
    $config = str_replace("// App\\Panels\\Admin\\AdminPanelProvider::class,", "App\\Panels\\Mlm\\MlmPanelProvider::class,", $config, $count);
    $count === 1 ? file_put_contents("config/panda-panel.php", $config) : exit(1);
' || { echo 'Could not register the panel.' >&2; exit 1; }

cp "$package/scripts/clean-install-smoke.php" mlm-smoke.php

artisan migrate:fresh --force --no-interaction --quiet
smoke checks
smoke backend

echo '-- configuration cache'
artisan config:cache --quiet
smoke checks
artisan config:clear --quiet

echo '-- route cache'
artisan route:cache --quiet
artisan route:list --path=mlm --json | "$php_bin" -r 'exit(count(json_decode(stream_get_contents(STDIN), true)) > 0 ? 0 : 1);' || { echo 'No panel route from the cache.' >&2; exit 1; }
smoke checks
artisan route:clear --quiet

echo '-- optimized autoloader'
composer dump-autoload --optimize --quiet
artisan package:discover | grep -q 'pandabear/mlm' || { echo 'Provider not discovered.' >&2; exit 1; }
smoke checks

echo '-- a connection of its own'
touch database/mlm.sqlite
"$php_bin" -r '
    $config = file_get_contents("config/database.php");
    $config = preg_replace("/\x27connections\x27 => \[/", "\x27connections\x27 => [\n\n        \x27mlm\x27 => [\x27driver\x27 => \x27sqlite\x27, \x27database\x27 => database_path(\x27mlm.sqlite\x27), \x27prefix\x27 => \x27\x27, \x27foreign_key_constraints\x27 => true],\n", $config, 1, $count);
    $count === 1 ? file_put_contents("config/database.php", $config) : exit(1);
' || { echo 'Could not add the connection.' >&2; exit 1; }
printf '\nMLM_DB_CONNECTION=mlm\n' >> .env
artisan migrate:fresh --force --no-interaction --quiet
artisan config:cache --quiet
smoke checks mlm
smoke backend mlm
artisan config:clear --quiet

echo "Clean install on Laravel ${laravel}: passed."
