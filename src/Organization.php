<?php

// Copyright 2026 NDC Digital, LLC
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Flametrench\Tenancy;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public Status $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public function withStatus(Status $status, \DateTimeImmutable $updatedAt): self
    {
        return new self($this->id, $status, $this->createdAt, $updatedAt);
    }
}
