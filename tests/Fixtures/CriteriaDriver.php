<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriver;

/**
 * A stand-in for an application's own component driver. It asks for a
 * `mode` parameter of "strict" or "lenient", and a strict component must have
 * at least one rule — its own rules, nothing the package requires. It
 * remembers what it was asked to validate.
 */
final class CriteriaDriver implements PlanComponentDriver
{
    /**
     * @var list<PlanComponentDefinition>
     */
    public array $validated = [];

    public function __construct(private readonly string $key = 'test.criteria') {}

    public function key(): string
    {
        return $this->key;
    }

    public function validate(PlanComponentDefinition $component): void
    {
        $this->validated[] = $component;
        $mode = $component->parameters['mode'] ?? null;

        if (! in_array($mode, ['strict', 'lenient'], true)) {
            throw InvalidPlanDefinition::input('parameter "mode"', 'the criteria driver needs "strict" or "lenient".');
        }

        if ($mode === 'strict' && $component->rules === []) {
            throw InvalidPlanDefinition::input('rules', 'a strict criteria component needs at least one rule.');
        }
    }
}
