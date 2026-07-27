<?php

namespace Jvjvjv\CodeTalker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Jvjvjv\CodeTalker\Http\Requests\SendAiChatBotMessageRequest;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Services\AiChatBotConversationService;
use Jvjvjv\CodeTalker\Services\AiModelReadinessService;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotAccessGuard;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotIndexPayload;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotPagePayload;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotRouteUrls;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatBotStatusResolver;
use Jvjvjv\CodeTalker\Services\ChatBot\ChatStreamResponse;
use Jvjvjv\CodeTalker\Services\ChatBot\ConversationHistoryPresenter;
use Jvjvjv\CodeTalker\Services\ChatBot\ConversationSessionStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatBotController extends Controller
{
    public function __construct(
        private AiChatBotConversationService $conversationService,
        private AiModelReadinessService $modelReadinessService,
        private ChatBotAccessGuard $accessGuard,
        private ConversationSessionStore $sessions,
        private ConversationHistoryPresenter $history,
        private ChatBotPagePayload $pagePayload,
        private ChatBotIndexPayload $indexPayload,
        private ChatBotStatusResolver $statusResolver,
        private ChatBotRouteUrls $urls,
        private ChatStreamResponse $stream,
    ) {
    }

    /**
     * Display the list of available chat bots.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->sessions->forgetLegacyCookies($request);

        return Inertia::render($this->component('chat_bots_index', 'ai/ChatBotsIndex'), [
            'bots' => $this->indexPayload->build($request->user()),
        ]);
    }

    public function statuses(Request $request): JsonResponse
    {
        return response()->json(['statuses' => $this->statusResolver->statusesBySlug()]);
    }

    /**
     * Display the chat bot page.
     */
    public function show(Request $request, AiChatBot $aiChatBot): InertiaResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        $conversation = $this->sessions->currentConversation($request, $aiChatBot);

        return Inertia::render($this->component('chat_bot', 'ai/ChatBot'), $this->pagePayload->build(
            $aiChatBot,
            $conversation,
            $this->history->forBot($request, $aiChatBot),
            // Without a conversation there is nobody to attribute the chat to yet.
            showIdentityForm: !$request->user()
                && $aiChatBot->require_visitor_identity
                && $conversation === null,
        ));
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

        $this->accessGuard->authorize($request, $bot);

        // Adopt the linked conversation as the current one for this browser.
        $this->sessions->remember($request, $bot, $conversation);

        return Inertia::render($this->component('chat_bot', 'ai/ChatBot'), $this->pagePayload->build(
            $bot,
            $conversation,
            $this->history->forBot($request, $bot),
            // The conversation already exists here, so the identity form keys
            // off whether anything has actually been said in it.
            showIdentityForm: !$request->user()
                && $bot->require_visitor_identity
                && $conversation->messages()->where('role', '!=', 'system')->count() === 0,
            includeChatHash: true,
        ));
    }

    public function status(Request $request, AiChatBot $aiChatBot): JsonResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        return response()->json([
            'status' => $this->modelReadinessService->statusForChatBot($aiChatBot),
        ]);
    }

    public function warmup(Request $request, AiChatBot $aiChatBot): JsonResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        return response()->json([
            'status' => $this->modelReadinessService->warmUpChatBot($aiChatBot),
        ]);
    }

    /**
     * Stream a response from the configured chat bot.
     */
    public function message(SendAiChatBotMessageRequest $request, AiChatBot $aiChatBot): StreamedResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        $conversation = $this->sessions->currentConversation($request, $aiChatBot)
            ?? $this->startConversation($request, $aiChatBot);

        // Always regenerate the hash to ensure it uses the current encoding format.
        // generateChatHash() is deterministic (same inputs → same output), so this
        // is safe and also migrates any stale hashes stored by old encode versions.
        $chatHash = $conversation->generateChatHash();

        $message = $request->validated('message');

        return $this->stream->make(
            $chatHash,
            fn (): iterable => $this->conversationService->continueConversation($conversation, $message),
        );
    }

    public function switch(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        $validated = $request->validate([
            'conversation' => ['required', 'string'],
        ]);

        abort_unless($this->sessions->switchTo($request, $aiChatBot, $validated['conversation']), 404);

        return redirect($this->urls->for($aiChatBot, 'show'));
    }

    /**
     * Start a new chat while preserving prior conversation history for this browser.
     */
    public function reset(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        return $this->newChat($request, $aiChatBot);
    }

    /**
     * Start a new chat conversation (resets session and redirects to show).
     */
    public function newChat(Request $request, AiChatBot $aiChatBot): RedirectResponse
    {
        $this->accessGuard->authorize($request, $aiChatBot);

        $this->sessions->startNewChat($request, $aiChatBot);

        return redirect($this->urls->for($aiChatBot, 'show'));
    }

    /**
     * The Inertia component to render for one of the chat pages.
     *
     * The fallback is not decoration: Laravel skips a package's config merge
     * entirely when the host has cached its configuration, so a host that
     * published `code-talker.php` before this key existed has no `inertia`
     * entry at all — and would otherwise render a null component in production
     * only.
     */
    private function component(string $key, string $default): string
    {
        return (string) config("code-talker.inertia.components.{$key}", $default);
    }

    /**
     * A first message with no conversation in session opens one, collecting the
     * visitor's identity first when the bot asks for it.
     */
    private function startConversation(Request $request, AiChatBot $aiChatBot): AiConversation
    {
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

        $this->sessions->remember($request, $aiChatBot, $conversation);

        return $conversation;
    }
}
