<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIRST_PARTITION_MONTH = '2026-01-01';

    private const PARTITION_MONTHS = 24;

    public function up(): void
    {
        // Append-only and the only table expected to reach 50L+ rows. It has no
        // foreign keys because MariaDB and MySQL do not allow them on partitioned
        // tables, and POST /usage validates the customer before inserting.
        $isMysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('usage_events', function (Blueprint $table) use ($isMysql) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('usage_date');
            $table->unsignedInteger('quantity');
            $idempotencyKey = $table->string('idempotency_key', 64);
            $table->timestamp('created_at')->useCurrent();

            // ASCII keeps this index small: 64 bytes per key rather than 256.
            if ($isMysql) {
                $idempotencyKey->charset('ascii')->collation('ascii_bin');
            }

            // Rejects retries, and its prefix serves aggregation lookups by
            // customer and day, so the table carries no other secondary index.
            $table->unique(['customer_id', 'usage_date', 'idempotency_key'], 'usage_events_idempotency_unique');
        });

        if (! $isMysql) {
            return;
        }

        // Every unique key must contain the partition column.
        DB::statement('ALTER TABLE usage_events DROP PRIMARY KEY, ADD PRIMARY KEY (id, usage_date)');
        DB::statement('ALTER TABLE usage_events PARTITION BY RANGE (TO_DAYS(usage_date)) ('.$this->partitions().')');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }

    private function partitions(): string
    {
        $month = CarbonImmutable::parse(self::FIRST_PARTITION_MONTH);
        $partitions = [];

        for ($i = 0; $i < self::PARTITION_MONTHS; $i++) {
            $next = $month->addMonthNoOverflow();
            $partitions[] = sprintf(
                "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))",
                $month->format('Ym'),
                $next->toDateString(),
            );
            $month = $next;
        }

        // Catches anything past the last month so an insert can never fail on a missing partition.
        $partitions[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        return implode(', ', $partitions);
    }
};
