<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIチャットメッセージの処理状態。
 *
 * Geminiの回答待ち・取得成功・取得失敗を管理する。
 */
enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
