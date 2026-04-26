<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

/**
 * Every tenancy backend implements this interface. The in-memory store
 * ships with this package; a Postgres-backed adapter is planned.
 *
 * Atomicity guarantees per the Flametrench v0.1 specification:
 *
 *   - changeRole updates the old mem, inserts a new mem, deletes the old
 *     tuple, inserts the new tuple — all in one transaction.
 *   - acceptInvitation creates a user if needed, inserts mem, inserts the
 *     membership tuple, expands preTuples into tuples, transitions the
 *     invitation — all in one transaction.
 *   - transferOwnership demotes the old owner's mem, promotes the target,
 *     swaps both tuples — one transaction.
 */
interface TenancyStore
{
    // ─── Organizations ───

    /**
     * Sentinel for {@see updateOrg} partial-update semantics.
     * Implementations re-export this constant.
     */
    public const UNSET = '__flametrench_unset__';

    /**
     * @param  ?string  $name  v0.2 (ADR 0011) optional display name.
     * @param  ?string  $slug  v0.2 (ADR 0011) optional URL handle.
     *
     * @return array{org: Organization, ownerMembership: Membership}
     */
    public function createOrg(
        string $creator,
        ?string $name = null,
        ?string $slug = null,
    ): array;

    public function getOrg(string $orgId): Organization;

    /**
     * Partial update of v0.2 metadata fields per ADR 0011.
     *
     * Pass {@see TenancyStore::UNSET} to skip a field; pass `null` to
     * clear it. Slug uniqueness violations raise OrgSlugConflictException;
     * updating a revoked org raises AlreadyTerminalException.
     */
    public function updateOrg(
        string $orgId,
        string|null $name = self::UNSET,
        string|null $slug = self::UNSET,
    ): Organization;

    public function suspendOrg(string $orgId): Organization;

    public function reinstateOrg(string $orgId): Organization;

    public function revokeOrg(string $orgId): Organization;

    // ─── Memberships ───

    public function addMember(
        string $orgId,
        string $usrId,
        Role $role,
        ?string $invitedBy = null,
    ): Membership;

    public function getMembership(string $memId): Membership;

    /**
     * @return Page<Membership>
     */
    public function listMembers(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?Status $status = null,
    ): Page;

    /** Revoke-and-re-add; returns the new active membership. */
    public function changeRole(string $memId, Role $newRole): Membership;

    public function suspendMembership(string $memId): Membership;

    public function reinstateMembership(string $memId): Membership;

    public function selfLeave(string $memId, ?string $transferTo = null): Membership;

    public function adminRemove(string $memId, string $adminUsrId): Membership;

    /**
     * @return array{fromMembership: Membership, toMembership: Membership}
     */
    public function transferOwnership(
        string $orgId,
        string $fromMemId,
        string $toMemId,
    ): array;

    // ─── Invitations ───

    /**
     * @param list<PreTuple> $preTuples
     */
    public function createInvitation(
        string $orgId,
        string $identifier,
        Role $role,
        string $invitedBy,
        \DateTimeImmutable $expiresAt,
        array $preTuples = [],
    ): Invitation;

    public function getInvitation(string $invId): Invitation;

    /**
     * @return Page<Invitation>
     */
    public function listInvitations(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?InvitationStatus $status = null,
    ): Page;

    /**
     * Accept an invitation.
     *
     * Per ADR 0009: when `$asUsrId` is provided, `$acceptingIdentifier`
     * is REQUIRED. The SDK byte-compares it to `invitation.identifier`;
     * mismatch raises {@see Exceptions\IdentifierMismatchException},
     * omission raises {@see Exceptions\IdentifierBindingRequiredException}.
     * If `$asUsrId` is null the SDK generates a fresh usr_ id and
     * `$acceptingIdentifier` is not consulted.
     *
     * The host MUST source `$acceptingIdentifier` from the
     * authenticated session, NOT from a request body.
     *
     * @return array{invitation: Invitation, membership: Membership, materializedTuples: list<Tuple>}
     */
    public function acceptInvitation(
        string $invId,
        ?string $asUsrId = null,
        ?string $acceptingIdentifier = null,
    ): array;

    public function declineInvitation(string $invId, ?string $asUsrId = null): Invitation;

    public function revokeInvitation(string $invId, string $adminUsrId): Invitation;

    // ─── Authorization tuple accessors (read-only) ───

    /**
     * @return list<Tuple>
     */
    public function listTuplesForSubject(string $subjectType, string $subjectId): array;

    /**
     * @return list<Tuple>
     */
    public function listTuplesForObject(
        string $objectType,
        string $objectId,
        ?string $relation = null,
    ): array;
}
