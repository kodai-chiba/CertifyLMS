<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * アバター画像の登録・差し替え・削除を検証する Feature テスト。
 *
 * Student / Coach / Admin が自身のアバターを管理できること、
 * PNG / JPEG / WebP 形式と2MBの容量制限、
 * 画像ファイルの保存・削除を保証する。
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_upload_avatar(): void
    {
        // Arrange
        Storage::fake('public');

        // Act
        $response = $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        // Assert
        $response->assertRedirect(route('login'));
        Storage::disk('public')->assertDirectoryEmpty('avatars');
    }

    public function test_student_can_upload_avatar(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        // Assert
        $response->assertRedirect(route('settings.profile.show'));
        $response->assertSessionHasNoErrors();

        $avatarUrl = $student->fresh()->avatar_url;

        $this->assertNotNull($avatarUrl);
        $this->assertStringStartsWith('/storage/avatars/', $avatarUrl);

        Storage::disk('public')->assertExists(
            substr($avatarUrl, strlen('/storage/'))
        );
    }

    public function test_coach_can_upload_avatar(): void
    {
        // Arrange
        Storage::fake('public');
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('coach.png'),
            ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($coach->fresh()->avatar_url);
    }

    public function test_admin_can_upload_avatar(): void
    {
        // Arrange
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('admin.jpg'),
            ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($admin->fresh()->avatar_url);
    }

    public function test_graduated_student_can_upload_avatar(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($student->fresh()->avatar_url);
    }

    public function test_avatar_can_be_replaced_and_old_file_is_deleted(): void
    {
        // Arrange
        Storage::fake('public');

        $student = User::factory()->student()->create();

        Storage::disk('public')->put('avatars/old.jpg', 'old image');

        $student->update([
            'avatar_url' => '/storage/avatars/old.jpg',
        ]);

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('new.jpg'),
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $newUrl = $student->fresh()->avatar_url;

        $this->assertNotNull($newUrl);
        $this->assertNotSame('/storage/avatars/old.jpg', $newUrl);

        Storage::disk('public')->assertMissing('avatars/old.jpg');
        Storage::disk('public')->assertExists(
            substr($newUrl, strlen('/storage/'))
        );
    }

    public function test_avatar_can_be_deleted(): void
    {
        // Arrange
        Storage::fake('public');

        $student = User::factory()->student()->create();

        Storage::disk('public')->put('avatars/avatar.jpg', 'image');

        $student->update([
            'avatar_url' => '/storage/avatars/avatar.jpg',
        ]);

        // Act
        $response = $this->actingAs($student)
            ->delete(route('settings.avatar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.profile.show'));
        $response->assertSessionHas('success');

        $this->assertNull($student->fresh()->avatar_url);

        Storage::disk('public')->assertMissing('avatars/avatar.jpg');
    }

    public function test_deleting_avatar_does_not_affect_other_user(): void
    {
        // Arrange
        Storage::fake('public');

        $student = User::factory()->student()->create([
            'avatar_url' => '/storage/avatars/student.jpg',
        ]);

        $other = User::factory()->student()->create([
            'avatar_url' => '/storage/avatars/other.jpg',
        ]);

        Storage::disk('public')->put('avatars/student.jpg', 'student image');
        Storage::disk('public')->put('avatars/other.jpg', 'other image');

        // Act
        $response = $this->actingAs($student)
            ->delete(route('settings.avatar.destroy'));

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->avatar_url);
        $this->assertSame(
            '/storage/avatars/other.jpg',
            $other->fresh()->avatar_url
        );

        Storage::disk('public')->assertMissing('avatars/student.jpg');
        Storage::disk('public')->assertExists('avatars/other.jpg');
    }

    public function test_avatar_upload_requires_image(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), []);

        // Assert
        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_avatar_upload_rejects_invalid_file_type(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create(
                    'document.pdf',
                    100,
                    'application/pdf'
                ),
            ]);

        // Assert
        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_avatar_upload_rejects_file_larger_than_2mb(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('large.jpg')->size(2049),
            ]);

        // Assert
        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_deleting_avatar_without_existing_image_is_safe(): void
    {
        // Arrange
        Storage::fake('public');
        $student = User::factory()->student()->create([
            'avatar_url' => null,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->delete(route('settings.avatar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.profile.show'));
        $this->assertNull($student->fresh()->avatar_url);
    }
}
