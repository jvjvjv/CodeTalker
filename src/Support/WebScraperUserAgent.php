<?php

namespace Jvjvjv\CodeTalker\Support;

use Jvjvjv\CodeTalker\Models\AiConversation;

class WebScraperUserAgent
{
    private const TEMPLATE = 'JayScraper/0.2.0 (name: %s; purpose: %s; contact: https://jasonvertucio.com)';

    public static function forConversation(AiConversation $conversation, string $purpose = 'research'): string
    {
        return self::forBotName($conversation->aiChatBot?->name, $purpose);
    }

    public static function forBotName(?string $botName, string $purpose = 'research'): string
    {
        $sanitizedName = trim((string) preg_replace('/[()]+/', '', (string) $botName));
        $sanitizedPurpose = trim((string) preg_replace('/[()]+/', '', $purpose));

        if ($sanitizedName === '') {
            $sanitizedName = 'ChatBot';
        }

        if ($sanitizedPurpose === '') {
            $sanitizedPurpose = 'research';
        }

        return sprintf(self::TEMPLATE, $sanitizedName, $sanitizedPurpose);
    }
}