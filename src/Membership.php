<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

final readonly class Membership
{
    public function __construct(
        public string $id,
        public string $usrId,
        public string $orgId,
        public Role $role,
        public Status $status,
        /** Previous membership in the rotation chain; null at the chain root. */
        public ?string $replaces,
        public ?string $invitedBy,
        /** Null for self-leave; non-null for admin-remove. Telltale field for audit. */
        public ?string $removedBy,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public function with(
        ?Status $status = null,
        ?string $removedBy = null,
        ?\DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            id: $this->id,
            usrId: $this->usrId,
            orgId: $this->orgId,
            role: $this->role,
            status: $status ?? $this->status,
            replaces: $this->replaces,
            invitedBy: $this->invitedBy,
            removedBy: $removedBy ?? $this->removedBy,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }
}
