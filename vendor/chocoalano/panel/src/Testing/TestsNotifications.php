<?php

declare(strict_types=1);

namespace PandaPanel\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use PandaPanel\Notifications\PanelNotificationSent;
use PHPUnit\Framework\Assert;

/**
 * Asserting on panel notifications.
 *
 * Two channels, and a test usually means one of them: a *toast* is the
 * broadcast event, and a *persisted* notification is a row. They are asserted
 * separately because they answer different questions — "was the user told"
 * and "can they find it later" — and a notification can legitimately be one
 * without the other.
 *
 * The broadcast assertions need `Event::fake([PanelNotificationSent::class])`
 * before the code under test. Faking every event would silence the model
 * events the panel relies on, which is why the fake is narrow.
 */
final class TestsNotifications
{
    /**
     * Starts recording broadcasts. Call before the code under test.
     */
    public static function fake(): void
    {
        Event::fake([PanelNotificationSent::class]);
    }

    /**
     * A toast reached this user, optionally with this title.
     */
    public static function assertSentTo(Authenticatable $user, ?string $title = null): void
    {
        Event::assertDispatched(
            PanelNotificationSent::class,
            static function (PanelNotificationSent $event) use ($user, $title): bool {
                if ($event->user->getAuthIdentifier() !== $user->getAuthIdentifier()) {
                    return false;
                }

                return $title === null || ($event->payload['title'] ?? null) === $title;
            },
        );
    }

    public static function assertNothingSent(): void
    {
        Event::assertNotDispatched(PanelNotificationSent::class);
    }

    /**
     * A row this user can find later.
     *
     * Read out of the database rather than from the event, because that is
     * the question: a broadcast that was not persisted is gone the moment the
     * tab closes.
     */
    public static function assertStoredFor(Authenticatable $user, ?string $title = null): void
    {
        $stored = self::stored($user);

        Assert::assertNotEmpty($stored, 'That user has no stored notifications.');

        if ($title === null) {
            return;
        }

        Assert::assertContains($title, $stored, sprintf(
            'That user has no stored notification titled [%s].',
            $title,
        ));
    }

    public static function assertNothingStoredFor(Authenticatable $user): void
    {
        Assert::assertSame([], self::stored($user), 'That user has stored notifications.');
    }

    /**
     * @return list<string>
     */
    private static function stored(Authenticatable $user): array
    {
        if (! method_exists($user, 'notifications')) {
            return [];
        }

        return $user->notifications()
            ->get()
            ->map(static fn ($notification): string => (string) ($notification->data['title'] ?? ''))
            ->values()
            ->all();
    }
}
