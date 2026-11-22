<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0
//
// Flametrench v0.1 conformance suite — PHP / PEST harness for the
// tenancy capability.
//
// Implements the state-machine fixture format defined in
// spec/conformance/fixture.schema.json. Each test:
//
//   1. Pre-allocates fresh usr_ IDs for declared named users.
//   2. Creates a fresh InMemoryTenancyStore (or the backend a test passes).
//   3. Walks the steps list. Each step's input has {name} references
//      resolved against the variable map (declared users + previous
//      captures). The step calls the matching operation; on success
//      captures may extract values for later substitution; on
//      expected error the step MUST throw.
//
// Pseudo-ops recognized only by the harness (not part of the SDK
// surface):
//
//   - assert_subject_relations(subject_type, subject_id, relations[])
//   - assert_equal(actual, expected)
//
// The Python harness in flametrench-tenancy is the canonical reference
// implementation; this PHP harness mirrors it exactly with snake_case
// → camelCase adaptation on inputs and capture paths.

declare(strict_types=1);

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
use Flametrench\Tenancy\InMemoryTenancyStore;
use Flametrench\Tenancy\PostgresTenancyStore;
use Flametrench\Tenancy\TenancyStore;
use Flametrench\Tenancy\PreTuple;
use Flametrench\Tenancy\Role;
use Flametrench\Tenancy\Status;

const TENANCY_FIXTURES_DIR = __DIR__ . '/conformance/fixtures';

const VAR_PATTERN = '/^\{([a-z_][a-z0-9_]*)\}$/';

const ERROR_CLASSES = [
    'SoleOwnerError' => SoleOwnerException::class,
    'RoleHierarchyError' => RoleHierarchyException::class,
    'ForbiddenError' => ForbiddenException::class,
    'DuplicateMembershipError' => DuplicateMembershipException::class,
    'InvitationNotPendingError' => InvitationNotPendingException::class,
    'InvitationExpiredError' => InvitationExpiredException::class,
    'PreconditionError' => PreconditionException::class,
    'AlreadyTerminalError' => AlreadyTerminalException::class,
    'NotFoundError' => NotFoundException::class,
    'IdentifierBindingRequiredError' => IdentifierBindingRequiredException::class,
    'IdentifierMismatchError' => IdentifierMismatchException::class,
    'OrgSlugConflictError' => OrgSlugConflictException::class,
];

/**
 * @return array{spec_version:string, capability:string, operation:string,
 *     conformance_level:string, description:string, tests:array<int,array>}
 */
function loadTenancyFixture(string $relativePath): array
{
    $raw = file_get_contents(TENANCY_FIXTURES_DIR . '/' . $relativePath);
    if ($raw === false) {
        throw new RuntimeException("Cannot read fixture: {$relativePath}");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON in fixture: {$relativePath}");
    }
    return $decoded;
}

/**
 * Recursively substitute {var} references in a value tree.
 *
 * @param array<string,mixed> $variables
 */
function resolveVars(mixed $value, array $variables): mixed
{
    if (is_string($value)) {
        if (preg_match(VAR_PATTERN, $value, $matches) === 1) {
            $name = $matches[1];
            if (!array_key_exists($name, $variables)) {
                throw new RuntimeException("Unknown variable in fixture: {{$name}}");
            }
            return $variables[$name];
        }
        return $value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = resolveVars($v, $variables);
        }
        return $out;
    }
    return $value;
}

/** Convert snake_case path segment to camelCase for PHP property/key lookup. */
function snakeToCamel(string $segment): string
{
    return lcfirst(str_replace('_', '', ucwords($segment, '_')));
}

/**
 * Walk a dotted path into a value (object property, array key) handling
 * both the snake_case fixture form and the camelCase PHP form.
 */
function walkPath(mixed $obj, string $dottedPath): mixed
{
    $current = $obj;
    foreach (explode('.', $dottedPath) as $segment) {
        $camelSegment = snakeToCamel($segment);
        if (is_object($current)) {
            // Try snake_case as-is first, then camelCase (PHP convention).
            if (property_exists($current, $segment)) {
                $current = $current->{$segment};
            } elseif (property_exists($current, $camelSegment)) {
                $current = $current->{$camelSegment};
            } else {
                throw new RuntimeException(
                    "Cannot resolve path segment '{$segment}' on " . get_class($current),
                );
            }
        } elseif (is_array($current)) {
            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (array_key_exists($camelSegment, $current)) {
                $current = $current[$camelSegment];
            } else {
                throw new RuntimeException(
                    "Cannot resolve path segment '{$segment}' in array",
                );
            }
        } else {
            throw new RuntimeException("Cannot walk into non-object/array at '{$segment}'");
        }
    }
    return $current;
}

/**
 * Build pre_tuples array → list<PreTuple>.
 *
 * @param array<int, array{relation:string, object_type:string, object_id:string}>|null $values
 * @return list<PreTuple>
 */
function buildPreTuples(?array $values): array
{
    if ($values === null) {
        return [];
    }
    return array_map(
        fn(array $v): PreTuple => new PreTuple(
            relation: $v['relation'],
            objectType: $v['object_type'],
            objectId: $v['object_id'],
        ),
        $values,
    );
}

/**
 * Dispatch a fixture op to the matching SDK / harness method.
 *
 * @param array<string,mixed> $args
 */
function invokeOp(TenancyStore $store, string $op, array $args): mixed
{
    return match ($op) {
        'create_org' => $store->createOrg(
            $args['creator'],
            name: $args['name'] ?? null,
            slug: $args['slug'] ?? null,
        ),

        'update_org' => $store->updateOrg(
            $args['org_id'],
            name: array_key_exists('name', $args) ? $args['name'] : TenancyStore::UNSET,
            slug: array_key_exists('slug', $args) ? $args['slug'] : TenancyStore::UNSET,
        ),

        'assert_org_fields' => assertOrgFields($store, $args),


        'add_member' => $store->addMember(
            orgId: $args['org_id'],
            usrId: $args['usr_id'],
            role: Role::from($args['role']),
            invitedBy: $args['invited_by'] ?? null,
        ),

        'change_role' => $store->changeRole(
            memId: $args['mem_id'],
            newRole: Role::from($args['new_role']),
        ),

        'suspend_membership' => $store->suspendMembership($args['mem_id']),

        'reinstate_membership' => $store->reinstateMembership($args['mem_id']),

        'self_leave' => $store->selfLeave(
            memId: $args['mem_id'],
            transferTo: $args['transfer_to'] ?? null,
        ),

        'admin_remove' => $store->adminRemove(
            memId: $args['mem_id'],
            adminUsrId: $args['admin_usr_id'],
        ),

        'transfer_ownership' => $store->transferOwnership(
            orgId: $args['org_id'],
            fromMemId: $args['from_mem_id'],
            toMemId: $args['to_mem_id'],
        ),

        'create_invitation' => $store->createInvitation(
            orgId: $args['org_id'],
            identifier: $args['identifier'],
            role: Role::from($args['role']),
            invitedBy: $args['invited_by'],
            expiresAt: (new DateTimeImmutable())->modify('+' . ($args['ttl_seconds'] ?? 86400) . ' seconds'),
            preTuples: buildPreTuples($args['pre_tuples'] ?? null),
        ),

        'accept_invitation' => $store->acceptInvitation(
            invId: $args['inv_id'],
            asUsrId: $args['as_usr_id'] ?? null,
            acceptingIdentifier: $args['accepting_identifier'] ?? null,
        ),

        'decline_invitation' => $store->declineInvitation(
            invId: $args['inv_id'],
            asUsrId: $args['as_usr_id'] ?? null,
        ),

        'revoke_invitation' => $store->revokeInvitation(
            invId: $args['inv_id'],
            adminUsrId: $args['admin_usr_id'],
        ),

        'revoke_invitation_as_system' => $store->revokeInvitationAsSystem(
            invId: $args['inv_id'],
        ),

        'suspend_org' => $store->suspendOrg($args['org_id']),
        'reinstate_org' => $store->reinstateOrg($args['org_id']),
        'revoke_org' => $store->revokeOrg($args['org_id']),

        'list_orgs' => ['page' => $store->listOrgs(
            cursor: $args['cursor'] ?? null,
            limit: $args['limit'] ?? 50,
            query: $args['query'] ?? null,
            status: isset($args['status']) ? Status::from($args['status']) : null,
        )],

        'assert_subject_relations' => assertSubjectRelations($store, $args),
        'assert_equal' => assertEqual($args),
        'assert_invitation_status' => assertInvitationStatus($store, $args),

        default => throw new RuntimeException("Unknown fixture op: {$op}"),
    };
}

/**
 * @param array{inv_id:string, expected_status:string} $args
 */
function assertInvitationStatus(TenancyStore $store, array $args): null
{
    $inv = $store->getInvitation($args['inv_id']);
    expect($inv->status->value)->toBe($args['expected_status']);
    return null;
}

/**
 * @param array{org_id:string, expected_name?:string|null, expected_slug?:string|null} $args
 */
function assertOrgFields(TenancyStore $store, array $args): null
{
    $org = $store->getOrg($args['org_id']);
    if (array_key_exists('expected_name', $args)) {
        expect($org->name)->toBe($args['expected_name']);
    }
    if (array_key_exists('expected_slug', $args)) {
        expect($org->slug)->toBe($args['expected_slug']);
    }
    return null;
}

/**
 * @param array{subject_type:string, subject_id:string, relations:list<string>} $args
 */
function assertSubjectRelations(TenancyStore $store, array $args): null
{
    $tuples = $store->listTuplesForSubject($args['subject_type'], $args['subject_id']);
    $actual = array_map(fn($t) => $t->relation, $tuples);
    sort($actual);
    $expected = $args['relations'];
    sort($expected);
    expect($actual)->toBe($expected);
    return null;
}

/**
 * @param array{actual:mixed, expected:mixed} $args
 */
function assertEqual(array $args): null
{
    expect($args['actual'])->toBe($args['expected']);
    return null;
}

/**
 * Run one state-machine test.
 *
 * @param array{users?:list<string>, steps:array<int,array<string,mixed>>} $test
 */
function runTenancyTest(array $test, ?callable $makeBackend = null): void
{
    // $makeBackend returns [TenancyStore, ?callable(string $usrId): void];
    // the callable seeds a declared user where the backend needs a usr row.
    [$store, $seedUser] = $makeBackend !== null
        ? $makeBackend()
        : [new InMemoryTenancyStore(), null];

    $variables = [];
    foreach ($test['users'] ?? [] as $name) {
        $variables[$name] = Id::generate('usr');
        if ($seedUser !== null) {
            $seedUser($variables[$name]);
        }
    }

    foreach ($test['steps'] as $step) {
        $op = $step['op'];
        /** @var array<string,mixed> $resolvedInput */
        $resolvedInput = resolveVars($step['input'], $variables);

        if (isset($step['expected']['error'])) {
            $errorClass = ERROR_CLASSES[$step['expected']['error']]
                ?? throw new RuntimeException("Unknown spec error: {$step['expected']['error']}");
            expect(fn() => invokeOp($store, $op, $resolvedInput))
                ->toThrow($errorClass);
            return; // error step terminates the test
        }

        $result = invokeOp($store, $op, $resolvedInput);

        foreach ($step['captures'] ?? [] as $name => $path) {
            $variables[$name] = walkPath($result, $path);
        }

        if (isset($step['expected']['result'])) {
            $r = $step['expected']['result'];
            /** @var \Flametrench\Tenancy\Page<object> $page */
            $page = walkPath($result, 'page');
            if (array_key_exists('data_ids_in_order', $r)) {
                $expected = resolveVars($r['data_ids_in_order'], $variables);
                $actual = array_map(fn($item) => $item->id, $page->data);
                expect($actual)->toBe($expected);
            }
            if (array_key_exists('data_ids_unordered', $r)) {
                $expected = resolveVars($r['data_ids_unordered'], $variables);
                $actual = array_map(fn($item) => $item->id, $page->data);
                sort($actual);
                sort($expected);
                expect($actual)->toBe($expected);
            }
            if (array_key_exists('next_cursor', $r)) {
                $expected = resolveVars($r['next_cursor'], $variables);
                expect($page->nextCursor)->toBe($expected);
            }
        }
    }

    // Tests that consist only of "do these steps and don't throw" carry
    // an implicit assertion that's invisible to PEST. Make it explicit
    // so the runner doesn't flag the test as risky.
    expect(true)->toBeTrue();
}

// ─── Test factories ───

foreach (
    [
        'tenancy.self_leave' => 'tenancy/self-leave.json',
        'tenancy.change_role' => 'tenancy/change-role.json',
        'tenancy.transfer_ownership' => 'tenancy/transfer-ownership.json',
        'tenancy.admin_remove' => 'tenancy/admin-remove.json',
        'tenancy.accept_invitation' => 'tenancy/invitation-accept.json',
        'tenancy.accept_invitation.binding' => 'tenancy/invitation-accept-binding.json',
        'tenancy.update_org' => 'tenancy/org-name-slug.json',
        'tenancy.list_orgs' => 'tenancy/list-orgs.json',
        'tenancy.revoke_invitation.authz' => 'tenancy/revoke-invitation-authz.json',
        'tenancy.revoke_invitation_as_system' => 'tenancy/revoke-invitation-as-system.json',
    ] as $describeName => $fixturePath
) {
    $fixture = loadTenancyFixture($fixturePath);
    describe("Conformance · {$describeName} [MUST]", function () use ($fixture) {
        foreach ($fixture['tests'] as $t) {
            it("[{$t['id']}] {$t['description']}", function () use ($t) {
                runTenancyTest($t);
            });
        }
    });
}

// The revokeInvitation vectors also run against PostgresTenancyStore
// when TENANCY_POSTGRES_URL is set.
$securityPgUrl = getenv('TENANCY_POSTGRES_URL') ?: null;
$postgresFixtures = $securityPgUrl === null ? [] : [
    'tenancy.revoke_invitation.authz' => 'tenancy/revoke-invitation-authz.json',
    'tenancy.revoke_invitation_as_system' => 'tenancy/revoke-invitation-as-system.json',
];
foreach ($postgresFixtures as $describeName => $fixturePath) {
    $fixture = loadTenancyFixture($fixturePath);
    describe("Conformance · {$describeName} [MUST] · postgres", function () use ($fixture, $securityPgUrl) {
        foreach ($fixture['tests'] as $t) {
            it("[{$t['id']}] {$t['description']}", function () use ($t, $securityPgUrl) {
                runTenancyTest($t, function () use ($securityPgUrl) {
                    $p = parse_url($securityPgUrl);
                    $pdo = new PDO(
                        sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/')),
                        $p['user'] ?? 'postgres',
                        $p['pass'] ?? '',
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                    );
                    $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
                    $pdo->exec((string) file_get_contents(__DIR__ . '/postgres-schema.sql'));
                    $seed = $pdo->prepare("INSERT INTO usr (id, status) VALUES (?, 'active')");
                    return [
                        new PostgresTenancyStore($pdo),
                        fn(string $usrId) => $seed->execute([Id::decode($usrId)['uuid']]),
                    ];
                });
            });
        }
    });
}
