<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpsertRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxSize = (int) config('services.sync.max_record_size', 262144);

        return [
            'payload' => ['required', 'string', "max:{$maxSize}"],
            'ttl' => ['nullable', 'date'],
        ];
    }

    public function ifUnmodifiedSince(): ?float
    {
        $header = $this->header('X-If-Unmodified-Since');

        return $header !== null ? (float) $header : null;
    }
}
