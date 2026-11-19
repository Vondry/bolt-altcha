<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Verification;

final readonly class VerificationResult
{
    public function __construct(
        public Status $status,
        public string $profile,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status->isValid();
    }
}
