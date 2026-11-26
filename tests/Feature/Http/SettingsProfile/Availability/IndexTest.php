<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Availability;

use App\Models\CoachAvailability;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 面談設定ページ (`settings.availability.index`) の描画と認可を検証する Feature テスト。
 * コーチは自分の面談可能時間枠ページを閲覧でき、他コーチの枠は混入しない。受講生 / 管理者は 403。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected(): void
    {
        // Arrange

        // Act
        $response = $this->get(route('settings.availability.index'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_coach_can_view_availability_page_with_own_slots(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $monday = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();
        $mondayEve = CoachAvailability::factory()->forCoach($coach)->monday()->evening()->create();
        $sunday = CoachAvailability::factory()->forCoach($coach)->sunday()->morning()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('settings.availability');

        $availabilities = $response->viewData('availabilities');
        $this->assertSame(3, $availabilities->count());
        $this->assertSame([
            $sunday->id,
            $monday->id,
            $mondayEve->id,
        ], $availabilities->pluck('id')->all());
    }

    public function test_coach_does_not_see_other_coaches_availabilities(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        CoachAvailability::factory()->forCoach($other)->monday()->create();

        // Act
        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        // Assert
        $response->assertOk();
        $this->assertSame(0, $response->viewData('availabilities')->count());
    }

    public function test_student_is_forbidden_on_availability_endpoint(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($student)->get(route('settings.availability.index'));

        // Assert
        $response->assertForbidden();
    }

    public function test_admin_is_forbidden_on_availability_endpoint(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->get(route('settings.availability.index'));

        // Assert
        $response->assertForbidden();
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_coach_sees_disconnected_google_calendar_status(): void
    {
        $coach = User::factory()->coach()->create();

        $this->mockGoogleCalendarWithoutApiCalls();

        $response = $this->actingAs($coach)
            ->get(route('settings.availability.index'));

        $response->assertOk();
        $response->assertViewIs('settings.availability');
        $response->assertSeeText('未連携');
        $response->assertSeeText('Googleカレンダーと連携する');
        $response->assertSee(route('settings.google-calendar.redirect'));
        $response->assertDontSeeText('連携を解除する');

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_coach_sees_connected_google_calendar_status(): void
    {
        $coach = User::factory()->coach()->create();

        $credential = $this->createGoogleCalendarCredential(
            $coach,
            'own-test-calendar',
        );

        $this->mockGoogleCalendarWithoutApiCalls();

        $response = $this->actingAs($coach)
            ->get(route('settings.availability.index'));

        $response->assertOk();
        $response->assertViewIs('settings.availability');
        $response->assertSeeText('連携中');
        $response->assertSeeText($credential->calendar_id);
        $response->assertSeeText('連携日時');
        $response->assertSeeText($credential->connected_at->format('Y-m-d H:i'));
        $response->assertSeeText('連携を解除する');
        $response->assertSee(route('settings.google-calendar.destroy'));
        $response->assertDontSeeText('Googleカレンダーと連携する');
        $response->assertDontSee('test-access-token');
        $response->assertDontSee('test-refresh-token');
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_coach_does_not_see_other_coaches_google_calendar_connection(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        $otherCredential = $this->createGoogleCalendarCredential(
            $otherCoach,
            'other-coach-test-calendar',
        );

        $this->mockGoogleCalendarWithoutApiCalls();

        $response = $this->actingAs($coach)
            ->get(route('settings.availability.index'));

        $response->assertOk();
        $response->assertSeeText('未連携');
        $response->assertSeeText('Googleカレンダーと連携する');
        $response->assertDontSeeText($otherCredential->calendar_id);
        $response->assertDontSeeText('連携を解除する');

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);

        $this->assertDatabaseHas('google_calendar_credentials', [
            'user_id' => $otherCoach->id,
            'calendar_id' => $otherCredential->calendar_id,
        ]);
    }

    /**
     * @group external-api
     * @group google-calendar
     */
    public function test_coach_sees_disconnected_status_after_removing_google_calendar_connection(): void
    {
        $coach = User::factory()->coach()->create();

        $credential = $this->createGoogleCalendarCredential(
            $coach,
            'disconnect-test-calendar',
        );

        $this->mockGoogleCalendarWithoutApiCalls();

        $this->actingAs($coach)
            ->get(route('settings.availability.index'))
            ->assertOk()
            ->assertSeeText('連携中')
            ->assertSeeText($credential->calendar_id)
            ->assertSeeText('連携を解除する');

        $this->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect(route('settings.availability.index'))
            ->assertSessionHas('success', 'Google Calendarの連携を解除しました。');

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);

        // 別リクエストでの画面表示を再現するため、認証ユーザーを再取得する
        $response = $this->actingAs($coach->fresh())
            ->get(route('settings.availability.index'));

        $response->assertOk();
        $response->assertSeeText('未連携');
        $response->assertSeeText('Googleカレンダーと連携する');
        $response->assertDontSeeText($credential->calendar_id);
        $response->assertDontSeeText('連携を解除する');
    }

    private function createGoogleCalendarCredential(
        User $coach,
        string $calendarId,
    ): GoogleCalendarCredential {
        return GoogleCalendarCredential::query()->create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => $calendarId,
            'connected_at' => now(),
        ]);
    }

    private function mockGoogleCalendarWithoutApiCalls(): void
    {
        $googleCalendar = Mockery::mock(GoogleCalendarService::class);

        $googleCalendar->shouldNotReceive('isBusy');
        $googleCalendar->shouldNotReceive('createMeetingEvent');
        $googleCalendar->shouldNotReceive('deleteMeetingEvent');

        $this->app->instance(GoogleCalendarService::class, $googleCalendar);
    }
}
