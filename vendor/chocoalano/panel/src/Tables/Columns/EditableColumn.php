<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for a column the user can write through.
 *
 * An editable cell is a write endpoint wearing a table's clothes, so it is
 * held to the same rules a form is and one more besides:
 *
 * - **Declared.** Only a column that is an `EditableColumn` can be written,
 *   and only the attribute it names. A request naming any other column is
 *   addressing something that does not exist.
 * - **Validated.** `validationRules()` is the server's, exactly as a form
 *   field's is. The control rendered in the cell is a convenience.
 * - **Authorized per record.** `Resource::canEdit($record)` is asked for the
 *   row being written, not once for the table.
 *
 * The extra rule is `disabledUsing()`: a cell can be read-only for some rows
 * and not others, and that has to be answered per record on the way out *and*
 * again on the way in — a disabled control is not a permission.
 */
abstract class EditableColumn extends Column
{
    /** @var list<mixed> */
    protected array $rules = [];

    /** @var (Closure(Model): bool)|null */
    protected ?Closure $disabledUsing = null;

    /** @var (Closure(mixed, Model): mixed)|null */
    protected ?Closure $mutateUsing = null;

    /** @var (Closure(mixed, Model): void)|null */
    protected ?Closure $updateUsing = null;

    /**
     * The attribute a write lands on, when it differs from the column name.
     */
    protected ?string $writeTo = null;

    /**
     * Additional Laravel rules. These are the source of truth; the control in
     * the cell only decides what is easy to type.
     *
     * @param  list<mixed>  $rules
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * @param  Closure(Model): bool  $callback
     */
    public function disabledUsing(Closure $callback): static
    {
        $this->disabledUsing = $callback;

        return $this;
    }

    /**
     * Transforms the validated value on its way into the record.
     *
     * @param  Closure(mixed, Model): mixed  $callback
     */
    public function mutateUsing(Closure $callback): static
    {
        $this->mutateUsing = $callback;

        return $this;
    }

    /**
     * Replaces the write entirely, for a value that is not a bare column —
     * a state machine, a service call, a related record.
     *
     * @param  Closure(mixed, Model): void  $callback
     */
    public function updateUsing(Closure $callback): static
    {
        $this->updateUsing = $callback;

        return $this;
    }

    public function writeTo(string $attribute): static
    {
        $this->writeTo = $attribute;

        return $this;
    }

    public function getWriteAttribute(): string
    {
        return $this->writeTo ?? $this->name;
    }

    /**
     * Asked on the way out to render the control, and again on the way in
     * before anything is written: a disabled control is not a permission.
     */
    public function isDisabledFor(Model $record): bool
    {
        return $this->disabledUsing !== null && ($this->disabledUsing)($record);
    }

    /**
     * The full Laravel rule set for a write to this cell.
     *
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        return [...$this->typeValidationRules(), ...$this->rules];
    }

    /**
     * Performs the write. The caller has authorized it and validated the
     * value.
     */
    public function write(Model $record, mixed $value): void
    {
        $value = $this->mutateUsing === null
            ? $this->castForWrite($value)
            : ($this->mutateUsing)($this->castForWrite($value), $record);

        if ($this->updateUsing !== null) {
            ($this->updateUsing)($value, $record);

            return;
        }

        $record->forceFill([$this->getWriteAttribute() => $value])->save();
    }

    /**
     * Rules implied by the control, such as `boolean` or `numeric`.
     *
     * @return list<mixed>
     */
    protected function typeValidationRules(): array
    {
        return [];
    }

    /**
     * Normalizes the submitted value before it reaches the record.
     */
    protected function castForWrite(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['editable' => true];
    }
}
