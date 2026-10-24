<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Jvjvjv\CodeTalker\Enums\AiProvider;
use Jvjvjv\CodeTalker\Enums\ThinkingLevel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\LaravelAi\AgentFactory;
use Jvjvjv\CodeTalker\Services\LaravelAi\Thinking\ThinkingProfile;
use Jvjvjv\CodeTalker\Services\LaravelAi\Thinking\ThinkingResolution;
use Jvjvjv\CodeTalker\Services\LaravelAi\Thinking\ThinkingResolver;
use Jvjvjv\CodeTalker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ThinkingMappingTest extends TestCase
{
    private function resolve(string $provider, string $model, ?string $level, ?int $maxTokens = 8192, array $attributes = []): ThinkingResolution
    {
        $system = new AiSystem(array_merge(['provider' => $provider, 'model' => $model, 'thinking_level' => $level], $attributes));

        return (new ThinkingResolver())->resolve(AiProvider::from($provider), $system, $maxTokens);
    }

    private function adaptive(string $effort, bool $display = true): array
    {
        return [
            'thinking' => $display ? ['type' => 'adaptive', 'display' => 'summarized'] : ['type' => 'adaptive'],
            'output_config' => ['effort' => $effort],
        ];
    }

    public function test_clamping_prefers_the_next_lower_level_then_the_next_higher(): void
    {
        $profile = ThinkingProfile::effort([ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High], canDisable: true);

        $this->assertSame(ThinkingLevel::High, $profile->clamp(ThinkingLevel::XHigh));
        $this->assertSame(ThinkingLevel::High, $profile->clamp(ThinkingLevel::Max));
        $this->assertSame(ThinkingLevel::Medium, $profile->clamp(ThinkingLevel::Medium));
        $this->assertSame(ThinkingLevel::Low, $profile->clamp(ThinkingLevel::Minimal));
    }

    public function test_an_unset_level_sends_nothing_and_shows_nothing(): void
    {
        foreach (['anthropic' => 'claude-opus-5-5', 'openai' => 'gpt-5.1', 'gemini' => 'gemini-3-pro', 'lm-studio' => 'qwen3'] as $provider => $model) {
            $resolution = $this->resolve($provider, $model, null, attributes: ['model_capabilities' => ['reasoning' => true]]);

            $this->assertSame([], $resolution->options, $provider);
            $this->assertFalse($resolution->showReasoning, $provider);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function anthropicCases(): array
    {
        $adaptive = static fn (string $effort, bool $display = true): array => [
            'thinking' => $display ? ['type' => 'adaptive', 'display' => 'summarized'] : ['type' => 'adaptive'],
            'output_config' => ['effort' => $effort],
        ];

        return [
            'opus 5.5 medium' => ['claude-opus-5-5', 'medium', $adaptive('medium')],
            'opus 5.5 off clamps to low' => ['claude-opus-5-5', 'off', $adaptive('low')],
            'opus 5.5 minimal clamps up to low' => ['claude-opus-5-5', 'minimal', $adaptive('low')],
            'sonnet 5.5 xhigh' => ['claude-sonnet-5-5', 'xhigh', $adaptive('xhigh')],
            'fable off clamps to low' => ['claude-fable-5-1', 'off', $adaptive('low')],
            'unknown future model is treated as newest' => ['claude-opus-6', 'off', $adaptive('low')],
            'opus 5 off is disabled' => ['claude-opus-5', 'off', ['thinking' => ['type' => 'disabled']]],
            'haiku 5.5 off is disabled' => ['claude-haiku-5-5', 'off', ['thinking' => ['type' => 'disabled']]],
            'sonnet 5 high' => ['claude-sonnet-5', 'high', $adaptive('high')],
            'opus 4.8 off omits thinking' => ['claude-opus-4-8', 'off', []],
            'opus 4.7 max' => ['claude-opus-4-7', 'max', $adaptive('max')],
            'sonnet 4.6 low has no display' => ['claude-sonnet-4-6', 'low', $adaptive('low', false)],
            'opus 4.6 xhigh clamps to high' => ['claude-opus-4-6', 'xhigh', $adaptive('high', false)],
            'bedrock-style id' => ['us.anthropic.claude-opus-4-8-v1:0', 'off', []],
            'stacked bedrock suffixes' => ['anthropic.claude-sonnet-4-5-20250929-v1:0', 'low', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]]],
            'sonnet 4.5 medium budget' => ['claude-sonnet-4-5-20250929', 'medium', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 4096]]],
            'haiku 4.5 low budget' => ['claude-haiku-4-5', 'low', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]]],
            'opus 4.5 max budget is capped below max_tokens' => ['claude-opus-4-5', 'max', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 8191]]],
            'sonnet 3.7 budget' => ['claude-3-7-sonnet-20250219', 'high', ['thinking' => ['type' => 'enabled', 'budget_tokens' => 8191]]],
            'budget model off' => ['claude-sonnet-4-5', 'off', []],
            'claude 3.5 has no thinking' => ['claude-3-5-sonnet-20241022', 'high', []],
        ];
    }

    #[DataProvider('anthropicCases')]
    public function test_anthropic_requests(string $model, string $level, array $expected): void
    {
        $this->assertSame($expected, $this->resolve('anthropic', $model, $level)->options);
    }

    public function test_anthropic_budget_models_get_no_thinking_without_room_for_the_minimum_budget(): void
    {
        $this->assertSame([], $this->resolve('anthropic', 'claude-sonnet-4-5', 'high', 1024)->options);
        $this->assertSame([], $this->resolve('anthropic', 'claude-sonnet-4-5', 'high', null)->options);
        $this->assertSame(
            ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]],
            $this->resolve('anthropic', 'claude-sonnet-4-5', 'high', 1025)->options,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function responsesApiCases(): array
    {
        $effort = static fn (string $effort): array => ['reasoning' => ['effort' => $effort, 'summary' => 'auto']];

        return [
            'gpt-5.1 low' => ['openai', 'gpt-5.1', 'low', $effort('low')],
            'gpt-5.1 off is none' => ['openai', 'gpt-5.1-2025-11-13', 'off', ['reasoning' => ['effort' => 'none']]],
            'gpt-5.1 xhigh clamps to high' => ['openai', 'gpt-5.1', 'xhigh', $effort('high')],
            'gpt-5.2 xhigh' => ['openai', 'gpt-5.2', 'xhigh', $effort('xhigh')],
            'gpt-5.2 max clamps to xhigh' => ['openai', 'gpt-5.2', 'max', $effort('xhigh')],
            'gpt-5 minimal' => ['openai', 'gpt-5-mini', 'minimal', $effort('minimal')],
            'gpt-5 off clamps to minimal' => ['openai', 'gpt-5', 'off', $effort('minimal')],
            'gpt-5-pro is high only' => ['openai', 'gpt-5-pro', 'low', $effort('high')],
            'o3 off clamps to low' => ['openai', 'o3', 'off', $effort('low')],
            'o4-mini max clamps to high' => ['openai', 'o4-mini', 'max', $effort('high')],
            'gpt-4o takes no effort' => ['openai', 'gpt-4o', 'high', []],
            'gpt-5-chat takes no effort' => ['openai', 'gpt-5-chat-latest', 'high', []],
            'fine-tune of a non-reasoning model' => ['openai', 'ft:gpt-4.1-mini:acme::abc123', 'high', []],
            'unknown openai model' => ['openai', 'gpt-7', 'max', $effort('high')],
            'grok-3-mini medium clamps to low' => ['grok', 'grok-3-mini', 'medium', ['reasoning' => ['effort' => 'low']]],
            'grok-3-mini max clamps to high' => ['grok', 'grok-3-mini-fast', 'max', ['reasoning' => ['effort' => 'high']]],
            'grok-4 takes no effort' => ['grok', 'grok-4', 'high', []],
        ];
    }

    #[DataProvider('responsesApiCases')]
    public function test_responses_api_requests(string $provider, string $model, string $level, array $expected): void
    {
        $this->assertSame($expected, $this->resolve($provider, $model, $level)->options);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function geminiCases(): array
    {
        $level = static fn (string $level): array => ['thinkingConfig' => ['thinkingLevel' => $level, 'includeThoughts' => true]];
        $budget = static fn (int $tokens): array => ['thinkingConfig' => ['thinkingBudget' => $tokens, 'includeThoughts' => true]];

        return [
            'gemini 3 pro high' => ['gemini-3-pro-preview', 'high', $level('high')],
            'gemini 3 pro medium clamps to low' => ['gemini-3-pro', 'medium', $level('low')],
            'gemini 3 pro off clamps to low' => ['gemini-3-pro', 'off', $level('low')],
            'gemini 3 flash medium' => ['gemini-3-flash', 'medium', $level('medium')],
            'gemini 3 flash off clamps to minimal' => ['gemini-3-flash', 'off', $level('minimal')],
            'gemini 2.5 pro medium budget' => ['gemini-2.5-pro', 'medium', $budget(4096)],
            'gemini 2.5 pro off clamps to minimal' => ['gemini-2.5-pro', 'off', $budget(512)],
            'gemini 2.5 flash off' => ['gemini-2.5-flash', 'off', ['thinkingConfig' => ['thinkingBudget' => 0]]],
            'gemini 2.5 flash-lite max' => ['models/gemini-2.5-flash-lite', 'max', $budget(24576)],
            'gemini 2.0 has no thinking' => ['gemini-2.0-flash', 'high', []],
            'unknown gemini model' => ['gemini-4-ultra', 'max', $level('high')],
        ];
    }

    #[DataProvider('geminiCases')]
    public function test_gemini_requests(string $model, string $level, array $expected): void
    {
        $this->assertSame($expected, $this->resolve('gemini', $model, $level)->options);
    }

    public function test_lm_studio_sends_its_native_level_only_for_reasoning_capable_models(): void
    {
        $capable = ['model_capabilities' => ['reasoning' => true]];

        $this->assertSame(['reasoning' => 'medium'], $this->resolve('lm-studio', 'qwen3', 'medium', attributes: $capable)->options);
        $this->assertSame(['reasoning' => 'off'], $this->resolve('lm-studio', 'qwen3', 'off', attributes: $capable)->options);
        $this->assertSame(['reasoning' => 'high'], $this->resolve('lm-studio', 'qwen3', 'max', attributes: $capable)->options);
        $this->assertSame(['reasoning' => 'low'], $this->resolve('lm-studio', 'qwen3', 'minimal', attributes: $capable)->options);

        foreach ([null, false] as $reasoning) {
            $resolution = $this->resolve('lm-studio', 'qwen3', 'high', attributes: ['model_capabilities' => ['reasoning' => $reasoning]]);

            $this->assertSame([], $resolution->options);
            $this->assertTrue($resolution->showReasoning);
        }
    }

    public function test_generic_openai_compatible_endpoints_get_visibility_only(): void
    {
        $on = $this->resolve('openai-compatible', 'deepseek-r1', 'high');
        $off = $this->resolve('openai-compatible', 'deepseek-r1', 'off');

        $this->assertSame([], $on->options);
        $this->assertTrue($on->showReasoning);
        $this->assertSame([], $off->options);
        $this->assertFalse($off->showReasoning);
    }

    public function test_reasoning_is_shown_only_above_off(): void
    {
        $this->assertTrue($this->resolve('anthropic', 'claude-opus-5-5', 'low')->showReasoning);
        $this->assertFalse($this->resolve('anthropic', 'claude-opus-5-5', 'off')->showReasoning);
        $this->assertFalse($this->resolve('anthropic', 'claude-opus-4-8', 'off')->showReasoning);
        $this->assertTrue($this->resolve('gemini', 'gemini-2.0-flash', 'high')->showReasoning);
    }

    public function test_the_legacy_flag_resolves_when_no_level_is_set(): void
    {
        $system = new AiSystem(['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'enable_thinking' => true]);

        $resolution = (new ThinkingResolver())->resolve(AiProvider::Anthropic, $system, 8192);

        $this->assertSame($this->adaptive('medium'), $resolution->options);
        $this->assertTrue($resolution->showReasoning);
    }

    public function test_gemini_thinking_config_reaches_the_generation_config_on_the_wire(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])]);

        $system = new AiSystem([
            'provider' => 'gemini',
            'api_key' => 'gm-test',
            'model' => 'gemini-3-flash',
            'max_tokens' => 1024,
            'thinking_level' => 'medium',
        ]);
        $system->id = 7;

        $this->app->make(AgentFactory::class)->forSystem($system)->prompt('Hello');

        Http::assertSent(fn (Request $request): bool => ($request->data()['generationConfig']['thinkingConfig'] ?? null)
            === ['thinkingLevel' => 'medium', 'includeThoughts' => true]);
    }
}
