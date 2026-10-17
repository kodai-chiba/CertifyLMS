<?php

declare(strict_types=1);

namespace App\Exceptions\QaThread;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 回答が存在する質問を削除しようとした場合に発火する例外(HTTP 409)。
 */
final class QaThreadHasRepliesException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            '回答が存在する質問は削除できません。',
            $previous,
        );
    }
}
