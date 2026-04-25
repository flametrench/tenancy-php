<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

final readonly class Invitation
{
    /**
     * @param list<PreTuple> $preTuples
     */
    public function __construct(
        public string $id,
        public string $orgId,
        public string $identifier,
        public Role $role,
        public InvitationStatus $status,
        public array $preTuples,
        public string $invitedBy,
        public ?string $invitedUserId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $terminalAt,
        public ?string $terminalBy,
    ) {}

    public function transitionTerminal(
        InvitationStatus $status,
        \DateTimeImmutable $at,
        ?string $by,
        ?string $invitedUserId = null,
    ): self {
        return new self(
            id: $this->id,
            orgId: $this->orgId,
            identifier: $this->identifier,
            role: $this->role,
            status: $status,
            preTuples: $this->preTuples,
            invitedBy: $this->invitedBy,
            invitedUserId: $invitedUserId ?? $this->invitedUserId,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            terminalAt: $at,
            terminalBy: $by,
        );
    }
}
