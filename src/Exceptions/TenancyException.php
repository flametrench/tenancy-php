<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

/**
 * Base class for every tenancy-layer exception. Carries a stable,
 * machine-readable `code` matching the OpenAPI Error envelope. PHP's
 * convention is "Exception" suffix; spec names (e.g. SoleOwnerError)
 * map to PHP class names with that suffix.
 */
class TenancyException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $flametrenchCode)
    {
        parent::__construct($message);
    }
}
