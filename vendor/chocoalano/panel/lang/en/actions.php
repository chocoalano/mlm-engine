<?php

declare(strict_types=1);

/*
 * Every string the panel's built-in actions put in front of somebody.
 *
 * Keys are grouped by the action that owns them, and an action's keys read in
 * the order the user meets them: the button, then the modal it opens, then
 * the sentence that confirms what happened. `relations` holds the variants a
 * relation manager needs, which differ from the top-level ones only where the
 * wording has to — deleting a related record is not the same as detaching it,
 * and a confirmation that says the wrong one is worse than none.
 */

return [
    'create' => [
        'label' => 'New :label',
        'modal_heading' => 'New :label',
        'submit' => 'Create',
        'success' => ':label created.',
    ],

    'edit' => [
        'label' => 'Edit',
    ],

    'view' => [
        'label' => 'View',
    ],

    'delete' => [
        'label' => 'Delete',
        'heading' => 'Delete this record?',
        'description' => 'This permanently removes the record. This cannot be undone.',
        'button' => 'Delete',
        'success' => 'Record deleted.',
    ],

    'delete_bulk' => [
        'label' => 'Delete selected',
        'heading' => 'Delete the selected records?',
        'description' => 'This permanently removes every selected record. This cannot be undone.',
        'button' => 'Delete',
        'success' => 'Selected records deleted.',
        'denied' => 'You may not delete every selected record.',
    ],

    'force_delete' => [
        'label' => 'Delete permanently',
        'heading' => 'Delete this record permanently?',
        'description' => 'This cannot be undone and the record cannot be restored afterwards.',
        'button' => 'Delete permanently',
        'success' => 'Record permanently deleted.',
    ],

    'force_delete_bulk' => [
        'label' => 'Delete selected permanently',
        'heading' => 'Delete the selected records permanently?',
        'description' => 'This cannot be undone and the records cannot be restored afterwards.',
        'button' => 'Delete permanently',
        'success' => 'Selected records permanently deleted.',
        'denied' => 'You may not permanently delete every selected record.',
    ],

    'restore' => [
        'label' => 'Restore',
        'success' => 'Record restored.',
    ],

    'restore_bulk' => [
        'label' => 'Restore selected',
        'success' => 'Selected records restored.',
        'denied' => 'You may not restore every selected record.',
    ],

    'replicate' => [
        'label' => 'Replicate',
        'heading' => 'Replicate this record?',
        'description' => 'A copy will be created. You can edit it afterwards.',
        'button' => 'Replicate',
        'success' => 'Record replicated.',
    ],

    'export' => [
        'label' => 'Export',
        'modal_heading' => 'Export records',
        'submit' => 'Export',
        'success' => 'Your export is ready.',
        'columns' => 'Columns',
        'format' => 'Format',
        'download' => 'Download',
        'completed' => 'Your export of :count records is ready.',
        'failed_title' => 'Export failed',
        'failed_body' => 'The file could not be written.',
    ],

    'import' => [
        'label' => 'Import',
        'modal_heading' => 'Import records',
        'submit' => 'Import',
        'description' => 'Upload a CSV or Excel file, then say which column is which.',
        'file' => 'File',
        'columns_section' => 'Columns',
        'required' => 'Required',
        'mapping_hint' => 'Leave a column blank to skip it. Blank columns are guessed from the headings.',
        'started' => 'Your import has started. You will be notified when it finishes.',
        'download_failed_rows' => 'Download failed rows',
        'error_heading' => 'Error',
        'completed' => 'Imported :count rows.',
        'completed_with_failures' => 'Imported :count rows. :failed could not be imported — download the report to see why.',
        'failed_title' => 'Import failed',
        'failed_body' => 'The file could not be read.',
        'missing_columns' => 'This file has no column for :missing, and :verb required. Its headings are: :headings. Rename the column in the file, or map it by hand before importing.',
        'missing_columns_verb' => '{1} it is|[2,*] they are',
        'no_headings' => '(none)',
    ],

    'relations' => [
        'create' => [
            'label' => 'New :title',
        ],

        'edit' => [
            'label' => 'Edit',
        ],

        'associate' => [
            'label' => 'Associate :title',
        ],

        'attach' => [
            'label' => 'Attach :title',
        ],

        'delete' => [
            'label' => 'Delete',
            'heading' => 'Delete this record?',
            'description' => 'This removes the record itself, not just its link to this one.',
            'button' => 'Delete',
            'success' => 'Record deleted.',
        ],

        'detach' => [
            'label' => 'Detach',
            'heading' => 'Detach this record?',
            'description' => 'The record itself is kept; only the link to it is removed.',
            'button' => 'Detach',
            'success' => 'Record detached.',
        ],

        'detach_bulk' => [
            'label' => 'Detach selected',
            'heading' => 'Detach the selected records?',
            'description' => 'The records themselves are kept; only the links to them are removed.',
            'button' => 'Detach',
            'success' => 'Selected records detached.',
            'denied' => 'You may not detach every selected record.',
        ],

        'dissociate' => [
            'label' => 'Dissociate',
            'heading' => 'Dissociate this record?',
            'description' => 'The record is kept but no longer belongs to this one.',
            'button' => 'Dissociate',
            'success' => 'Record dissociated.',
        ],
    ],

    'confirmation' => [
        'description' => 'This cannot be undone.',
    ],

    'bulk_denied' => 'You may not :action every selected record.',
];
