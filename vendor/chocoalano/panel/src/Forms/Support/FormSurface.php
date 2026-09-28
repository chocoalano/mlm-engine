<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

/**
 * Which kind of form a side request is about.
 *
 * A form is not one thing. The resource's own create and edit forms, the form
 * an action carries, the form a relation manager opens for one of its
 * operations, and the form a relation's row action carries are four different
 * schemas guarded by four different abilities — and every endpoint that
 * serves a form from the side (options, state refresh, uploads) has to decide
 * which of them it is looking at before it builds anything.
 *
 * A closed set rather than a free string, for the reason `RelationOperation`
 * is one: the surface decides which schema is built and which ability is
 * asked, so a value the server does not recognise must be a 404 rather than a
 * fallback to the most permissive branch.
 */
enum FormSurface: string
{
    /** The resource's own create form. */
    case Create = 'create';

    /** The resource's own edit form, for one record. */
    case Edit = 'edit';

    /** The form a resource, table, bulk, or infolist action declared. */
    case Action = 'action';

    /** The form a relation manager opens for create/edit/attach/associate. */
    case Relation = 'relation';

    /** The form a relation manager's row or bulk action declared. */
    case RelationAction = 'relation-action';

    /**
     * The `page` a resource schema is built for.
     *
     * Every surface has one, because `hiddenOn()` and `disabledOn()` are
     * answered against it whatever built the schema. A form that is not the
     * resource's own is built as a create form: it holds no record of the
     * resource's, so a field hidden on edit has nothing to be hidden from.
     */
    public function page(): string
    {
        return $this === self::Edit ? 'edit' : 'create';
    }

    /**
     * Whether this surface is one of the resource's own record forms.
     */
    public function isResourceForm(): bool
    {
        return $this === self::Create || $this === self::Edit;
    }

    /**
     * Whether the form belongs to a relation manager rather than a resource.
     */
    public function isRelation(): bool
    {
        return $this === self::Relation || $this === self::RelationAction;
    }

    /**
     * Whether the form belongs to an action rather than to a record page.
     */
    public function isAction(): bool
    {
        return $this === self::Action || $this === self::RelationAction;
    }
}
