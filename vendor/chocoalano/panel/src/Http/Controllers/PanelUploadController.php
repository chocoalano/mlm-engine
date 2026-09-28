<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormContext;

/**
 * Stores one file for a form field.
 *
 * The request names a resource and a field, never a disk or a directory. Both
 * of those come from the field's own declaration, looked up in the schema
 * that declared it — so a request cannot choose where its file lands, which
 * is the whole point of doing the upload separately from the form submit.
 *
 * Everything the field declares is enforced here *and* again when the form is
 * submitted, because they are two requests and only the second attaches the
 * path to a record.
 *
 * ## Which permission an upload needs
 *
 * The one that would be needed to submit the form the field belongs to, and
 * nothing weaker. There are three kinds of form and they are not
 * interchangeable, so the context in the URL decides which schema is built
 * and which ability is asked:
 *
 * | Context in the URL          | Schema                        | Ability |
 * | --------------------------- | ----------------------------- | ------- |
 * | `page=create`               | the resource's create form     | `create` |
 * | `page=edit` + `record`      | the resource's edit form       | `update` on that record |
 * | `relation` + `operation`    | the relation form              | the relation's own, per operation |
 * | `action` + `scope`          | the action's form              | the action's own |
 *
 * The context is built by the server and travels in the query string; the
 * client adds only the field name and the file. That split is what stops a
 * user who may create in a resource from uploading against an edit form for
 * a record they may not touch, and a `viewAny` reader from uploading at all.
 *
 * Reading the resource is never enough. An upload writes a file to a disk
 * under a directory this application chose, and the ability to look at a list
 * is not the ability to put something on that disk.
 */
final class PanelUploadController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string'],
            'file' => ['required', 'file'],
        ]);

        // Read from the query string, never from the body. The body is the
        // form's values, and a field that happens to be named `resource`
        // must not be able to point the upload at a different one — the same
        // rule the relation endpoints follow.
        //
        // Authorization and the schema are answered together, by the same
        // resolver: a schema built for one context and a permission asked
        // about another is the bug this endpoint exists to not have. That
        // resolver is now shared with the options and state endpoints, so
        // "which form is this" has one answer rather than three that have to
        // be kept in step.
        $context = FormContext::resolve($this->manager, $request);

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $field = $this->resolveField($schema, (string) $validated['field'], $context->stateRecord());

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, 422, __('panda-panel::errors.no_file_uploaded'));

        // The field's own limits, applied to the real file rather than to
        // what the browser claimed before sending it.
        $rules = ['file' => ['file', 'max:'.$field->getMaxSize()]];

        if ($field->getAcceptedTypes() !== []) {
            $rules['file'][] = 'mimetypes:'.implode(',', $field->getAcceptedTypes());
        }

        validator(['file' => $file], $rules)->validate();

        $path = $file->store($field->getDirectory(), $field->getDisk());

        abort_if($path === false, 500, __('panda-panel::errors.file_not_stored'));

        return response()->json([
            'path' => $path,
            'name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * The field, resolved out of the schema that declared it.
     *
     * A name the schema does not have does not exist, and a field that is not
     * a `FileUpload` is not somewhere a file may be stored — both are refused
     * rather than defaulted.
     */
    private function resolveField(FormSchema $schema, string $name, ?Model $record = null): FileUpload
    {
        $field = $schema->field($name, $record);

        abort_if($field === null, 404, __('panda-panel::errors.unknown_field'));
        abort_unless($field instanceof FileUpload, 400, __('panda-panel::errors.field_rejects_files'));

        return $field;
    }
}
