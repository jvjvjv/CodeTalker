<?php

namespace Jvjvjv\CodeTalker\Http\Requests\Admin;

use App\Models\AiChatBot;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiChatBotRequest extends FormRequest
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
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('ai_chat_bots', 'slug'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('access_path') === AiChatBot::ACCESS_PATH_ROOT
                        && in_array((string) $value, AiChatBot::reservedRootSlugs(), true)) {
                        $fail('This slug is reserved for an existing site route and cannot be used from the root path.');
                    }
                },
            ],
            'access_path' => ['required', Rule::in([AiChatBot::ACCESS_PATH_CHAT, AiChatBot::ACCESS_PATH_ROOT])],
            'description' => ['nullable', 'string'],
            'ai_system_id' => ['required', 'integer', 'exists:ai_systems,id'],
            'context_length' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'prompt_template' => ['required', 'string'],
            'allowed_roles' => ['nullable', 'array'],
            'allowed_roles.*' => ['string', 'max:255'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'require_visitor_identity' => ['boolean'],
            'tools_enabled' => ['boolean'],
        ];
    }
}
