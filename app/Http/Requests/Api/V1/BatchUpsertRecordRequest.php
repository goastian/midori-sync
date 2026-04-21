<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BatchUpsertRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'max:100'],
            'records.*.id' => ['required', 'string', 'max:255'],
            'records.*.payload' => ['required', 'string'],
            'records.*.ttl' => ['nullable', 'date'],
            'records.*.deleted' => ['nullable', 'boolean'],
        ];
    }
}
