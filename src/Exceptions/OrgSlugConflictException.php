<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

/**
 * The supplied org slug is already in use by another active org.
 *
 * Per ADR 0011, slugs are globally unique within a deployment when set.
 * Revoked orgs free their slug; NULL slugs are not unique-constrained.
 */
final class OrgSlugConflictException extends TenancyException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct(
            "Org slug '{$slug}' is already in use",
            'conflict.org_slug',
        );
    }
}
