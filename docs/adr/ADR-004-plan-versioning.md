# ADR-004: A plan is a stable identity; its versions are numbered revisions

## Status

Accepted — Phase 1.1. Amended in Phase 2.4: the definition tables exist (ADR-014).

## Context

A compensation plan changes over time, but results already calculated under an earlier form of it must stay explainable. What stays constant (which plan this is) has to be separated from what changes (its definition).

## Decision

- `Plan` holds identity only: `program_id`, `code`, `name`. It belongs to one program (ADR-002). Its `code` is unique within the program — `UNIQUE (program_id, code)` — and reusable across programs.
- `PlanVersion` is one numbered revision of a plan, and the only place a plan's definition will live. Future planning rule tables reference `plan_version_id`, never `plan_id`.
- A version number is a positive integer, unique within its plan — `UNIQUE (plan_id, version)`. No semantic versions (`1.0.0`, `v2`, `2026.1`).
- `PlanVersionLifecycle::draft()` allocates the number: one past the plan's highest version, under a lock on the plan row. `PlanVersion` is fully guarded, so a number cannot be chosen by mass assignment.
- No settings, rules, JSON, driver or PHP class names on either table in this phase. Nothing executable will ever be stored. Since Phase 2.4 a version's definition lives in its own tables — components with a driver key and JSON parameters, rules with a JSON condition tree — still without class names or anything executable (ADR-014).
- Both foreign keys restrict deletion: a program with plans, or a plan with versions, cannot be deleted.

## Consequences

- A calculation can always name the exact version it used.
- A number that ever reached validated is never reused: locked versions cannot be deleted (ADR-005), and a new number is always above every remaining one. Only a discarded draft's number can be allocated again, and nothing ever depended on a draft.
- Two tables where one would hold less data. The split is what lets a definition change without rewriting history.
