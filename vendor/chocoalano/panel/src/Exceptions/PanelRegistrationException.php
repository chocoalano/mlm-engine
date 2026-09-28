<?php

declare(strict_types=1);

namespace PandaPanel\Exceptions;

use RuntimeException;

/**
 * Thrown when panel, resource, or page registration is ambiguous.
 *
 * These are developer errors, not user errors: they must fail loudly during
 * boot and during `panel:cache` rather than degrade at runtime.
 */
final class PanelRegistrationException extends RuntimeException
{
    public static function duplicatePanelId(string $id): self
    {
        return new self("A panel with the id [{$id}] is already registered.");
    }

    public static function duplicatePanelPath(string $path, ?string $domain, string $existingId): self
    {
        $location = $domain === null ? "path [/{$path}]" : "path [/{$path}] on domain [{$domain}]";

        return new self("The {$location} is already used by the panel [{$existingId}].");
    }

    /**
     * Two plugins claiming one id.
     *
     * A panel is asked `hasPlugin('billing')` to decide what to show, so two
     * plugins answering to one name is a question with two answers — caught
     * at registration rather than discovered as a resource that appears
     * twice.
     */
    public static function duplicatePlugin(string $id, string $panelId): self
    {
        return new self(sprintf(
            'Two plugins claim the id [%s] in panel [%s]. A plugin id is how a panel is asked whether it has one, so it has to be unique.',
            $id,
            $panelId,
        ));
    }

    public static function unknownPanel(string $id): self
    {
        return new self("No panel is registered with the id [{$id}].");
    }

    public static function missingPanelId(): self
    {
        return new self('The panel has no id. Pass one to Panel::make() or call ->id() in the panel provider.');
    }

    public static function duplicateResourceSlug(string $slug, string $existing, string $incoming): self
    {
        return new self("The resource slug [{$slug}] is used by both [{$existing}] and [{$incoming}].");
    }

    public static function duplicatePageSlug(string $slug, string $existing, string $incoming): self
    {
        return new self("The page slug [{$slug}] is used by both [{$existing}] and [{$incoming}].");
    }

    public static function slugCollidesWithResource(string $slug, string $page, string $resource): self
    {
        return new self("The page [{$page}] uses the slug [{$slug}], which is already registered by the resource [{$resource}].");
    }

    public static function duplicateWidgetId(string $id, string $existing, string $incoming): self
    {
        return new self("The widget id [{$id}] is used by both [{$existing}] and [{$incoming}].");
    }

    public static function resourceNotInPanel(string $resource, string $panelId): self
    {
        return new self("The resource [{$resource}] is not registered in the panel [{$panelId}], so it has no URL there.");
    }

    public static function noCurrentPanel(): self
    {
        return new self('There is no current panel for this request. Resolve one through panel middleware or pass an explicit panel.');
    }

    /**
     * @param  class-string  $model
     * @param  class-string  $manager
     */
    public static function unknownRelation(string $model, string $relation, string $manager): self
    {
        return new self("[{$manager}] manages the relation [{$relation}], which [{$model}] does not define as an Eloquent relation.");
    }

    /**
     * @param  class-string  $resource
     */
    public static function duplicateRelationKey(string $key, string $resource): self
    {
        return new self("The resource [{$resource}] registers two relation managers under the key [{$key}].");
    }

    /**
     * @param  class-string  $resource
     * @param  class-string  $parent
     */
    public static function unregisteredParentResource(string $resource, string $parent): self
    {
        return new self("[{$resource}] is nested under [{$parent}], which is not registered in the same panel.");
    }

    public static function noParentRecord(string $resource): self
    {
        return new self("[{$resource}] is a nested resource, so it can only be reached under a parent record. None is bound to this request.");
    }

    /**
     * A plugin that wants a version of this framework it is not running on.
     *
     * Named rather than left to fail later: the alternative is `Call to
     * undefined method` inside a request, naming the framework rather than
     * the plugin that asked for it.
     */
    public static function incompatiblePlugin(
        string $name,
        string $id,
        string $panelId,
        string $constraint,
        string $installed,
    ): self {
        return new self(
            "The [{$name}] plugin ([{$id}], registered on the [{$panelId}] panel) requires "
            ."panda-panel {$constraint}, and {$installed} is installed. Upgrade the plugin, or "
            .'pin this framework to a version it supports.'
        );
    }

    /**
     * A tenant scope was asked for and there is no tenant.
     *
     * Raised rather than answered with an unscoped query, which would show
     * every tenant's records to whoever asked. The usual cause is a route
     * registered without `ResolveTenant` — a panel route group gets it
     * automatically once the panel declares `tenant()`, so this normally
     * means a route registered by hand.
     */
    public static function noCurrentTenant(): self
    {
        return new self(
            'This panel is tenant-scoped, but no tenant is bound to this request. '
            .'Routes registered by the panel resolve one through ResolveTenant; a route '
            .'registered by hand has to include that middleware, and console or queue work '
            .'has to enter a tenant with Tenancy::for().'
        );
    }

    /**
     * @param  class-string  $resource
     * @param  class-string  $model
     */
    public static function unknownTenantRelationship(string $resource, string $model, string $relation): self
    {
        return new self(
            "[{$resource}] scopes to the tenant through [{$relation}], which [{$model}] does not have. "
            .'Name a relationship that exists, or override query() and scope it yourself.'
        );
    }

    /**
     * Two routes on one path shape.
     *
     * Laravel matches the first and silently ignores the second, which is
     * exactly the kind of ambiguity that shows up as a page that renders the
     * wrong thing rather than as an error.
     */
    /**
     * A `$tenantRelationship` that names a method which is not a relationship.
     */
    public static function tenantRelationshipIsNotARelation(string $resource, string $model, string $relation): self
    {
        return new self(sprintf(
            '[%s] scopes by the tenant relationship [%s], and [%s::%s()] exists but does not '
                .'return an Eloquent relationship. A scope or an accessor cannot be traversed to '
                .'a tenant — name a belongsTo, belongsToMany or hasOneThrough, or override '
                .'query() and scope it yourself.',
            $resource,
            $relation,
            $model,
            $relation,
        ));
    }

    public static function collidingRoutePath(string $path, string $existing, string $incoming): self
    {
        return new self(
            "The path [{$path}] is registered by both [{$existing}] and [{$incoming}]. "
            .'Only the first would ever match. Give one of them a different slug or route path.'
        );
    }
}
