<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Flametrench\Ids\Id;
use Flametrench\Tenancy\Exceptions\AlreadyTerminalException;
use Flametrench\Tenancy\Exceptions\DuplicateMembershipException;
use Flametrench\Tenancy\Exceptions\InvitationNotPendingException;
use Flametrench\Tenancy\Exceptions\NotFoundException;
use Flametrench\Tenancy\Exceptions\OrgSlugConflictException;
use Flametrench\Tenancy\Exceptions\PreconditionException;
use Flametrench\Tenancy\Exceptions\RoleHierarchyException;
use Flametrench\Tenancy\Exceptions\SoleOwnerException;
use Flametrench\Tenancy\InvitationStatus;
use Flametrench\Tenancy\PostgresTenancyStore;
use Flametrench\Tenancy\PreTuple;
use Flametrench\Tenancy\Role;
use Flametrench\Tenancy\Status;

$tenancyPostgresUrl = getenv('TENANCY_POSTGRES_URL') ?: null;

if ($tenancyPostgresUrl === null) {
    fwrite(STDERR, "[PostgresTenancyStoreTest] TENANCY_POSTGRES_URL not set; tests skipped.\n");
    return;
}

beforeEach(function () use ($tenancyPostgresUrl) {
    $pdo = pgPdoFromUrl($tenancyPostgresUrl);
    $this->pdo = $pdo;
    $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
    $pdo->exec((string) file_get_contents(__DIR__ . '/postgres-schema.sql'));
    $this->store = new PostgresTenancyStore($pdo);
    $this->alice = Id::generate('usr');
    $this->bob = Id::generate('usr');
    $this->carol = Id::generate('usr');
    foreach ([$this->alice, $this->bob, $this->carol] as $u) {
        $stmt = $pdo->prepare("INSERT INTO usr (id, status) VALUES (?, 'active')");
        $stmt->execute([Id::decode($u)['uuid']]);
    }
});

// ───── createOrg ─────

it('creates org + owner membership + membership tuple transactionally', function () {
    ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    expect($org->status)->toBe(Status::Active);
    expect($owner->role)->toBe(Role::Owner);
    expect($owner->usrId)->toBe($this->alice);
    $tuples = $this->store->listTuplesForSubject('usr', $this->alice);
    expect($tuples)->toHaveCount(1);
    expect($tuples[0]->relation)->toBe('owner');
});

it('createOrg persists name and slug round-trip', function () {
    ['org' => $org] = $this->store->createOrg($this->alice, name: 'Acme', slug: 'acme');
    $fetched = $this->store->getOrg($org->id);
    expect($fetched->name)->toBe('Acme');
    expect($fetched->slug)->toBe('acme');
});

it('createOrg with duplicate slug raises OrgSlugConflictException', function () {
    $this->store->createOrg($this->alice, slug: 'shared');
    $this->store->createOrg($this->bob, slug: 'shared');
})->throws(OrgSlugConflictException::class);

it('createOrg with malformed slug raises PreconditionException', function () {
    $this->store->createOrg($this->alice, slug: 'AcmeInc');
})->throws(PreconditionException::class);

// ───── updateOrg ─────

it('updateOrg partial-updates name only, leaves slug untouched', function () {
    ['org' => $org] = $this->store->createOrg($this->alice, name: 'Old', slug: 'old-slug');
    $updated = $this->store->updateOrg($org->id, name: 'New');
    expect($updated->name)->toBe('New');
    expect($updated->slug)->toBe('old-slug');
});

it('updateOrg with explicit null clears the slug', function () {
    ['org' => $org] = $this->store->createOrg($this->alice, slug: 'to-clear');
    $updated = $this->store->updateOrg($org->id, slug: null);
    expect($updated->slug)->toBeNull();
});

it('updateOrg of revoked org raises AlreadyTerminalException', function () {
    ['org' => $org] = $this->store->createOrg($this->alice, name: 'RIP');
    $this->store->revokeOrg($org->id);
    $this->store->updateOrg($org->id, name: 'Whatever');
})->throws(AlreadyTerminalException::class);

// ───── addMember / changeRole ─────

it('adds a member and creates the membership tuple', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $mem = $this->store->addMember($org->id, $this->bob, Role::Member, invitedBy: $this->alice);
    expect($mem->role)->toBe(Role::Member);
    expect($mem->invitedBy)->toBe($this->alice);
    expect($this->store->listTuplesForSubject('usr', $this->bob))->toHaveCount(1);
});

it('rejects duplicate active memberships', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $this->store->addMember($org->id, $this->bob, Role::Member);
    $this->store->addMember($org->id, $this->bob, Role::Admin);
})->throws(DuplicateMembershipException::class);

it('changeRole: replaces chain and tuple swap are atomic', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
    $newMem = $this->store->changeRole($bobMem->id, Role::Admin);
    expect($newMem->replaces)->toBe($bobMem->id);
    expect($newMem->role)->toBe(Role::Admin);
    $oldMem = $this->store->getMembership($bobMem->id);
    expect($oldMem->status)->toBe(Status::Revoked);
    $tuples = $this->store->listTuplesForSubject('usr', $this->bob);
    expect($tuples)->toHaveCount(1);
    expect($tuples[0]->relation)->toBe('admin');
});

it('changeRole: refuses demoting the sole active owner', function () {
    ['ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    $this->store->changeRole($owner->id, Role::Member);
})->throws(SoleOwnerException::class);

// ───── suspend / reinstate ─────

it('suspendMembership removes the tuple; reinstate restores it', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
    $this->store->suspendMembership($bobMem->id);
    expect($this->store->listTuplesForSubject('usr', $this->bob))->toBe([]);
    $this->store->reinstateMembership($bobMem->id);
    expect($this->store->listTuplesForSubject('usr', $this->bob))->toHaveCount(1);
});

// ───── selfLeave ─────

it('selfLeave: non-owner leaves without transfer; removedBy is null', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
    $left = $this->store->selfLeave($bobMem->id);
    expect($left->status)->toBe(Status::Revoked);
    expect($left->removedBy)->toBeNull();
});

it('selfLeave: sole-owner with transferTo atomically transfers + revokes', function () {
    ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    $this->store->addMember($org->id, $this->bob, Role::Member);
    $left = $this->store->selfLeave($owner->id, transferTo: $this->bob);
    expect($left->status)->toBe(Status::Revoked);
    expect($this->store->listTuplesForSubject('usr', $this->alice))->toBe([]);
    $bobTuples = $this->store->listTuplesForSubject('usr', $this->bob);
    expect($bobTuples)->toHaveCount(1);
    expect($bobTuples[0]->relation)->toBe('owner');
});

it('selfLeave: sole-owner without transferTo is rejected', function () {
    ['ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    $this->store->selfLeave($owner->id);
})->throws(SoleOwnerException::class);

// ───── adminRemove ─────

it('adminRemove: removedBy is the admin', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
    $removed = $this->store->adminRemove($bobMem->id, adminUsrId: $this->alice);
    expect($removed->removedBy)->toBe($this->alice);
});

it('adminRemove: cannot remove an owner', function () {
    ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    $this->store->addMember($org->id, $this->bob, Role::Admin);
    $this->store->adminRemove($owner->id, adminUsrId: $this->bob);
})->throws(RoleHierarchyException::class);

// ───── transferOwnership ─────

it('transferOwnership atomically demotes owner + promotes target', function () {
    ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
    $bobMem = $this->store->addMember($org->id, $this->bob, Role::Admin);
    $result = $this->store->transferOwnership($org->id, $owner->id, $bobMem->id);
    expect($result['fromMembership']->role)->toBe(Role::Member);
    expect($result['toMembership']->role)->toBe(Role::Owner);
    $aliceTuples = $this->store->listTuplesForSubject('usr', $this->alice);
    expect(array_map(fn($t) => $t->relation, $aliceTuples))->toBe(['member']);
    $bobTuples = $this->store->listTuplesForSubject('usr', $this->bob);
    expect(array_map(fn($t) => $t->relation, $bobTuples))->toBe(['owner']);
});

// ───── Invitations ─────

it('acceptInvitation materializes membership + pre-tuples in one transaction', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $projectId = '0190f2a8-1b3c-7abc-8123-456789abcdef';
    $inv = $this->store->createInvitation(
        orgId: $org->id,
        identifier: 'carol@example.com',
        role: Role::Guest,
        invitedBy: $this->alice,
        expiresAt: new \DateTimeImmutable('+1 hour'),
        preTuples: [
            new PreTuple(relation: 'viewer', objectType: 'proj', objectId: $projectId),
        ],
    );
    $result = $this->store->acceptInvitation(
        invId: $inv->id,
        asUsrId: $this->carol,
        acceptingIdentifier: 'carol@example.com',
    );
    expect($result['materializedTuples'])->toHaveCount(1);
    expect($result['invitation']->status)->toBe(InvitationStatus::Accepted);
    expect($result['invitation']->terminalBy)->toBe($this->carol);
    $carolTuples = $this->store->listTuplesForSubject('usr', $this->carol);
    expect($carolTuples)->toHaveCount(2);
    $viewer = array_values(array_filter($carolTuples, fn($t) => $t->relation === 'viewer'))[0] ?? null;
    expect($viewer?->objectId)->toBe($projectId);
});

it('acceptInvitation: non-pending invitation is rejected', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $inv = $this->store->createInvitation(
        orgId: $org->id,
        identifier: 'x@y',
        role: Role::Member,
        invitedBy: $this->alice,
        expiresAt: new \DateTimeImmutable('+1 hour'),
    );
    $this->store->acceptInvitation($inv->id, asUsrId: $this->bob, acceptingIdentifier: 'x@y');
    $this->store->acceptInvitation($inv->id, asUsrId: $this->carol, acceptingIdentifier: 'x@y');
})->throws(InvitationNotPendingException::class);

it('declineInvitation transitions to declined', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $inv = $this->store->createInvitation(
        orgId: $org->id,
        identifier: 'x@y',
        role: Role::Member,
        invitedBy: $this->alice,
        expiresAt: new \DateTimeImmutable('+1 hour'),
    );
    $declined = $this->store->declineInvitation($inv->id, asUsrId: $this->bob);
    expect($declined->status)->toBe(InvitationStatus::Declined);
    expect($declined->terminalBy)->toBe($this->bob);
});

it('revokeInvitation transitions to revoked', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $inv = $this->store->createInvitation(
        orgId: $org->id,
        identifier: 'x@y',
        role: Role::Member,
        invitedBy: $this->alice,
        expiresAt: new \DateTimeImmutable('+1 hour'),
    );
    $revoked = $this->store->revokeInvitation($inv->id, adminUsrId: $this->alice);
    expect($revoked->status)->toBe(InvitationStatus::Revoked);
    expect($revoked->terminalBy)->toBe($this->alice);
});

// ───── Org revoke cascade ─────

it('revokeOrg cascades: memberships revoked, tuples deleted', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $this->store->addMember($org->id, $this->bob, Role::Member);
    $this->store->revokeOrg($org->id);
    expect($this->store->getOrg($org->id)->status)->toBe(Status::Revoked);
    expect($this->store->listTuplesForSubject('usr', $this->alice))->toBe([]);
    expect($this->store->listTuplesForSubject('usr', $this->bob))->toBe([]);
});

// ───── Listing ─────

it('listMembers paginates', function () {
    ['org' => $org] = $this->store->createOrg($this->alice);
    $extra1 = Id::generate('usr');
    $extra2 = Id::generate('usr');
    foreach ([$extra1, $extra2] as $u) {
        $stmt = $this->pdo->prepare("INSERT INTO usr (id, status) VALUES (?, 'active')");
        $stmt->execute([Id::decode($u)['uuid']]);
    }
    foreach ([$this->bob, $this->carol, $extra1, $extra2] as $u) {
        $this->store->addMember($org->id, $u, Role::Member);
    }
    $page1 = $this->store->listMembers($org->id, limit: 2);
    expect($page1->data)->toHaveCount(2);
    expect($page1->nextCursor)->not->toBeNull();
    $page2 = $this->store->listMembers($org->id, cursor: $page1->nextCursor, limit: 10);
    $allIds = array_unique(array_merge(
        array_map(fn($m) => $m->id, $page1->data),
        array_map(fn($m) => $m->id, $page2->data),
    ));
    expect(count($allIds))->toBe(5); // alice + 4 added
});

// ───── Outer-transaction nesting (ADR 0013) ─────

it('createOrg cooperates with an outer transaction (no nested-BEGIN error)', function () {
    $this->pdo->beginTransaction();
    ['org' => $org] = $this->store->createOrg($this->alice, name: 'Outer', slug: 'outer');
    expect($this->pdo->inTransaction())->toBeTrue();
    $this->pdo->commit();

    $fetched = $this->store->getOrg($org->id);
    expect($fetched->name)->toBe('Outer');
});

it('rolling back an outer transaction undoes the inner createOrg', function () {
    $this->pdo->beginTransaction();
    ['org' => $org] = $this->store->createOrg($this->alice, slug: 'will-rollback');
    $this->pdo->rollBack();

    expect(fn() => $this->store->getOrg($org->id))->toThrow(NotFoundException::class);

    $countStmt = $this->pdo->query("SELECT count(*) FROM org WHERE slug = 'will-rollback'");
    expect((int) $countStmt->fetchColumn())->toBe(0);
});

it('outer transaction can commit a second SDK call after the first one rolls back its savepoint', function () {
    // Seed a slug so the next createOrg with the same slug will conflict.
    $this->store->createOrg($this->bob, slug: 'taken');

    $this->pdo->beginTransaction();
    try {
        $this->store->createOrg($this->alice, slug: 'taken'); // inner SDK call rolls back its savepoint
        $this->fail('expected OrgSlugConflictException');
    } catch (OrgSlugConflictException) {
        // expected — savepoint rolled back, outer transaction still live
    }

    // Outer transaction is still usable; another SDK call commits cleanly.
    ['org' => $survivor] = $this->store->createOrg($this->carol, slug: 'survivor');
    $this->pdo->commit();

    $fetched = $this->store->getOrg($survivor->id);
    expect($fetched->slug)->toBe('survivor');
});

it('multiple SDK calls in one outer transaction commit-or-rollback together', function () {
    $this->pdo->beginTransaction();
    ['org' => $orgA] = $this->store->createOrg($this->alice, slug: 'group-a');
    ['org' => $orgB] = $this->store->createOrg($this->bob, slug: 'group-b');
    $this->pdo->rollBack();

    expect(fn() => $this->store->getOrg($orgA->id))->toThrow(NotFoundException::class);
    expect(fn() => $this->store->getOrg($orgB->id))->toThrow(NotFoundException::class);
});

// ───── NotFound paths ─────

it('getOrg / getMembership / getInvitation throw NotFoundException for unknown ids', function () {
    expect(fn() => $this->store->getOrg(Id::generate('org')))->toThrow(NotFoundException::class);
    expect(fn() => $this->store->getMembership(Id::generate('mem')))->toThrow(NotFoundException::class);
    expect(fn() => $this->store->getInvitation(Id::generate('inv')))->toThrow(NotFoundException::class);
});

/**
 * Convert a postgres:// URL into a PDO connection.
 */
function pgPdoFromUrl(string $url): PDO
{
    $parts = parse_url($url);
    if ($parts === false) {
        throw new RuntimeException("invalid postgres URL: {$url}");
    }
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 5432;
    $db = ltrim($parts['path'] ?? '/postgres', '/');
    $user = $parts['user'] ?? 'postgres';
    $pass = $parts['pass'] ?? '';
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
