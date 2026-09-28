<?php

declare(strict_types=1);

/*
 * What the panel says when a request cannot be served.
 *
 * Only the refusals a user can actually reach live here. A message that means
 * "this package is wired up wrong" — a missing policy, a duplicate column
 * name, a resource with no model — stays in `PandaPanel\Exceptions` in
 * English: its reader is a developer holding a stack trace, and translating
 * it would only make the same problem harder to search for.
 */

return [
    'action_no_form' => 'This action has no form.',
    'action_requires_form' => 'This action must be run through its form.',
    'action_not_executable' => 'This action cannot be executed.',
    'cell_not_editable' => 'That cell cannot be edited.',
    'column_not_editable' => 'That column is not editable.',
    'field_has_no_options' => 'That field has no options.',
    'field_rejects_files' => 'That field does not accept files.',
    'file_gone' => 'That file is no longer there.',
    'file_not_stored' => 'The file could not be stored.',
    'form_has_no_steps' => 'This form has no steps.',
    'invalid_field' => 'Invalid field.',
    'invalid_notification' => 'Invalid notification.',
    'invalid_page' => 'Invalid page.',
    'invalid_parent_key' => 'Invalid parent key.',
    'invalid_record_key' => 'Invalid record key.',
    'invalid_record_keys' => 'Invalid record keys.',
    'invalid_relation' => 'Invalid relation.',
    'invalid_resource' => 'Invalid resource.',
    'invalid_scope' => 'Invalid scope.',
    'no_export_owner' => 'That user has no key to file an export under.',
    'no_file_uploaded' => 'No file was uploaded.',
    'no_import_owner' => 'That user has no key to file an import under.',
    'no_panel' => 'No panel is resolved for this request.',
    'no_such_tenant' => 'No such tenant.',
    'not_notifiable' => 'The user model is not notifiable.',
    'not_reorderable' => 'This table is not reorderable.',
    'record_already_related' => 'That record is already in this relation.',
    'records_not_found' => 'Some records could not be found.',
    'two_factor_page_missing' => 'Two-factor authentication is required, but the security page is not registered.',
    'unknown_action' => 'Unknown action.',
    'unknown_bulk_action' => 'Unknown bulk action.',
    'unknown_column' => 'Unknown column.',
    'unknown_export' => 'Unknown export.',
    'unknown_field' => 'Unknown field.',
    'unknown_locale' => 'This panel is not offered in that language.',
    'unknown_import' => 'Unknown import.',
    'unknown_relation' => 'Unknown relation.',
    'unknown_relation_operation' => 'Unknown relation operation.',
    'unknown_resource' => 'Unknown resource.',
    'unknown_step' => 'Unknown step.',
    'unsupported_trigger' => 'That trigger is not one this resource fires.',

    /*
     * Read failures on a file somebody just uploaded. They reach the user as
     * the body of the "Import failed" notification, which is the only place
     * they will ever be read — so they name what is wrong with the file, not
     * what threw.
     */
    'spreadsheet' => [
        'unreadable' => 'That file is not a readable spreadsheet.',
        'read_failed' => 'That spreadsheet could not be read.',
        'no_sheet' => 'That workbook has no readable sheet.',
        'too_large' => 'That spreadsheet is too large to read safely.',
        'report_unwritable' => 'Cannot write the failure report.',
        'report_unreadable' => 'The failure report could not be read back.',
        'export_temp_failed' => 'Cannot create a temporary file for the export.',
        'export_unreadable' => 'The export file could not be read back.',
    ],

    /*
     * Shown beside the URL field on the integrations screen, so each one says
     * what to change rather than that something was refused.
     */
    'outbound_url' => [
        'not_a_url' => 'That is not a URL this can send a request to.',
        'unsupported_scheme' => 'Only http and https are supported, not :scheme.',
    ],
];
