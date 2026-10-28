<?php

namespace App\Http\Requests;

use App\Observability\Reports\TimeRange;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared query-string filter for every dashboard report: ?range=1h|6h|24h|7d
 */
class RangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', 'in:'.implode(',', array_keys(TimeRange::PRESETS))],
        ];
    }

    public function range(string $default = '24h'): TimeRange
    {
        return TimeRange::preset($this->input('range') ?: $default);
    }
}
