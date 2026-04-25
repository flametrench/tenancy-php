<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

/**
 * The six built-in relations registered in Flametrench v0.1.
 *
 * Applications MAY register custom relation names (matching
 * /^[a-z_]{2,32}$/) for their own domain objects, but membership roles
 * MUST be drawn from this enum so cross-SDK tenancy semantics stay
 * byte-identical.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';
    case Viewer = 'viewer';
    case Editor = 'editor';

    /**
     * The admin-hierarchy ranking used by the adminRemove precondition.
     * Higher rank removes lower or equal rank. Viewer/editor are
     * object-scoped and do not participate.
     */
    public function adminRank(): ?int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Member => 2,
            self::Guest => 1,
            default => null,
        };
    }
}
