<?php

namespace Jvjvjv\CodeTalker\Contracts;

interface CanLoadModels
{
    /**
     * Returns true if the given model identifier has at least one active loaded instance.
     */
    public function isModelLoaded(string $model): bool;

    /**
     * Explicitly load the given model into memory.
     *
     * @return array{status: string, instance_id: string, load_time_seconds: float}
     */
    public function loadModel(string $model, ?int $contextLength = null): array;
}
