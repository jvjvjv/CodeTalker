<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;


use Illuminate\Routing\Controller;
use Jvjvjv\CodeTalker\Http\Requests\Admin\StoreAiChatBotRequest;
use Jvjvjv\CodeTalker\Http\Requests\Admin\UpdateAiChatBotRequest;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Jvjvjv\CodeTalker\Services\Mcp\ChatBotToolRegistry;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiChatBotController extends Controller
{
    /**
     * Display a list of AI chat bots.
     */
    public function index(Request $request): InertiaResponse
    {
        $aiSystemId = $request->query('ai_system_id');

        $bots = AiChatBot::query()
            ->with('aiSystem')
            ->withCount('conversations')
            ->withSum('conversations', 'usage_input_tokens')
            ->withSum('conversations', 'usage_output_tokens')
            ->withSum('conversations', 'usage_cost_usd')
            ->when($aiSystemId, fn ($q) => $q->where('ai_system_id', $aiSystemId))
            ->orderBy('name')
            ->get()
            ->map(fn (AiChatBot $bot) => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
                'access_path' => $bot->access_path,
                'public_url' => $bot->publicPath(),
                'description' => $bot->description,
                'is_active' => $bot->is_active,
                'ai_system' => $bot->aiSystem,
                'require_visitor_identity' => $bot->require_visitor_identity,
                'conversations_count' => $bot->conversations_count,
                '_id' => $bot->aiSystemId,
                'tools_enabled' => $bot->tools_enabled,
                'usage' => $bot->conversations_sum_usage_cost_usd !== null ? [
                    'input_tokens' => (int) ($bot->conversations_sum_usage_input_tokens ?? 0),
                    'output_tokens' => (int) ($bot->conversations_sum_usage_output_tokens ?? 0),
                    'total_tokens' => null,
                    'cost_usd' => (float) $bot->conversations_sum_usage_cost_usd,
                    'synced_at' => null,
                ] : null,
            ]);

        return Inertia::render('ai/bots/Index', [
            'bots' => $bots,
            'filters' => ['ai_system_id' => $aiSystemId],
        ]);
    }

    /**
     * Show the form for creating a bot.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('ai/bots/Create', [
            'systems' => $this->systems(),
        ]);
    }

    /**
     * Store a newly created bot.
     */
    public function store(StoreAiChatBotRequest $request): RedirectResponse
    {
        $bot = AiChatBot::create($request->validated());

        return redirect()->route('admin.ai.bots.index')
            ->with('success', "AI chat bot \"{$bot->name}\" created successfully.");
    }

    /**
     * Show the form for editing a bot.
     */
    public function edit(AiChatBot $aiChatBot): InertiaResponse
    {
        $aiChatBot->loadCount('conversations');

        return Inertia::render('ai/bots/Edit', [
            'bot' => $aiChatBot,
            'systems' => $this->systems(),
        ]);
    }

    /**
     * Return the currently available MCP tools for admin UI display.
     */
    public function mcpTools(
        Request $request,
        AiMemoryService $memoryService,
    ): JsonResponse {
        $request->validate([
            'ai_system_id' => ['nullable', 'integer', 'exists:ai_systems,id'],
            'include_all' => ['nullable', 'boolean'],
        ]);

        $conversation = new AiConversation([
            'user_id' => $request->user()?->getKey(),
            'context' => [],
        ]);

        $allowedTools = null;
        $includeAllTools = $request->boolean('include_all');

        if (!$includeAllTools && $request->filled('ai_system_id')) {
            $allowedTools = AiSystem::query()
                ->whereKey($request->integer('ai_system_id'))
                ->value('allowed_tools');
        }

        $registry = new ChatBotToolRegistry(
            $conversation,
            $allowedTools,
            $includeAllTools,
        );

        return response()->json([
            'tools' => array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                ],
                $registry->toApiTools(),
            ),
        ]);
    }

    /**
     * Update the specified bot.
     */
    public function update(UpdateAiChatBotRequest $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $aiChatBot->update($request->validated());

        return redirect()->route('admin.ai.bots.index')
            ->with('success', "AI chat bot \"{$aiChatBot->name}\" updated successfully.");
    }

    /**
     * Soft delete the specified bot.
     */
    public function destroy(AiChatBot $aiChatBot): RedirectResponse
    {
        $name = $aiChatBot->name;
        $aiChatBot->delete();

        return redirect()->route('admin.ai.bots.index')
            ->with('success', "AI chat bot \"{$name}\" deleted successfully.");
    }

    /**
    * @return array<int, array{id: int, name: string, model: string, context_length: int|null, temperature: float|null, supports_tools: bool}>
     */
    private function systems(): array
    {
        return AiSystem::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (AiSystem $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'model' => $system->model,
                'context_length' => $system->context_length,
                'temperature' => $system->temperature !== null ? (float) $system->temperature : null,
                'supports_tools' => (bool) $system->supports_tools,
            ])
            ->all();
    }

}
