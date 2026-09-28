<?php

declare(strict_types=1);

namespace PandaPanel\Testing;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Tables\TableSchema;
use PHPUnit\Framework\Assert;

/**
 * Running and asserting on actions the way the endpoint does.
 *
 * Every lookup here goes through the same schema the controller resolves
 * against, so an action this helper can find is an action the endpoint can
 * find — and one it cannot find is one a request could not run either. A
 * helper that reached for the action by class name would prove nothing about
 * whether the panel actually offers it.
 *
 * Authorization is asked the same way too. `assertCanRun()` fails for an
 * action the user may not run, which is the assertion worth writing: the
 * button being absent is what a test should be able to check.
 */
final class TestsActions
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  'record'|'table'|'bulk'|'infolist'  $scope
     */
    private function __construct(
        private readonly string $resource,
        private readonly string $scope,
    ) {}

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function record(string $resource): self
    {
        return new self($resource, 'record');
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function table(string $resource): self
    {
        return new self($resource, 'table');
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function bulk(string $resource): self
    {
        return new self($resource, 'bulk');
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function infolist(string $resource): self
    {
        return new self($resource, 'infolist');
    }

    /**
     * The action, or null when the schema never declared it.
     */
    public function find(string $name): ?Action
    {
        $schema = $this->resource::table(TableSchema::make());

        return match ($this->scope) {
            'record' => $schema->getRecordAction($name),
            'table' => $schema->getTableAction($name),
            'bulk' => $schema->getBulkAction($name),
            default => $this->resource::infolist(InfolistSchema::make())->getAction($name),
        };
    }

    /**
     * Runs it, exactly as the endpoint would.
     *
     * Authorization is checked first and fails the test rather than being
     * skipped: a helper that ran an action the user may not run would prove
     * the handler works and nothing about whether it is reachable.
     *
     * @param  array<string, mixed>  $data  what the action's own form submitted
     */
    public function call(string $name, ?Model $record = null, array $data = []): self
    {
        $action = $this->findOrFail($name);

        Assert::assertTrue(
            $action->isAuthorizedFor($record),
            sprintf('The action [%s] is not authorized for that record.', $name),
        );

        if ($record === null) {
            $action->executeWithoutRecord($data);

            return $this;
        }

        $action->execute($record, $data);

        return $this;
    }

    public function assertExists(string $name): self
    {
        $this->findOrFail($name);

        return $this;
    }

    public function assertDoesNotExist(string $name): self
    {
        Assert::assertNull($this->find($name), sprintf(
            'The %s actions of [%s] include [%s], and were not expected to.',
            $this->scope,
            $this->resource,
            $name,
        ));

        return $this;
    }

    /**
     * Whether this action is offered for this record at all.
     *
     * Visible *and* authorized, because that is what `Action::toArray()`
     * answers — an action refused for a record is absent from the row rather
     * than a button that answers 403.
     */
    public function assertVisible(string $name, ?Model $record = null): self
    {
        Assert::assertNotNull(
            $this->findOrFail($name)->toArray($record),
            sprintf('The action [%s] is not offered for that record.', $name),
        );

        return $this;
    }

    public function assertHidden(string $name, ?Model $record = null): self
    {
        $action = $this->find($name);

        Assert::assertTrue(
            $action === null || $action->toArray($record) === null,
            sprintf('The action [%s] is offered for that record, and was not expected to be.', $name),
        );

        return $this;
    }

    public function assertCanRun(string $name, ?Model $record = null): self
    {
        Assert::assertTrue(
            $this->findOrFail($name)->isAuthorizedFor($record),
            sprintf('The action [%s] is not authorized for that record.', $name),
        );

        return $this;
    }

    public function assertCanNotRun(string $name, ?Model $record = null): self
    {
        Assert::assertFalse(
            $this->findOrFail($name)->isAuthorizedFor($record),
            sprintf('The action [%s] is authorized for that record, and was not expected to be.', $name),
        );

        return $this;
    }

    /**
     * The action, failing the test rather than returning null.
     *
     * Every assertion here needs the action before it can say anything about
     * it, and "there is no such action" is a better failure than a null
     * dereference three lines later.
     */
    private function findOrFail(string $name): Action
    {
        $action = $this->find($name);

        Assert::assertNotNull($action, sprintf(
            'The %s actions of [%s] do not include [%s].',
            $this->scope,
            $this->resource,
            $name,
        ));

        return $action;
    }
}
