# The host seam

Eighteen modules the panel's published components import but do not ship.
They belong to the application: a Laravel Vue starter kit writes most of them,
and [Wayfinder](https://github.com/laravel/wayfinder) generates the rest at
build time from the application's own routes and controllers.

This directory holds a minimal stand-in for each. Its first job is this
repository's own type-check and build: `@/…` resolves to `resources/js/…` first,
and falls through to this directory only when the file is genuinely not part of
the package.

Its second job is a blank application. `php artisan panel:install` copies the
stand-ins an application has _no module of its own_ for — which is nineteen of
them on a `laravel new`, and none of them on a Laravel Vue starter kit. Nothing
here is in a publish map, and nothing here ever overwrites: an application that
has a `UserMenuContent` keeps it, however it is spelled on disk.

That is a change of position. These used to be documented as never shipping, on
the grounds that a component is the application's design and a vendored one
would be overwriting somebody's. Both halves of that are still true, and neither
is an argument for a blank application getting a build error and a list of
nineteen files to write by hand instead.

That fall-through is what makes `npm run typecheck` possible at all. Without
it, 337 files would fail to resolve eighteen imports and the toolchain would
report nothing useful about the code it _can_ check.

## Why stand-ins rather than shipping the real thing

Two of these are impossible to ship and the rest would be wrong to:

- `routes/*` and `actions/*` are **generated**. Wayfinder writes them from the
  application's route table, so a copy vendored here would be a snapshot of
  somebody else's routes.
- The components are the application's design. `UserMenuContent` is where a
  project puts its own account links; shipping one would mean overwriting a
  file every starter kit already has, and every project has already edited.

## Keeping the seam honest

A stand-in that drifts from what the starter kit really exports would let a
real breakage type-check clean. Two things guard against that:

- Each stub below declares the **exact** props, emits and exports the panel's
  own components use — no `any` escape hatches on the surface that matters —
  so removing a prop from a stub breaks the build here.
- `php artisan panel:install` checks an application for every one of these
  paths and names the ones it is missing, so the seam is verified where it is
  real rather than only where it is simulated.
