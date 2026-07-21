<?php

namespace Jvjvjv\CodeTalker\Support;

use Composer\InstalledVersions;
use Jvjvjv\CodeTalker\Models\AiConversation;

class WebScraperUserAgent
{
    private const BROWSER_PREFIX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';

    private const PACKAGE_NAME = 'jvjvjv/code-talker';

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

        return sprintf(
            '%s CodeTalker/%s (+https://jasonvertucio.com; name=%s; purpose=%s)',
            self::BROWSER_PREFIX,
            self::packageVersion(),
            $sanitizedName,
            $sanitizedPurpose,
        );
    }

    private static function packageVersion(): string {
        if (!class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'dev';
    }
}