# Changelog

All notable changes to `flametrench/tenancy` are recorded here.
Spec-level changes live in [`spec/CHANGELOG.md`](https://github.com/flametrench/spec/blob/main/CHANGELOG.md).

## [v0.2.0-rc.4] — 2026-04-27

### Added
- `Flametrench\Tenancy\PostgresTenancyStore` — a Postgres-backed `TenancyStore`. Mirrors `InMemoryTenancyStore` byte-for-byte at the SDK boundary; the difference is durability and concurrency.
  - Schema: `spec/reference/postgres.sql` (the `org`, `mem`, `inv`, `tup` tables, plus the v0.2 `org.name`/`org.slug` ADR 0011 columns).
  - Connection: accepts a `PDO` instance. `ext-pdo` and `ext-pdo_pgsql` are listed under `suggest` rather than `require` — adopters using only the in-memory store don't need them.
  - Multi-statement ops (`createOrg` + owner membership + tuple, `changeRole` revoke-and-re-add, `acceptInvitation` with pre-tuples, `transferOwnership`) run inside a transaction.
  - Coverage: 25 integration tests, gated on `TENANCY_POSTGRES_URL`.

## [v0.2.0-rc.3] — 2026-04-26

ADR 0011 org metadata (`name` + `slug`) — `UNSET` sentinel for partial updates, slug-format validation, `OrgSlugConflictException`. See [`spec/CHANGELOG.md`](https://github.com/flametrench/spec/blob/main/CHANGELOG.md).

## [v0.2.0-rc.1] — 2026-04-25

Initial v0.2 release-candidate.

For pre-rc history, see git tags.
