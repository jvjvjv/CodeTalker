<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Jvjvjv\CodeTalker\Jobs\BackfillConversationUsageJob;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiConversationController extends Controller
{
    /**
     * Display AI conversations across all features.
     */
    public function index(Request $request): InertiaResponse
    {
        $baseRelations = ['aiSystem', 'aiChatBot', 'user'];
        if (method_exists(AiConversation::class, 'targetedResume')) {
            $baseRelations[] = 'targetedResume';
        }

        $query = AiConversation::query()
            ->with($baseRelations)
            ->withCount(['messages' => fn ($messages) => $messages->where('role', '!=', 'system')])
            ->orderByLastMessageAtDesc();

        if ($request->filled('feature')) {
            $query->where('feature', $request->string('feature'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('ai_system_id')) {
            $query->where('ai_system_id', $request->integer('ai_system_id'));
        }

        if ($request->filled('ai_chat_bot_id')) {
            $query->where('ai_chat_bot_id', $request->integer('ai_chat_bot_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('visitor_name', 'like', '%' . $search . '%')
                    ->orWhere('visitor_email', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('aiChatBot', function ($botQuery) use ($search) {
                        $botQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('slug', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('messages', function ($messageQuery) use ($search) {
                        $messageQuery->where('role', '!=', 'system')
                            ->where('content', 'like', '%' . $search . '%');
                    });
            });
        }

        $conversations = $query->paginate(50)->through(fn (AiConversation $conversation) => [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'feature' => $conversation->feature,
            'status' => $conversation->status->value,
            'updated_at' => $conversation->last_message_at?->diffForHumans()
                ?? $conversation->updated_at?->diffForHumans(),
            'messages_count' => $conversation->messages_count,
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
            'user_name' => $conversation->user?->name,
            'user_email' => $conversation->user?->email,
            'ai_system_name' => $conversation->aiSystem?->name,
            'ai_chat_bot_name' => $conversation->aiChatBot?->name,
            'ai_chat_bot_slug' => $conversation->aiChatBot?->slug,
            'chat_hash' => $conversation->chat_hash,
            'usage' => [
                'input_tokens' => $conversation->usage_input_tokens,
                'output_tokens' => $conversation->usage_output_tokens,
                'total_tokens' => $conversation->usage_total_tokens,
                'cost_usd' => $conversation->usage_cost_usd !== null ? (float) $conversation->usage_cost_usd : null,
                'synced_at' => $conversation->usage_synced_at?->toIso8601String(),
            ],
            'targeted_resume' => method_exists($conversation, 'targetedResume') && $conversation->targetedResume ? [
                'id' => $conversation->targetedResume->id,
                'company_name' => $conversation->targetedResume->company_name,
                'position' => $conversation->targetedResume->position,
            ] : null,
        ]);

        return Inertia::render('ai/conversations/Index', [
            'conversations' => $conversations,
            'filters' => $request->only(['feature', 'status', 'ai_system_id', 'ai_chat_bot_id', 'search']),
            'features' => AiConversation::query()->distinct()->orderBy('feature')->pluck('feature'),
            'systems' => AiSystem::query()->orderBy('name')->get(['id', 'name']),
            'bots' => AiChatBot::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Show a single AI conversation.
     */
    public function show(AiConversation $conversation): InertiaResponse
    {
        $showRelations = ['messages', 'aiSystem', 'aiChatBot', 'user'];
        if (method_exists(AiConversation::class, 'targetedResume')) {
            $showRelations[] = 'targetedResume';
        }
        $conversation->load($showRelations);

        $memories = AiFeatureMemory::query()
            ->where('source_conversation_id', $conversation->id)
            ->orderByDesc('confidence')
            ->get(['id', 'feature', 'category', 'key', 'content', 'confidence', 'is_active']);

        return Inertia::render('ai/conversations/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'chat_hash' => $conversation->chat_hash,
                'title' => $conversation->title,
                'feature' => $conversation->feature,
                'status' => $conversation->status->value,
                'context' => $conversation->context,
                'visitor_name' => $conversation->visitor_name,
                'visitor_email' => $conversation->visitor_email,
                'user_name' => $conversation->user?->name,
                'user_email' => $conversation->user?->email,
                'ai_system_name' => $conversation->aiSystem?->name,
                'ai_chat_bot' => $conversation->aiChatBot ? [
                    'id' => $conversation->aiChatBot->id,
                    'name' => $conversation->aiChatBot->name,
                    'slug' => $conversation->aiChatBot->slug,
                ] : null,
                'usage' => [
                    'input_tokens' => $conversation->usage_input_tokens,
                    'output_tokens' => $conversation->usage_output_tokens,
                    'total_tokens' => $conversation->usage_total_tokens,
                    'cost_usd' => $conversation->usage_cost_usd !== null ? (float) $conversation->usage_cost_usd : null,
                    'synced_at' => $conversation->usage_synced_at?->toIso8601String(),
                ],
                'targeted_resume' => method_exists($conversation, 'targetedResume') && $conversation->targetedResume ? [
                    'id' => $conversation->targetedResume->id,
                    'company_name' => $conversation->targetedResume->company_name,
                    'position' => $conversation->targetedResume->position,
                ] : null,
            ],
            'messages' => $conversation->messages
                ->sortBy('created_at')
                ->values()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'metadata' => $message->metadata,
                    'created_at' => $message->created_at?->format('M j, Y g:i A'),
                ]),
            'memories' => $memories,
        ]);
    }

    /**
     * Soft delete a conversation.
     */
    public function destroy(AiConversation $conversation): RedirectResponse
    {
        $conversation->delete();

        return redirect()->route('admin.ai.conversations.index')
            ->with('success', 'AI conversation deleted successfully.');
    }

    public function queueUsageBackfill(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'all' => ['nullable', 'boolean'],
            'chunk' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $all = (bool) ($validated['all'] ?? false);
        $chunk = (int) ($validated['chunk'] ?? 200);

        BackfillConversationUsageJob::dispatch($all, $chunk);

        return redirect()->route('admin.ai.conversations.index')
            ->with('success', $all
                ? 'Usage recompute has been queued for all conversations.'
                : 'Usage backfill has been queued for conversations missing usage.');
    }
}
