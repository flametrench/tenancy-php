<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

/**
 * An authorization tuple. `subjectType` is always `"usr"` in v0.1; `grp_`
 * (groups) is a v0.2+ subject type.
 */
final readonly class Tuple
{
    public function __construct(
        public string $subjectType, // 'usr' in v0.1
        public string $subjectId,
        public string $relation,
        public string $objectType,
        public string $objectId,
    ) {}
}
