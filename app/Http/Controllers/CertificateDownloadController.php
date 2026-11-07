<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return $disk->download(
            $certificate->pdf_path,
            'certificate-'.$certificate->id.'.pdf',
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }
}
