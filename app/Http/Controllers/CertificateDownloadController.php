<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CertificateDownloadController extends Controller
{
    /**
     * 修了証PDFをダウンロードする。
     */
    public function __invoke(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        $disk = Storage::disk('private');

        abort_unless(
            $disk->exists($certificate->pdf_path),
            Response::HTTP_NOT_FOUND
        );

        try {
            // レスポンス送信前にPDF全体を読み込む。
            $pdfContents = $disk->get($certificate->pdf_path);

            if (! is_string($pdfContents)) {
                throw new RuntimeException('修了証PDFの読み込みに失敗しました。');
            }
        } catch (Throwable $exception) {
            Log::error('修了証PDFのダウンロードに失敗しました。', [
                'certificate_id' => $certificate->id,
                'exception' => $exception->getMessage(),
            ]);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->streamDownload(
            static function () use ($pdfContents): void {
                echo $pdfContents;
            },
            'certificate-'.$certificate->id.'.pdf',
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }
}
