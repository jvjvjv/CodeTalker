<?php

namespace Jvjvjv\CodeTalker\Services\Mcp;

use Jvjvjv\CodeTalker\Contracts\Mcp\AiToolHandlerContract;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use SplFileInfo;

trait DiscoversAiToolHandlers
{
    /**
     * Discover tool handlers from the given path→namespace map.
     *
     * @param array<string, string> $toolDirectories  Absolute path → PSR-4 namespace prefix (with trailing backslash)
     * @param array<string, mixed> $parameterOverrides  Container make() overrides passed to each handler
     * @param array<int, string> $preferredRelativePrefixes  Relative path prefixes to prioritise in sort order
     * @return array<string, AiToolHandlerContract>
     */
    private function discoverHandlers(
        array $toolDirectories,
        array $parameterOverrides = [],
        array $preferredRelativePrefixes = [],
    ): array {
        $toolFiles = [];

        foreach (array_keys($toolDirectories) as $toolDirectory) {
            if (!File::isDirectory($toolDirectory)) {
                continue;
            }

            foreach (File::allFiles($toolDirectory) as $toolFile) {
                $toolFiles[] = [$toolFile, $toolDirectory];
            }
        }

        usort(
            $toolFiles,
            fn (array $left, array $right): int => strcmp(
                $this->toolDiscoverySortKey($left[0], $left[1], $preferredRelativePrefixes),
                $this->toolDiscoverySortKey($right[0], $right[1], $preferredRelativePrefixes),
            ),
        );

        $handlers = [];

        foreach ($toolFiles as [$toolFile, $toolDirectory]) {
            $className = $this->classNameFromToolFile($toolFile, $toolDirectory, $toolDirectories[$toolDirectory]);

            if ($className === null || !class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(AiToolHandlerContract::class)) {
                continue;
            }

            $handler = app()->makeWith($className, $parameterOverrides);
            $handlerName = $handler->name();

            if (!isset($handlers[$handlerName])) {
                $handlers[$handlerName] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * @param array<int, string> $preferredRelativePrefixes
     */
    private function toolDiscoverySortKey(SplFileInfo $toolFile, string $baseDirectory, array $preferredRelativePrefixes): string
    {
        $relativePath = $this->relativeToolPath($toolFile, $baseDirectory);
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        $priority = count($preferredRelativePrefixes);

        foreach ($preferredRelativePrefixes as $index => $prefix) {
            $normalizedPrefix = trim(str_replace(DIRECTORY_SEPARATOR, '/', $prefix), '/');

            if (str_starts_with($normalizedPath, $normalizedPrefix . '/')) {
                $priority = $index;

                break;
            }
        }

        return sprintf('%03d:%s', $priority, $normalizedPath);
    }

    /**
     * Derive a fully-qualified class name from a tool file using the registered namespace prefix.
     */
    private function classNameFromToolFile(SplFileInfo $toolFile, string $baseDirectory, string $namespacePrefix): ?string
    {
        $relativePath = $this->relativeToolPath($toolFile, $baseDirectory);

        if ($relativePath === '') {
            return null;
        }

        return rtrim($namespacePrefix, '\\') . '\\' . str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relativePath,
        );
    }

    private function relativeToolPath(SplFileInfo $toolFile, string $baseDirectory): string
    {
        $base = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $pathName = $toolFile->getPathname();

        if (!str_starts_with($pathName, $base)) {
            return '';
        }

        return substr($pathName, strlen($base));
    }
}
