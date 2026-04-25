<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

final class DuplicateMembershipException extends TenancyException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'conflict.duplicate_membership');
    }
}
