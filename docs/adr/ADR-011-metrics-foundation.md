# ADR-011: Metrics are trusted runtime computations resolved by key

## Status

Accepted — Phase 2.1.

## Context

Qualification, ranks and compensation will all ask numeric questions about members: how much volume, how many of something, over what period. Plans need to refer to those questions by name, applications need to add their own, and none of it may become code or SQL stored in the database.

## Decision

- **A metric is a computation, not stored state.** `Metric::resolve(MetricContext): MetricValue` computes a numeric fact from the package's source data — volume entries, genealogy, members — each time it is asked. There are no metric tables and no cache. A projection waits for evidence that one is needed.
- **A metric is not a qualification.** It answers "what is the value", never "does the member qualify". Thresholds, comparisons and combinations belong to a later rule layer.
- **Trusted code, registered by key.** `MetricRegistry` holds the metrics an application can resolve, by stable machine key: 1–100 lowercase letters, digits, `.`, `-` and `_`, never a class name. It is an application singleton filled at bootstrap — the package's built-ins through the same `register()` any application uses, then whatever service providers add with `callAfterResolving(MetricRegistry::class, …)`. Resolving never changes it.
- **Duplicates fail, unknowns fail.** A second metric claiming a registered key is refused with `DuplicateMetric`, so which code runs never depends on the order providers boot in; applications choose namespaced keys (`acme.retention`). An unknown key throws `UnknownMetric` — unknown is not zero.
- **One engine, no switch.** `MetricEngine::resolve($key, $context)` asks the registry and delegates. It knows no metric by name, built-ins included.
- **Context.** `MetricContext` carries the member, the metric's own named parameters, and an optional effective range `[from, until)` — the same rule and the same validation as volume totals: normalised to the application's timezone to the second, and `from` must come before `until`. No plan version, tree choice or qualification state. Each metric validates the parameters it accepts and refuses any other, so a misspelt parameter is an error, not ignored.
- **Exact values.** `MetricValue` is an exact decimal (up to six places) or count, as its canonical string, never a float. It reuses `Quantity`'s exact arithmetic without exposing the volume domain to callers.
- **First built-in: `member.volume`.** The member's own net volume of one type — required parameter `type` — over the context's range. It delegates to `VolumeTotals::forMember()`, so reversals net out and the range semantics are identical. It reads by member id only; genealogy plays no part.
- **The configuration boundary.** Stored plan configuration may later reference a metric *key* and its *parameters*. It must never hold PHP code, SQL, closures, class names to instantiate or serialised objects.
- **No network metrics yet.** Direct edges record when each sponsorship or placement was made (`assigned_at`, `placed_at`), but closure paths describe the *current* structure only — they carry no effective interval. A member placed under Bob in March makes Bob → member a path today, so a naive "Bob's January network volume" would count January activity from a member who was not below Bob in January. Rather than hide that behind an assumption that today's structure applies retroactively, sponsor- and placement-based metrics wait until temporal genealogy is designed.

## Consequences

- Plans and applications can name numeric facts safely, and new ones are added without changing the package.
- Every resolution recomputes from source data; performance work will need evidence before introducing a projection.
- `MetricRegistry::register()` stays public for bootstrapping. Registering while requests are being served is not supported.
- Network, team and descendant metrics are deliberately absent until genealogy can answer "who was below whom, when".
