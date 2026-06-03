<?php

namespace Jvjvjv\CodeTalker\Console\Commands;

use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiSystemCapabilityService;
use Illuminate\Console\Command;

class BackfillAiSystemCapabilitiesCommand extends Command
{
    protected $signature = 'ai:backfill-system-capabilities
        {--provider=* : Restrict backfill to one or more providers}
        {--id=* : Restrict backfill to one or more AI system IDs}
        {--force : Refresh systems even if capabilities are already stored}
        {--chunk=100 : Number of systems to process per batch}';

    protected $description = 'Backfill persisted model capability metadata for AI systems.';

    public function __construct(private AiSystemCapabilityService $aiSystemCapabilityService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $providers = collect($this->option('provider'))
            ->filter(static fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->values()
            ->all();

        $requestedIds = collect($this->option('id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $supportedProviders = $this->aiSystemCapabilityService->supportedProviders();

        if ($providers !== []) {
            $unsupportedProviders = array_values(array_diff($providers, $supportedProviders));

            if ($unsupportedProviders !== []) {
                $this->warn('Capability backfill is not supported for: ' . implode(', ', $unsupportedProviders));
            }

            $providers = array_values(array_intersect($providers, $supportedProviders));
        } else {
            $providers = $supportedProviders;
        }

        if ($providers === []) {
            $this->info('No supported providers selected for capability backfill.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $chunkSize = max((int) $this->option('chunk'), 1);

        $query = AiSystem::query()
            ->whereIn('provider', $providers)
            ->orderBy('id');

        if ($requestedIds !== []) {
            $query->whereIn('id', $requestedIds);
        }

        if (!$force) {
            $query->whereNull('model_capabilities');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No AI systems matched the backfill criteria.');

            return self::SUCCESS;
        }

        $this->info('Backfilling capabilities for ' . $total . ' AI system(s)...');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $missing = 0;

        $query->chunkById($chunkSize, function ($systems) use (&$updated, &$unchanged, &$missing, $bar): void {
            foreach ($systems as $system) {
                if (!$system instanceof AiSystem) {
                    continue;
                }

                $capabilities = $this->aiSystemCapabilityService->backfillSystem($system);

                if ($capabilities === null) {
                    $missing++;
                    $bar->advance();
                    continue;
                }

                if ($system->model_capabilities === $capabilities) {
                    $unchanged++;
                    $bar->advance();
                    continue;
                }

                $system->forceFill(['model_capabilities' => $capabilities])->save();
                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('Capability backfill complete.');
        $this->line('Updated: ' . $updated);
        $this->line('Unchanged: ' . $unchanged);
        $this->line('Missing metadata: ' . $missing);

        return self::SUCCESS;
    }
}
