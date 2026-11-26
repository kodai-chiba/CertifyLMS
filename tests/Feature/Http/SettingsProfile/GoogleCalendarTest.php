<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Google\Service\Calendar;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group external-api
 * @group google-calendar
 */
class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_SESSION_KEY = 'google_calendar_oauth_state';

    private const REDIRECT_URI = 'http://localhost/settings/google-calendar/callback';

    private MockHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');
        config()->set('services.google_calendar.redirect_uri', self::REDIRECT_URI);

        $this->handler = new MockHandler;

        $this->app->instance(
            ClientInterface::class,
            new Client([
                'handler' => HandlerStack::create($this->handler),
                'http_errors' => false,
            ])
        );
    }

    public function test_coach_can_start_google_calendar_oauth_flow(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));

        $response->assertStatus(302);
        $response->assertSessionHas(self::STATE_SESSION_KEY);

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertSame('https', parse_url($location, PHP_URL_SCHEME));
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));

        parse_str(
            (string) parse_url($location, PHP_URL_QUERY),
            $query
        );

        $state = session(self::STATE_SESSION_KEY);

        $this->assertIsString($state);
        $this->assertSame(64, strlen($state));
        $this->assertSame($state, $query['state']);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('true', $query['include_granted_scopes']);

        $this->assertEqualsCanonicalizing(
            [
                Calendar::CALENDAR_READONLY,
                Calendar::CALENDAR_EVENTS,
            ],
            explode(' ', $query['scope'])
        );

        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_coach_can_connect_google_calendar_and_save_credentials(): void
    {
        $this->freezeTime();

        $coach = User::factory()->coach()->create();

        $this->handler->append(
            $this->jsonResponse(200, [
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ])
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'success',
            'Google Calendarを連携しました。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $credential = GoogleCalendarCredential::query()
            ->where('user_id', $coach->id)
            ->firstOrFail();

        $this->assertSame('test-access-token', $credential->access_token);
        $this->assertSame('test-refresh-token', $credential->refresh_token);
        $this->assertSame('primary', $credential->calendar_id);

        $this->assertSame(
            now()->addHour()->getTimestamp(),
            $credential->expires_at->getTimestamp()
        );

        $this->assertSame(
            now()->getTimestamp(),
            $credential->connected_at->getTimestamp()
        );

        $this->assertDatabaseCount('google_calendar_credentials', 1);
        $this->assertCount(0, $this->handler);

        $request = $this->handler->getLastRequest();

        $this->assertNotNull($request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/token', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $body);

        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('test-authorization-code', $body['code']);
        $this->assertSame('test-client-id', $body['client_id']);
        $this->assertSame('test-client-secret', $body['client_secret']);
        $this->assertSame(self::REDIRECT_URI, $body['redirect_uri']);
    }

    public function test_reconnection_preserves_existing_refresh_token_and_calendar_id(): void
    {
        $coach = User::factory()->coach()->create();

        $credential = $this->createCredential($coach);

        $this->handler->append(
            $this->jsonResponse(200, [
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ])
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas('success');
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $credential->refresh();

        $this->assertSame('new-access-token', $credential->access_token);
        $this->assertSame('existing-refresh-token', $credential->refresh_token);
        $this->assertSame('existing-calendar', $credential->calendar_id);

        $this->assertDatabaseCount('google_calendar_credentials', 1);
        $this->assertCount(0, $this->handler);
    }

    public function test_callback_rejects_mismatched_state_without_api_request(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'expected-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'invalid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google連携の認証を確認できませんでした。もう一度お試しください。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_callback_rejects_missing_session_state_without_api_request(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google連携の認証を確認できませんでした。もう一度お試しください。'
        );

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_callback_rejects_missing_query_state_without_api_request(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google連携の認証を確認できませんでした。もう一度お試しください。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_callback_handles_authorization_cancellation(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'error' => 'access_denied',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの連携がキャンセルされたか、認証に失敗しました。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_callback_rejects_missing_authorization_code(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの連携がキャンセルされたか、認証に失敗しました。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_callback_handles_token_api_error(): void
    {
        $coach = User::factory()->coach()->create();

        $this->handler->append(
            $this->jsonResponse(400, [
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code is invalid.',
            ])
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'invalid-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの認証情報を取得できませんでした。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertCount(0, $this->handler);
    }

    public function test_callback_handles_missing_access_token(): void
    {
        $coach = User::factory()->coach()->create();

        $this->handler->append(
            $this->jsonResponse(200, [
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
            ])
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの認証情報を取得できませんでした。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertCount(0, $this->handler);
    }

    public function test_callback_rejects_missing_refresh_token_on_initial_connection(): void
    {
        $coach = User::factory()->coach()->create();

        $this->handler->append(
            $this->jsonResponse(200, [
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ])
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの継続的な連携に必要な認証情報を取得できませんでした。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertCount(0, $this->handler);
    }

    public function test_callback_handles_connection_exception(): void
    {
        $coach = User::factory()->coach()->create();

        $this->handler->append(
            new ConnectException(
                'Connection failed.',
                new Request('POST', 'https://oauth2.googleapis.com/token')
            )
        );

        $response = $this->actingAs($coach)
            ->withSession([
                self::STATE_SESSION_KEY => 'valid-state',
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => 'valid-state',
                'code' => 'test-authorization-code',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'error',
            'Google Calendarの連携に失敗しました。時間をおいて再度お試しください。'
        );
        $response->assertSessionMissing(self::STATE_SESSION_KEY);

        $this->assertDatabaseCount('google_calendar_credentials', 0);
        $this->assertCount(0, $this->handler);
    }

    public function test_coach_can_disconnect_google_calendar(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        $credential = $this->createCredential($coach);
        $otherCredential = $this->createCredential($otherCoach);

        $response = $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas(
            'success',
            'Google Calendarの連携を解除しました。'
        );

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseHas('google_calendar_credentials', [
            'id' => $otherCredential->id,
        ]);

        $this->assertDatabaseCount('google_calendar_credentials', 1);
        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_guest_cannot_access_google_calendar_oauth_routes(): void
    {
        $this->get(route('settings.google-calendar.redirect'))
            ->assertRedirect(route('login'));

        $this->get(route('settings.google-calendar.callback'))
            ->assertRedirect(route('login'));

        $this->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect(route('login'));

        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_student_cannot_access_google_calendar_oauth_routes(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('settings.google-calendar.redirect'))
            ->assertForbidden();

        $this->get(route('settings.google-calendar.callback'))
            ->assertForbidden();

        $this->delete(route('settings.google-calendar.destroy'))
            ->assertForbidden();

        $this->assertNull($this->handler->getLastRequest());
    }

    public function test_admin_cannot_access_google_calendar_oauth_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.google-calendar.redirect'))
            ->assertForbidden();

        $this->get(route('settings.google-calendar.callback'))
            ->assertForbidden();

        $this->delete(route('settings.google-calendar.destroy'))
            ->assertForbidden();

        $this->assertNull($this->handler->getLastRequest());
    }

    private function createCredential(User $coach): GoogleCalendarCredential
    {
        return GoogleCalendarCredential::query()->create([
            'user_id' => $coach->id,
            'access_token' => 'existing-access-token',
            'refresh_token' => 'existing-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'existing-calendar',
            'connected_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }
}
