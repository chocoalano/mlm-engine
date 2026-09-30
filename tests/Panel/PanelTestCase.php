<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use PandaBear\Mlm\PandaMlmPlugin;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use Throwable;

/**
 * A panel carrying the plugin, routed and current, with capabilities
 * granted the way an application grants them: as Gate abilities, never as
 * roles.
 */
abstract class PanelTestCase extends DatabaseTestCase
{
    protected Panel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panel = Panel::make('mlm')->path('mlm')->plugins([PandaMlmPlugin::make()]);

        $manager = $this->app->make(PanelManager::class);
        $manager->register($this->panel);
        $this->app->make(PanelRouteRegistrar::class)->register($this->panel);
        $this->app->make('router')->getRoutes()->refreshNameLookups();
        $manager->setCurrentPanel($this->panel);

        // A refused request is answered the way the application answers it:
        // a 403 page, a redirect carrying the operator's message.
        $this->withExceptionHandling();

        // What a permission package does: an ability the user holds is
        // allowed, anything else falls through to Laravel's own deny.
        Gate::before(static function (?Authenticatable $user, string $ability): ?bool {
            $granted = $user instanceof GenericUser ? (array) $user->permissions : [];

            return in_array($ability, $granted, true) ? true : null;
        });
    }

    /**
     * A panel request runs the `web` group, whose cookies are encrypted.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
    }

    protected function grant(string ...$permissions): Authenticatable
    {
        $user = new GenericUser(['id' => 1, 'name' => 'Operator', 'permissions' => $permissions]);

        $this->actingAs($user);

        return $user;
    }

    protected function grantAll(): Authenticatable
    {
        return $this->grant(...MlmPermission::all());
    }

    protected function grantViewOnly(): Authenticatable
    {
        return $this->grant(...MlmPermission::view());
    }

    /**
     * Runs a callback expected to be refused by the domain, and returns the
     * message the operator is shown.
     */
    protected function refusal(callable $callback): string
    {
        try {
            $callback();
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $message = $response instanceof RedirectResponse ? $response->getSession()?->get('error') : null;

            $this->assertIsString($message, 'The refusal carried no operator message.');

            return $message;
        } catch (Throwable $exception) {
            $this->fail('Expected an operator-facing refusal, got '.$exception::class.': '.$exception->getMessage());
        }

        $this->fail('The action was not refused.');
    }
}
