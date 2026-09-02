# ga4 — Claude instructions

## Nothing in `src/` may touch storage, globals, or the environment

No PDO, no database connection, no settings table, no `$_SESSION`,
no `$_GET`, no `getenv()`. Configuration arrives through constructor
arguments; caching goes through `CacheInterface`; HTTP goes through
`HttpInterface`. See README.md's "Design" section for why -- that
explanation now lives there instead of here, since this file ships in
the public repo and the design rationale should not be a dead end for
readers who don't have it.

Nexus-only: the original design conversation and rationale are recorded
at `vps-infra/docs/superpowers/specs/2026-09-01-ga4-package-design.md`,
which lives in a private repo and is not reachable from here.

## No runtime dependencies

`require` is PHP plus three extensions. Adding a Composer runtime
dependency means every consuming site's committed `vendor/` grows by
it — see the spec's rationale for rejecting `google/analytics-data`.

## Tests run in Docker

nexus has no PHP or Composer on the host. Use `bin/dev test`.
Tests must not touch the network; `Client`, `Dashboard` and `Admin`
are all tested against `FakeHttp`.
