<?php

namespace Jvjvjv\CodeTalker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSystemRequest extends FormRequest
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'model_capabilities' => ['nullable', 'array'],
            'model_capabilities.reasoning' => ['nullable', 'boolean'],
            'model_capabilities.vision' => ['nullable', 'boolean'],
            'model_capabilities.tools' => ['nullable', 'boolean'],
            'model_capabilities.max_context_length' => ['nullable', 'integer', 'min:1', 'max:2000000'],
            'base_url'    => ['nullable', 'string', 'url', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:50'],
            'max_tokens'  => ['required', 'integer', 'min:1', 'max:200000'],
            'context_length' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active'   => ['boolean'],
            'system_prompt_id' => ['nullable', 'integer', 'exists:ai_system_prompts,id'],
            'custom_system_prompt' => ['nullable', 'string'],
            'config'      => ['nullable', 'json'],
            'credentials' => ['nullable', 'json'],
            'auth_type' => ['nullable', 'string', 'max:50'],
            'endpoint_type' => ['nullable', 'string', 'max:50'],
            'stream_protocol' => ['nullable', 'string', 'max:50'],
            'system_prompt_mode' => ['nullable', 'string', 'max:50'],
            'supports_tools' => ['boolean'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:255'],
            'supports_json_mode' => ['boolean'],
            'enable_thinking' => ['nullable', 'boolean'],
            'is_local_endpoint' => ['boolean'],
            'pricing_profile' => ['nullable', 'json'],
            'feature_defaults'   => ['nullable', 'array'],
            'feature_defaults.*' => ['string', 'in:targeted-resume,cover-letter'],
        ];
    }
}
