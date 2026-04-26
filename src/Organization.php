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
        // v0.2 (ADR 0011) — optional metadata fields. Default to null
        // for backwards compatibility with v0.1 callers that
        // constructed Organization positionally without name/slug.
        public ?string $name = null,
        public ?string $slug = null,
    ) {}

    public function withStatus(Status $status, \DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->id,
            $status,
            $this->createdAt,
            $updatedAt,
            $this->name,
            $this->slug,
        );
    }

    public function withMetadata(
        ?string $name,
        ?string $slug,
        \DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $this->id,
            $this->status,
            $this->createdAt,
            $updatedAt,
            $name,
            $slug,
        );
    }
}
