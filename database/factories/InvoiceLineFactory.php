<?php

namespace Database\Factories;

use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'type' => InvoiceLineType::Base,
            'description' => 'Base charge',
            'quantity' => 1,
            'rate_micros' => 9990000,
            'proration_days' => 31,
            'cycle_days' => 31,
            'amount_minor' => 99900,
        ];
    }
}
