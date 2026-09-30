<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Support\NavigationItem;

/**
 * A read-oriented Panda MLM resource (ADR-031).
 *
 * Viewing is one capability; creating, editing, deleting, restoring are
 * never offered — every MLM record is written by its domain service, and
 * financial history is never deleted. Lifecycle changes are explicit
 * actions that call those services, authorized by their own capability.
 */
abstract class MlmResource extends Resource
{
    /** The capability that lets a user list and open these records. */
    abstract protected static function viewPermission(): string;

    /** The key of this resource's words under `mlm::mlm.resources`. */
    abstract protected static function translationKey(): string;

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema;
    }

    public static function defaultLabel(): string
    {
        return __('mlm::mlm.resources.'.static::translationKey().'.label');
    }

    public static function defaultPluralLabel(): string
    {
        return __('mlm::mlm.resources.'.static::translationKey().'.plural');
    }

    public static function emptyHeading(): string
    {
        return __('mlm::mlm.resources.'.static::translationKey().'.empty');
    }

    public static function emptyDescription(): string
    {
        return __('mlm::mlm.resources.'.static::translationKey().'.empty_description');
    }

    /**
     * The parent's entry, in the one MLM group unless the panel configured
     * another — a translated group cannot be a static property.
     */
    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        $item = parent::navigationItem($panel);

        if ($item === null) {
            return null;
        }

        return new NavigationItem(
            label: $item->label,
            href: $item->href,
            icon: $item->icon,
            badge: $item->badge,
            active: $item->active,
            sort: $item->sort,
            group: $item->group ?? MlmNavigation::group(),
            children: $item->children,
            fullPage: $item->fullPage,
            activeIcon: $item->activeIcon,
        );
    }

    public static function canViewAny(): bool
    {
        return MlmPermission::allows(static::viewPermission());
    }

    public static function canView(Model $record): bool
    {
        return MlmPermission::allows(static::viewPermission());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
