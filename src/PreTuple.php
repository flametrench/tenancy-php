<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

/**
 * A resource-scoped grant pre-declared on an invitation. Materialized as a
 * `tup_` row at accept time with the accepting user as subject.
 */
final readonly class PreTuple
{
    public function __construct(
        public string $relation,
        public string $objectType,
        public string $objectId,
    ) {}
}
