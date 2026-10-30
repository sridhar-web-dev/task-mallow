<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\SubscriptionSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionSegment extends Model
{
    /** @use HasFactory<SubscriptionSegmentFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'plan_id',
        'starts_on',
        'ends_on',
        'base_price_minor',
        'included_units',
        'overage_rate_micros',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
