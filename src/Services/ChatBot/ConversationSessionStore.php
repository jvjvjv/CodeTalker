<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Jvjvjv\CodeTalker\Models\AiChatBot;
use Jvjvjv\CodeTalker\Models\AiConversation;

/**
 * Remembers which conversation a browser is currently in, per chat bot.
 *
 * State lives in the server-side session. Only the current conversation id is
 * mirrored into a single cookie, so the request header stays small no matter
 * how many bots a visitor has talked to — the failure mode that retired the
 * former per-bot cookies.
 */
class ConversationSessionStore
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

    /**
     * The conversation this browser is currently in, if it still exists and
     * still belongs to whoever is asking.
     */
    public function currentConversation(Request $request, AiChatBot $aiChatBot): ?AiConversation
    {
        $conversationPublicId = data_get($this->state($request, $aiChatBot), 'current');

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
     * @return array{current: ?string, history: array<int, array{handle: string, public_id: string}>}
     */
    public function state(Request $request, AiChatBot $aiChatBot): array
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
    public function put(Request $request, AiChatBot $aiChatBot, array $state): void
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
     * Make a conversation the current one, adding it to this bot's switcher
     * history if it is not already there.
     */
    public function remember(Request $request, AiChatBot $aiChatBot, AiConversation $conversation): void
    {
        $history = collect($this->state($request, $aiChatBot)['history']);

        if (!$history->contains(fn (array $item) => $item['public_id'] === $conversation->public_id)) {
            $history->prepend([
                'handle' => (string) Str::ulid(),
                'public_id' => $conversation->public_id,
            ]);
        }

        $this->put($request, $aiChatBot, [
            'current' => $conversation->public_id,
            'history' => $history->values()->all(),
        ]);
    }

    /**
     * Leave the current conversation while keeping the switcher history, so the
     * next message starts a fresh chat.
     */
    public function startNewChat(Request $request, AiChatBot $aiChatBot): void
    {
        $state = $this->state($request, $aiChatBot);
        $state['current'] = null;

        $this->put($request, $aiChatBot, $state);
    }

    /**
     * Switch to a previously seen conversation by its opaque switcher handle.
     *
     * @return bool false when the handle is not in this bot's history
     */
    public function switchTo(Request $request, AiChatBot $aiChatBot, string $handle): bool
    {
        $state = $this->state($request, $aiChatBot);
        $match = collect($state['history'])->firstWhere('handle', $handle);

        if ($match === null) {
            return false;
        }

        $state['current'] = $match['public_id'];
        $this->put($request, $aiChatBot, $state);

        return true;
    }

    /**
     * Forget the legacy per-bot conversation cookies. These grew unbounded and, with
     * one per bot at path `/`, bloated the request header until the server rejected it.
     */
    public function forgetLegacyCookies(Request $request): void
    {
        foreach (array_keys($request->cookies->all()) as $name) {
            if (is_string($name) && preg_match(self::LEGACY_COOKIE_PATTERN, $name) === 1) {
                Cookie::queue(Cookie::forget($name));
            }
        }
    }

    private function stateKey(AiChatBot $aiChatBot): string
    {
        return 'ai_chat_bot_conversations_' . $aiChatBot->id;
    }
}
