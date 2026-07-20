<?php

namespace Jvjvjv\CodeTalker\Http\Controllers;
use Illuminate\Routing\Controller;

use Jvjvjv\CodeTalker\Http\Requests\SendAiChatBotMessageRequest;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
// User model resolved via config('code-talker.user_model')
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Services\AiModelReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatBotController extends Controller
{
    private const COOKIE_MINUTES = 60 * 24 * 180;

    /**
     * The single cookie that remembers the visitor's most recent conversation.
     * Replaces the former per-bot `ai_chat_bot_conversations_{id}` cookies, which
     * accumulated and grew unbounded until the request header exceeded server limits.
     */
    private const CURRENT_COOKIE = 'ai_chat_bot_current';

    /**
     * Defensive cap on the per-bot conversation switcher list. History lives only in
     * the server-side session now, so this never affects cookie size.
     */
    private const MAX_HISTORY = 25;

    /**
     * Legacy per-bot cookie names to forget on sight, e.g. `ai_chat_bot_conversations_12`.
     */
    private const LEGACY_COOKIE_PATTERN = '/^ai_chat_bot_conversations_\d+$/';

    public function __construct(
        private AiChatBotConversationService $conversationService,
        private AiModelReadinessService $modelReadinessService,
    ) {
    }

    /**
     * Display the list of available chat bots.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->forgetLegacyCookies($request);

        $user = $request->user();

        $bots = AiChatBot::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->values();

        $conversationsByBotId = collect();

        if ($user !== null && $bots->isNotEmpty()) {
            $conversationsByBotId = AiConversation::query()
                ->where('user_id', $user->id)
                ->whereIn('ai_chat_bot_id', $bots->pluck('id')->all())
                ->orderByLastMessageAtDesc()
                ->get()
                ->groupBy('ai_chat_bot_id');
        }

        return Inertia::render('ai/ChatBotsIndex', [
            'bots' => $bots->map(function (AiChatBot $bot) use ($conversationsByBotId): array {
                $conversations = collect($conversationsByBotId->get($bot->id, []));

                return [
                    'slug' => $bot->slug,
                    'name' => $bot->name,
                    'description' => $bot->description,
                    'new_chat_url' => $this->routeUrlFor($bot, 'new'),
                    'status_url' => $this->routeUrlFor($bot, 'status'),
                    'conversations' => $conversations->map(function (AiConversation $conversation): array {
                        return [
                            'title' => trim((string) ($conversation->title ?: 'New chat')),
                            'updated_at' => $conversation->last_message_at?->toIso8601String()
                                ?? $conversation->updated_at?->toIso8601String(),
                            'updated_at_human' => $conversation->last_message_at?->diffForHumans()
                                ?? $conversation->updated_at?->diffForHumans()
                                ?? 'just now',
                            'is_stale' => $conversation->is_stale,
                        ];
                    })->values()->all(),
                ];
            })->all(),
        ]);
    }

    public function statuses(Request $request): JsonResponse {
        $bots = AiChatBot::query()
            ->active()
            ->with('aiSystem')
            ->orderBy('name')
            ->get()
            ->values();

        $statusesBySystemId = [];
        $statusesByBotSlug = [];

        foreach ($bots as $bot) {
            if (!array_key_exists($bot->ai_system_id, $statusesBySystemId)) {
                $statusesBySystemId[$bot->ai_system_id] = $this->modelReadinessService->statusForSystem($bot->aiSystem);
            }

            $statusesByBotSlug[$bot->slug] = $statusesBySystemId[$bot->ai_system_id];
        }

        return response()->json(['statuses' => $statusesByBotSlug]);
    }

    /**
     * Display the chat bot page.
     */
    public function show(Request $request, AiChatBot $aiChatBot): InertiaResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        $conversation = $this->storedConversation($request, $aiChatBot);
        $history = $this->historyForBot($request, $aiChatBot);
        $messages = [];

        if ($conversation !== null) {
            $messages = $conversation->messages()
                ->where('role', '!=', 'system')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($message) => [
                    'role' => $message->role,
                    'content' => $message->content,
                    'reasoning_content' => $message->reasoning_content,
                    'blocks' => $message->blocks,
                ])
                ->all();
        }

        // Compute the hash-based URL for the current conversation (if it has one).
        $chatHash = $conversation?->chat_hash;
        $chatUrl = $chatHash
            ? '/chat/' . $aiChatBot->slug . '/' . $chatHash
            : null;

        return Inertia::render('ai/ChatBot', [
            'bot' => [
                'name' => $aiChatBot->name,
                'description' => $aiChatBot->description,
                'require_visitor_identity' => $aiChatBot->require_visitor_identity,
                'total_cost_usd' => (float) (AiConversation::query()
                    ->where('ai_chat_bot_id', $aiChatBot->id)
                    ->sum('usage_cost_usd') ?? 0),
            ],
            'messages' => $messages,
            'history' => $history,
            'messageUrl' => $this->routeUrlFor($aiChatBot, 'message'),
            'resetUrl' => $this->routeUrlFor($aiChatBot, 'reset'),
            'switchUrl' => $this->routeUrlFor($aiChatBot, 'switch'),
            'statusUrl' => $this->routeUrlFor($aiChatBot, 'status'),
            'warmupUrl' => $this->routeUrlFor($aiChatBot, 'warmup'),
            'chatUrl' => $chatUrl,
            'chatUrlBase' => '/chat/' . $aiChatBot->slug . '/',
            'showIdentityForm' => !$request->user()
                && $aiChatBot->require_visitor_identity
                && $conversation === null,
        ]);
    }

    public function status(Request $request, AiChatBot $aiChatBot): JsonResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        return response()->json([
            'status' => $this->modelReadinessService->statusForChatBot($aiChatBot),
        ]);
    }

    public function warmup(Request $request, AiChatBot $aiChatBot): JsonResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        return response()->json([
            'status' => $this->modelReadinessService->warmUpChatBot($aiChatBot),
        ]);
    }

    /**
     * Stream a response from the configured chat bot.
     */
    public function message(SendAiChatBotMessageRequest $request, AiChatBot $aiChatBot): StreamedResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        $conversation = $this->storedConversation($request, $aiChatBot);

        if ($conversation === null) {
            if ($aiChatBot->require_visitor_identity && !$request->user()) {
                $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                ]);
            }

            $conversation = $this->conversationService->startConversation(
                bot: $aiChatBot,
                user: $request->user(),
                visitorName: $request->string('name')->toString() ?: null,
                visitorEmail: $request->string('email')->toString() ?: null,
            );

            $this->rememberConversation($request, $aiChatBot, $conversation);
        }

        // Always regenerate the hash to ensure it uses the current encoding format.
        // generateChatHash() is deterministic (same inputs → same output), so this
        // is safe and also migrates any stale hashes stored by old encode versions.
        $chatHash = $conversation->generateChatHash();

        return response()->stream(function () use ($request, $conversation) {
            echo 'data: ' . json_encode([
                'type' => 'status',
                'phase' => 'request_received',
                'message' => 'Preparing your request.',
            ]) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            $generator = $this->conversationService->continueConversation(
                $conversation,
                $request->validated('message'),
            );

            try {
                foreach ($generator as $chunk) {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                Log::error('Chat bot stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Stream failed unexpectedly.']) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-Chat-Hash' => $chatHash,
        ]);
    }

    public function switch(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        $validated = $request->validate([
            'conversation' => ['required', 'string'],
        ]);

        $state = $this->storedState($request, $aiChatBot);
        $match = collect($state['history'] ?? [])->firstWhere('handle', $validated['conversation']);

        abort_unless($match !== null, 404);

        $state['current'] = $match['public_id'];
        $this->putStoredState($request, $aiChatBot, $state);

        return redirect($this->routeUrlFor($aiChatBot, 'show'));
    }

    /**
    * Start a new chat while preserving prior conversation history for this browser.
     */
    public function reset(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        $state = $this->storedState($request, $aiChatBot);
        $state['current'] = null;
        $this->putStoredState($request, $aiChatBot, $state);

        return redirect($this->routeUrlFor($aiChatBot, 'show'));
    }

    /**
     * Start a new chat conversation (resets session and redirects to show).
     */
    public function newChat(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $this->abortIfInaccessible($request, $aiChatBot);

        $state = $this->storedState($request, $aiChatBot);
        $state['current'] = null;
        $this->putStoredState($request, $aiChatBot, $state);

        return redirect($this->routeUrlFor($aiChatBot, 'show'));
    }

    /**
     * Load a conversation by its hash or UUID (UUID is the fallback for direct linking).
     * This allows accessing a specific chat from any computer.
     */
    public function showByHash(Request $request, string $slug, string $hash): InertiaResponse
    {
        $conversation = AiConversation::findByChatHashOrUuid($hash);

        if ($conversation === null) {
            abort(404);
        }

        $bot = $conversation->aiChatBot;

        $this->abortIfInaccessible($request, $bot);

        // Restore the conversation as the current one in session
        $state = $this->storedState($request, $bot);
        $state['current'] = $conversation->public_id;
        $history = collect($state['history'] ?? []);
        if (!$history->contains(fn (array $item) => $item['public_id'] === $conversation->public_id)) {
            $history->prepend([
                'handle' => (string) Str::ulid(),
                'public_id' => $conversation->public_id,
            ]);
        }
        $this->putStoredState($request, $bot, [
            'current' => $conversation->public_id,
            'history' => $history->values()->all(),
        ]);

        $messages = $conversation->messages()
            ->where('role', '!=', 'system')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
                'reasoning_content' => $message->reasoning_content,
                'blocks' => $message->blocks,
            ])
            ->all();

        $historyForBot = $this->historyForBot($request, $bot);

        $chatUrl = $conversation->chat_hash
            ? '/chat/' . $bot->slug . '/' . $conversation->chat_hash
            : null;

        return Inertia::render('ai/ChatBot', [
            'bot' => [
                'name' => $bot->name,
                'description' => $bot->description,
                'require_visitor_identity' => $bot->require_visitor_identity,
                'total_cost_usd' => (float) (AiConversation::query()
                    ->where('ai_chat_bot_id', $bot->id)
                    ->sum('usage_cost_usd') ?? 0),
            ],
            'messages' => $messages,
            'history' => $historyForBot,
            'messageUrl' => $this->routeUrlFor($bot, 'message'),
            'resetUrl' => $this->routeUrlFor($bot, 'reset'),
            'switchUrl' => $this->routeUrlFor($bot, 'switch'),
            'statusUrl' => $this->routeUrlFor($bot, 'status'),
            'warmupUrl' => $this->routeUrlFor($bot, 'warmup'),
            'showIdentityForm' => !$request->user()
                && $bot->require_visitor_identity
                && $conversation->messages()->where('role', '!=', 'system')->count() === 0,
            'chatHash' => $conversation->chat_hash,
            'chatUrl' => $chatUrl,
            'chatUrlBase' => '/chat/' . $bot->slug . '/',
        ]);
    }

    protected function abortIfInaccessible(Request $request, AiChatBot $aiChatBot): void
    {
        abort_unless($aiChatBot->is_active, 404);
        abort_unless($aiChatBot->access_path === $this->requestAccessPath($request), 404);
    }

    protected function storedConversation(Request $request, AiChatBot $aiChatBot): ?AiConversation
    {
        $conversationPublicId = data_get($this->storedState($request, $aiChatBot), 'current');

        if ($conversationPublicId === null) {
            return null;
        }

        $conversation = AiConversation::query()
            ->where('public_id', $conversationPublicId)
            ->where('ai_chat_bot_id', $aiChatBot->id)
            ->with('messages')
            ->first();

        if ($conversation === null) {
            $request->session()->forget($this->stateKey($aiChatBot));

            return null;
        }

        if ($conversation->user_id !== null && $conversation->user_id !== $request->user()?->id) {
            $request->session()->forget($this->stateKey($aiChatBot));

            return null;
        }

        return $conversation;
    }

    /**
    * @return array<int, array{handle: string, label: string, is_current: bool, updated_at: string, cost_usd: ?float}>
     */
    protected function historyForBot(Request $request, AiChatBot $aiChatBot): array
    {
        $state = $this->storedState($request, $aiChatBot);
        $historyItems = collect($state['history'] ?? []);

        if ($historyItems->isEmpty()) {
            return [];
        }

        $conversations = AiConversation::query()
            ->where('ai_chat_bot_id', $aiChatBot->id)
            ->whereIn('public_id', $historyItems->pluck('public_id')->all())
            ->orderByLastMessageAtDesc()
            ->get()
            ->keyBy('public_id');

        return $historyItems
            ->map(function (array $item) use ($conversations, $state): ?array {
                /** @var AiConversation|null $conversation */
                $conversation = $conversations->get($item['public_id']);

                if ($conversation === null) {
                    return null;
                }

                $label = trim((string) ($conversation->title ?: 'New chat'));

                return [
                    'handle' => $item['handle'],
                    'label' => $label,
                    'is_current' => ($state['current'] ?? null) === $conversation->public_id,
                    'is_stale' => $conversation->is_stale,
                    'updated_at' => $conversation->last_message_at?->diffForHumans()
                        ?? $conversation->updated_at?->diffForHumans()
                        ?? 'just now',
                    'cost_usd' => $conversation->usage_cost_usd !== null
                        ? (float) $conversation->usage_cost_usd
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{current: ?string, history: array<int, array{handle: string, public_id: string}>}
     */
    protected function storedState(Request $request, AiChatBot $aiChatBot): array
    {
        $this->forgetLegacyCookies($request);

        $state = $request->session()->get($this->stateKey($aiChatBot));

        if (!is_array($state)) {
            $current = $request->cookie(self::CURRENT_COOKIE);
            $state = [
                'current' => is_string($current) && $current !== '' ? $current : null,
                'history' => [],
            ];
            $request->session()->put($this->stateKey($aiChatBot), $state);
        }

        return [
            'current' => is_string($state['current'] ?? null) ? $state['current'] : null,
            'history' => collect($state['history'] ?? [])
                ->filter(fn (mixed $item) => is_array($item) && is_string($item['handle'] ?? null) && is_string($item['public_id'] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * Persist per-bot state in the server-side session, and mirror only the current
     * conversation id into the single `ai_chat_bot_current` cookie. History is never
     * written to a cookie, so the request header stays small regardless of bot count.
     *
     * @param array{current: ?string, history: array<int, array{handle: string, public_id: string}>} $state
     */
    protected function putStoredState(Request $request, AiChatBot $aiChatBot, array $state): void
    {
        $current = is_string($state['current'] ?? null) ? $state['current'] : null;
        $history = collect($state['history'] ?? [])->take(self::MAX_HISTORY)->values()->all();

        $request->session()->put($this->stateKey($aiChatBot), [
            'current' => $current,
            'history' => $history,
        ]);

        if ($current === null) {
            Cookie::queue(Cookie::forget(self::CURRENT_COOKIE));

            return;
        }

        Cookie::queue(cookie()->make(
            self::CURRENT_COOKIE,
            $current,
            self::COOKIE_MINUTES,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * Forget the legacy per-bot conversation cookies. These grew unbounded and, with
     * one per bot at path `/`, bloated the request header until the server rejected it.
     */
    protected function forgetLegacyCookies(Request $request): void
    {
        foreach (array_keys($request->cookies->all()) as $name) {
            if (is_string($name) && preg_match(self::LEGACY_COOKIE_PATTERN, $name) === 1) {
                Cookie::queue(Cookie::forget($name));
            }
        }
    }

    protected function rememberConversation(Request $request, AiChatBot $aiChatBot, AiConversation $conversation): void
    {
        $state = $this->storedState($request, $aiChatBot);
        $history = collect($state['history']);

        if (!$history->contains(fn (array $item) => $item['public_id'] === $conversation->public_id)) {
            $history->prepend([
                'handle' => (string) Str::ulid(),
                'public_id' => $conversation->public_id,
            ]);
        }

        $this->putStoredState($request, $aiChatBot, [
            'current' => $conversation->public_id,
            'history' => $history->values()->all(),
        ]);
    }

    protected function clearStoredState(Request $request, AiChatBot $aiChatBot): void
    {
        $request->session()->forget($this->stateKey($aiChatBot));
        Cookie::queue(Cookie::forget(self::CURRENT_COOKIE));
    }

    protected function stateKey(AiChatBot $aiChatBot): string
    {
        return 'ai_chat_bot_conversations_' . $aiChatBot->id;
    }

    protected function requestAccessPath(Request $request): string
    {
        return $request->routeIs('chat-bots.root.*')
            ? AiChatBot::ACCESS_PATH_ROOT
            : AiChatBot::ACCESS_PATH_CHAT;
    }

    protected function routeUrlFor(AiChatBot $aiChatBot, string $action): string
    {
        $prefix = $aiChatBot->usesRootAccessPath() ? 'chat-bots.root.' : 'chat-bots.chat.';

        return route($prefix . $action, $aiChatBot);
    }
}
