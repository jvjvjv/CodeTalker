<?php

namespace Jvjvjv\CodeTalker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiSystemPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:200'],
            'content'     => ['required', 'string'],
        ];
    }
}
