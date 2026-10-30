<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * プロフィール設定画面の表示・更新・認可を検証する Feature テスト。
 *
 * Student / Coach / Admin は自身のプロフィールを操作できる。
 * 修了済みStudentも利用可能で、他ユーザーの情報は変更できない。
 * 固定面談URLはCoachのみ更新可能。
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Arrange

        // Act
        $response = $this->get(route('settings.profile.show'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_student_can_view_own_profile(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->get(route('settings.profile.show'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('settings.profile');
        $this->assertTrue($response->viewData('user')->is($student));
    }

    public function test_coach_can_view_own_profile(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->get(route('settings.profile.show'));

        // Assert
        $response->assertOk();
        $this->assertTrue($response->viewData('user')->is($coach));
    }

    public function test_admin_can_view_own_profile(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('settings.profile.show'));

        // Assert
        $response->assertOk();
        $this->assertTrue($response->viewData('user')->is($admin));
    }

    public function test_graduated_student_can_view_profile(): void
    {
        // Arrange
        $student = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($student)
            ->get(route('settings.profile.show'));

        // Assert
        $response->assertOk();
    }

    public function test_student_can_update_name_and_bio(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '変更後の氏名',
                'bio' => '変更後の自己紹介',
                'email' => $student->email,
            ]);

        // Assert
        $response->assertRedirect(route('settings.profile.show'));
        $response->assertSessionHas('success');

        $student->refresh();

        $this->assertSame('変更後の氏名', $student->name);
        $this->assertSame('変更後の自己紹介', $student->bio);
    }

    public function test_coach_can_update_meeting_url(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'bio' => 'コーチの自己紹介',
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
                'email' => $coach->email,
            ]);

        // Assert
        $response->assertRedirect(route('settings.profile.show'));
        $response->assertSessionHasNoErrors();

        $this->assertSame(
            'https://meet.google.com/abc-defg-hij',
            $coach->fresh()->meeting_url
        );
    }

    public function test_student_cannot_update_meeting_url(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => $student->name,
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            ]);

        // Assert
        $response->assertSessionHasErrors('meeting_url');
        $this->assertNull($student->fresh()->meeting_url);
    }

    public function test_profile_update_does_not_change_email(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $originalEmail = $student->email;

        // Act
        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '変更後の氏名',
                'email' => 'changed@example.test',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertSame($originalEmail, $student->fresh()->email);
    }

    public function test_profile_update_rejects_role_and_status_changes(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => $student->name,
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Graduated->value,
            ]);

        // Assert
        $response->assertSessionHasErrors(['role', 'status']);

        $student->refresh();

        $this->assertSame(UserRole::Student, $student->role);
        $this->assertSame(UserStatus::InProgress, $student->status);
    }

    public function test_profile_update_does_not_modify_other_user(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create([
            'name' => '別の受講生',
        ]);

        // Act
        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '更新した氏名',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertSame('更新した氏名', $student->fresh()->name);
        $this->assertSame('別の受講生', $other->fresh()->name);
    }
}
