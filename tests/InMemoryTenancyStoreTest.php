<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Flametrench\Ids\Id;
use Flametrench\Tenancy\Exceptions\DuplicateMembershipException;
use Flametrench\Tenancy\Exceptions\ForbiddenException;
use Flametrench\Tenancy\Exceptions\InvitationExpiredException;
use Flametrench\Tenancy\Exceptions\InvitationNotPendingException;
use Flametrench\Tenancy\Exceptions\NotFoundException;
use Flametrench\Tenancy\Exceptions\PreconditionException;
use Flametrench\Tenancy\Exceptions\RoleHierarchyException;
use Flametrench\Tenancy\Exceptions\SoleOwnerException;
use Flametrench\Tenancy\InMemoryTenancyStore;
use Flametrench\Tenancy\InvitationStatus;
use Flametrench\Tenancy\PreTuple;
use Flametrench\Tenancy\Role;
use Flametrench\Tenancy\Status;

function newUsr(): string
{
    return Id::generate('usr');
}

beforeEach(function () {
    $this->store = new InMemoryTenancyStore();
    $this->alice = newUsr();
    $this->bob = newUsr();
    $this->carol = newUsr();
    $this->dave = newUsr();
});

// ─── Organizations ───

describe('createOrg', function () {
    it('creates org + owner membership with correct shape', function () {
        $result = $this->store->createOrg($this->alice);
        expect($result['org']->status)->toBe(Status::Active);
        expect($result['ownerMembership']->usrId)->toBe($this->alice);
        expect($result['ownerMembership']->role)->toBe(Role::Owner);
        expect($result['ownerMembership']->status)->toBe(Status::Active);
        expect($result['ownerMembership']->replaces)->toBeNull();
    });

    it('materializes the membership tuple', function () {
        $result = $this->store->createOrg($this->alice);
        $tuples = $this->store->listTuplesForSubject('usr', $this->alice);
        expect($tuples)->toHaveCount(1);
        expect($tuples[0]->relation)->toBe('owner');
        expect($tuples[0]->objectId)->toBe($result['org']->id);
    });
});

describe('org lifecycle', function () {
    it('suspends then reinstates', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $suspended = $this->store->suspendOrg($org->id);
        expect($suspended->status)->toBe(Status::Suspended);
        $reinstated = $this->store->reinstateOrg($org->id);
        expect($reinstated->status)->toBe(Status::Active);
    });

    it('revokes and cascades: memberships revoked, tuples deleted', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Member);
        $this->store->revokeOrg($org->id);
        expect($this->store->getOrg($org->id)->status)->toBe(Status::Revoked);
        expect($this->store->listTuplesForSubject('usr', $this->alice))->toBe([]);
        expect($this->store->listTuplesForSubject('usr', $this->bob))->toBe([]);
    });
});

// ─── Memberships ───

describe('addMember', function () {
    it('adds and materializes tuple', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $mem = $this->store->addMember($org->id, $this->bob, Role::Member, invitedBy: $this->alice);
        expect($mem->invitedBy)->toBe($this->alice);
        $tuples = $this->store->listTuplesForSubject('usr', $this->bob);
        expect($tuples)->toHaveCount(1);
        expect($tuples[0]->relation)->toBe('member');
    });

    it('rejects duplicate active membership', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Member);
        expect(fn() => $this->store->addMember($org->id, $this->bob, Role::Admin))
            ->toThrow(DuplicateMembershipException::class);
    });

    it('rejects add to non-active org', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $this->store->suspendOrg($org->id);
        expect(fn() => $this->store->addMember($org->id, $this->bob, Role::Member))
            ->toThrow(PreconditionException::class);
    });
});

describe('changeRole (revoke + re-add)', function () {
    it('creates new mem with replaces chain and swaps tuple', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $newMem = $this->store->changeRole($bobMem->id, Role::Admin);
        expect($newMem->id)->not->toBe($bobMem->id);
        expect($newMem->replaces)->toBe($bobMem->id);
        expect($newMem->role)->toBe(Role::Admin);
        expect($this->store->getMembership($bobMem->id)->status)->toBe(Status::Revoked);
        $tuples = $this->store->listTuplesForSubject('usr', $this->bob);
        expect($tuples)->toHaveCount(1);
        expect($tuples[0]->relation)->toBe('admin');
    });

    it('refuses sole-owner demotion', function () {
        ['ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        expect(fn() => $this->store->changeRole($owner->id, Role::Member))
            ->toThrow(SoleOwnerException::class);
    });
});

describe('suspend / reinstate membership', function () {
    it('suspends and removes tuple, reinstating restores it', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $this->store->suspendMembership($bobMem->id);
        expect($this->store->listTuplesForSubject('usr', $this->bob))->toBe([]);
        $this->store->reinstateMembership($bobMem->id);
        expect($this->store->listTuplesForSubject('usr', $this->bob))->toHaveCount(1);
    });

    it('refuses to suspend sole owner', function () {
        ['ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        expect(fn() => $this->store->suspendMembership($owner->id))
            ->toThrow(SoleOwnerException::class);
    });
});

// ─── selfLeave ───

describe('selfLeave', function () {
    it('lets a non-owner leave; removedBy is null', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $left = $this->store->selfLeave($bobMem->id);
        expect($left->status)->toBe(Status::Revoked);
        expect($left->removedBy)->toBeNull();
    });

    it('refuses sole-owner self-leave without transferTo', function () {
        ['ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        expect(fn() => $this->store->selfLeave($owner->id))
            ->toThrow(SoleOwnerException::class);
    });

    it('atomically transfers + revokes when sole owner supplies transferTo', function () {
        ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Member);
        $left = $this->store->selfLeave($owner->id, transferTo: $this->bob);
        expect($left->status)->toBe(Status::Revoked);
        expect($this->store->listTuplesForSubject('usr', $this->alice))->toBe([]);
        $bobTuples = $this->store->listTuplesForSubject('usr', $this->bob);
        expect($bobTuples)->toHaveCount(1);
        expect($bobTuples[0]->relation)->toBe('owner');
    });
});

// ─── adminRemove ───

describe('adminRemove', function () {
    it('owner removes a member; removedBy is admin', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $removed = $this->store->adminRemove($bobMem->id, $this->alice);
        expect($removed->status)->toBe(Status::Revoked);
        expect($removed->removedBy)->toBe($this->alice);
    });

    it('non-admin caller is forbidden', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Member);
        $carolMem = $this->store->addMember($org->id, $this->carol, Role::Guest);
        expect(fn() => $this->store->adminRemove($carolMem->id, $this->bob))
            ->toThrow(ForbiddenException::class);
    });

    it('owner cannot be removed via adminRemove', function () {
        ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Admin);
        expect(fn() => $this->store->adminRemove($owner->id, $this->bob))
            ->toThrow(RoleHierarchyException::class);
    });
});

// ─── transferOwnership ───

describe('transferOwnership', function () {
    it('atomically demotes old owner and promotes target', function () {
        ['org' => $org, 'ownerMembership' => $owner] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Admin);
        $result = $this->store->transferOwnership($org->id, $owner->id, $bobMem->id);
        expect($result['toMembership']->role)->toBe(Role::Owner);
        expect($result['fromMembership']->role)->toBe(Role::Member);
    });

    it('refuses if from is not owner', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Admin);
        $carolMem = $this->store->addMember($org->id, $this->carol, Role::Member);
        expect(fn() => $this->store->transferOwnership($org->id, $bobMem->id, $carolMem->id))
            ->toThrow(PreconditionException::class);
    });
});

// ─── Invitations ───

describe('invitations', function () {
    it('creates pending invitation with pre-tuples', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $inv = $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'carol@example.com',
            role: Role::Guest,
            invitedBy: $this->alice,
            expiresAt: (new DateTimeImmutable())->modify('+1 hour'),
            preTuples: [new PreTuple('viewer', 'proj', '0190f2a8-1b3c-7abc-8123-456789abcdef')],
        );
        expect($inv->status)->toBe(InvitationStatus::Pending);
        expect($inv->preTuples)->toHaveCount(1);
    });

    it('refuses past expiration', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        expect(fn() => $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'x@y',
            role: Role::Member,
            invitedBy: $this->alice,
            expiresAt: new DateTimeImmutable('2000-01-01'),
        ))->toThrow(PreconditionException::class);
    });

    it('accepts invitation atomically: membership + tuples + state transition', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $project = '0190f2a8-1b3c-7abc-8123-456789abcdef';
        $inv = $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'carol@example.com',
            role: Role::Guest,
            invitedBy: $this->alice,
            expiresAt: (new DateTimeImmutable())->modify('+1 hour'),
            preTuples: [new PreTuple('viewer', 'proj', $project)],
        );
        $result = $this->store->acceptInvitation($inv->id, asUsrId: $this->carol);
        expect($result['invitation']->status)->toBe(InvitationStatus::Accepted);
        expect($result['invitation']->invitedUserId)->toBe($this->carol);
        expect($result['materializedTuples'])->toHaveCount(1);
        $tuples = $this->store->listTuplesForSubject('usr', $this->carol);
        expect($tuples)->toHaveCount(2);
    });

    it('refuses accepting already-terminal invitation', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $inv = $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'x@y',
            role: Role::Member,
            invitedBy: $this->alice,
            expiresAt: (new DateTimeImmutable())->modify('+1 hour'),
        );
        $this->store->acceptInvitation($inv->id, asUsrId: $this->bob);
        expect(fn() => $this->store->acceptInvitation($inv->id, asUsrId: $this->carol))
            ->toThrow(InvitationNotPendingException::class);
    });

    it('declines with terminal attribution', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $inv = $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'x@y',
            role: Role::Member,
            invitedBy: $this->alice,
            expiresAt: (new DateTimeImmutable())->modify('+1 hour'),
        );
        $declined = $this->store->declineInvitation($inv->id, asUsrId: $this->bob);
        expect($declined->status)->toBe(InvitationStatus::Declined);
        expect($declined->terminalBy)->toBe($this->bob);
    });

    it('revokes invitation with admin attribution', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $inv = $this->store->createInvitation(
            orgId: $org->id,
            identifier: 'x@y',
            role: Role::Member,
            invitedBy: $this->alice,
            expiresAt: (new DateTimeImmutable())->modify('+1 hour'),
        );
        $revoked = $this->store->revokeInvitation($inv->id, $this->alice);
        expect($revoked->status)->toBe(InvitationStatus::Revoked);
        expect($revoked->terminalBy)->toBe($this->alice);
    });

    it('refuses accept on expired invitation', function () {
        // Use a clock that starts in the past, advances, then we jump it far forward.
        $t = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $seq = 0;
        $shifted = new InMemoryTenancyStore(
            clock: function () use (&$t, &$seq) {
                $result = $t->modify("+{$seq} seconds");
                $seq += 1;
                return $result;
            }
        );
        ['org' => $org] = $shifted->createOrg($this->alice);
        $inv = $shifted->createInvitation(
            orgId: $org->id,
            identifier: 'x@y',
            role: Role::Member,
            invitedBy: $this->alice,
            expiresAt: $t->modify('+10 seconds'),
        );
        // Advance seq past the expiration so now() > expiresAt.
        $seq = 100;
        expect(fn() => $shifted->acceptInvitation($inv->id, asUsrId: $this->bob))
            ->toThrow(InvitationExpiredException::class);
    });
});

// ─── Listing + pagination ───

describe('listMembers', function () {
    it('paginates via UUID cursor', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        foreach ([$this->bob, $this->carol, $this->dave] as $u) {
            $this->store->addMember($org->id, $u, Role::Member);
        }
        $page1 = $this->store->listMembers($org->id, limit: 2);
        expect($page1->data)->toHaveCount(2);
        expect($page1->nextCursor)->not->toBeNull();
        $page2 = $this->store->listMembers($org->id, cursor: $page1->nextCursor, limit: 10);
        expect($page2->data)->not->toBe([]);
        $ids = array_merge(
            array_map(fn($m) => $m->id, $page1->data),
            array_map(fn($m) => $m->id, $page2->data),
        );
        expect(count(array_unique($ids)))->toBe(4); // alice + 3 added
    });

    it('filters by status', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $this->store->suspendMembership($bobMem->id);
        $active = $this->store->listMembers($org->id, status: Status::Active);
        expect(count($active->data))->toBe(1);
        expect($active->data[0]->usrId)->toBe($this->alice);
        $suspended = $this->store->listMembers($org->id, status: Status::Suspended);
        expect(count($suspended->data))->toBe(1);
        expect($suspended->data[0]->usrId)->toBe($this->bob);
    });
});

// ─── Tuple accessors ───

describe('tuple accessors', function () {
    it('listTuplesForObject enumerates holders of a relation', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $this->store->addMember($org->id, $this->bob, Role::Admin);
        $this->store->addMember($org->id, $this->carol, Role::Member);
        $admins = $this->store->listTuplesForObject('org', $org->id, 'admin');
        expect($admins)->toHaveCount(1);
        expect($admins[0]->subjectId)->toBe($this->bob);
        $all = $this->store->listTuplesForObject('org', $org->id);
        expect($all)->toHaveCount(3); // owner + admin + member
    });
});

// ─── removedBy attribution ───

describe('removedBy attribution', function () {
    it('is null for self-leave and non-null for admin-remove', function () {
        ['org' => $org] = $this->store->createOrg($this->alice);
        $bobMem = $this->store->addMember($org->id, $this->bob, Role::Member);
        $carolMem = $this->store->addMember($org->id, $this->carol, Role::Member);
        $self = $this->store->selfLeave($bobMem->id);
        expect($self->removedBy)->toBeNull();
        $removed = $this->store->adminRemove($carolMem->id, $this->alice);
        expect($removed->removedBy)->toBe($this->alice);
    });
});

// ─── Not-found paths ───

describe('not-found paths', function () {
    it('unknown ids throw NotFoundException', function () {
        expect(fn() => $this->store->getOrg('org_' . str_repeat('0', 32)))
            ->toThrow(NotFoundException::class);
        expect(fn() => $this->store->getMembership('mem_' . str_repeat('0', 32)))
            ->toThrow(NotFoundException::class);
    });
});
