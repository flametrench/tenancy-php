# flametrench/tenancy

[![CI](https://github.com/flametrench/tenancy-php/actions/workflows/ci.yml/badge.svg)](https://github.com/flametrench/tenancy-php/actions/workflows/ci.yml)

Tenancy primitives for [Flametrench](https://flametrench.dev): organizations, memberships, and invitations. Spec-conformant revoke-and-re-add lifecycle, atomic invitation acceptance, sole-owner protection, and a `mem_`/`tup_` duality maintained without drift.

The PHP counterpart of [`@flametrench/tenancy`](https://github.com/flametrench/node/tree/main/packages/tenancy) — same shapes, same invariants, same operations. Both SDKs are exercised against the spec's [conformance suite](https://github.com/flametrench/spec/tree/main/conformance) so applications can move between the two languages with no behavioral surprises.

**Status:** v0.2.0-rc.6 (release candidate). PHP 8.3+ required. Includes the production-ready `PostgresTenancyStore` alongside the in-memory reference store; the Postgres adapter mirrors in-memory semantics byte-for-byte at the SDK boundary, and per ADR 0013 cooperates with adopter-side outer transactions via savepoints when nested.

## Install

```bash
composer require flametrench/tenancy
```

## Quick start

```php
use Flametrench\Tenancy\InMemoryTenancyStore;
use Flametrench\Tenancy\PreTuple;
use Flametrench\Tenancy\Role;

$store = new InMemoryTenancyStore();

// Alice creates Acme and becomes its owner.
['org' => $org, 'ownerMembership' => $owner] = $store->createOrg('usr_0190...alice');

// Alice invites Carol with a project-scoped grant.
$inv = $store->createInvitation(
    orgId: $org->id,
    identifier: 'carol@example.com',
    role: Role::Guest,
    invitedBy: 'usr_0190...alice',
    expiresAt: new DateTimeImmutable('+7 days'),
    preTuples: [new PreTuple('viewer', 'proj', '0190...project42')],
);

// Carol accepts. Membership and pre-tuples materialize atomically.
$result = $store->acceptInvitation($inv->id, asUsrId: 'usr_0190...carol');
```

## API

`TenancyStore` defines the contract every backend implements. Both the in-memory reference implementation and the production-ready `PostgresTenancyStore` ship with this package.

| Operation | Returns |
|---|---|
| `createOrg(creator)` | `['org' => Organization, 'ownerMembership' => Membership]` |
| `getOrg / suspendOrg / reinstateOrg / revokeOrg` | `Organization` |
| `addMember / getMembership / changeRole / suspendMembership / reinstateMembership` | `Membership` |
| `selfLeave(memId, transferTo?)` | `Membership` (revoked) |
| `adminRemove(memId, adminUsrId)` | `Membership` (revoked, with `removedBy` set) |
| `transferOwnership(orgId, fromMemId, toMemId)` | `['fromMembership' => Membership, 'toMembership' => Membership]` |
| `listMembers(orgId, cursor?, limit, status?)` | `Page<Membership>` |
| `createInvitation / getInvitation / declineInvitation / revokeInvitation` | `Invitation` |
| `acceptInvitation(invId, asUsrId?)` | `['invitation' => Invitation, 'membership' => Membership, 'materializedTuples' => Tuple[]]` |
| `listInvitations(orgId, cursor?, limit, status?)` | `Page<Invitation>` |
| `listTuplesForSubject(subjectType, subjectId)` | `Tuple[]` |
| `listTuplesForObject(objectType, objectId, relation?)` | `Tuple[]` |

## Spec conformance

Mirrors the normative spec: see [`spec/docs/tenancy.md`](https://github.com/flametrench/spec/blob/main/docs/tenancy.md) and the design decisions in [ADR 0002](https://github.com/flametrench/spec/blob/main/decisions/0002-tenancy-model.md) + [ADR 0003](https://github.com/flametrench/spec/blob/main/decisions/0003-invitation-state-machine.md).

The 31 PEST tests in this package cover every sole-owner-protection path, the `replaces` chain on role changes, atomic invitation acceptance with pre-tuple materialization, admin-hierarchy enforcement, ownership transfer atomicity, and `removedBy` attribution.

## Errors

Every error is a `Flametrench\Tenancy\Exceptions\TenancyException` subclass with a stable `flametrenchCode` matching the OpenAPI Error envelope:

| Class | Code |
|---|---|
| `NotFoundException` | `not_found` |
| `SoleOwnerException` | `conflict.sole_owner` |
| `RoleHierarchyException` | `forbidden.role_hierarchy` |
| `DuplicateMembershipException` | `conflict.duplicate_membership` |
| `AlreadyTerminalException` | `conflict.already_terminal` |
| `InvitationExpiredException` | `conflict.invitation_expired` |
| `InvitationNotPendingException` | `conflict.invitation_not_pending` |
| `ForbiddenException` | `forbidden` |
| `PreconditionException` | `precondition.<specifics>` |

## Development

```bash
composer install
composer test
```

## License

Apache License 2.0. Copyright 2026 NDC Digital, LLC.
