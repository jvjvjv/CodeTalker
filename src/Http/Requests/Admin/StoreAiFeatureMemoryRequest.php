<?php

namespace Jvjvjv\CodeTalker\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAiFeatureMemoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'feature' => ['required', 'string', 'max:50'],
            'category' => ['required', 'string', 'in:preference,domain_knowledge,system_tuning'],
            'key' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'confidence' => ['required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
