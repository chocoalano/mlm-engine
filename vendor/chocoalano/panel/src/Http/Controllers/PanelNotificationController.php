<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use PandaPanel\Contracts\PanelNotifiable;
use PandaPanel\Notifications\Enums\NotificationColor;
use PandaPanel\Notifications\NotificationAction;

/**
 * The notification centre.
 *
 * Every route here is scoped to the authenticated user's own notifications by
 * construction: the query starts from `$request->user()`, so there is no id a
 * request could send that would reach somebody else's. That is why none of
 * these need a policy — the scope *is* the authorization.
 *
 * JSON rather than Inertia. The bell opens over whatever page is on screen,
 * and a full page response would replace it.
 */
final class PanelNotificationController
{
    /**
     * How many the bell holds. A notification centre is not an archive; past
     * a screenful nobody scrolls, and an unbounded query on a table that only
     * grows is a page that gets slower forever.
     */
    private const LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $this->notifiable($request);

        $notifications = $user->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'notifications' => $notifications
                ->map(fn (DatabaseNotification $notification): array => $this->serialize($notification))
                ->all(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marks one read, or every one when no id is given.
     */
    public function read(Request $request): JsonResponse
    {
        $user = $this->notifiable($request);

        $id = $request->input('id');

        if ($id === null) {
            $user->unreadNotifications()->update(['read_at' => now()]);
        } else {
            abort_unless(is_string($id), 422, __('panda-panel::errors.invalid_notification'));

            // Scoped to this user's notifications, so an id belonging to
            // somebody else matches nothing rather than 403s — which is the
            // same outcome and one fewer thing to leak.
            $user->notifications()->whereKey($id)->update(['read_at' => now()]);
        }

        return response()->json(['unread' => $user->unreadNotifications()->count()]);
    }

    /**
     * Removes them. Read ones by default, or all of them when asked.
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $this->notifiable($request);

        $query = $user->notifications();

        if ($request->boolean('all') !== true) {
            $query->whereNotNull('read_at');
        }

        $query->delete();

        return response()->json(['unread' => $user->unreadNotifications()->count()]);
    }

    /**
     * The signed-in user, as something with notifications on it.
     *
     * A user model that is not `Notifiable` cannot answer any of these
     * questions, and a 403 says so at the door rather than as a fatal three
     * lines later. Laravel's own `Notifiable` trait satisfies this, so an
     * ordinary application never sees it.
     *
     * The trait is accepted whether or not the model declares the interface:
     * `PanelNotifiable` names the requirement for static analysis, and a
     * native return type would refuse every user model written before the
     * interface existed.
     *
     * @return PanelNotifiable
     */
    private function notifiable(Request $request): object
    {
        $user = $request->user();

        abort_unless(
            $user instanceof PanelNotifiable || (is_object($user) && method_exists($user, 'unreadNotifications')),
            403,
        );

        /** @var PanelNotifiable $user */
        return $user;
    }

    /**
     * One stored row, narrowed to the shape the frontend renders.
     *
     * The row is JSON somebody wrote a week ago, which makes it untrusted in
     * exactly the way a request body is: a colour that is not one of the four
     * falls back rather than reaching a class name that does not exist, and
     * an action that does not parse is dropped rather than rendered.
     *
     * @return array<string, mixed>
     */
    private function serialize(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->getAttribute('data');

        $color = NotificationColor::tryFrom((string) ($data['color'] ?? '')) ?? NotificationColor::Info;

        $actions = [];

        foreach ((array) ($data['actions'] ?? []) as $action) {
            $parsed = is_array($action) ? NotificationAction::fromArray($action) : null;

            if ($parsed !== null) {
                $actions[] = $parsed;
            }
        }

        return [
            'id' => $notification->getKey(),
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
            'body' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'color' => $color->value,
            'icon' => is_string($data['icon'] ?? null) ? $data['icon'] : $color->icon(),
            'actions' => $actions,
            'read' => $notification->getAttribute('read_at') !== null,
            'createdAt' => $notification->getAttribute('created_at')?->diffForHumans(),
        ];
    }
}
