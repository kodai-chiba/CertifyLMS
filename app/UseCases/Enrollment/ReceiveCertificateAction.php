<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\CompletionNotEligibleException;
use App\Exceptions\Enrollment\EnrollmentNotLearningException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CompletionEligibilityService;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Certificate\IssueAction as IssueCertificateAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReceiveCertificateAction
{
    public function __construct(
        private readonly CompletionEligibilityService $eligibility,
        private readonly EnrollmentStatusChangeService $statusChanger,
        private readonly IssueCertificateAction $issueCertificate,
    ) {}

    /**
     * @throws CompletionNotEligibleException
     * @throws EnrollmentNotLearningException
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        $certificate = null;

        try {
            return DB::transaction(function () use (
                $enrollment,
                &$certificate
            ): Certificate {
                $lockedEnrollment = Enrollment::query()
                    ->whereKey($enrollment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedEnrollment->status !== EnrollmentStatus::Learning) {
                    throw new EnrollmentNotLearningException;
                }

                if (! $this->eligibility->isEligible($lockedEnrollment)) {
                    throw new CompletionNotEligibleException;
                }

                $lockedEnrollment->update([
                    'status' => EnrollmentStatus::Passed->value,
                    'passed_at' => now(),
                ]);

                $this->statusChanger->recordStatusChange(
                    $lockedEnrollment,
                    fromStatus: EnrollmentStatus::Learning,
                    toStatus: EnrollmentStatus::Passed,
                    changedBy: $lockedEnrollment->user,
                    reason: '受講生による修了証受領',
                );

                $certificate = ($this->issueCertificate)(
                    $lockedEnrollment->refresh()
                );

                return $certificate;
            });
        } catch (Throwable $exception) {
            if ($certificate !== null) {
                Storage::disk('private')->delete($certificate->pdf_path);
            }

            throw $exception;
        }
    }
}
