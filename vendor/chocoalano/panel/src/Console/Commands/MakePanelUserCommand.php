<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\PanelManager;
use Throwable;

/**
 * Creates an account that can sign into a panel.
 *
 * The gap this closes is small and unmissable: a fresh install has a panel,
 * routes, a login page — and no user, so the first thing anyone does is open
 * `tinker` and write a `create()` call from memory, guessing which columns
 * this application's user model actually has.
 *
 * The model is the auth guard's, not one this command names. A project with
 * `App\Models\Admin` behind a second guard gets its admin, and a project that
 * renamed the default gets that. Guessing `App\Models\User` would work for
 * most projects and fail silently — creating a row in the wrong table — for
 * exactly the ones that need this most.
 *
 * It reports, but does not refuse, when the new account cannot reach the panel
 * it was made for: `canAccess()` and `PanelUser::canAccessPanel()` are the
 * application's rules, and a user with `is_admin` still to be set is a normal
 * intermediate state rather than a mistake to block on.
 */
final class MakePanelUserCommand extends Command
{
    protected $signature = 'panel:user
        {--name= : The account name}
        {--email= : The email address to sign in with}
        {--password= : The password, prompted for when omitted}
        {--guard= : The auth guard whose user model to create, defaults to the application default}
        {--panel= : Report whether the new account can reach this panel}';

    protected $description = 'Create a user who can sign into a panel';

    public function handle(PanelManager $manager): int
    {
        $model = $this->resolveModel();

        if ($model === null) {
            return self::FAILURE;
        }

        $attributes = $this->gather();

        if ($attributes === null) {
            return self::FAILURE;
        }

        try {
            /** @var Model&Authenticatable $user */
            $user = new $model;
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                // A user created from the console has, by definition, been
                // verified by whoever ran the command. Leaving it null would
                // put them straight into the verify-email wall.
                'email_verified_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->components->error('The user could not be created: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Created %s <%s>.', $attributes['name'], $attributes['email']));

        $this->reportPanelAccess($manager, $user);

        return self::SUCCESS;
    }

    /**
     * Says plainly when the new account cannot get in.
     *
     * A panel refuses on two independent rules and both must agree, so the
     * message names which one said no — "the panel's own" and "your user
     * model's" are fixed in different files.
     */
    private function reportPanelAccess(PanelManager $manager, Authenticatable $user): void
    {
        $id = $this->stringOption('panel');
        $panels = $manager->all();

        // Named, or else the first registered — which is also the panel a
        // bare sign-in lands in, so it is the one an operator means when they
        // do not say.
        $panel = $id === null
            ? ($panels[array_key_first($panels)] ?? null)
            : ($manager->has($id) ? $manager->get($id) : null);

        if ($panel === null) {
            return;
        }

        if ($panel->isAccessibleTo($user)) {
            $this->components->info(sprintf('They can sign into the %s panel at %s.', $panel->getName(), $panel->getPath()));

            return;
        }

        $this->components->warn(sprintf(
            'They cannot reach the %s panel yet — %s says no. Set whatever that rule reads before signing in.',
            $panel->getName(),
            $user instanceof PanelUser && ! $user->canAccessPanel($panel)
                ? 'your user model\'s canAccessPanel()'
                : 'the panel\'s own canAccess()',
        ));
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    private function gather(): ?array
    {
        $attributes = [
            'name' => $this->stringOption('name') ?? $this->prompt('Name'),
            'email' => $this->stringOption('email') ?? $this->prompt('Email address'),
            'password' => $this->stringOption('password') ?? $this->prompt('Password', secret: true),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Deliberately not `Password::defaults()`: this is a console
            // command run by whoever owns the database, and a rule written for
            // public sign-up refusing an operator's own password is friction
            // with nothing behind it.
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return null;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * Prompts for one value.
     *
     * Not called `ask()`: `Command` already has one with a different
     * signature, and quietly changing the shape of an inherited method is how
     * a caller ends up passing a default into a parameter that means
     * something else.
     *
     * A non-interactive run answers with an empty string rather than
     * prompting into a pipe that will never reply. Validation then reports
     * which options the command needed, which is the right failure for a
     * script that forgot one.
     */
    private function prompt(string $label, bool $secret = false): string
    {
        if (! $this->input->isInteractive()) {
            return '';
        }

        return $secret
            ? password(label: $label)
            : text(label: $label, required: true);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The user model behind the guard, resolved through the auth config.
     *
     * @return class-string|null
     */
    private function resolveModel(): ?string
    {
        $config = $this->laravel->make('config');
        $guard = $this->stringOption('guard') ?? (string) $config->get('auth.defaults.guard', 'web');
        $providerName = $config->get("auth.guards.{$guard}.provider");

        if (! is_string($providerName)) {
            $this->components->error("No auth guard named [{$guard}].");

            return null;
        }

        $model = $config->get("auth.providers.{$providerName}.model");

        if (! is_string($model) || ! class_exists($model)) {
            $this->components->error("The [{$providerName}] user provider names no model this command can create.");

            return null;
        }

        return $model;
    }
}
