<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\DailyUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyUsage extends Model
{
    /** @use HasFactory<DailyUsageFactory> */
    use HasFactory;

    protected $table = 'daily_usage';

    protected $fillable = ['merchant_id', 'customer_id', 'usage_date', 'units'];

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
