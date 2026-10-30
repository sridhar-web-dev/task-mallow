<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['merchant_id', 'customer_id', 'usage_date', 'quantity', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'usage_date' => DateOnly::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
