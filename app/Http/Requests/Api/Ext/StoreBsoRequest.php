<?php

namespace App\Http\Requests\Api\Ext;

use Illuminate\Foundation\Http\FormRequest;

class StoreBsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bsos' => ['required', 'array', 'min:1', 'max:100'],
            'bsos.*.id' => ['required', 'string', 'max:255'],
            'bsos.*.payload' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $decoded = json_decode($this->getContent(), true);

        if (is_array($decoded) && isset($decoded[0])) {
            $this->merge(['bsos' => $decoded]);
        }
    }

    /**
     * Get the validated BSOs ready for storage.
     */
    public function validatedBsos(): array
    {
        return array_map(fn (array $bso) => [
            'id' => (string) $bso['id'],
            'payload' => (string) $bso['payload'],
        ], $this->validated()['bsos']);
    }
}
