<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'in:desktop,mobile,tablet'],
            'device_os' => ['nullable', 'string', 'max:255'],
            'browser_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
