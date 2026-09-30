<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaPanel\Actions\Action;
use Throwable;

/**
 * An operator action that runs one domain service (ADR-031).
 *
 * The panel collects intent, asks the operate capability, calls the service,
 * and shows what the service refused. It decides nothing itself: whether a
 * step is allowed is the service's answer, asked under its own locks.
 *
 * The service owns its transaction. The panel would otherwise wrap the
 * handler in one, and the period calculator refuses to run inside a
 * caller's transaction.
 */
final class DomainAction
{
    public static function make(string $name, string $permission): Action
    {
        return Action::make($name)
            ->label(self::label($name))
            ->successMessage(__("mlm::mlm.actions.{$name}.success"))
            ->databaseTransaction(false)
            ->authorize(static fn (?Model $record = null): bool => MlmPermission::allows($permission));
    }

    /**
     * The action with its confirmation: what it does to the record, and
     * whether money moves.
     */
    public static function confirmed(string $name, string $permission): Action
    {
        return self::make($name, $permission)->requiresConfirmation(
            heading: __("mlm::mlm.actions.{$name}.heading"),
            description: __("mlm::mlm.actions.{$name}.description"),
            button: self::label($name),
        );
    }

    /**
     * The action with a form of its own, its explanation in the dialog.
     */
    public static function withForm(string $name, string $permission): Action
    {
        return self::make($name, $permission)
            ->modalHeading(__("mlm::mlm.actions.{$name}.heading"))
            ->modalDescription(__("mlm::mlm.actions.{$name}.description"))
            ->modalSubmitLabel(self::label($name));
    }

    /**
     * Runs the service, once the capability is asked again here — the panel
     * asks it before running an action, and a handler that relied on that
     * alone would run for any request that reached it another way.
     *
     * A refusal of an expected kind goes back to the operator as the reason
     * the service gave; anything else — a corrupt record, a failing
     * database — is not the operator's to read and fails the request as it
     * would anywhere.
     *
     * @param  list<class-string<Throwable>>  $expected
     */
    public static function attempt(string $name, string $permission, array $expected, Closure $operation): mixed
    {
        abort_unless(MlmPermission::allows($permission), 403);

        try {
            return $operation();
        } catch (Throwable $exception) {
            foreach ($expected as $class) {
                if ($exception instanceof $class) {
                    throw new HttpResponseException(back()->withInput()->with('error', __('mlm::mlm.errors.refused', [
                        'action' => self::label($name),
                        'reason' => $exception->getMessage(),
                    ])));
                }
            }

            throw $exception;
        }
    }

    /**
     * A link to another screen, offered only to who may open it.
     *
     * @param  Closure(Model): string  $url
     * @param  Closure(): bool  $canOpen
     */
    public static function link(string $name, string $label, Closure $url, Closure $canOpen): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('eye')
            ->url($url)
            ->authorize(static fn (?Model $record = null): bool => $canOpen());
    }

    /**
     * A whole number the form's `integer` rule accepted, as one — anything
     * else goes to the service as it came, for the service to refuse.
     */
    public static function integer(mixed $value): mixed
    {
        $integer = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $integer === false ? $value : $integer;
    }

    private static function label(string $name): string
    {
        return __("mlm::mlm.actions.{$name}.label");
    }
}
