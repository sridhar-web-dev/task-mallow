<?php

namespace App\DTOs;

final readonly class InvoiceCalculation
{
    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public int $baseAmountMinor,
        public int $overageAmountMinor,
        public int $totalMinor,
    ) {}
}
