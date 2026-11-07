<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certificate;

use App\Models\Certificate;
use App\Services\CertificatePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificatePdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_pdf_and_saves_it_to_private_storage(): void
    {
        Storage::fake('private');

        $certificate = Certificate::factory()->create();

        app(CertificatePdfService::class)->generate($certificate);

        Storage::disk('private')->assertExists($certificate->pdf_path);

        $pdfContents = Storage::disk('private')->get($certificate->pdf_path);

        $this->assertStringStartsWith('%PDF-', $pdfContents);
    }

    public function test_generated_pdf_contains_expected_pdf_structure(): void
    {
        Storage::fake('private');

        $certificate = Certificate::factory()->create();

        app(CertificatePdfService::class)->generate($certificate);

        $pdfContents = Storage::disk('private')->get($certificate->pdf_path);

        $this->assertStringContainsString('%%EOF', $pdfContents);
        $this->assertGreaterThan(1000, strlen($pdfContents));
    }
}
