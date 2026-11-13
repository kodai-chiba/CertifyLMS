<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 同一 Enrollment に対して修了証が二重発行されようとした際の例外（HTTP 409）。
 * Certificate\IssueAction 内で Enrollment のロック取得後に既存の修了証を検出した場合に throw される。
 * `enrollment_id` の UNIQUE 制約は DB 側でも二重発行を防止する。
 */
final class CertificateAlreadyIssuedException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('この受講登録の修了証はすでに発行されています。', $previous);
    }
}
