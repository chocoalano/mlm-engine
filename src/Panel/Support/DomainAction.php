<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaPanel\Actions\Action;

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
     * Runs the service. A refusal of an expected kind goes back to the
     * operator as the reason the service gave; anything else — a corrupt
     * record, a failing database — is not the operator's to read and fails
     * the request as it would anywhere.
     *
     * @param  list<class-string<DomainException>>  $expected
     */
    public static function attempt(string $name, array $expected, Closure $operation): void
    {
        try {
            $operation();
        } catch (DomainException $exception) {
            foreach ($expected as $class) {
                if ($exception instanceof $class) {
                    throw new HttpResponseException(back()->with('error', __('mlm::mlm.errors.refused', [
                        'action' => self::label($name),
                        'reason' => $exception->getMessage(),
                    ])));
                }
            }

            throw $exception;
        }
    }

    private static function label(string $name): string
    {
        return __("mlm::mlm.actions.{$name}.label");
    }
}
