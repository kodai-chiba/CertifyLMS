<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class IssueAction
{
    public function __construct(
        private readonly CertificatePdfService $pdfService,
    ) {}

    /**
     * @throws EnrollmentNotPassedException
     * @throws CertificateAlreadyIssuedException
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        $pdfPath = 'certificates/'.Str::ulid().'.pdf';

        try {
            return DB::transaction(function () use (
                $enrollment,
                $pdfPath
            ): Certificate {
                $lockedEnrollment = Enrollment::query()
                    ->whereKey($enrollment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedEnrollment->status !== EnrollmentStatus::Passed
                    || $lockedEnrollment->passed_at === null
                ) {
                    throw new EnrollmentNotPassedException;
                }

                $existing = Certificate::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->first();

                if ($existing !== null) {
                    throw new CertificateAlreadyIssuedException;
                }

                $certificate = Certificate::create([
                    'user_id' => $lockedEnrollment->user_id,
                    'enrollment_id' => $lockedEnrollment->id,
                    'certification_id' => $lockedEnrollment->certification_id,
                    'pdf_path' => $pdfPath,
                    'issued_at' => now(),
                ]);

                $this->pdfService->generate($certificate);

                return $certificate;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($pdfPath);

            throw $exception;
        }
    }
}
