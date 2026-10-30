<?php

namespace App\DTOs;

use App\Enums\InvoiceLineType;

final readonly class InvoiceLineData
{
    public function __construct(
        public InvoiceLineType $type,
        public string $description,
        public int $quantity,
        public int $rateMicros,
        public int $prorationDays,
        public int $cycleDays,
        public int $amountMinor,
        public ?int $segmentId = null,
    ) {}
}
