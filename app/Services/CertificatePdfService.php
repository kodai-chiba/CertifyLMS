<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class CertificatePdfService
{
    /**
     * 修了証PDFを生成してプライベートストレージに保存する。
     *
     * @throws RuntimeException PDF生成・保存に失敗した場合
     */
    public function generate(Certificate $certificate): void
    {
        $fontPath = '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf';

        if (! is_file($fontPath)) {
            throw new RuntimeException('日本語フォントが見つかりません。');
        }

        $tempDir = storage_path('app/mpdf-temp');

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new RuntimeException('PDF一時保存ディレクトリを作成できません。');
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'fontDir' => [
                dirname($fontPath),
            ],
            'fontdata' => [
                'ipagothic' => [
                    'R' => basename($fontPath),
                ],
            ],
            'default_font' => 'ipagothic',
        ]);

        $html = view('certificates.pdf', [
            'certificate' => $certificate->loadMissing([
                'user',
                'certification',
            ]),
        ])->render();

        $mpdf->WriteHTML($html);

        $pdfContents = $mpdf->Output(
            '',
            Destination::STRING_RETURN
        );

        $saved = Storage::disk('private')->put(
            $certificate->pdf_path,
            $pdfContents
        );

        if (! $saved) {
            throw new RuntimeException('修了証PDFの保存に失敗しました。');
        }
    }
}
