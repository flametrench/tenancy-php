<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

use Flametrench\Ids\Id;
use Flametrench\Tenancy\Exceptions\AlreadyTerminalException;
use Flametrench\Tenancy\Exceptions\DuplicateMembershipException;
use Flametrench\Tenancy\Exceptions\ForbiddenException;
use Flametrench\Tenancy\Exceptions\IdentifierBindingRequiredException;
use Flametrench\Tenancy\Exceptions\IdentifierMismatchException;
use Flametrench\Tenancy\Exceptions\InvitationExpiredException;
use Flametrench\Tenancy\Exceptions\InvitationNotPendingException;
use Flametrench\Tenancy\Exceptions\NotFoundException;
use Flametrench\Tenancy\Exceptions\OrgSlugConflictException;
use Flametrench\Tenancy\Exceptions\PreconditionException;
use Flametrench\Tenancy\Exceptions\RoleHierarchyException;
use Flametrench\Tenancy\Exceptions\SoleOwnerException;

/**
 * Reference in-memory TenancyStore. Behaviorally spec-conformant for every
 * transition: revoke-and-re-add with `replaces` chain on role changes,
 * atomic accept-with-pre-tuples, sole-owner protection on all relevant
 * paths, and a shadow tuple set kept in lockstep with mem.status so the
 * mem_/tup_ duality cannot drift.
 *
 * Not thread-safe across coroutines; intended for tests, documentation,
 * and small applications. Production workloads should use the (planned)
 * Postgres-backed store.
 */
final class InMemoryTenancyStore implements TenancyStore
{
    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, Membership> */
    private array $memberships = [];

    /** @var array<string, Invitation> */
    private array $invitations = [];

    /** @var array<string, true> Shadow set of active tuple keys. */
    private array $tupleKeys = [];

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    // ─── Helpers ───

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function tupleKey(Tuple $t): string
    {
        return "{$t->subjectType}|{$t->subjectId}|{$t->relation}|{$t->objectType}|{$t->objectId}";
    }

    private static function membershipTuple(Membership $m): Tuple
    {
        return new Tuple(
            subjectType: 'usr',
            subjectId: $m->usrId,
            relation: $m->role->value,
            objectType: 'org',
            objectId: $m->orgId,
        );
    }

    private function insertTuple(Tuple $t): void
    {
        $this->tupleKeys[self::tupleKey($t)] = true;
    }

    private function deleteTuple(Tuple $t): void
    {
        unset($this->tupleKeys[self::tupleKey($t)]);
    }

    private function countActiveOwners(string $orgId): int
    {
        $n = 0;
        foreach ($this->memberships as $m) {
            if ($m->orgId === $orgId && $m->status === Status::Active && $m->role === Role::Owner) {
                $n++;
            }
        }
        return $n;
    }

    private function findActiveMembership(string $usrId, string $orgId): ?Membership
    {
        foreach ($this->memberships as $m) {
            if ($m->usrId === $usrId && $m->orgId === $orgId && $m->status === Status::Active) {
                return $m;
            }
        }
        return null;
    }

    private function requireOrg(string $orgId): Organization
    {
        return $this->orgs[$orgId] ?? throw new NotFoundException("Organization {$orgId} not found");
    }

    private function requireMembership(string $memId): Membership
    {
        return $this->memberships[$memId] ?? throw new NotFoundException("Membership {$memId} not found");
    }

    // ─── Organizations ───

    /**
     * Sentinel value for `update_org` to distinguish "don't change"
     * from an explicit "set to null." Use {@see TenancyStore::UNSET}.
     */
    public const UNSET = '__flametrench_unset__';

    /**
     * @return array{org: Organization, ownerMembership: Membership}
     */
    public function createOrg(
        string $creator,
        ?string $name = null,
        ?string $slug = null,
    ): array {
        if ($slug !== null) {
            self::validateSlug($slug);
            $this->enforceSlugUnique($slug, excludeOrgId: null);
        }
        $now = $this->now();
        $org = new Organization(
            id: Id::generate('org'),
            status: Status::Active,
            createdAt: $now,
            updatedAt: $now,
            name: $name,
            slug: $slug,
        );
        $ownerMembership = new Membership(
            id: Id::generate('mem'),
            usrId: $creator,
            orgId: $org->id,
            role: Role::Owner,
            status: Status::Active,
            replaces: null,
            invitedBy: null,
            removedBy: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->orgs[$org->id] = $org;
        $this->memberships[$ownerMembership->id] = $ownerMembership;
        $this->insertTuple(self::membershipTuple($ownerMembership));
        return ['org' => $org, 'ownerMembership' => $ownerMembership];
    }

    public function getOrg(string $orgId): Organization
    {
        return $this->requireOrg($orgId);
    }

    /**
     * ADR 0011 partial update.
     *
     * Use the {@see UNSET} sentinel for "don't change" and pass `null`
     * for "set to null." Slug uniqueness violations raise
     * {@see OrgSlugConflictException}; updating a revoked org raises
     * {@see AlreadyTerminalException}.
     */
    public function updateOrg(
        string $orgId,
        string|null $name = self::UNSET,
        string|null $slug = self::UNSET,
    ): Organization {
        $org = $this->requireOrg($orgId);
        if ($org->status === Status::Revoked) {
            throw new AlreadyTerminalException("Org {$orgId} is revoked; cannot update");
        }
        $newName = $name === self::UNSET ? $org->name : $name;
        $newSlug = $slug === self::UNSET ? $org->slug : $slug;
        if ($slug !== self::UNSET && $newSlug !== null) {
            self::validateSlug($newSlug);
            $this->enforceSlugUnique($newSlug, excludeOrgId: $orgId);
        }
        $updated = $org->withMetadata($newName, $newSlug, $this->now());
        $this->orgs[$orgId] = $updated;
        return $updated;
    }

    private function enforceSlugUnique(string $slug, ?string $excludeOrgId): void
    {
        foreach ($this->orgs as $existing) {
            if ($existing->id === $excludeOrgId) {
                continue;
            }
            if ($existing->slug === $slug && $existing->status !== Status::Revoked) {
                throw new OrgSlugConflictException($slug);
            }
        }
    }

    private static function validateSlug(string $slug): void
    {
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $slug) !== 1) {
            throw new PreconditionException(
                "Slug '{$slug}' does not match the spec pattern "
                . '(DNS-label-style: 1-63 lowercase ASCII chars or digits or hyphens, '
                . 'no leading/trailing hyphen)',
                'org_slug_format',
            );
        }
    }

    private function transitionOrg(string $orgId, Status $to): Organization
    {
        $org = $this->requireOrg($orgId);
        if ($org->status === $to) {
            throw new AlreadyTerminalException("Org {$orgId} is already {$to->value}");
        }
        if ($org->status === Status::Revoked) {
            throw new AlreadyTerminalException("Org {$orgId} is revoked; cannot transition");
        }
        $updated = $org->withStatus($to, $this->now());
        $this->orgs[$orgId] = $updated;
        return $updated;
    }

    public function suspendOrg(string $orgId): Organization
    {
        return $this->transitionOrg($orgId, Status::Suspended);
    }

    public function reinstateOrg(string $orgId): Organization
    {
        $org = $this->requireOrg($orgId);
        if ($org->status !== Status::Suspended) {
            throw new PreconditionException(
                "Org {$orgId} is {$org->status->value}; only suspended orgs can be reinstated",
                'invalid_transition',
            );
        }
        return $this->transitionOrg($orgId, Status::Active);
    }

    public function revokeOrg(string $orgId): Organization
    {
        $org = $this->requireOrg($orgId);
        if ($org->status === Status::Revoked) {
            throw new AlreadyTerminalException("Org {$orgId} is already revoked");
        }
        $now = $this->now();
        foreach ($this->memberships as $id => $m) {
            if ($m->orgId === $orgId && $m->status === Status::Active) {
                $this->deleteTuple(self::membershipTuple($m));
                $this->memberships[$id] = $m->with(status: Status::Revoked, updatedAt: $now);
            }
        }
        $updated = $org->withStatus(Status::Revoked, $now);
        $this->orgs[$orgId] = $updated;
        return $updated;
    }

    public function listOrgs(
        ?string $cursor = null,
        int $limit = 50,
        ?string $query = null,
        ?Status $status = null,
    ): Page {
        $limit = max(1, min(200, $limit));
        $all = array_values(array_filter(
            $this->orgs,
            function (Organization $o) use ($status, $query): bool {
                if ($status !== null && $o->status !== $status) {
                    return false;
                }
                if ($query !== null) {
                    $q = mb_strtolower($query);
                    $nameMatch = $o->name !== null && str_contains(mb_strtolower($o->name), $q);
                    $slugMatch = $o->slug !== null && str_contains(mb_strtolower($o->slug), $q);
                    if (!$nameMatch && !$slugMatch) {
                        return false;
                    }
                }
                return true;
            },
        ));
        usort($all, fn(Organization $a, Organization $b) => strcmp($a->id, $b->id));
        return $this->paginate($all, $cursor, $limit);
    }

    // ─── Memberships ───

    public function addMember(string $orgId, string $usrId, Role $role, ?string $invitedBy = null): Membership
    {
        $org = $this->requireOrg($orgId);
        if ($org->status !== Status::Active) {
            throw new PreconditionException(
                "Cannot add member to {$org->status->value} org",
                'org_not_active',
            );
        }
        if ($this->findActiveMembership($usrId, $orgId) !== null) {
            throw new DuplicateMembershipException(
                "User {$usrId} already has an active membership in {$orgId}",
            );
        }
        $now = $this->now();
        $mem = new Membership(
            id: Id::generate('mem'),
            usrId: $usrId,
            orgId: $orgId,
            role: $role,
            status: Status::Active,
            replaces: null,
            invitedBy: $invitedBy,
            removedBy: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->memberships[$mem->id] = $mem;
        $this->insertTuple(self::membershipTuple($mem));
        return $mem;
    }

    public function getMembership(string $memId): Membership
    {
        return $this->requireMembership($memId);
    }

    public function listMembers(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?Status $status = null,
    ): Page {
        $all = array_values(array_filter(
            $this->memberships,
            fn(Membership $m) => $m->orgId === $orgId
                && ($status === null || $m->status === $status),
        ));
        usort($all, fn(Membership $a, Membership $b) => strcmp($a->id, $b->id));
        return $this->paginate($all, $cursor, $limit);
    }

    /**
     * @template T of object
     * @param list<T> $all  Each T MUST have a public string `$id` property.
     * @return Page<T>
     */
    private function paginate(array $all, ?string $cursor, int $limit): Page
    {
        if ($cursor !== null) {
            $startIdx = 0;
            foreach ($all as $i => $item) {
                if ($item->id > $cursor) {
                    $startIdx = $i;
                    break;
                }
                $startIdx = $i + 1;
            }
        } else {
            $startIdx = 0;
        }
        $slice = array_slice($all, $startIdx, $limit);
        $next = ($startIdx + $limit) < count($all) && count($slice) > 0
            ? $slice[count($slice) - 1]->id
            : null;
        return new Page(data: $slice, nextCursor: $next);
    }

    public function changeRole(string $memId, Role $newRole): Membership
    {
        $old = $this->requireMembership($memId);
        if ($old->status !== Status::Active) {
            throw new PreconditionException(
                "Membership {$memId} is {$old->status->value}; only active memberships can change role",
                'mem_not_active',
            );
        }
        if (
            $old->role === Role::Owner
            && $newRole !== Role::Owner
            && $this->countActiveOwners($old->orgId) === 1
        ) {
            throw new SoleOwnerException(
                'Cannot change role of the sole active owner; transfer ownership first',
            );
        }
        $now = $this->now();
        $revoked = $old->with(status: Status::Revoked, updatedAt: $now);
        $this->memberships[$old->id] = $revoked;
        $this->deleteTuple(self::membershipTuple($old));

        $fresh = new Membership(
            id: Id::generate('mem'),
            usrId: $old->usrId,
            orgId: $old->orgId,
            role: $newRole,
            status: Status::Active,
            replaces: $old->id,
            invitedBy: $old->invitedBy,
            removedBy: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->memberships[$fresh->id] = $fresh;
        $this->insertTuple(self::membershipTuple($fresh));
        return $fresh;
    }

    public function suspendMembership(string $memId): Membership
    {
        $mem = $this->requireMembership($memId);
        if ($mem->status !== Status::Active) {
            throw new PreconditionException(
                "Membership {$memId} is {$mem->status->value}; only active memberships can be suspended",
                'mem_not_active',
            );
        }
        if ($mem->role === Role::Owner && $this->countActiveOwners($mem->orgId) === 1) {
            throw new SoleOwnerException(
                'Cannot suspend the sole active owner; transfer ownership first',
            );
        }
        $now = $this->now();
        $updated = $mem->with(status: Status::Suspended, updatedAt: $now);
        $this->memberships[$memId] = $updated;
        $this->deleteTuple(self::membershipTuple($mem));
        return $updated;
    }

    public function reinstateMembership(string $memId): Membership
    {
        $mem = $this->requireMembership($memId);
        if ($mem->status !== Status::Suspended) {
            throw new PreconditionException(
                "Membership {$memId} is {$mem->status->value}; only suspended memberships can be reinstated",
                'invalid_transition',
            );
        }
        if ($this->findActiveMembership($mem->usrId, $mem->orgId) !== null) {
            throw new DuplicateMembershipException(
                "User {$mem->usrId} has a separate active membership in {$mem->orgId}; cannot reinstate",
            );
        }
        $now = $this->now();
        $updated = $mem->with(status: Status::Active, updatedAt: $now);
        $this->memberships[$memId] = $updated;
        $this->insertTuple(self::membershipTuple($updated));
        return $updated;
    }

    public function selfLeave(string $memId, ?string $transferTo = null): Membership
    {
        $mem = $this->requireMembership($memId);
        if ($mem->status !== Status::Active) {
            throw new PreconditionException(
                "Membership {$memId} is {$mem->status->value}; only active memberships can self-leave",
                'mem_not_active',
            );
        }
        if ($mem->role === Role::Owner && $this->countActiveOwners($mem->orgId) === 1) {
            if ($transferTo === null) {
                throw new SoleOwnerException(
                    'Cannot self-leave as sole active owner; pass transferTo to atomically transfer ownership',
                );
            }
            $target = $this->findActiveMembership($transferTo, $mem->orgId);
            if ($target === null) {
                throw new NotFoundException(
                    "transferTo user {$transferTo} has no active membership in {$mem->orgId}",
                );
            }
            // Promote target to owner; this creates a second active owner so the
            // subsequent self-revoke does not trip the sole-owner guard.
            $this->changeRole($target->id, Role::Owner);
        }
        $now = $this->now();
        $revoked = $mem->with(status: Status::Revoked, removedBy: null, updatedAt: $now);
        // Preserve null removedBy explicitly (with() uses null-coalesce which
        // would otherwise keep the old value; both old and new are null here,
        // but callers reading the code should see the intent).
        $this->memberships[$mem->id] = $revoked;
        $this->deleteTuple(self::membershipTuple($mem));
        return $revoked;
    }

    public function adminRemove(string $memId, string $adminUsrId): Membership
    {
        $target = $this->requireMembership($memId);
        if ($target->status !== Status::Active) {
            throw new PreconditionException(
                "Target membership {$memId} is {$target->status->value}",
                'mem_not_active',
            );
        }
        $admin = $this->findActiveMembership($adminUsrId, $target->orgId);
        if ($admin === null) {
            throw new ForbiddenException(
                "User {$adminUsrId} has no active membership in {$target->orgId}",
            );
        }
        if ($admin->role !== Role::Owner && $admin->role !== Role::Admin) {
            throw new ForbiddenException(
                "Role {$admin->role->value} is not permitted to remove members",
            );
        }
        if ($target->role === Role::Owner) {
            throw new RoleHierarchyException(
                'Owner removal requires transferOwnership, not adminRemove',
            );
        }
        $adminRank = $admin->role->adminRank();
        $targetRank = $target->role->adminRank();
        if ($adminRank === null || $targetRank === null) {
            throw new PreconditionException(
                'adminRemove operates only on owner/admin/member/guest roles',
                'scope_mismatch',
            );
        }
        if ($adminRank < $targetRank) {
            throw new RoleHierarchyException(
                "Role {$admin->role->value} cannot remove role {$target->role->value}",
            );
        }
        $now = $this->now();
        $revoked = $target->with(status: Status::Revoked, removedBy: $admin->usrId, updatedAt: $now);
        $this->memberships[$target->id] = $revoked;
        $this->deleteTuple(self::membershipTuple($target));
        return $revoked;
    }

    public function transferOwnership(string $orgId, string $fromMemId, string $toMemId): array
    {
        $from = $this->requireMembership($fromMemId);
        $to = $this->requireMembership($toMemId);
        if ($from->status !== Status::Active) {
            throw new PreconditionException("From membership {$fromMemId} is {$from->status->value}", 'from_not_active');
        }
        if ($to->status !== Status::Active) {
            throw new PreconditionException("To membership {$toMemId} is {$to->status->value}", 'to_not_active');
        }
        if ($from->orgId !== $orgId || $to->orgId !== $orgId) {
            throw new PreconditionException("Both memberships must belong to {$orgId}", 'org_mismatch');
        }
        if ($from->role !== Role::Owner) {
            throw new PreconditionException('From membership must hold the owner role', 'from_not_owner');
        }
        if ($from->usrId === $to->usrId) {
            throw new PreconditionException('Cannot transfer ownership to self', 'self_transfer');
        }
        // Promote target first so the donor is no longer the sole active owner
        // when we demote them.
        $toMembership = $this->changeRole($to->id, Role::Owner);
        $fromMembership = $this->changeRole($from->id, Role::Member);
        return ['fromMembership' => $fromMembership, 'toMembership' => $toMembership];
    }

    // ─── Invitations ───

    public function createInvitation(
        string $orgId,
        string $identifier,
        Role $role,
        string $invitedBy,
        \DateTimeImmutable $expiresAt,
        array $preTuples = [],
    ): Invitation {
        $org = $this->requireOrg($orgId);
        if ($org->status !== Status::Active) {
            throw new PreconditionException(
                "Cannot create invitation for {$org->status->value} org",
                'org_not_active',
            );
        }
        $now = $this->now();
        if ($expiresAt <= $now) {
            throw new PreconditionException('expiresAt must be in the future', 'past_expiration');
        }
        $inv = new Invitation(
            id: Id::generate('inv'),
            orgId: $orgId,
            identifier: $identifier,
            role: $role,
            status: InvitationStatus::Pending,
            preTuples: array_values($preTuples),
            invitedBy: $invitedBy,
            invitedUserId: null,
            createdAt: $now,
            expiresAt: $expiresAt,
            terminalAt: null,
            terminalBy: null,
        );
        $this->invitations[$inv->id] = $inv;
        return $inv;
    }

    public function getInvitation(string $invId): Invitation
    {
        return $this->invitations[$invId]
            ?? throw new NotFoundException("Invitation {$invId} not found");
    }

    public function listInvitations(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?InvitationStatus $status = null,
    ): Page {
        $all = array_values(array_filter(
            $this->invitations,
            fn(Invitation $i) => $i->orgId === $orgId
                && ($status === null || $i->status === $status),
        ));
        usort($all, fn(Invitation $a, Invitation $b) => strcmp($a->id, $b->id));
        return $this->paginate($all, $cursor, $limit);
    }

    public function acceptInvitation(
        string $invId,
        ?string $asUsrId = null,
        ?string $acceptingIdentifier = null,
    ): array {
        $inv = $this->getInvitation($invId);
        if ($inv->status !== InvitationStatus::Pending) {
            throw new InvitationNotPendingException(
                "Invitation {$invId} is {$inv->status->value}, not pending",
            );
        }
        $now = $this->now();
        if ($now > $inv->expiresAt) {
            throw new InvitationExpiredException(
                "Invitation {$invId} expired at " . $inv->expiresAt->format(\DateTimeInterface::ATOM),
            );
        }
        // ADR 0009: existing-user accept MUST supply a matching identifier.
        if ($asUsrId !== null) {
            if ($acceptingIdentifier === null) {
                throw new IdentifierBindingRequiredException();
            }
            if ($acceptingIdentifier !== $inv->identifier) {
                throw new IdentifierMismatchException($acceptingIdentifier, $inv->identifier);
            }
        }
        $usrId = $asUsrId ?? Id::generate('usr');
        if ($this->findActiveMembership($usrId, $inv->orgId) !== null) {
            throw new DuplicateMembershipException(
                "User {$usrId} already has an active membership in {$inv->orgId}",
            );
        }
        $membership = new Membership(
            id: Id::generate('mem'),
            usrId: $usrId,
            orgId: $inv->orgId,
            role: $inv->role,
            status: Status::Active,
            replaces: null,
            invitedBy: $inv->invitedBy,
            removedBy: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->memberships[$membership->id] = $membership;
        $this->insertTuple(self::membershipTuple($membership));

        $materializedTuples = [];
        foreach ($inv->preTuples as $pt) {
            $t = new Tuple(
                subjectType: 'usr',
                subjectId: $usrId,
                relation: $pt->relation,
                objectType: $pt->objectType,
                objectId: $pt->objectId,
            );
            $this->insertTuple($t);
            $materializedTuples[] = $t;
        }

        $updatedInv = $inv->transitionTerminal(
            status: InvitationStatus::Accepted,
            at: $now,
            by: $usrId,
            invitedUserId: $usrId,
        );
        $this->invitations[$inv->id] = $updatedInv;

        return [
            'invitation' => $updatedInv,
            'membership' => $membership,
            'materializedTuples' => $materializedTuples,
        ];
    }

    public function declineInvitation(string $invId, ?string $asUsrId = null): Invitation
    {
        $inv = $this->getInvitation($invId);
        if ($inv->status !== InvitationStatus::Pending) {
            throw new InvitationNotPendingException(
                "Invitation {$invId} is {$inv->status->value}, not pending",
            );
        }
        $updated = $inv->transitionTerminal(
            status: InvitationStatus::Declined,
            at: $this->now(),
            by: $asUsrId,
        );
        $this->invitations[$inv->id] = $updated;
        return $updated;
    }

    public function revokeInvitation(string $invId, string $adminUsrId): Invitation
    {
        $inv = $this->getInvitation($invId);
        if ($inv->status !== InvitationStatus::Pending) {
            throw new InvitationNotPendingException(
                "Invitation {$invId} is {$inv->status->value}, not pending",
            );
        }
        $updated = $inv->transitionTerminal(
            status: InvitationStatus::Revoked,
            at: $this->now(),
            by: $adminUsrId,
        );
        $this->invitations[$inv->id] = $updated;
        return $updated;
    }

    // ─── Tuple accessors ───

    public function listTuplesForSubject(string $subjectType, string $subjectId): array
    {
        $prefix = "{$subjectType}|{$subjectId}|";
        $results = [];
        foreach (array_keys($this->tupleKeys) as $key) {
            if (!str_starts_with($key, $prefix)) continue;
            $parts = explode('|', $key);
            if (count($parts) !== 5) continue;
            [$st, $sid, $rel, $ot, $oid] = $parts;
            $results[] = new Tuple(
                subjectType: $st,
                subjectId: $sid,
                relation: $rel,
                objectType: $ot,
                objectId: $oid,
            );
        }
        return $results;
    }

    public function listTuplesForObject(string $objectType, string $objectId, ?string $relation = null): array
    {
        $results = [];
        foreach (array_keys($this->tupleKeys) as $key) {
            $parts = explode('|', $key);
            if (count($parts) !== 5) continue;
            [$st, $sid, $rel, $ot, $oid] = $parts;
            if ($ot !== $objectType || $oid !== $objectId) continue;
            if ($relation !== null && $rel !== $relation) continue;
            $results[] = new Tuple(
                subjectType: $st,
                subjectId: $sid,
                relation: $rel,
                objectType: $ot,
                objectId: $oid,
            );
        }
        return $results;
    }
}
