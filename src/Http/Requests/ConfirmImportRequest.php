<?php

namespace Trendyminds\Distributary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
