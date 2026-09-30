<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use ArrayObject;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
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
     * Submits a table-level action's form — a list's "new" action — the way
     * the dialog does.
     *
     * @param  array<string, mixed>  $data
     */
    protected function submitTable(string $resource, string $action, array $data = []): TestResponse
    {
        return $this->post('/mlm/actions/form', ['resource' => $resource, 'action' => $action, 'scope' => 'table', ...$data]);
    }

    /**
     * Submits a detail page's action form.
     *
     * @param  array<string, mixed>  $data
     */
    protected function submitRecord(string $resource, string $action, Model $record, array $data = [], string $scope = 'infolist'): TestResponse
    {
        return $this->post('/mlm/actions/form', ['resource' => $resource, 'action' => $action, 'scope' => $scope, 'record' => $record->getKey(), ...$data]);
    }

    /**
     * Runs a detail page's action that has no form.
     */
    protected function runRecord(string $resource, string $action, Model $record, string $endpoint = 'infolist'): TestResponse
    {
        return $this->post("/mlm/actions/{$endpoint}", ['resource' => $resource, 'action' => $action, 'record' => $record->getKey()]);
    }

    /**
     * Submits a relation table's action form: its header action, or a row's.
     *
     * @param  array<string, mixed>  $data
     */
    protected function submitRelation(string $resource, Model $owner, string $relation, string $action, array $data = [], ?Model $related = null): TestResponse
    {
        return $this->post('/mlm/relations/action-form?'.http_build_query(array_filter([
            'resource' => $resource,
            'record' => $owner->getKey(),
            'relation' => $relation,
            'action' => $action,
            'scope' => $related === null ? 'table' : 'record',
            'related' => $related?->getKey(),
        ])), $data);
    }

    /**
     * Runs a relation table row's action that has no form.
     */
    protected function runRelation(string $resource, Model $owner, string $relation, string $action, Model $related): TestResponse
    {
        return $this->post('/mlm/relations/action', [
            'resource' => $resource,
            'record' => $owner->getKey(),
            'relation' => $relation,
            'action' => $action,
            'scope' => 'record',
            'related' => $related->getKey(),
        ]);
    }

    /**
     * Counts how often each class is resolved from the container — proof an
     * action went through the service rather than around it.
     *
     * @param  list<class-string>  $classes
     * @return ArrayObject<class-string, int>
     */
    protected function spyOn(array $classes): ArrayObject
    {
        $resolved = new ArrayObject(array_fill_keys($classes, 0));

        foreach ($classes as $class) {
            $this->app->resolving($class, static function () use ($resolved, $class): void {
                $resolved[$class]++;
            });
        }

        return $resolved;
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
