import type { FilterDefinition, QueryBuilderRule } from '@/panel/types/table';

/**
 * Which query-builder rules the server will actually run.
 *
 * The server drops a rule it cannot execute — a comparison with no value to
 * compare against is not a condition, and running it would filter by
 * something the user never described. That is right, and this does not soften
 * it.
 *
 * What it fixes is the consequence. A rule the user has only just added has
 * no value yet, so posting it and adopting the server's answer deleted the
 * row they were about to fill in — which made "Add condition" unusable in the
 * default immediate mode. The editor needs to keep an incomplete rule while
 * sending only the complete ones.
 *
 * The rule below mirrors `Constraint::accepts()` exactly: an operator that
 * needs no value is complete on its own, and one that needs a value needs a
 * non-empty scalar. `needsValue` is already serialized per operator, so both
 * sides read the same declaration rather than two guesses about it.
 */
export function needsValue(
    filter: FilterDefinition,
    rule: QueryBuilderRule,
): boolean {
    if (filter.type !== 'query_builder') {
        return false;
    }

    return (
        filter.constraints
            .find((constraint) => constraint.name === rule.column)
            ?.operators.find((operator) => operator.value === rule.operator)
            ?.needsValue ?? false
    );
}

/** Whether the server would accept this rule as an executable condition. */
export function isComplete(
    filter: FilterDefinition,
    rule: QueryBuilderRule,
): boolean {
    if (!needsValue(filter, rule)) {
        return true;
    }

    const value = rule.value;

    return (
        (typeof value === 'string' || typeof value === 'number') && value !== ''
    );
}

/** Only the rules worth sending. */
export function completeRules(
    filter: FilterDefinition,
    rules: QueryBuilderRule[],
): QueryBuilderRule[] {
    return rules.filter((rule) => isComplete(filter, rule));
}

/**
 * Whether this list still holds something the server would refuse.
 *
 * The editor keeps its own copy only while that is true. Once every rule is
 * executable the server's answer says the same thing, and holding a second
 * copy of it would be a way for the two to disagree.
 */
export function hasIncomplete(
    filter: FilterDefinition,
    rules: QueryBuilderRule[],
): boolean {
    return rules.some((rule) => !isComplete(filter, rule));
}
