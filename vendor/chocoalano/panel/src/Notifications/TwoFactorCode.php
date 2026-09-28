<?php

declare(strict_types=1);

namespace PandaPanel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;

/**
 * The one-time code, in the account's own inbox.
 *
 * Queued, because sending it is the slowest part of signing in and nobody
 * should watch an SMTP handshake. The code is already in the cache by the
 * time this is dispatched, so a delayed mail delays the login rather than
 * breaking it.
 *
 * Deliberately not a panel notification: this is a credential going to one
 * address, not something to persist in a bell somebody else might open.
 */
final class TwoFactorCode extends LaravelNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('panda-panel::notifications.two_factor_code.subject'))
            ->line(__('panda-panel::notifications.two_factor_code.intro'))
            ->line('**'.$this->code.'**')
            ->line(__('panda-panel::notifications.two_factor_code.expiry'))
            ->line(__('panda-panel::notifications.two_factor_code.warning'));
    }
}
