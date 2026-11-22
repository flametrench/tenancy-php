<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0
//
// revokeInvitation authorization: what the fixture format can't assert
// yet — a refused revoke changes nothing (the invitation stays pending,
// with no terminal_at / terminal_by), and an unauthorized caller is
// refused before learning the invitation's status. Runs against the
// in-memory store and, when TENANCY_POSTGRES_URL is set, Postgres.

declare(strict_types=1);

use Flametrench\Ids\Id;
use Flametrench\Tenancy\Exceptions\ForbiddenException;
use Flametrench\Tenancy\Exceptions\InvitationNotPendingException;
use Flametrench\Tenancy\InMemoryTenancyStore;
use Flametrench\Tenancy\InvitationStatus;
use Flametrench\Tenancy\PostgresTenancyStore;
use Flametrench\Tenancy\Role;

$spkUrl = getenv('TENANCY_POSTGRES_URL') ?: null;
$spkBackends = ['in-memory' => fn() => [new InMemoryTenancyStore(), fn(string $u) => null]];
if ($spkUrl !== null) {
    $spkBackends['postgres'] = function () use ($spkUrl) {
        $p = parse_url($spkUrl);
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/')),
            $p['user'] ?? 'postgres',
            $p['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
        $pdo->exec((string) file_get_contents(__DIR__ . '/postgres-schema.sql'));
        $seed = $pdo->prepare("INSERT INTO usr (id, status) VALUES (?, 'active')");
        return [new PostgresTenancyStore($pdo), fn(string $u) => $seed->execute([Id::decode($u)['uuid']])];
    };
}

foreach ($spkBackends as $backend => $make) {
    describe("revokeInvitation authorization · {$backend}", function () use ($make) {
        beforeEach(function () use ($make) {
            [$this->store, $seed] = $make();
            foreach (['alice', 'bob', 'mallory'] as $name) {
                $this->{$name} = Id::generate('usr');
                $seed($this->{$name});
            }
            $this->orgId = $this->store->createOrg($this->alice)['org']->id;
            $this->store->addMember($this->orgId, $this->bob, Role::Member);
            $this->store->createOrg($this->mallory);
            $this->inv = $this->store->createInvitation(
                $this->orgId, 'invitee@example.com', Role::Member, $this->alice,
                new \DateTimeImmutable('+1 day'),
            );
        });

        it('a refused revoke leaves the invitation pending with no terminal fields', function () {
            foreach ([$this->bob, $this->mallory] as $caller) {
                expect(fn() => $this->store->revokeInvitation($this->inv->id, $caller))
                    ->toThrow(ForbiddenException::class);
                $after = $this->store->getInvitation($this->inv->id);
                expect($after->status)->toBe(InvitationStatus::Pending);
                expect($after->terminalAt)->toBeNull();
                expect($after->terminalBy)->toBeNull();
            }
            // Still revocable by an authorized caller afterwards.
            $revoked = $this->store->revokeInvitation($this->inv->id, $this->alice);
            expect($revoked->status)->toBe(InvitationStatus::Revoked);
            expect($revoked->terminalBy)->toBe($this->alice);
        });

        it('an unauthorized caller gets Forbidden even for a non-pending invitation (no status disclosure)', function () {
            $this->store->revokeInvitation($this->inv->id, $this->alice);
            expect(fn() => $this->store->revokeInvitation($this->inv->id, $this->mallory))
                ->toThrow(ForbiddenException::class);
            // An authorized caller still sees the real reason.
            expect(fn() => $this->store->revokeInvitation($this->inv->id, $this->alice))
                ->toThrow(InvitationNotPendingException::class);
        });
    });
}
