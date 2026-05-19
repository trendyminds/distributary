<?php

namespace Trendyminds\Distributary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:zip'],
        ];
    }
}
