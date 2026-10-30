<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ChangePlanRequest extends FormRequest
{
    /**
     * The merchant is authenticated by middleware before this request runs.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function effectiveOn(): ?CarbonImmutable
    {
        return $this->filled('effective_on')
            ? CarbonImmutable::parse($this->string('effective_on')->toString(), 'UTC')
            : null;
    }
}
