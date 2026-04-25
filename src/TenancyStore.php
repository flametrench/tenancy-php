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
     * @return array{org: Organization, ownerMembership: Membership}
     */
    public function createOrg(string $creator): array;

    public function getOrg(string $orgId): Organization;

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
     * Accept an invitation. If $asUsrId is null a new usr_ id is generated.
     *
     * @return array{invitation: Invitation, membership: Membership, materializedTuples: list<Tuple>}
     */
    public function acceptInvitation(string $invId, ?string $asUsrId = null): array;

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
