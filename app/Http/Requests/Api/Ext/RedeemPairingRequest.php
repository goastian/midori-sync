<?php

namespace App\Http\Requests\Api\Ext;

use Illuminate\Foundation\Http\FormRequest;

class RedeemPairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pairing_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'in:desktop,mobile,tablet'],
        ];
    }
}
