<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIチャットメッセージの送信者。
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
