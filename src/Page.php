<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

/**
 * Paginated result envelope.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $data
     */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
    ) {}
}
