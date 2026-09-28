# ADR-005: The plan version lifecycle is forward-only, and activation is atomic

## Status

Accepted — Phase 1.1.

## Context

A plan version is edited, checked, released and put into force, and later replaced. Results depend on which version was in force, so the lifecycle must never run backwards, a released definition must never change, and a plan must never have two versions in force at once.

## Decision

- **States**: `draft → validated → published → active → superseded → archived`, stored as those lowercase strings in `status` (`PlanVersionStatus`). Every state has exactly one successor; `archived` has none. No skipping, no going back.
- **Timestamps**: entering a state stamps its column once — `validated_at`, `published_at`, `activated_at`, `superseded_at`, `archived_at`. A later state never stamps an earlier column, and a stamp never changes afterwards.
- **Immutability boundary**: only a `draft` is mutable. `validated` is locked too: re-validation after an edit needs rule tables that do not exist yet, and until then an editable validated version would be "validated" in a form nobody checked. `PlanVersion::isMutable()` and `assertMutable()` are what future rule tables call before changing a version's definition. A locked version cannot be deleted.
- **One supported writer**: `PlanVersionLifecycle` creates versions and moves them: `draft()`, `markValidated()`, `publish()`, `activate()`, `archive()`. There is no public supersede. The model refuses mass assignment of any column and refuses a plain Eloquent save that changes `plan_id`, `version`, `status` or a lifecycle timestamp. A version is always created as a draft.
- **Activation** runs in one transaction on the package connection. It locks the plan row, re-reads the target version, and requires it to be `published` and newer than the active version. It supersedes the active version and activates the target at the same instant. Every write is a compare-and-set on the expected status.
- **Only archive what was replaced**: `archive()` accepts only a `superseded` version.
- **No redundant state**: no `is_active` flag and no `active_version_id` on the plan. `status` is the single source of truth, and `Plan::currentActiveVersion()` returns zero or one — more than one throws `MultipleRecordsFoundException` rather than picking one.

## Consequences

- Through the supported API, a plan has at most one active version, activation never moves to an older version, and a failed activation changes nothing.
- Concurrent activations of one plan serialise on the plan row (`SELECT … FOR UPDATE` on MySQL and PostgreSQL; SQLite serialises writers itself).
- Query-builder writes and raw SQL bypass every Eloquent guard — the lifecycle itself writes through the query builder. The one-active invariant is not a database constraint: a portable partial unique index does not exist across SQLite, MySQL and PostgreSQL.
- A caller holding a model instance of a version that another activation superseded has a stale copy until it refreshes. The lifecycle always decides from the database, never from the instance.
