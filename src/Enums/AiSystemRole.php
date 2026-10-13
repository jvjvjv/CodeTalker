<?php

namespace Jvjvjv\CodeTalker\Enums;

/**
 * What an AiSystem is for. A chat system and a voice system are separate
 * records: the driver behind `lm-studio` and `openai-compatible` implements
 * neither audio nor transcription, so a local-chat deployment always needs a
 * different provider for voice.
 */
enum AiSystemRole: string
{
    case Chat = 'chat';
    case Audio = 'audio';
    case Transcription = 'transcription';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
