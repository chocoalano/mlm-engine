# ADR-015: Qualification evaluates one chosen rule, exactly and completely, and explains it

## Status

Accepted — Phase 2.5. Amended in Phase 2.6: `RankEngine` composes explicit qualification evaluations, one per rank of a ladder (ADR-016); qualification itself is unchanged.

## Context

Plan versions hold rules in a safe condition language (ADR-014), but nothing executed them. Qualification needs to answer "does this member meet this rule over this range?" — reproducibly, for current and historical versions, and in a form that can be explained to the member and audited later. Ranks and commission will build on the answer; they must not be decided here.

## Decision

- **One explicitly chosen rule.** `QualificationEngine::evaluate(PlanRule $rule, QualificationContext $context)` evaluates the stored rule the caller passes. It never selects a plan, an active version or a rule, and gives no meaning to a component with several rules — all, any, first, best — which stays undefined until a later phase needs one.
- **Context.** `QualificationContext` is a member and an optional `[from, until)` range — `from` included, `until` excluded, either open, and `from` before `until` when both are given — the same rule and validation as metric contexts. There is no period model. Every condition of the rule receives the same range; metric parameters come from the conditions alone.
- **Validated versions only.** A draft's rule is refused: its definition may still change. A rule of any validated version — validated, published, active, superseded, archived — can be evaluated, so a past decision can be reproduced from the version it was made under. An old rule is never swapped for the current active one.
- **Stored state only.** The rule, its component, version, plan and program, and the member are re-read from the database. An instance changed in memory — another component, another status, another program — changes nothing.
- **Program boundary.** The member must belong to the program of the rule's plan. Another program's member is an invalid request, refused with an exception, never a `false`.
- **Rules are read through the safe parser.** The definition is `$rule->definition`, parsed by the rule language (ADR-014). There is no entry point for a caller's own tree, raw JSON or arrays. Component drivers are not run: they validate configuration only.
- **Metrics through the engine.** Every condition is resolved by `MetricEngine::resolve()` with a new `MetricContext` — the stored member, the condition's parameters, the context's range. The engine never names a metric; the registry remains the extension point, so an application's `PlanConfigurableMetric` is evaluated like a built-in. Network history and reversal attribution stay entirely inside the metrics (ADR-013). No resolution is cached: two conditions naming the same metric and parameters resolve twice. A cache would need its own semantics first.
- **Exact comparisons.** Values and operands are `MetricValue`s, compared with `MetricValue::compare()` over the exact `Quantity::compare()` — never through floats or as text. `!=`, `>`, `>=`, `<`, `<=` compare with the one operand; `in` holds when the value equals one of the operands, `not_in` when it equals none; `between` holds from the lower to the upper bound, both included.
- **Groups without short-circuit.** `all` holds when every child holds, `any` when at least one does, recursively. Every child of every group is evaluated, even once the group is decided, so every condition appears in the trace with its value — there is no "skipped" state.
- **The decision.** `QualificationDecision` is plain data: `qualified`, the ids and codes of the program, plan and member, the version's id and number, the component and rule keys, the range, and the root of the trace. Group traces carry path, match, outcome and children; condition traces carry path, metric, parameters, the resolved value, operator, operands and outcome. Paths use the rule language's own vocabulary — `root`, `root.children[1].children[0]`. Numbers are canonical decimal strings. There is no evaluation time: the same stored data and context give the same `toArray()`, whatever the clock.
- **Errors are not decisions.** `qualified` is false only when the rule was evaluated and does not hold. Anything that prevents evaluation throws `QualificationEvaluationException`, naming version, component, rule, condition path and metric — never parameter values — and keeping the cause: a draft, another program, a missing record, a stored definition outside the language, a metric no longer registered (a version validated with an application metric that the application has since stopped registering), a metric that fails to resolve.
- **Reads only, stores nothing.** No table, migration or result record; nothing is written. The engine is stateless and resolved through the container.

## Consequences

- Any validated rule can be evaluated for any member of its program over any range, now or later, with a full explanation — the building block ranks and commission will need.
- Evaluating a large rule costs one metric resolution per condition, repeated conditions included. Worth measuring before adding a cache with explicit semantics.
- The metric reads of one evaluation are separate queries, not one snapshot: a write committed while a rule is being evaluated may be visible to some conditions and not others.
- A decision is not recorded. Persisting decisions, choosing between rules, ranks, calculation runs and periods are later phases.
