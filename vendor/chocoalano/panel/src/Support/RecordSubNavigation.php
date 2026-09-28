<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\ManageRelatedRecords;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The links that move between the pages of one record.
 *
 * Built from the resource's own `pages()` map, so a resource that declares no
 * view page simply has no link to one. Every item is authorized for the
 * record it points at: an unauthorized page is absent rather than a link that
 * answers 403, and the route enforces the same rule independently.
 *
 * Only record pages take part. A page whose route carries no `{record}` —
 * the index and create pages — is not somewhere this record can be seen.
 *
 * A `ManageRelatedRecords` page is a record page too, and it is discovered
 * rather than listed: its ability depends on the manager it names, so a fixed
 * map could not answer for it.
 */
final class RecordSubNavigation
{
    /**
     * Page key to the ability that decides whether it is offered, and the
     * icon registry key it renders with. An icon that is not registered
     * simply does not render.
     *
     * @var array<string, array{ability: string, icon: string}>
     */
    private const RECORD_PAGES = [
        'view' => ['ability' => 'canView', 'icon' => 'search'],
        'edit' => ['ability' => 'canEdit', 'icon' => 'settings'],
    ];

    /**
     * @param  class-string<PanelResource>  $resource
     * @return list<array{key: string, label: string, href: string, icon: string|null, active: bool}>
     */
    public static function for(string $resource, Model $record, string $currentPage): array
    {
        $declared = $resource::pages();
        $items = [];

        foreach (self::RECORD_PAGES as $key => $page) {
            if (! array_key_exists($key, $declared) || ! $resource::{$page['ability']}($record)) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => __('panda-panel::pages.record_navigation.'.$key),
                'href' => $resource::url($key, $record),
                'icon' => $page['icon'],
                'active' => $key === $currentPage,
            ];
        }

        foreach (self::relationItems($resource, $record, $currentPage) as $item) {
            $items[] = $item;
        }

        // One link is not navigation: there is nowhere else to go, and a lone
        // tab is noise on every record page in the panel.
        return count($items) > 1 ? $items : [];
    }

    /**
     * The relation pages this record can be seen on.
     *
     * Authorized through the manager rather than the resource — whether a
     * user may read a record's posts is the posts' policy's answer, not the
     * record's — and skipped entirely for a manager the resource does not
     * declare, which is the same check the page itself makes.
     *
     * @param  class-string<PanelResource>  $resource
     * @return list<array{key: string, label: string, href: string, icon: string|null, active: bool}>
     */
    private static function relationItems(string $resource, Model $record, string $currentPage): array
    {
        $items = [];

        foreach ($resource::pages() as $key => $page) {
            if (! is_subclass_of($page, ManageRelatedRecords::class)) {
                continue;
            }

            $manager = $page::relationManager();

            if ($resource::relationManager($manager::key()) === null) {
                continue;
            }

            if (! $manager::canViewAny($record)) {
                continue;
            }

            // Keyed by the relation, not by the page key: the page key is
            // whatever `pages()` chose and the current page can only report
            // which relation it is showing.
            $items[] = [
                'key' => $manager::key(),
                'label' => $manager::title(),
                'href' => $resource::url($key, $record),
                'icon' => $manager::icon(),
                'active' => $manager::key() === $currentPage,
            ];
        }

        return $items;
    }
}
