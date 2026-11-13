<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_false_when_coach_has_no_google_calendar_connection(): void
    {
        $coach = User::factory()->coach()->create();

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $isBusy = app(GoogleCalendarService::class)->isBusy(
            $coach,
            $start,
            $end
        );

        $this->assertFalse($isBusy);
    }

    public function test_returns_false_when_google_calendar_configuration_is_missing(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', null);
        config()->set('services.google_calendar.client_secret', null);

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $isBusy = app(GoogleCalendarService::class)->isBusy(
            $coach,
            $start,
            $end
        );

        $this->assertFalse($isBusy);
    }

    public function test_returns_false_when_google_calendar_api_returns_server_error(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        $mockHandler = new MockHandler([
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                '{"error":{"code":500,"message":"Internal Server Error"}}'
            ),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        $httpClient = new HttpClient([
            'handler' => $handlerStack,
        ]);

        $service = new GoogleCalendarService($httpClient);

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $isBusy = $service->isBusy(
            $coach,
            $start,
            $end
        );

        $this->assertFalse($isBusy);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_true_when_google_calendar_has_busy_events(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        $responseBody = json_encode([
            'kind' => 'calendar#freeBusy',
            'timeMin' => '2026-11-09T09:00:00+09:00',
            'timeMax' => '2026-11-09T10:00:00+09:00',
            'calendars' => [
                'primary' => [
                    'busy' => [
                        [
                            'start' => '2026-11-09T09:00:00+09:00',
                            'end' => '2026-11-09T10:00:00+09:00',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockHandler = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                $responseBody
            ),
        ]);

        $httpClient = new HttpClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $service = new GoogleCalendarService($httpClient);

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $isBusy = $service->isBusy(
            $coach,
            $start,
            $end
        );

        $this->assertTrue($isBusy);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_false_when_google_calendar_has_no_busy_events(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        $responseBody = json_encode([
            'kind' => 'calendar#freeBusy',
            'timeMin' => '2026-11-09T09:00:00+09:00',
            'timeMax' => '2026-11-09T10:00:00+09:00',
            'calendars' => [
                'primary' => [
                    'busy' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockHandler = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                $responseBody
            ),
        ]);

        $httpClient = new HttpClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $service = new GoogleCalendarService($httpClient);

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $isBusy = $service->isBusy(
            $coach,
            $start,
            $end
        );

        $this->assertFalse($isBusy);
        $this->assertCount(0, $mockHandler);
    }

    public function test_creates_google_calendar_event_and_saves_event_id(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create([
                'scheduled_at' => Carbon::parse('2026-11-10 19:00:00', 'Asia/Tokyo'),
            ]);

        $expectedEventId = 'lms'.hash('sha256', (string) $meeting->getKey());

        $mockHandler = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'kind' => 'calendar#event',
                    'id' => $expectedEventId,
                    'summary' => 'Certify LMS 面談',
                ], JSON_THROW_ON_ERROR)
            ),
        ]);

        $httpClient = new HttpClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $service = new GoogleCalendarService($httpClient);
        $service->createMeetingEvent($meeting);

        $this->assertSame($expectedEventId, $meeting->fresh()->google_event_id);
        $this->assertCount(0, $mockHandler);
    }

    public function test_deletes_google_calendar_event_and_clears_event_id(): void
    {
        $coach = User::factory()->coach()->create();

        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        $mockHandler = new MockHandler([
            new Response(204),
        ]);

        $httpClient = new HttpClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $service = new GoogleCalendarService($httpClient);
        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $mockHandler);
    }

    public function test_does_not_save_event_id_when_creation_api_fails(): void
    {
        $coach = $this->connectedCoach();
        $meeting = Meeting::factory()->forCoach($coach)->reserved()->create();
        [$service, $handler] = $this->mockService(new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":500,"message":"Server error"}}'));

        $service->createMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
    }

    public function test_keeps_event_id_when_deletion_api_fails(): void
    {
        $coach = $this->connectedCoach();
        $meeting = Meeting::factory()->forCoach($coach)->canceled()->create(['google_event_id' => 'lmstestevent123']);
        [$service, $handler] = $this->mockService(new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":500,"message":"Server error"}}'));

        $service->deleteMeetingEvent($meeting);

        $this->assertSame('lmstestevent123', $meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
    }

    public function test_treats_conflict_as_success_when_creating_existing_event(): void
    {
        $coach = $this->connectedCoach();
        $meeting = Meeting::factory()->forCoach($coach)->reserved()->create();
        [$service, $handler] = $this->mockService(new Response(409, ['Content-Type' => 'application/json'], '{"error":{"code":409,"message":"Conflict"}}'));

        $service->createMeetingEvent($meeting);

        $this->assertSame('lms'.hash('sha256', (string) $meeting->getKey()), $meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
    }

    public function test_treats_missing_event_as_success_when_deleting(): void
    {
        $coach = $this->connectedCoach();
        $meeting = Meeting::factory()->forCoach($coach)->canceled()->create(['google_event_id' => 'lmstestevent123']);
        [$service, $handler] = $this->mockService(new Response(404, ['Content-Type' => 'application/json'], '{"error":{"code":404,"message":"Not Found"}}'));

        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
    }

    public function test_skips_event_creation_and_deletion_when_coach_is_not_connected(): void
    {
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->forCoach($coach)->reserved()->create();
        [$service, $handler] = $this->mockService(new Response(200));

        $service->createMeetingEvent($meeting);
        $this->assertNull($meeting->fresh()->google_event_id);

        $meeting->update(['google_event_id' => 'lmstestevent123']);
        $service->deleteMeetingEvent($meeting);

        $this->assertSame('lmstestevent123', $meeting->fresh()->google_event_id);
        $this->assertCount(1, $handler);
    }

    public function test_does_not_create_duplicate_event_when_event_id_is_already_saved(): void
    {
        $coach = $this->connectedCoach();
        $meeting = Meeting::factory()->forCoach($coach)->reserved()->create(['google_event_id' => 'lmstestevent123']);
        [$service, $handler] = $this->mockService(new Response(200));

        $service->createMeetingEvent($meeting);

        $this->assertSame('lmstestevent123', $meeting->fresh()->google_event_id);
        $this->assertCount(1, $handler);
    }

    private function connectedCoach(): User
    {
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);
        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');

        return $coach;
    }

    /** @return array{GoogleCalendarService, MockHandler} */
    private function mockService(Response $response): array
    {
        $handler = new MockHandler([$response]);
        $client = new HttpClient(['handler' => HandlerStack::create($handler)]);

        return [new GoogleCalendarService($client), $handler];
    }
}
