<?php

namespace App\Models;

use App\Enums\InvoiceLineType;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'subscription_segment_id',
        'type',
        'description',
        'quantity',
        'rate_micros',
        'proration_days',
        'cycle_days',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionSegment::class, 'subscription_segment_id');
    }
}
