# Changelog

All notable changes to `flametrench/tenancy` are recorded here.
Spec-level changes live in [`spec/CHANGELOG.md`](https://github.com/flametrench/spec/blob/main/CHANGELOG.md).

## [v0.4.0] — 2026-06-07

### Added
- `TenancyStore::listOrgs(?cursor, limit=50, ?query, ?status) → Page<Organization>` — cross-org enumeration primitive (ADR 0025 / [spec#25](https://github.com/flametrench/spec/issues/25)).
  - Cursor-paginated, ordered by `id` ASC (UUIDv7 ≈ creation time).
  - Optional `status` filter (`active` | `suspended` | `revoked`).
  - Optional `query`: case-insensitive substring match over org `name` or `slug` (ADR 0011 fields).
  - Lands in both `InMemoryTenancyStore` and `PostgresTenancyStore` (ILIKE for Postgres, `mb_strtolower`+`str_contains` for InMemory).
  - SDK is ungated per spec — adopter MUST gate call site to admin/system caller.
  - Resolves the dangling `listOrgs` reference from ADR 0015.
  - 8/8 MUST conformance cases from `tenancy/list-orgs.json` pass.

## [v0.2.0-rc.5] — 2026-04-27

### Fixed
- `PostgresTenancyStore.acceptInvitation` (when materializing pre-tuples) and `listTuplesForObject` now accept wire-format `object_id` values with app-defined prefixes (e.g. `proj_<32hex>`, `file_<32hex>`) in addition to bare 32-hex and canonical hyphenated UUIDs. Previously, an invitation carrying pre-tuples with wire-format prefixed IDs failed at acceptance time when binding to the UUID column. Closes [`spec#8`](https://github.com/flametrench/spec/issues/8).

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
