<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\RelationTable;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Widgets\PageContext;

/**
 * A page devoted to one of a record's relations.
 *
 * The same manager a record page can show inline, given its own route and its
 * own place in the record's sub-navigation. Which of the two to use is a
 * question about the relation, not about the framework: a handful of rows
 * belongs beside the record, and a table somebody will page and search
 * through deserves a URL of its own.
 *
 * Declared in `Resource::pages()` like every other page, so it is routed,
 * named, and authorized by the same machinery:
 *
 * ```php
 * 'posts' => ManageUserPosts::class,
 * ```
 *
 * with the page naming the manager and a `{record}` in its route path.
 */
abstract class ManageRelatedRecords extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $component = 'panel/resources/ManageRelated';

    /** @var class-string<RelationManager> */
    protected static string $relationManager;

    protected static string $page = 'relation';

    public function render(Request $request, ?string $record = null): Response
    {
        $resource = static::$resource;
        $manager = static::$relationManager;

        // A page for a manager the resource does not declare would be a way
        // to reach a relation that was never registered, so the page list is
        // not a second registration — it points into the first.
        abort_if($resource::relationManager($manager::key()) === null, 404);

        $owner = $this->resolveRecord($record);

        $relation = RelationTable::forManager($resource, $manager, $owner, $request);

        abort_if($relation === null, 403);

        return Inertia::render(static::$component, [
            'page' => $this->pageMetadata($owner),
            'resource' => $this->resourceMetadata(),
            ...$this->widgetProps(PageContext::forRecord($owner)),
            'recordKey' => $owner->getKey(),
            'relation' => $relation,
        ]);
    }

    /**
     * @return class-string<RelationManager>
     */
    public static function relationManager(): string
    {
        return static::$relationManager;
    }

    /**
     * The key this page is known by in `Resource::pages()`, which is also the
     * segment its route ends with.
     */
    public static function routePath(string $key): string
    {
        return static::$routePath ?? '{record}/'.$key;
    }

    protected function defaultTitle(?Model $record): string
    {
        return static::$relationManager::title();
    }

    /**
     * The owner beneath the relation's name: the page is one record's posts,
     * not every post.
     */
    protected function defaultSubheading(?Model $record): ?string
    {
        return $record === null ? null : $this->recordTitle($record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageMetadata(Model $owner): array
    {
        $manager = static::$relationManager;
        $title = $this->recordTitle($owner);

        return [
            ...$this->headingMetadata($owner),
            'breadcrumbs' => $this->serializeBreadcrumbs([
                ...$this->baseBreadcrumbs(),
                $this->recordCrumb($owner, $title),
                Breadcrumb::make($manager::title())->current(),
            ]),
            'headerActions' => [],
            'scope' => static::renderHookScope(),
            'cluster' => $this->clusterNavigation(),
            'subNavigation' => $this->subNavigation($owner, static::relationPageKey()),
        ];
    }

    /**
     * The sub-navigation key this page answers to, which is its relation.
     */
    public static function relationPageKey(): string
    {
        return static::$relationManager::key();
    }

    protected function widgetPageKey(): string
    {
        return 'relation:'.static::relationPageKey();
    }
}
