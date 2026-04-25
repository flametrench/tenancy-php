<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

final class InvitationExpiredException extends TenancyException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'conflict.invitation_expired');
    }
}
