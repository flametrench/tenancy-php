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
use PDO;
use PDOException;

/**
 * Postgres-backed TenancyStore. Mirrors InMemoryTenancyStore byte-for-byte
 * at the SDK boundary; the difference is durability and concurrency.
 * Schema lives in spec/reference/postgres.sql.
 *
 * Every operation that touches more than one row runs inside a
 * BEGIN/COMMIT block, so the spec's atomicity guarantees (membership +
 * tuple together, accept-with-pre-tuples, transferOwnership) are
 * backed by a real database transaction.
 */
final class PostgresTenancyStore implements TenancyStore
{
    public const UNSET = TenancyStore::UNSET;

    private const ADMIN_RANK = [
        'owner'  => 4,
        'admin'  => 3,
        'member' => 2,
        'guest'  => 1,
    ];

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly PDO $pdo,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function fmt(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s.uP');
    }

    private static function wireToUuid(string $wireId): string
    {
        return Id::decode($wireId)['uuid'];
    }

    /**
     * Run $fn inside BEGIN/COMMIT. Rolls back and rethrows on any error.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
                // Rollback may fail if connection is broken; surface original.
            }
            throw $e;
        }
    }

    /** Postgres SQLSTATE 23505 = unique_violation. Optionally narrow on constraint name. */
    private static function isUniqueViolation(PDOException $e, ?string $constraint = null): bool
    {
        $sqlstate = $e->errorInfo[0] ?? null;
        if ($sqlstate !== '23505' && !str_contains($e->getMessage(), 'SQLSTATE[23505]')) {
            return false;
        }
        if ($constraint === null) {
            return true;
        }
        return str_contains($e->getMessage(), $constraint);
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

    // ─── Row → entity mappers ───

    /** @param array<string, mixed> $r */
    private static function rowToOrg(array $r): Organization
    {
        return new Organization(
            id: Id::encode('org', (string) $r['id']),
            status: Status::from((string) $r['status']),
            createdAt: new \DateTimeImmutable((string) $r['created_at']),
            updatedAt: new \DateTimeImmutable((string) $r['updated_at']),
            name: $r['name'] !== null ? (string) $r['name'] : null,
            slug: $r['slug'] !== null ? (string) $r['slug'] : null,
        );
    }

    /** @param array<string, mixed> $r */
    private static function rowToMem(array $r): Membership
    {
        return new Membership(
            id: Id::encode('mem', (string) $r['id']),
            usrId: Id::encode('usr', (string) $r['usr_id']),
            orgId: Id::encode('org', (string) $r['org_id']),
            role: Role::from((string) $r['role']),
            status: Status::from((string) $r['status']),
            replaces: $r['replaces'] !== null ? Id::encode('mem', (string) $r['replaces']) : null,
            invitedBy: $r['invited_by'] !== null ? Id::encode('usr', (string) $r['invited_by']) : null,
            removedBy: $r['removed_by'] !== null ? Id::encode('usr', (string) $r['removed_by']) : null,
            createdAt: new \DateTimeImmutable((string) $r['created_at']),
            updatedAt: new \DateTimeImmutable((string) $r['updated_at']),
        );
    }

    /** @param array<string, mixed> $r */
    private static function rowToInv(array $r): Invitation
    {
        $preTuples = [];
        if ($r['pre_tuples'] !== null) {
            $decoded = is_array($r['pre_tuples'])
                ? $r['pre_tuples']
                : json_decode((string) $r['pre_tuples'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $pt) {
                    if (!is_array($pt)) continue;
                    $preTuples[] = new PreTuple(
                        relation: (string) ($pt['relation'] ?? ''),
                        objectType: (string) ($pt['object_type'] ?? $pt['objectType'] ?? ''),
                        objectId: (string) ($pt['object_id'] ?? $pt['objectId'] ?? ''),
                    );
                }
            }
        }
        return new Invitation(
            id: Id::encode('inv', (string) $r['id']),
            orgId: Id::encode('org', (string) $r['org_id']),
            identifier: (string) $r['identifier'],
            role: Role::from((string) $r['role']),
            status: InvitationStatus::from((string) $r['status']),
            preTuples: $preTuples,
            invitedBy: Id::encode('usr', (string) $r['invited_by']),
            invitedUserId: $r['invited_user_id'] !== null
                ? Id::encode('usr', (string) $r['invited_user_id'])
                : null,
            createdAt: new \DateTimeImmutable((string) $r['created_at']),
            expiresAt: new \DateTimeImmutable((string) $r['expires_at']),
            terminalAt: $r['terminal_at'] !== null
                ? new \DateTimeImmutable((string) $r['terminal_at'])
                : null,
            terminalBy: $r['terminal_by'] !== null
                ? Id::encode('usr', (string) $r['terminal_by'])
                : null,
        );
    }

    /** @param array<string, mixed> $r */
    private static function rowToTup(array $r): Tuple
    {
        return new Tuple(
            subjectType: (string) $r['subject_type'],
            subjectId: Id::encode('usr', (string) $r['subject_id']),
            relation: (string) $r['relation'],
            objectType: (string) $r['object_type'],
            objectId: (string) $r['object_id'],
        );
    }

    // ─── Organizations ───

    public function createOrg(
        string $creator,
        ?string $name = null,
        ?string $slug = null,
    ): array {
        if ($slug !== null) {
            self::validateSlug($slug);
        }
        $now = self::fmt($this->now());
        $orgUuid = Id::decode(Id::generate('org'))['uuid'];
        $memUuid = Id::decode(Id::generate('mem'))['uuid'];
        $tupUuid = Id::decode(Id::generate('tup'))['uuid'];
        $creatorUuid = self::wireToUuid($creator);

        return $this->tx(function () use ($creator, $name, $slug, $now, $orgUuid, $memUuid, $tupUuid, $creatorUuid) {
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO org (id, status, name, slug, created_at, updated_at)
                     VALUES (?, 'active', ?, ?, ?, ?)"
                );
                $stmt->execute([$orgUuid, $name, $slug, $now, $now]);
            } catch (PDOException $e) {
                if ($slug !== null && self::isUniqueViolation($e, 'org_slug_unique')) {
                    throw new OrgSlugConflictException($slug);
                }
                throw $e;
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO mem (id, usr_id, org_id, role, status, created_at, updated_at)
                 VALUES (?, ?, ?, 'owner', 'active', ?, ?)"
            );
            $stmt->execute([$memUuid, $creatorUuid, $orgUuid, $now, $now]);
            $stmt = $this->pdo->prepare(
                "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at, created_by)
                 VALUES (?, 'usr', ?, 'owner', 'org', ?, ?, ?)"
            );
            $stmt->execute([$tupUuid, $creatorUuid, $orgUuid, $now, $creatorUuid]);

            $createdAt = new \DateTimeImmutable($now);
            $org = new Organization(
                id: Id::encode('org', $orgUuid),
                status: Status::Active,
                createdAt: $createdAt,
                updatedAt: $createdAt,
                name: $name,
                slug: $slug,
            );
            $ownerMembership = new Membership(
                id: Id::encode('mem', $memUuid),
                usrId: $creator,
                orgId: $org->id,
                role: Role::Owner,
                status: Status::Active,
                replaces: null,
                invitedBy: null,
                removedBy: null,
                createdAt: $createdAt,
                updatedAt: $createdAt,
            );
            return ['org' => $org, 'ownerMembership' => $ownerMembership];
        });
    }

    public function getOrg(string $orgId): Organization
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, name, slug, created_at, updated_at FROM org WHERE id = ?'
        );
        $stmt->execute([self::wireToUuid($orgId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Organization {$orgId} not found");
        }
        return self::rowToOrg($row);
    }

    public function updateOrg(
        string $orgId,
        string|null $name = self::UNSET,
        string|null $slug = self::UNSET,
    ): Organization {
        return $this->tx(function () use ($orgId, $name, $slug) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, name, slug, created_at, updated_at FROM org WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($orgId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Organization {$orgId} not found");
            }
            if ($row['status'] === 'revoked') {
                throw new AlreadyTerminalException("Org {$orgId} is revoked; cannot update");
            }
            $newName = $name === self::UNSET ? $row['name'] : $name;
            $newSlug = $slug === self::UNSET ? $row['slug'] : $slug;
            if ($slug !== self::UNSET && $newSlug !== null) {
                self::validateSlug($newSlug);
            }
            $now = self::fmt($this->now());
            try {
                $stmt = $this->pdo->prepare(
                    'UPDATE org SET name = ?, slug = ?, updated_at = ?
                     WHERE id = ?
                     RETURNING id, status, name, slug, created_at, updated_at'
                );
                $stmt->execute([$newName, $newSlug, $now, self::wireToUuid($orgId)]);
                /** @var array<string, mixed> $updated */
                $updated = $stmt->fetch(PDO::FETCH_ASSOC);
                return self::rowToOrg($updated);
            } catch (PDOException $e) {
                if ($newSlug !== null && self::isUniqueViolation($e, 'org_slug_unique')) {
                    throw new OrgSlugConflictException($newSlug);
                }
                throw $e;
            }
        });
    }

    private function transitionOrg(string $orgId, Status $to): Organization
    {
        return $this->tx(function () use ($orgId, $to) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, name, slug, created_at, updated_at FROM org WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($orgId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Organization {$orgId} not found");
            }
            if ($row['status'] === 'revoked') {
                throw new AlreadyTerminalException("Org {$orgId} is revoked; cannot transition");
            }
            if ($row['status'] === $to->value) {
                throw new AlreadyTerminalException("Org {$orgId} is already {$to->value}");
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                'UPDATE org SET status = ?, updated_at = ? WHERE id = ?
                 RETURNING id, status, name, slug, created_at, updated_at'
            );
            $stmt->execute([$to->value, $now, self::wireToUuid($orgId)]);
            /** @var array<string, mixed> $updated */
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToOrg($updated);
        });
    }

    public function suspendOrg(string $orgId): Organization
    {
        return $this->transitionOrg($orgId, Status::Suspended);
    }

    public function reinstateOrg(string $orgId): Organization
    {
        return $this->tx(function () use ($orgId) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, name, slug, created_at, updated_at FROM org WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($orgId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Organization {$orgId} not found");
            }
            if ($row['status'] !== 'suspended') {
                throw new PreconditionException(
                    "Org {$orgId} is {$row['status']}; only suspended orgs can be reinstated",
                    'invalid_transition',
                );
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                "UPDATE org SET status = 'active', updated_at = ? WHERE id = ?
                 RETURNING id, status, name, slug, created_at, updated_at"
            );
            $stmt->execute([$now, self::wireToUuid($orgId)]);
            /** @var array<string, mixed> $updated */
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToOrg($updated);
        });
    }

    public function revokeOrg(string $orgId): Organization
    {
        return $this->tx(function () use ($orgId) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, name, slug, created_at, updated_at FROM org WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($orgId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Organization {$orgId} not found");
            }
            if ($row['status'] === 'revoked') {
                throw new AlreadyTerminalException("Org {$orgId} is already revoked");
            }
            $now = self::fmt($this->now());
            $orgUuid = self::wireToUuid($orgId);
            $stmt = $this->pdo->prepare(
                "DELETE FROM tup WHERE object_type = 'org' AND object_id = ?"
            );
            $stmt->execute([$orgUuid]);
            $stmt = $this->pdo->prepare(
                "UPDATE mem SET status = 'revoked', updated_at = ?
                 WHERE org_id = ? AND status = 'active'"
            );
            $stmt->execute([$now, $orgUuid]);
            $stmt = $this->pdo->prepare(
                "UPDATE org SET status = 'revoked', updated_at = ? WHERE id = ?
                 RETURNING id, status, name, slug, created_at, updated_at"
            );
            $stmt->execute([$now, $orgUuid]);
            /** @var array<string, mixed> $updated */
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToOrg($updated);
        });
    }

    // ─── Memberships ───

    public function addMember(
        string $orgId,
        string $usrId,
        Role $role,
        ?string $invitedBy = null,
    ): Membership {
        return $this->tx(function () use ($orgId, $usrId, $role, $invitedBy) {
            $stmt = $this->pdo->prepare('SELECT status FROM org WHERE id = ?');
            $stmt->execute([self::wireToUuid($orgId)]);
            $orgRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($orgRow === false) {
                throw new NotFoundException("Organization {$orgId} not found");
            }
            if ($orgRow['status'] !== 'active') {
                throw new PreconditionException(
                    "Cannot add member to {$orgRow['status']} org",
                    'org_not_active',
                );
            }
            $memUuid = Id::decode(Id::generate('mem'))['uuid'];
            $tupUuid = Id::decode(Id::generate('tup'))['uuid'];
            $now = self::fmt($this->now());
            $usrUuid = self::wireToUuid($usrId);
            $orgUuid = self::wireToUuid($orgId);
            $invitedByUuid = $invitedBy !== null ? self::wireToUuid($invitedBy) : null;
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO mem (id, usr_id, org_id, role, status, invited_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
                     RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
                );
                $stmt->execute([$memUuid, $usrUuid, $orgUuid, $role->value, $invitedByUuid, $now, $now]);
                /** @var array<string, mixed> $row */
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at, created_by)
                     VALUES (?, 'usr', ?, ?, 'org', ?, ?, ?)"
                );
                $stmt->execute([$tupUuid, $usrUuid, $role->value, $orgUuid, $now, $invitedByUuid]);
                return self::rowToMem($row);
            } catch (PDOException $e) {
                if (self::isUniqueViolation($e)) {
                    throw new DuplicateMembershipException(
                        "User {$usrId} already has an active membership in {$orgId}",
                    );
                }
                throw $e;
            }
        });
    }

    public function getMembership(string $memId): Membership
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at
             FROM mem WHERE id = ?'
        );
        $stmt->execute([self::wireToUuid($memId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Membership {$memId} not found");
        }
        return self::rowToMem($row);
    }

    public function listMembers(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?Status $status = null,
    ): Page {
        $params = [self::wireToUuid($orgId)];
        $where = ['org_id = ?'];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }
        if ($cursor !== null) {
            $where[] = 'id > ?';
            $params[] = self::wireToUuid($cursor);
        }
        $params[] = $limit;
        $stmt = $this->pdo->prepare(
            'SELECT id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at
             FROM mem
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY id LIMIT ?'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(self::rowToMem(...), $rows);
        $next = count($data) === $limit && count($data) > 0
            ? $data[count($data) - 1]->id
            : null;
        return new Page(data: $data, nextCursor: $next);
    }

    public function changeRole(string $memId, Role $newRole): Membership
    {
        return $this->tx(function () use ($memId, $newRole) {
            $old = $this->lockMembership($memId);
            if ($old['status'] !== 'active') {
                throw new PreconditionException(
                    "Membership {$memId} is {$old['status']}; only active memberships can change role",
                    'mem_not_active',
                );
            }
            if ($old['role'] === 'owner' && $newRole !== Role::Owner) {
                $this->guardSoleOwner($old['org_id']);
            }
            return $this->rotateMembership($old, $newRole, removedBy: null);
        });
    }

    public function suspendMembership(string $memId): Membership
    {
        return $this->tx(function () use ($memId) {
            $mem = $this->lockMembership($memId);
            if ($mem['status'] !== 'active') {
                throw new PreconditionException(
                    "Membership {$memId} is {$mem['status']}; only active memberships can be suspended",
                    'mem_not_active',
                );
            }
            if ($mem['role'] === 'owner') {
                $this->guardSoleOwner($mem['org_id']);
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                "DELETE FROM tup WHERE subject_type = 'usr' AND subject_id = ? AND relation = ?
                 AND object_type = 'org' AND object_id = ?"
            );
            $stmt->execute([$mem['usr_id'], $mem['role'], $mem['org_id']]);
            $stmt = $this->pdo->prepare(
                "UPDATE mem SET status = 'suspended', updated_at = ? WHERE id = ?
                 RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
            );
            $stmt->execute([$now, $mem['id']]);
            /** @var array<string, mixed> $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToMem($row);
        });
    }

    public function reinstateMembership(string $memId): Membership
    {
        return $this->tx(function () use ($memId) {
            $mem = $this->lockMembership($memId);
            if ($mem['status'] !== 'suspended') {
                throw new PreconditionException(
                    "Membership {$memId} is {$mem['status']}; only suspended memberships can be reinstated",
                    'invalid_transition',
                );
            }
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c FROM mem WHERE usr_id = ? AND org_id = ? AND status = 'active'"
            );
            $stmt->execute([$mem['usr_id'], $mem['org_id']]);
            /** @var array{c: int|string} $cnt */
            $cnt = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $cnt['c'] > 0) {
                throw new DuplicateMembershipException(
                    'User has a separate active membership in this org; cannot reinstate',
                );
            }
            $now = self::fmt($this->now());
            $tupUuid = Id::decode(Id::generate('tup'))['uuid'];
            $stmt = $this->pdo->prepare(
                "UPDATE mem SET status = 'active', updated_at = ? WHERE id = ?
                 RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
            );
            $stmt->execute([$now, $mem['id']]);
            /** @var array<string, mixed> $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $this->pdo->prepare(
                "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at)
                 VALUES (?, 'usr', ?, ?, 'org', ?, ?)"
            );
            $stmt->execute([$tupUuid, $mem['usr_id'], $mem['role'], $mem['org_id'], $now]);
            return self::rowToMem($row);
        });
    }

    public function selfLeave(string $memId, ?string $transferTo = null): Membership
    {
        return $this->tx(function () use ($memId, $transferTo) {
            $mem = $this->lockMembership($memId);
            if ($mem['status'] !== 'active') {
                throw new PreconditionException(
                    "Membership {$memId} is {$mem['status']}; only active memberships can self-leave",
                    'mem_not_active',
                );
            }
            if ($mem['role'] === 'owner' && $this->countOwners($mem['org_id']) === 1) {
                if ($transferTo === null) {
                    throw new SoleOwnerException(
                        'Cannot self-leave as sole active owner; pass transferTo to atomically transfer ownership',
                    );
                }
                $targetUuid = self::wireToUuid($transferTo);
                $stmt = $this->pdo->prepare(
                    "SELECT id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at
                     FROM mem WHERE usr_id = ? AND org_id = ? AND status = 'active' FOR UPDATE"
                );
                $stmt->execute([$targetUuid, $mem['org_id']]);
                $target = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($target === false) {
                    throw new NotFoundException(
                        "transferTo user {$transferTo} has no active membership in org",
                    );
                }
                // Promote target to owner; subsequent self-revoke won't trip sole-owner.
                $this->rotateMembership($target, Role::Owner, removedBy: null);
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                "DELETE FROM tup WHERE subject_type = 'usr' AND subject_id = ? AND relation = ?
                 AND object_type = 'org' AND object_id = ?"
            );
            $stmt->execute([$mem['usr_id'], $mem['role'], $mem['org_id']]);
            $stmt = $this->pdo->prepare(
                "UPDATE mem SET status = 'revoked', removed_by = NULL, updated_at = ? WHERE id = ?
                 RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
            );
            $stmt->execute([$now, $mem['id']]);
            /** @var array<string, mixed> $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToMem($row);
        });
    }

    public function adminRemove(string $memId, string $adminUsrId): Membership
    {
        return $this->tx(function () use ($memId, $adminUsrId) {
            $target = $this->lockMembership($memId);
            if ($target['status'] !== 'active') {
                throw new PreconditionException(
                    "Target membership {$memId} is {$target['status']}",
                    'mem_not_active',
                );
            }
            $stmt = $this->pdo->prepare(
                "SELECT usr_id, role, status FROM mem
                 WHERE usr_id = ? AND org_id = ? AND status = 'active'"
            );
            $stmt->execute([self::wireToUuid($adminUsrId), $target['org_id']]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($admin === false) {
                throw new ForbiddenException(
                    "User {$adminUsrId} has no active membership in target org",
                );
            }
            if ($admin['role'] !== 'owner' && $admin['role'] !== 'admin') {
                throw new ForbiddenException(
                    "Role {$admin['role']} is not permitted to remove members",
                );
            }
            if ($target['role'] === 'owner') {
                throw new RoleHierarchyException(
                    'Owner removal requires transferOwnership, not adminRemove',
                );
            }
            $adminRank = self::ADMIN_RANK[$admin['role']] ?? null;
            $targetRank = self::ADMIN_RANK[$target['role']] ?? null;
            if ($adminRank === null || $targetRank === null) {
                throw new PreconditionException(
                    'adminRemove operates only on owner/admin/member/guest roles',
                    'scope_mismatch',
                );
            }
            if ($adminRank < $targetRank) {
                throw new RoleHierarchyException(
                    "Role {$admin['role']} cannot remove role {$target['role']}",
                );
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                "DELETE FROM tup WHERE subject_type = 'usr' AND subject_id = ? AND relation = ?
                 AND object_type = 'org' AND object_id = ?"
            );
            $stmt->execute([$target['usr_id'], $target['role'], $target['org_id']]);
            $stmt = $this->pdo->prepare(
                "UPDATE mem SET status = 'revoked', removed_by = ?, updated_at = ? WHERE id = ?
                 RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
            );
            $stmt->execute([$admin['usr_id'], $now, $target['id']]);
            /** @var array<string, mixed> $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToMem($row);
        });
    }

    public function transferOwnership(string $orgId, string $fromMemId, string $toMemId): array
    {
        return $this->tx(function () use ($orgId, $fromMemId, $toMemId) {
            $orgUuid = self::wireToUuid($orgId);
            $from = $this->lockMembership($fromMemId);
            $to = $this->lockMembership($toMemId);
            if ($from['status'] !== 'active') {
                throw new PreconditionException("From membership is {$from['status']}", 'from_not_active');
            }
            if ($to['status'] !== 'active') {
                throw new PreconditionException("To membership is {$to['status']}", 'to_not_active');
            }
            if ($from['org_id'] !== $orgUuid || $to['org_id'] !== $orgUuid) {
                throw new PreconditionException("Both memberships must belong to {$orgId}", 'org_mismatch');
            }
            if ($from['role'] !== 'owner') {
                throw new PreconditionException('From membership must hold the owner role', 'from_not_owner');
            }
            if ($from['usr_id'] === $to['usr_id']) {
                throw new PreconditionException('Cannot transfer ownership to self', 'self_transfer');
            }
            // Promote target first so demoting the donor doesn't trip sole-owner.
            $toMembership = $this->rotateMembership($to, Role::Owner, removedBy: null);
            $fromMembership = $this->rotateMembership($from, Role::Member, removedBy: null);
            return ['fromMembership' => $fromMembership, 'toMembership' => $toMembership];
        });
    }

    /**
     * Lock and return a `mem` row. Caller is responsible for being inside a tx.
     *
     * @return array<string, mixed>
     */
    private function lockMembership(string $memId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at
             FROM mem WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([self::wireToUuid($memId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Membership {$memId} not found");
        }
        return $row;
    }

    private function countOwners(string $orgUuid): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM mem WHERE org_id = ? AND role = 'owner' AND status = 'active'"
        );
        $stmt->execute([$orgUuid]);
        /** @var array{c: int|string} $cnt */
        $cnt = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $cnt['c'];
    }

    private function guardSoleOwner(string $orgUuid): void
    {
        if ($this->countOwners($orgUuid) === 1) {
            throw new SoleOwnerException(
                'Cannot transition the sole active owner; transfer ownership first',
            );
        }
    }

    /**
     * Revoke an old `mem` row and insert a fresh one with `replaces` set.
     * Swaps the corresponding `tup` row. Caller is responsible for being inside a tx.
     *
     * @param  array<string, mixed>  $old
     */
    private function rotateMembership(array $old, Role $newRole, ?string $removedBy): Membership
    {
        $now = self::fmt($this->now());
        $newMemUuid = Id::decode(Id::generate('mem'))['uuid'];
        $newTupUuid = Id::decode(Id::generate('tup'))['uuid'];
        $stmt = $this->pdo->prepare(
            "UPDATE mem SET status = 'revoked', updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$now, $old['id']]);
        $stmt = $this->pdo->prepare(
            "DELETE FROM tup WHERE subject_type = 'usr' AND subject_id = ? AND relation = ?
             AND object_type = 'org' AND object_id = ?"
        );
        $stmt->execute([$old['usr_id'], $old['role'], $old['org_id']]);
        $stmt = $this->pdo->prepare(
            "INSERT INTO mem (id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
             RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
        );
        $stmt->execute([
            $newMemUuid, $old['usr_id'], $old['org_id'], $newRole->value,
            $old['id'], $old['invited_by'], $removedBy, $now, $now,
        ]);
        /** @var array<string, mixed> $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare(
            "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at)
             VALUES (?, 'usr', ?, ?, 'org', ?, ?)"
        );
        $stmt->execute([$newTupUuid, $old['usr_id'], $newRole->value, $old['org_id'], $now]);
        return self::rowToMem($row);
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
        $org = $this->getOrg($orgId);
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
        $invUuid = Id::decode(Id::generate('inv'))['uuid'];
        $preTuplesJson = json_encode(array_map(
            static fn(PreTuple $pt): array => [
                'relation' => $pt->relation,
                'object_type' => $pt->objectType,
                'object_id' => $pt->objectId,
            ],
            array_values($preTuples),
        ), JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            "INSERT INTO inv (id, org_id, identifier, role, status, pre_tuples, invited_by, created_at, expires_at)
             VALUES (?, ?, ?, ?, 'pending', ?::jsonb, ?, ?, ?)
             RETURNING id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id, created_at, expires_at, terminal_at, terminal_by"
        );
        $stmt->execute([
            $invUuid,
            self::wireToUuid($orgId),
            $identifier,
            $role->value,
            $preTuplesJson,
            self::wireToUuid($invitedBy),
            self::fmt($now),
            self::fmt($expiresAt),
        ]);
        /** @var array<string, mixed> $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return self::rowToInv($row);
    }

    public function getInvitation(string $invId): Invitation
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                    created_at, expires_at, terminal_at, terminal_by
             FROM inv WHERE id = ?'
        );
        $stmt->execute([self::wireToUuid($invId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Invitation {$invId} not found");
        }
        return self::rowToInv($row);
    }

    public function listInvitations(
        string $orgId,
        ?string $cursor = null,
        int $limit = 50,
        ?InvitationStatus $status = null,
    ): Page {
        $params = [self::wireToUuid($orgId)];
        $where = ['org_id = ?'];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }
        if ($cursor !== null) {
            $where[] = 'id > ?';
            $params[] = self::wireToUuid($cursor);
        }
        $params[] = $limit;
        $stmt = $this->pdo->prepare(
            'SELECT id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                    created_at, expires_at, terminal_at, terminal_by
             FROM inv
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY id LIMIT ?'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(self::rowToInv(...), $rows);
        $next = count($data) === $limit && count($data) > 0
            ? $data[count($data) - 1]->id
            : null;
        return new Page(data: $data, nextCursor: $next);
    }

    public function acceptInvitation(
        string $invId,
        ?string $asUsrId = null,
        ?string $acceptingIdentifier = null,
    ): array {
        return $this->tx(function () use ($invId, $asUsrId, $acceptingIdentifier) {
            $stmt = $this->pdo->prepare(
                'SELECT id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                        created_at, expires_at, terminal_at, terminal_by
                 FROM inv WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($invId)]);
            $invRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invRow === false) {
                throw new NotFoundException("Invitation {$invId} not found");
            }
            if ($invRow['status'] !== 'pending') {
                throw new InvitationNotPendingException(
                    "Invitation {$invId} is {$invRow['status']}, not pending",
                );
            }
            $now = $this->now();
            $expiresAt = new \DateTimeImmutable((string) $invRow['expires_at']);
            if ($now > $expiresAt) {
                throw new InvitationExpiredException(
                    "Invitation {$invId} expired at " . $expiresAt->format(\DateTimeInterface::ATOM),
                );
            }
            // ADR 0009: existing-user accept MUST supply a matching identifier.
            if ($asUsrId !== null) {
                if ($acceptingIdentifier === null) {
                    throw new IdentifierBindingRequiredException();
                }
                if ($acceptingIdentifier !== (string) $invRow['identifier']) {
                    throw new IdentifierMismatchException($acceptingIdentifier, (string) $invRow['identifier']);
                }
            }
            $usrUuid = $asUsrId !== null
                ? self::wireToUuid($asUsrId)
                : Id::decode(Id::generate('usr'))['uuid'];
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c FROM mem WHERE usr_id = ? AND org_id = ? AND status = 'active'"
            );
            $stmt->execute([$usrUuid, $invRow['org_id']]);
            /** @var array{c: int|string} $cnt */
            $cnt = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $cnt['c'] > 0) {
                throw new DuplicateMembershipException(
                    'User already has an active membership in this org',
                );
            }
            $memUuid = Id::decode(Id::generate('mem'))['uuid'];
            $tupUuid = Id::decode(Id::generate('tup'))['uuid'];
            $nowFmt = self::fmt($now);
            $stmt = $this->pdo->prepare(
                "INSERT INTO mem (id, usr_id, org_id, role, status, invited_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
                 RETURNING id, usr_id, org_id, role, status, replaces, invited_by, removed_by, created_at, updated_at"
            );
            $stmt->execute([$memUuid, $usrUuid, $invRow['org_id'], (string) $invRow['role'], $invRow['invited_by'], $nowFmt, $nowFmt]);
            /** @var array<string, mixed> $memRow */
            $memRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $this->pdo->prepare(
                "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at)
                 VALUES (?, 'usr', ?, ?, 'org', ?, ?)"
            );
            $stmt->execute([$tupUuid, $usrUuid, (string) $invRow['role'], $invRow['org_id'], $nowFmt]);

            $materializedTuples = [];
            $preTuples = is_array($invRow['pre_tuples'])
                ? $invRow['pre_tuples']
                : (json_decode((string) $invRow['pre_tuples'], true) ?? []);
            foreach ($preTuples as $pt) {
                if (!is_array($pt)) continue;
                $relation = (string) ($pt['relation'] ?? '');
                $objectType = (string) ($pt['object_type'] ?? $pt['objectType'] ?? '');
                $objectId = (string) ($pt['object_id'] ?? $pt['objectId'] ?? '');
                $ptTupUuid = Id::decode(Id::generate('tup'))['uuid'];
                $stmt = $this->pdo->prepare(
                    "INSERT INTO tup (id, subject_type, subject_id, relation, object_type, object_id, created_at)
                     VALUES (?, 'usr', ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$ptTupUuid, $usrUuid, $relation, $objectType, $objectId, $nowFmt]);
                $materializedTuples[] = new Tuple(
                    subjectType: 'usr',
                    subjectId: Id::encode('usr', $usrUuid),
                    relation: $relation,
                    objectType: $objectType,
                    objectId: $objectId,
                );
            }

            $stmt = $this->pdo->prepare(
                "UPDATE inv SET status = 'accepted', terminal_at = ?, terminal_by = ?, invited_user_id = ?
                 WHERE id = ?
                 RETURNING id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                          created_at, expires_at, terminal_at, terminal_by"
            );
            $stmt->execute([$nowFmt, $usrUuid, $usrUuid, $invRow['id']]);
            /** @var array<string, mixed> $invOut */
            $invOut = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'invitation' => self::rowToInv($invOut),
                'membership' => self::rowToMem($memRow),
                'materializedTuples' => $materializedTuples,
            ];
        });
    }

    public function declineInvitation(string $invId, ?string $asUsrId = null): Invitation
    {
        return $this->tx(function () use ($invId, $asUsrId) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status FROM inv WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($invId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Invitation {$invId} not found");
            }
            if ($row['status'] !== 'pending') {
                throw new InvitationNotPendingException("Invitation {$invId} is {$row['status']}");
            }
            $now = self::fmt($this->now());
            $by = $asUsrId !== null ? self::wireToUuid($asUsrId) : null;
            $stmt = $this->pdo->prepare(
                "UPDATE inv SET status = 'declined', terminal_at = ?, terminal_by = ? WHERE id = ?
                 RETURNING id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                          created_at, expires_at, terminal_at, terminal_by"
            );
            $stmt->execute([$now, $by, $row['id']]);
            /** @var array<string, mixed> $updated */
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToInv($updated);
        });
    }

    public function revokeInvitation(string $invId, string $adminUsrId): Invitation
    {
        return $this->tx(function () use ($invId, $adminUsrId) {
            $stmt = $this->pdo->prepare(
                'SELECT id, status FROM inv WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([self::wireToUuid($invId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new NotFoundException("Invitation {$invId} not found");
            }
            if ($row['status'] !== 'pending') {
                throw new InvitationNotPendingException("Invitation {$invId} is {$row['status']}");
            }
            $now = self::fmt($this->now());
            $stmt = $this->pdo->prepare(
                "UPDATE inv SET status = 'revoked', terminal_at = ?, terminal_by = ? WHERE id = ?
                 RETURNING id, org_id, identifier, role, status, pre_tuples, invited_by, invited_user_id,
                          created_at, expires_at, terminal_at, terminal_by"
            );
            $stmt->execute([$now, self::wireToUuid($adminUsrId), $row['id']]);
            /** @var array<string, mixed> $updated */
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::rowToInv($updated);
        });
    }

    // ─── Tuple accessors ───

    public function listTuplesForSubject(string $subjectType, string $subjectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, subject_type, subject_id, relation, object_type, object_id, created_at, created_by
             FROM tup WHERE subject_type = ? AND subject_id = ?'
        );
        $stmt->execute([$subjectType, self::wireToUuid($subjectId)]);
        return array_map(self::rowToTup(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listTuplesForObject(string $objectType, string $objectId, ?string $relation = null): array
    {
        $params = [$objectType, $objectId];
        $sql = 'SELECT id, subject_type, subject_id, relation, object_type, object_id, created_at, created_by
                FROM tup WHERE object_type = ? AND object_id = ?';
        if ($relation !== null) {
            $sql .= ' AND relation = ?';
            $params[] = $relation;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(self::rowToTup(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
