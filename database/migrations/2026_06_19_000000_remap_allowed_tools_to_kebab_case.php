<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Built-in tools were renamed from snake_case to laravel/mcp's kebab-case
     * convention. Tool names are persisted in ai_systems.allowed_tools and are
     * matched by exact string, so existing rows must be remapped or the tools
     * would silently stop being enabled.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'fetch_web_page' => 'fetch-web-page',
        'search_web' => 'search-web',
        'scan_memories' => 'scan-memories',
    ];

    public function up(): void
    {
        $this->remap(self::RENAMES);
    }

    public function down(): void
    {
        $this->remap(array_flip(self::RENAMES));
    }

    /**
     * Rewrite only the known tool names inside each row's allowed_tools array,
     * leaving any host-app tool names untouched. Idempotent: applying it twice
     * is a no-op because only exact source names are rewritten.
     *
     * @param array<string, string> $map
     */
    private function remap(array $map): void
    {
        DB::table('ai_systems')
            ->whereNotNull('allowed_tools')
            ->orderBy('id')
            ->each(function (object $system) use ($map): void {
                $tools = json_decode((string) $system->allowed_tools, true);

                if (!is_array($tools)) {
                    return;
                }

                $remapped = array_values(array_unique(array_map(
                    static fn ($name): string => $map[$name] ?? (string) $name,
                    $tools,
                )));

                if ($remapped === $tools) {
                    return;
                }

                DB::table('ai_systems')
                    ->where('id', $system->id)
                    ->update(['allowed_tools' => json_encode($remapped)]);
            });
    }
};
