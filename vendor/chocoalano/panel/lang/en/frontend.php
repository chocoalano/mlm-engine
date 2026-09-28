<?php

declare(strict_types=1);

/*
 * Every string the published Vue components put on screen.
 *
 * Separate from the other groups because it travels differently: this one
 * file is serialized into `usePage().props.translations` by `SharePanelData`,
 * and the components read it through `useTranslator()`. The rest of `lang/`
 * is read in PHP and never leaves the server.
 *
 * Keys are grouped by where they are read, not by what they say, so a
 * component's strings sit together and a group can be skimmed against the
 * screen it draws. Within a group they are ordered the way a reader meets
 * them.
 *
 * Only chrome is here. Every label that comes from a schema — a column
 * header, a field label, an action's button — is resolved on the server and
 * arrives inside the payload already translated, because that is where the
 * schema lives. See `PandaPanel\Support\Label`.
 */

return [

    /*
     * The shadcn primitives. Mostly screen-reader labels: a sighted user sees
     * an ✕, and everybody else hears whatever is written here.
     */
    'ui' => [
        'copy_command' => 'Copy the :name command',
        'close' => 'Close',
        'more' => 'More',
        'loading' => 'Loading',
        'sidebar' => 'Sidebar',
        'sidebar_description' => 'Displays the mobile sidebar.',
        'toggle_sidebar' => 'Toggle sidebar',
        'breadcrumb' => 'breadcrumb',
        'appearance' => 'Appearance',
        'appearance_light' => 'Light',
        'appearance_dark' => 'Dark',
        'appearance_system' => 'System',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'open' => 'Open',
    ],

    /*
     * The panel shell: header, sidebar, search, notifications, switchers.
     */
    'shell' => [
        'current_section' => 'Current section',
        'panel_navigation' => 'Panel navigation',
        'record_navigation' => 'Record navigation',
        'switch_panel' => 'Switch panel',
        'switch_panel_description' => 'The panels you have access to. You are in :panel.',
        'none' => 'none',
        'switch_tenant' => 'Switch tenant',
        'switch_language' => 'Language',
        'select' => 'Select',
        'light_mode' => 'Switch to light mode',
        'dark_mode' => 'Switch to dark mode',
        'unsaved_changes' => 'You have unsaved changes. Leave this page and lose them?',

        'search' => 'Search',
        'search_description' => "Search across this panel's resources.",
        'search_placeholder' => 'Search...',
        'search_too_short' => 'Type at least two characters.',
        'search_empty' => 'Nothing found.',
        'search_label' => 'Search this panel',
        'search_results' => 'Search results',
        'search_searching' => 'Searching…',
        'search_result_count' => ':count result(s)',
        'search_failed' => 'The search could not be run.',
        'search_stale' => 'Showing earlier results — the update failed.',
        'retry' => 'Try again',

        'notifications' => 'Notifications',
        'notification_center' => 'Notification center',
        'unread_count' => ':count unread',
        'mark_all_read' => 'Mark all read',
        'clear_read' => 'Clear read',
        'mark_as_read' => 'Mark as read',
        'read' => 'Read',
        'notifications_failed' => 'Notifications could not be loaded.',
        'notifications_empty' => 'Nothing here yet.',
    ],

    /*
     * The dashboard a panel has before anything is registered in it.
     */
    'dashboard' => [
        'this_panel' => 'This panel',
        'ready' => ':panel is ready. Its dashboard is empty.',
        'empty' => 'Nothing is registered to show here yet. Run either of these and it appears on this screen the next time you load it.',
        'add_widget' => 'Add a widget',
        'add_widget_description' => 'A figure, a chart, or a small table. Widgets are what a dashboard is made of.',
        'add_resource' => 'Add a resource',
        'add_resource_description' => 'A model with a table, a form, and its four pages. It joins the sidebar on its own.',
        'already_here' => 'Already here:',
    ],

    /*
     * The table: toolbar, header, rows, paging, and the two renderers.
     */
    'tables' => [
        'move_column' => 'Move :column',
        'sort_by' => 'Sort by :column',
        'reorder_row' => 'Reorder :record, position :position of :total',
        'reorder_announcement' => ':record moved to position :position of :total',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'select_row' => 'Select :record',
        'per_page' => ':count / page',
        'page_of' => 'Page :page of :pages',
        'remove_filter' => 'Remove :filter',
        'filter_from' => ':filter from',
        'filter_to' => ':filter to',
        'rule_value' => 'Value for :rule',
        'remove_rule' => 'Remove rule :number',
        'rule' => 'rule',
        'select_all_rows' => 'Select all rows on this page',
        'row_actions' => 'Row actions',
        'actions' => 'Actions',
        'reorder' => 'Reorder',
        'sort' => 'Sort',
        'filter' => 'Filter',
        'filters_not_applied' => 'Not applied yet.',
        'clear' => 'Clear',
        'apply' => 'Apply',
        'column' => 'Column',
        'condition' => 'Condition',
        'add_condition' => 'Add condition',
        'max_conditions' => 'Up to :count conditions.',
        'no_queryable_columns' => 'No columns available to filter by.',
        'from' => 'From',
        'to' => 'To',
        'layout' => 'Layout',
        'layout_table' => 'Table view',
        'layout_cards' => 'Card view',
        'search_table' => 'Search this table',
        'search_column' => 'Search :column',
        'no_results' => 'No results',
        'range' => ':from–:to of :total',
        'sorted_by' => 'Sorted by :column',
        'all' => 'All',
        'rows_per_page' => 'Rows per page',
        'previous_page' => 'Previous page',
        'next_page' => 'Next page',
        'previous' => 'Previous',
        'next' => 'Next',
    ],

    /*
     * Form chrome, and the fields that draw controls of their own.
     */
    'forms' => [
        'create_record' => 'Create :resource',
        'confirm_field' => 'Confirm :field',
        'search_field_placeholder' => 'Search :field…',
        'remove_file' => 'Remove :file',
        'move_item_up' => 'Move :item up',
        'move_item_down' => 'Move :item down',
        'remove_item' => 'Remove :item',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'back' => 'Back',
        'next' => 'Next',
        'loading' => 'Loading',
        'load_failed' => 'This form could not be loaded.',
        'tab_has_errors' => 'This tab has errors',
        'error_summary' => 'This form has :count error(s). Fix them and try again.',
        'no_renderer' => 'This field has no renderer.',
        'create_another' => 'Create & create another',

        'select_placeholder' => 'Select...',
        'select_empty' => 'Nothing to choose from.',
        'select_more' => '+:count more',
        'search_field' => 'Search :field',
        'select_no_matches' => 'Nothing matches that search.',
        'select_failed' => 'The list could not be loaded.',
        'select_stale' => 'Showing earlier results — the update failed.',
        'retry' => 'Try again',
        'checkbox_select_all' => 'Select all',
        'checkbox_deselect_all' => 'Deselect all',

        'add_row' => 'Add row',
        'remove' => 'Remove',
        'no_entries' => 'No entries yet.',

        'no_blocks' => 'No blocks yet.',
        'block_unavailable' => 'This block type is no longer available.',
        'move_block_up' => 'Move block up',
        'move_block_down' => 'Move block down',
        'remove_block' => 'Remove block',
        'collapse_section' => 'Collapse section',
        'expand_section' => 'Expand section',

        'pick_a_date' => 'Pick a date',
        'color_for_field' => ':field colour',
        'clear_date' => 'Clear date',
        'pick_a_time' => 'Pick a time',
        'clear_time' => 'Clear time',
        'date' => 'Date',
        'time' => 'Time',
        'date_and_time' => 'Date and time',
        'hour' => 'Hour',
        'minute' => 'Minute',
        'second' => 'Second',
        'uploads_unavailable' => 'This form cannot store files.',
        'link' => 'Link',
        'link_url' => 'Link URL',
        'plain_text' => 'Plain text',
        'write' => 'Write',
        'preview' => 'Preview',
        'editor_link' => 'Link',
        'editor_bullet_list' => '• List',
        'editor_ordered_list' => '1. List',
    ],

    /*
     * Actions, and the dialogs they open.
     */
    'actions' => [
        'cancel' => 'Cancel',
        'row_actions' => 'Row actions',
        'copy' => 'Copy',
    ],

    /*
     * Widgets: the chrome around whatever each one draws.
     */
    'widgets' => [
        'chart_summary' => ':type, :series series across :categories periods',
        'chart_line' => 'Line chart',
        'chart_bar' => 'Bar chart',
        'chart_area' => 'Area chart',
        'category' => 'Period :number',
        'category_empty' => ':category — no data',
        'chart_data' => 'Chart data',
        'show_data' => 'View data',
        'hide_data' => 'Hide data',
        'period' => 'Period',
        'trend_positive' => 'an improvement',
        'trend_negative' => 'a decline',
        'filters' => 'Filters',
        'unavailable' => 'This widget is unavailable.',
        'empty' => 'Nothing to show yet.',
        'no_data' => 'No data for this period.',
        'increased' => 'Increased',
        'decreased' => 'Decreased',
        'unchanged' => 'Unchanged',
        'search' => 'Search',
        'search_table' => 'Search this table',
        'previous' => 'Previous',
        'next' => 'Next',
    ],

    /*
     * The integrations screen: outbound requests a resource fires.
     */
    'integrations' => [
        'new_request' => 'New request',
        'request_name' => 'Request name',
        'trigger' => 'Trigger',
        'active' => 'Active',
        'off' => 'Off',
        'method' => 'Method',
        'url' => 'URL',
        'url_placeholder' => 'https://api.example.com/hooks/record',
        'save' => 'Save',
        'send' => 'Send',
        'delete' => 'Delete',
        'send_failed' => 'The request could not be made.',

        'no_hosts' => 'No destination is allowed yet. Add a host to',
        'no_hosts_after' => '; until then every URL here is refused when it is saved.',
        'empty' => 'No requests yet',
        'empty_description' => 'A request here is sent when a record is written.',

        'params' => 'Params',
        'headers' => 'Headers',
        'body' => 'Body',
        'signing' => 'Signing',
        'history' => 'History',
        'value' => 'Value',
        'bodies' => 'Bodies',
        'header' => 'Header',
        'parameter' => 'Parameter',
        'reveal' => 'Reveal',
        'hide' => 'Hide',
        'failed' => 'failed',
        'just_now' => 'just now',
        'signature_hmac' => ', an HMAC-SHA256 over',
        'template_hint' => 'is substituted from the payload. It is not Blade — paths only, no expressions.',

        'signature_intro' => 'Every request carries',
        'signature_middle' => 'using the secret below, and',
        'signature_after' => 'which is stable across the retries of one delivery so the receiver can deduplicate.',
        'signing_secret' => 'Signing secret',
        'rotate' => 'Rotate',
        'rotate_warning' => 'Rotating takes effect on the very next send. Update the receiving system first.',
        'history_empty' => 'Nothing sent yet',
        'history_empty_description' => 'Attempts appear here once this request fires.',
    ],

    /*
     * The screens Fortify answers: signing in, and getting back in.
     */
    'auth' => [
        'sign_in' => 'Sign in',
        'log_in' => 'Log in',
        'log_out' => 'Log out',
        'sign_up' => 'Sign up',
        'name' => 'Name',
        'email' => 'Email address',
        'email_placeholder' => 'email@example.com',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'new_password' => 'New password',
        'remember_me' => 'Remember me',
        'continue' => 'Continue',
        'sign_in_code' => 'Sign-in code',
        'register' => 'Register',
        'verify_email_title' => 'Verify email',
        'login_description' => 'Enter your details to continue to :brand.',
        'register_description' => 'Sign up to continue to :brand.',
        'email_code_description' => 'We sent a six-digit code to :email.',

        'forgot_password' => 'Forgot password',
        'forgot_password_link' => 'Forgot your password?',
        'forgot_password_description' => 'Enter your email and we will send you a reset link.',
        'email_reset_link' => 'Email reset link',
        'back_to_login' => 'Back to log in',
        'reset_password' => 'Reset password',

        'create_account' => 'Create account',
        'create_an_account' => 'Create an account',
        'have_account' => 'Already have an account?',
        'no_account' => "Don't have an account?",

        'verify_email' => 'Verify your email',
        'verify_email_description' => 'We sent you a link. Open it to finish signing in.',
        'verify_email_sent' => 'A new link has been sent to your address.',
        'resend_link' => 'Resend the link',

        'check_email' => 'Check your email',
        'code' => 'Code',
        'send_another_code' => 'Send another code',
        'wait_before_retry' => 'Wait :seconds seconds before asking again',

        'passkey_sign_in' => 'Sign in with a passkey',
        'passkey_authenticating' => 'Authenticating...',
        'or_continue_with_email' => 'Or continue with email',
    ],

    /*
     * The account pages every panel registers.
     */
    'settings' => [
        'save' => 'Save',
        'name' => 'Name',
        'full_name' => 'Full name',
        'email' => 'Email address',
        'email_unverified' => 'Your email address is unverified.',
        'email_resend' => 'Click here to re-send the verification email.',
        'email_resent' => 'A new verification link has been sent to your email address.',

        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm password',

        'passkeys' => 'Passkeys',
        'passkeys_description' => 'Manage your passkeys for passwordless sign-in',
        'passkeys_empty' => 'No passkeys yet',
        'passkeys_empty_description' => 'Add a passkey to sign in without a password',

        'two_factor' => 'Two-factor authentication',
        'two_factor_description' => 'Manage your two-factor authentication settings',
        'two_factor_disabled_description' => 'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
        'two_factor_enabled_description' => 'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
        'two_factor_enable' => 'Enable 2FA',
        'two_factor_disable' => 'Disable 2FA',
        'two_factor_continue_setup' => 'Continue setup',

        'email_codes' => 'Email codes',
        'email_codes_on' => 'A one-time code is sent to your email address each time you sign in on a new session.',
        'email_codes_off' => 'Send a one-time code to your email address when signing in.',
        'turn_on' => 'Turn on',
        'turn_off' => 'Turn off',

        'delete_account' => 'Delete account',
        'delete_account_description' => 'Delete your account and all of its resources',
        'delete_account_warning_heading' => 'Warning',
        'delete_account_warning' => 'Please proceed with caution, this cannot be undone.',
        'delete_account_confirm' => 'Are you sure you want to delete your account?',
        'delete_account_explanation' => 'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
        'password' => 'Password',
        'cancel' => 'Cancel',
    ],
];
