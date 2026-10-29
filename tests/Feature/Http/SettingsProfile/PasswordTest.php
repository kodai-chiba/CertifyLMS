<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * パスワード変更機能の認証・更新・バリデーションを検証する Feature テスト。
 *
 * Student / Coach / Admin と修了済みStudentが自身のパスワードを変更できること、
 * 現在のパスワード検証、新しいパスワードの検証、
 * エラー時のパスワードタブへの遷移を保証する。
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_update_password(): void
    {
        // Arrange

        // Act
        $response = $this->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_student_can_update_password(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        // Assert
        $response->assertRedirect(
            route('settings.profile.show', ['tab' => 'password'])
        );

        $response->assertSessionHas(
            'success',
            'パスワードを更新しました。'
        );

        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('new-password123', $student->fresh()->password)
        );

        $this->assertFalse(
            Hash::check('password', $student->fresh()->password)
        );
    }

    public function test_coach_can_update_password(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'coach-new-password123',
                'password_confirmation' => 'coach-new-password123',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('coach-new-password123', $coach->fresh()->password)
        );
    }

    public function test_admin_can_update_password(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'admin-new-password123',
                'password_confirmation' => 'admin-new-password123',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('admin-new-password123', $admin->fresh()->password)
        );
    }

    public function test_graduated_student_can_update_password(): void
    {
        // Arrange
        $student = User::factory()->student()->graduated()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'graduated-new-password123',
                'password_confirmation' => 'graduated-new-password123',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('graduated-new-password123', $student->fresh()->password)
        );
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        // Assert
        $response->assertRedirect(
            route('settings.profile.show', ['tab' => 'password'])
        );

        $response->assertSessionHasErrors(
            ['current_password'],
            null,
            'updatePassword'
        );

        $this->assertTrue(
            Hash::check('password', $student->fresh()->password)
        );
    }

    public function test_password_shorter_than_eight_characters_is_rejected(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        // Assert
        $response->assertRedirect(
            route('settings.profile.show', ['tab' => 'password'])
        );

        $response->assertSessionHasErrors(
            ['password'],
            null,
            'updatePassword'
        );

        $this->assertTrue(
            Hash::check('password', $student->fresh()->password)
        );
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password123',
                'password_confirmation' => 'different-password123',
            ]);

        // Assert
        $response->assertRedirect(
            route('settings.profile.show', ['tab' => 'password'])
        );

        $response->assertSessionHasErrors(
            ['password'],
            null,
            'updatePassword'
        );

        $this->assertTrue(
            Hash::check('password', $student->fresh()->password)
        );
    }

    public function test_password_update_does_not_modify_other_user(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        // Assert
        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('new-password123', $student->fresh()->password)
        );

        $this->assertTrue(
            Hash::check('password', $other->fresh()->password)
        );
    }

    public function test_validation_errors_do_not_flash_password_values(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        // Assert
        $response->assertSessionHasErrors(
            ['current_password'],
            null,
            'updatePassword'
        );

        $response->assertSessionMissing('_old_input.current_password');
        $response->assertSessionMissing('_old_input.password');
        $response->assertSessionMissing('_old_input.password_confirmation');
    }
}
