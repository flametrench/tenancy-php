<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy\Exceptions;

/**
 * The supplied `acceptingIdentifier` does not match `invitation.identifier`.
 *
 * Per ADR 0009, this byte-equality check is the SDK's contribution to
 * closing the privilege-escalation primitive in spec#5: an attacker
 * substituting a foreign `usr_id` will fail to also produce a matching
 * identifier sourced from the authenticated session.
 */
final class IdentifierMismatchException extends PreconditionException
{
    public function __construct(
        public readonly string $acceptingIdentifier,
        public readonly string $invitationIdentifier,
    ) {
        parent::__construct(
            "acceptingIdentifier '{$acceptingIdentifier}' does not match "
            . "invitation.identifier '{$invitationIdentifier}'",
            'identifier_mismatch',
        );
    }
}
