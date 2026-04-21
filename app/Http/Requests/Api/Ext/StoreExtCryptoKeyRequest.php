<?php

namespace App\Http\Requests\Api\Ext;

use Illuminate\Foundation\Http\FormRequest;

class StoreExtCryptoKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'encryption_key' => ['required', 'string', 'max:10240'],
        ];
    }
}
