<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SectionImage;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_admin_can_upload_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $file = UploadedFile::fake()->image('cover.png', 800, 600);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'alt_placeholder']);

        $this->assertDatabaseHas('section_images', [
            'section_id' => $section->id,
            'mime_type' => 'image/png',
        ]);

        $payload = $response->json();
        $path = ltrim(str_replace('/storage/', '', $payload['url']), '/');

        Storage::disk('public')->assertExists($path);
    }

    public function test_assigned_coach_can_upload_image(): void
    {
        Storage::fake('public');

        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => UploadedFile::fake()->image('coach.png', 800, 600),
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'alt_placeholder']);

        $this->assertDatabaseHas('section_images', [
            'section_id' => $section->id,
            'mime_type' => 'image/png',
        ]);
    }

    public function test_unassigned_coach_cannot_upload_image(): void
    {
        Storage::fake('public');

        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => UploadedFile::fake()->image('unauthorized.png'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('section_images', [
            'section_id' => $section->id,
        ]);
    }

    public function test_revoked_coach_cannot_upload_image(): void
    {
        Storage::fake('public');

        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();

        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');

        $this->assignCoach($coach, $cert);

        $cert->coaches()->updateExistingPivot($coach->id, [
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => UploadedFile::fake()->image('revoked.png'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('section_images', [
            'section_id' => $section->id,
        ]);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $file = UploadedFile::fake()->create('big.png', 3000, 'image/png');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertStatus(422);
    }

    public function test_rejects_invalid_mime(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(
            Certification::factory()->published()->create(),
            'draft',
        );

        $file = UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.images.store', $section), [
                'file' => $file,
            ])
            ->assertStatus(422);
    }
}
