<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Testing\TestsActions;
use PandaPanel\Testing\TestsNotifications;
use PandaPanel\Testing\TestsSchemas;
use PandaPanel\Testing\TestsTables;

/*
|--------------------------------------------------------------------------
| Panel testing helpers — public API
|--------------------------------------------------------------------------
|
| Free functions rather than a trait, so they read the same inside a Pest
| closure as inside a class-based test, and so a test that wants one does not
| have to opt into all of them.
|
| Every one of these goes through the real schema, query, and action
| machinery. They are a nicer way to *ask*, never a second implementation of
| the answer: a helper that computed its own idea of what a table shows would
| pass while the table was broken.
|
| Shipped rather than kept in this repository's own `tests/`, because the
| question they answer — "would this user see this row", "is this field
| required", "can this action run" — is the same question in an application's
| suite as in this one, and an application that had to reimplement them would
| be reimplementing the panel's internals to test against them.
|
| Autoloaded through composer's `files`, so they are available in a test
| without an import and without a base class. Every one is guarded by
| `function_exists`, so an application that already has a `panelTable()` of
| its own keeps it.
|
| The classes behind them — `PandaPanel\Testing\TestsTables` and friends — are
| public too, for a test that wants to hold one rather than chain from a
| free function.
|
*/

if (! function_exists('panelTable')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelTable(string $resource): TestsTables
    {
        return TestsTables::for($resource);
    }
}

if (! function_exists('panelForm')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelForm(string $resource, string $page = 'create'): TestsSchemas
    {
        return TestsSchemas::form($resource, $page);
    }
}

if (! function_exists('panelInfolistLabels')) {
    /**
     * @param  class-string<PanelResource>  $resource
     * @return array<int|string, string>
     */
    function panelInfolistLabels(string $resource, Model $record): array
    {
        return TestsSchemas::infolistLabels($resource, $record);
    }
}

if (! function_exists('panelRecordActions')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelRecordActions(string $resource): TestsActions
    {
        return TestsActions::record($resource);
    }
}

if (! function_exists('panelTableActions')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelTableActions(string $resource): TestsActions
    {
        return TestsActions::table($resource);
    }
}

if (! function_exists('panelBulkActions')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelBulkActions(string $resource): TestsActions
    {
        return TestsActions::bulk($resource);
    }
}

if (! function_exists('panelInfolistActions')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelInfolistActions(string $resource): TestsActions
    {
        return TestsActions::infolist($resource);
    }
}

if (! function_exists('fakePanelNotifications')) {
    function fakePanelNotifications(): void
    {
        TestsNotifications::fake();
    }
}

if (! function_exists('assertPanelNotificationSentTo')) {
    function assertPanelNotificationSentTo(Authenticatable $user, ?string $title = null): void
    {
        TestsNotifications::assertSentTo($user, $title);
    }
}

if (! function_exists('assertNoPanelNotifications')) {
    function assertNoPanelNotifications(): void
    {
        TestsNotifications::assertNothingSent();
    }
}

if (! function_exists('assertPanelNotificationStoredFor')) {
    function assertPanelNotificationStoredFor(Authenticatable $user, ?string $title = null): void
    {
        TestsNotifications::assertStoredFor($user, $title);
    }
}

if (! function_exists('assertNoPanelNotificationsStoredFor')) {
    function assertNoPanelNotificationsStoredFor(Authenticatable $user): void
    {
        TestsNotifications::assertNothingStoredFor($user);
    }
}
