<?php

namespace App\DTOs;

final readonly class RecordUsageData
{
    public function __construct(
        public int $merchantId,
        public string $customerExternalId,
        public string $usageDate,
        public int $quantity,
        public string $idempotencyKey,
    ) {}
}
