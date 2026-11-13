<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CertificateDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_student_can_download_own_certificate(): void
    {
        $student = User::factory()->student()->create();
        $certificate = Certificate::factory()
            ->for($student)
            ->create();

        Storage::disk('private')->put(
            $certificate->pdf_path,
            '%PDF-1.4 test certificate %%EOF'
        );

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload('certificate-'.$certificate->id.'.pdf');
    }

    public function test_student_cannot_download_another_students_certificate(): void
    {
        $student = User::factory()->student()->create();
        $anotherStudent = User::factory()->student()->create();

        $certificate = Certificate::factory()
            ->for($anotherStudent)
            ->create();

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $certificate = Certificate::factory()
            ->for($certification)
            ->create();

        Storage::disk('private')->put(
            $certificate->pdf_path,
            '%PDF-1.4 test certificate %%EOF'
        );

        $response = $this->actingAs($coach)
            ->get(route('certificates.download', $certificate));

        $response->assertOk();
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();

        $certificate = Certificate::factory()->create();

        $response = $this->actingAs($coach)
            ->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = Certificate::factory()->create();

        Storage::disk('private')->put(
            $certificate->pdf_path,
            '%PDF-1.4 test certificate %%EOF'
        );

        $response = $this->actingAs($admin)
            ->get(route('certificates.download', $certificate));

        $response->assertOk();
    }

    public function test_guest_cannot_download_certificate(): void
    {
        $certificate = Certificate::factory()->create();

        $response = $this->get(
            route('certificates.download', $certificate)
        );

        $response->assertRedirect();
    }

    public function test_missing_pdf_returns_not_found(): void
    {
        $student = User::factory()->student()->create();

        $certificate = Certificate::factory()
            ->for($student)
            ->create();

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertNotFound();
    }

    public function test_download_failure_returns_server_error(): void
    {
        $student = User::factory()->student()->create();

        $certificate = Certificate::factory()
            ->for($student)
            ->create();

        $disk = \Mockery::mock(FilesystemAdapter::class);

        $disk->shouldReceive('exists')
            ->once()
            ->with($certificate->pdf_path)
            ->andReturnTrue();

        $disk->shouldReceive('get')
            ->once()
            ->with($certificate->pdf_path)
            ->andThrow(new RuntimeException('PDF読み込み失敗'));

        Storage::shouldReceive('disk')
            ->once()
            ->with('private')
            ->andReturn($disk);

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertInternalServerError();
    }
}
