<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchUsersRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->input('query');

        if (is_string($query)) {
            $this->merge(['query' => trim($query)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100', 'not_regex:/[%_]/u'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'query.not_regex' => 'The search query may not contain SQL wildcard characters.',
        ];
    }
}
