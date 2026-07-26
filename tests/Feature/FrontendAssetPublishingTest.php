<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Jvjvjv\CodeTalker\Tests\TestCase;

/**
 * The TypeScript shipped to host apps is only useful if the publish tags
 * actually resolve to files that exist — a renamed or moved source would
 * otherwise fail silently at `vendor:publish` time in someone else's app.
 */
class FrontendAssetPublishingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function pathsFor(string $tag): array
    {
        return ServiceProvider::pathsToPublish(CodeTalkerServiceProvider::class, $tag);
    }

    public function test_the_types_tag_publishes_the_declaration_file(): void
    {
        $paths = $this->pathsFor('code-talker-types');

        $this->assertCount(1, $paths);

        foreach ($paths as $source => $destination) {
            $this->assertFileExists($source);
            $this->assertStringEndsWith('code-talker.d.ts', $source);
            $this->assertStringEndsWith('js/types/code-talker.d.ts', $destination);
        }
    }

    /**
     * The client imports its types by relative path, so publishing it without
     * the declarations would leave that import dangling in the host app.
     */
    public function test_the_client_tag_publishes_the_client_and_its_types(): void
    {
        $paths = $this->pathsFor('code-talker-client');

        $sources = array_keys($paths);

        $this->assertCount(2, $paths);

        foreach ($sources as $source) {
            $this->assertFileExists($source);
        }

        $basenames = array_map('basename', $sources);

        $this->assertContains('code-talker-stream.ts', $basenames);
        $this->assertContains('code-talker.d.ts', $basenames);
    }

    public function test_the_client_import_path_survives_publishing(): void
    {
        $paths = $this->pathsFor('code-talker-client');

        $destinations = array_values($paths);
        $client = null;
        $types = null;

        foreach ($paths as $source => $destination) {
            if (str_ends_with($source, 'code-talker-stream.ts')) {
                $client = $destination;
            } else {
                $types = $destination;
            }
        }

        $this->assertNotNull($client);
        $this->assertNotNull($types);

        // The client resolves './types/code-talker' relative to itself, so the
        // declarations must land in a `types/` directory beside it.
        $this->assertSame(
            dirname($client) . '/types/code-talker.d.ts',
            $types,
            'Published layout must match the relative import inside the client.',
        );

        $this->assertNotEmpty($destinations);
    }

    public function test_the_published_client_imports_only_relative_paths(): void
    {
        $source = file_get_contents(__DIR__ . '/../../resources/js/code-talker-stream.ts');

        preg_match_all('/from\s+[\'"]([^\'"]+)[\'"]/', $source, $matches);

        $this->assertNotEmpty($matches[1], 'Expected at least one import to check.');

        foreach ($matches[1] as $importPath) {
            $this->assertStringStartsWith(
                '.',
                $importPath,
                "The client must stay dependency-free; found a package import: {$importPath}",
            );
        }
    }
}
