<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

/**
 * `acceptInvitation` was called with `asUsrId` but no `acceptingIdentifier`.
 *
 * Per ADR 0009, the SDK fails closed: callers MUST supply
 * `acceptingIdentifier` whenever they assert an existing `asUsrId`. The
 * mint-new-user path (`asUsrId` null) does not need this parameter.
 */
final class IdentifierBindingRequiredException extends PreconditionException
{
    public function __construct(
        string $message = 'acceptInvitation requires acceptingIdentifier when asUsrId is provided',
    ) {
        parent::__construct($message, 'identifier_binding_required');
    }
}
