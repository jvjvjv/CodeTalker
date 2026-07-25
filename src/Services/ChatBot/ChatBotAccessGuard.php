<?php

namespace Jvjvjv\CodeTalker\Services\ChatBot;

use Illuminate\Http\Request;
use Jvjvjv\CodeTalker\Models\AiChatBot;

/**
 * A bot is reachable only when it is active and is being asked for at the
 * access path it was configured for — a `/chat/{slug}` bot does not answer at
 * the site root, and vice versa.
 */
class ChatBotAccessGuard
{
    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function authorize(Request $request, AiChatBot $aiChatBot): void
    {
        abort_unless($aiChatBot->is_active, 404);
        abort_unless($aiChatBot->access_path === $this->requestAccessPath($request), 404);
    }

    public function requestAccessPath(Request $request): string
    {
        return $request->routeIs('chat-bots.root.*')
            ? AiChatBot::ACCESS_PATH_ROOT
            : AiChatBot::ACCESS_PATH_CHAT;
    }
}
