<?php

namespace Jvjvjv\CodeTalker\Services;

use Jvjvjv\CodeTalker\Enums\AiInteractionStatus;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiInteractionLog;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Illuminate\Support\Collection;

class ConversationUsageService
{
    public function syncConversation(AiConversation $conversation): bool
    {
        $usage = $this->buildUsageSummary($conversation);

        $updated = [
            'usage_input_tokens' => $usage['input_tokens'],
            'usage_output_tokens' => $usage['output_tokens'],
            'usage_total_tokens' => $usage['total_tokens'],
            'usage_cost_usd' => $usage['cost_usd'],
            'usage_synced_at' => now(),
        ];

        $currentCost = $conversation->usage_cost_usd !== null
            ? (float) $conversation->usage_cost_usd
            : null;

        $hasChanges =
            $conversation->usage_input_tokens !== $updated['usage_input_tokens']
            || $conversation->usage_output_tokens !== $updated['usage_output_tokens']
            || $conversation->usage_total_tokens !== $updated['usage_total_tokens']
            || $currentCost !== $updated['usage_cost_usd'];

        AiConversation::withoutTimestamps(function () use ($conversation, $updated): void {
            $conversation->forceFill($updated)->save();
        });

        return $hasChanges;
    }

    /**
     * @return array{input_tokens: ?int, output_tokens: ?int, total_tokens: ?int, cost_usd: ?float}
     */
    public function buildUsageSummary(AiConversation $conversation): array
    {
        $logs = AiInteractionLog::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('status', AiInteractionStatus::Success->value)
            ->where(function ($query): void {
                $query->whereNotNull('input_tokens')
                    ->orWhereNotNull('output_tokens');
            })
            ->with('aiSystem:id,provider,pricing_profile')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'cost_usd' => null,
            ];
        }

        $inputTokens = (int) $logs->sum(fn (AiInteractionLog $log): int => (int) ($log->input_tokens ?? 0));
        $outputTokens = (int) $logs->sum(fn (AiInteractionLog $log): int => (int) ($log->output_tokens ?? 0));
        $totalTokens = $inputTokens + $outputTokens;
        $costUsd = $this->estimateCostUsd($logs);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
        ];
    }

    /**
     * @return array{input_token_price_snapshot: ?float, output_token_price_snapshot: ?float}
     */
    public function pricingSnapshotForSystem(AiSystem $system, ?string $model = null): array
    {
        $pricing = $this->pricingRatesForSystem($system, $model);

        return [
            'input_token_price_snapshot' => $pricing['input_rate'] !== null
                ? round($pricing['input_rate'] / 1000000, 8)
                : null,
            'output_token_price_snapshot' => $pricing['output_rate'] !== null
                ? round($pricing['output_rate'] / 1000000, 8)
                : null,
        ];
    }

    /**
     * @param Collection<int, AiInteractionLog> $logs
     */
    private function estimateCostUsd(Collection $logs): ?float
    {
        $totalCost = 0.0;

        foreach ($logs as $log) {
            $inputTokens = (int) ($log->input_tokens ?? 0);
            $outputTokens = (int) ($log->output_tokens ?? 0);
            $inputSnapshot = $log->input_token_price_snapshot !== null
                ? (float) $log->input_token_price_snapshot
                : null;
            $outputSnapshot = $log->output_token_price_snapshot !== null
                ? (float) $log->output_token_price_snapshot
                : null;

            if ($inputSnapshot !== null) {
                $totalCost += $inputTokens * $inputSnapshot;
            }

            if ($outputSnapshot !== null) {
                $totalCost += $outputTokens * $outputSnapshot;
            }

            if ($inputSnapshot !== null && $outputSnapshot !== null) {
                continue;
            }

            $pricing = $this->pricingRatesForSystem($log->aiSystem, $log->model);

            if (($inputSnapshot === null && $pricing['input_rate'] === null)
                || ($outputSnapshot === null && $pricing['output_rate'] === null)) {
                return null;
            }

            if ($inputSnapshot === null && $pricing['input_rate'] !== null) {
                $totalCost += ($inputTokens / 1000000) * $pricing['input_rate'];
            }

            if ($outputSnapshot === null && $pricing['output_rate'] !== null) {
                $totalCost += ($outputTokens / 1000000) * $pricing['output_rate'];
            }
        }

        return round($totalCost, 6);
    }

    /**
     * @return array{input_rate: ?float, output_rate: ?float}
     */
    private function pricingRatesForSystem(?AiSystem $system, ?string $model = null): array
    {
        if ($system === null) {
            return [
                'input_rate' => null,
                'output_rate' => null,
            ];
        }

        $pricingConfig = $this->effectivePricingConfig(
            $system->provider,
            (array) ($system->pricing_profile ?? []),
        );

        if ($pricingConfig === null) {
            return [
                'input_rate' => null,
                'output_rate' => null,
            ];
        }

        return $this->pricingRatesForConfig($pricingConfig, (string) ($model ?? ''));
    }

    /**
     * @param array<string, mixed> $pricingProfile
     * @return array<string, mixed>|null
     */
    private function effectivePricingConfig(string $provider, array $pricingProfile): ?array
    {
        $providerPricing = $this->pricingConfigForProvider($provider);

        if ($providerPricing === null && $pricingProfile === []) {
            return null;
        }

        $normalizedProfile = $this->normalizePricingConfig($pricingProfile);

        if ($providerPricing === null) {
            return $normalizedProfile;
        }

        if ($normalizedProfile === []) {
            return $providerPricing;
        }

        return array_replace_recursive($providerPricing, $normalizedProfile);
    }

    /**
     * @param array<string, mixed> $pricingConfig
     * @return array{input_rate: ?float, output_rate: ?float}
     */
    private function pricingRatesForConfig(array $pricingConfig, string $model): array
    {
        $normalizedPricing = $this->normalizePricingConfig($pricingConfig);
        $pricingByModel = (array) ($normalizedPricing['models'] ?? []);
        $defaultPricing = (array) ($normalizedPricing['default'] ?? []);
        $modelPricing = (array) ($pricingByModel[$model] ?? []);

        $defaultInputRate = isset($defaultPricing['input_per_million'])
            ? (float) $defaultPricing['input_per_million']
            : null;
        $defaultOutputRate = isset($defaultPricing['output_per_million'])
            ? (float) $defaultPricing['output_per_million']
            : null;

        return [
            'input_rate' => isset($modelPricing['input_per_million'])
                ? (float) $modelPricing['input_per_million']
                : $defaultInputRate,
            'output_rate' => isset($modelPricing['output_per_million'])
                ? (float) $modelPricing['output_per_million']
                : $defaultOutputRate,
        ];
    }

    /**
     * @param array<string, mixed> $pricingConfig
     * @return array<string, mixed>
     */
    private function normalizePricingConfig(array $pricingConfig): array
    {
        if ($pricingConfig === []) {
            return [];
        }

        if (array_key_exists('default', $pricingConfig) || array_key_exists('models', $pricingConfig)) {
            return $pricingConfig;
        }

        $defaultPricing = [];

        if (isset($pricingConfig['input_per_million'])) {
            $defaultPricing['input_per_million'] = $pricingConfig['input_per_million'];
        }

        if (isset($pricingConfig['output_per_million'])) {
            $defaultPricing['output_per_million'] = $pricingConfig['output_per_million'];
        }

        if ($defaultPricing === []) {
            return $pricingConfig;
        }

        return [
            'default' => $defaultPricing,
            'models' => (array) ($pricingConfig['models'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pricingConfigForProvider(string $provider): ?array {
        return match ($provider) {
            'anthropic' => (array) config('code-talker.providers.anthropic.pricing', []),
            'openai', 'openai-compatible' => (array) config('code-talker.providers.openai.pricing', []),
            default => null,
        };
    }
}
