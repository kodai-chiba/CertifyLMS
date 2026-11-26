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
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * @group external-api
 * @group google-calendar
 */
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    public function test_returns_false_when_coach_has_no_google_calendar_connection(): void
    {
        $coach = User::factory()->coach()->create();

        [$service, $handler] = $this->mockService();

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_returns_false_when_google_calendar_configuration_is_missing(): void
    {
        $coach = $this->connectedCoach();

        config()->set('services.google_calendar.client_id', null);
        config()->set('services.google_calendar.client_secret', null);

        [$service, $handler] = $this->mockService();

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_returns_false_when_google_calendar_api_returns_server_error(): void
    {
        $coach = $this->connectedCoach();

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(500, [
                'error' => [
                    'code' => 500,
                    'message' => 'Internal Server Error',
                ],
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_returns_true_when_google_calendar_has_busy_events(): void
    {
        $coach = $this->connectedCoach();

        [$service, $handler] = $this->mockService(
            $this->freeBusyResponse([
                [
                    'start' => '2026-11-09T09:00:00+09:00',
                    'end' => '2026-11-09T10:00:00+09:00',
                ],
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertTrue($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);

        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/freeBusy', $request->getUri()->getPath());
        $this->assertSame(
            'Bearer test-access-token',
            $request->getHeaderLine('Authorization')
        );

        $body = json_decode(
            (string) $request->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($start->toRfc3339String(), $body['timeMin']);
        $this->assertSame($end->toRfc3339String(), $body['timeMax']);
        $this->assertSame([['id' => 'primary']], $body['items']);
    }

    public function test_returns_false_when_google_calendar_has_no_busy_events(): void
    {
        $coach = $this->connectedCoach();

        [$service, $handler] = $this->mockService(
            $this->freeBusyResponse([])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_creates_google_calendar_event_and_saves_event_id(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create([
                'scheduled_at' => Carbon::parse(
                    '2026-11-10 19:00:00',
                    'Asia/Tokyo'
                ),
            ]);

        $expectedEventId = 'lms'.hash('sha256', (string) $meeting->getKey());

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(200, [
                'kind' => 'calendar#event',
                'id' => $expectedEventId,
                'summary' => 'Certify LMS 面談',
            ])
        );

        $service->createMeetingEvent($meeting);

        $this->assertSame(
            $expectedEventId,
            $meeting->fresh()->google_event_id
        );

        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);

        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith(
            '/calendars/primary/events',
            $request->getUri()->getPath()
        );

        $body = json_decode(
            (string) $request->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($expectedEventId, $body['id']);
        $this->assertSame('Certify LMS 面談', $body['summary']);
        $this->assertSame(
            'Certify LMSで予約された面談です。',
            $body['description']
        );
        $this->assertSame(
            $meeting->scheduled_at->toRfc3339String(),
            $body['start']['dateTime']
        );
        $this->assertSame(
            $meeting->scheduled_at->copy()->addHour()->toRfc3339String(),
            $body['end']['dateTime']
        );
    }

    public function test_deletes_google_calendar_event_and_clears_event_id(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        [$service, $handler] = $this->mockService(new Response(204));

        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);

        $request = $this->history[0]['request'];

        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith(
            '/calendars/primary/events/lmstestevent123',
            $request->getUri()->getPath()
        );
    }

    public function test_does_not_save_event_id_when_creation_api_fails(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create();

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(500, [
                'error' => [
                    'code' => 500,
                    'message' => 'Server error',
                ],
            ])
        );

        $service->createMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_keeps_event_id_when_deletion_api_fails(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(500, [
                'error' => [
                    'code' => 500,
                    'message' => 'Server error',
                ],
            ])
        );

        $service->deleteMeetingEvent($meeting);

        $this->assertSame(
            'lmstestevent123',
            $meeting->fresh()->google_event_id
        );

        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_treats_conflict_as_success_when_creating_existing_event(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create();

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(409, [
                'error' => [
                    'code' => 409,
                    'message' => 'Conflict',
                ],
            ])
        );

        $service->createMeetingEvent($meeting);

        $this->assertSame(
            'lms'.hash('sha256', (string) $meeting->getKey()),
            $meeting->fresh()->google_event_id
        );

        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_treats_missing_event_as_success_when_deleting(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(404, [
                'error' => [
                    'code' => 404,
                    'message' => 'Not Found',
                ],
            ])
        );

        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_treats_gone_event_as_success_when_deleting(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(410, [
                'error' => [
                    'code' => 410,
                    'message' => 'Gone',
                ],
            ])
        );

        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_skips_event_creation_and_deletion_when_coach_is_not_connected(): void
    {
        $coach = User::factory()->coach()->create();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create();

        [$service, $handler] = $this->mockService(new Response(200));

        $service->createMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);

        $meeting->update([
            'google_event_id' => 'lmstestevent123',
        ]);

        $service->deleteMeetingEvent($meeting);

        $this->assertSame(
            'lmstestevent123',
            $meeting->fresh()->google_event_id
        );

        $this->assertCount(1, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_does_not_create_duplicate_event_when_event_id_is_already_saved(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->reserved()
            ->create([
                'google_event_id' => 'lmstestevent123',
            ]);

        [$service, $handler] = $this->mockService(new Response(200));

        $service->createMeetingEvent($meeting);

        $this->assertSame(
            'lmstestevent123',
            $meeting->fresh()->google_event_id
        );

        $this->assertCount(1, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_skips_event_deletion_when_event_id_is_missing(): void
    {
        $coach = $this->connectedCoach();

        $meeting = Meeting::factory()
            ->forCoach($coach)
            ->canceled()
            ->create([
                'google_event_id' => null,
            ]);

        [$service, $handler] = $this->mockService(new Response(204));

        $service->deleteMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertCount(1, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_refreshes_expired_access_token_and_uses_new_token_for_free_busy_request(): void
    {
        $this->freezeTime();

        $coach = $this->connectedCoach();

        $credential = $coach->googleCredential;

        $credential->update([
            'expires_at' => now()->subMinute(),
        ]);

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(200, [
                'access_token' => 'refreshed-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            $this->freeBusyResponse([
                [
                    'start' => '2026-11-09T09:00:00+09:00',
                    'end' => '2026-11-09T10:00:00+09:00',
                ],
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertTrue($service->isBusy($coach, $start, $end));

        $credential->refresh();

        $this->assertSame(
            'refreshed-access-token',
            $credential->access_token
        );

        $this->assertSame(
            'test-refresh-token',
            $credential->refresh_token
        );

        $this->assertSame(
            now()->addHour()->getTimestamp(),
            $credential->expires_at->getTimestamp()
        );

        $this->assertCount(0, $handler);
        $this->assertCount(2, $this->history);

        $refreshRequest = $this->history[0]['request'];

        $this->assertSame('POST', $refreshRequest->getMethod());
        $this->assertStringEndsWith(
            '/token',
            $refreshRequest->getUri()->getPath()
        );

        parse_str(
            (string) $refreshRequest->getBody(),
            $refreshBody
        );

        $this->assertSame(
            'refresh_token',
            $refreshBody['grant_type']
        );

        $this->assertSame(
            'test-refresh-token',
            $refreshBody['refresh_token']
        );

        $calendarRequest = $this->history[1]['request'];

        $this->assertStringEndsWith(
            '/freeBusy',
            $calendarRequest->getUri()->getPath()
        );

        $this->assertSame(
            'Bearer refreshed-access-token',
            $calendarRequest->getHeaderLine('Authorization')
        );
    }

    public function test_returns_false_when_access_token_refresh_fails(): void
    {
        $this->freezeTime();

        $coach = $this->connectedCoach();

        $credential = $coach->googleCredential;

        $expiredAt = now()->subMinute();

        $credential->update([
            'expires_at' => $expiredAt,
        ]);

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(400, [
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token is invalid.',
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));

        $credential->refresh();

        $this->assertSame(
            'test-access-token',
            $credential->access_token
        );

        $this->assertSame(
            'test-refresh-token',
            $credential->refresh_token
        );

        $this->assertSame(
            $expiredAt->getTimestamp(),
            $credential->expires_at->getTimestamp()
        );

        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);

        $this->assertStringEndsWith(
            '/token',
            $this->history[0]['request']->getUri()->getPath()
        );
    }

    public function test_returns_false_when_expired_access_token_has_no_refresh_token(): void
    {
        $coach = $this->connectedCoach();

        $coach->googleCredential->update([
            'expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        [$service, $handler] = $this->mockService();

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(0, $this->history);
    }

    public function test_returns_false_when_free_busy_response_is_missing_calendar(): void
    {
        $coach = $this->connectedCoach();

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(200, [
                'kind' => 'calendar#freeBusy',
                'calendars' => [],
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
    }

    public function test_returns_false_when_free_busy_response_contains_errors(): void
    {
        $coach = $this->connectedCoach();

        [$service, $handler] = $this->mockService(
            $this->jsonResponse(200, [
                'kind' => 'calendar#freeBusy',
                'calendars' => [
                    'primary' => [
                        'errors' => [
                            [
                                'domain' => 'calendar',
                                'reason' => 'notFound',
                            ],
                        ],
                        'busy' => [
                            [
                                'start' => '2026-11-09T09:00:00+09:00',
                                'end' => '2026-11-09T10:00:00+09:00',
                            ],
                        ],
                    ],
                ],
            ])
        );

        $start = Carbon::parse('2026-11-09 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();

        $this->assertFalse($service->isBusy($coach, $start, $end));
        $this->assertCount(0, $handler);
        $this->assertCount(1, $this->history);
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

        config()->set(
            'services.google_calendar.client_id',
            'test-client-id'
        );

        config()->set(
            'services.google_calendar.client_secret',
            'test-client-secret'
        );

        return $coach;
    }

    /**
     * @return array{GoogleCalendarService, MockHandler}
     */
    private function mockService(Response ...$responses): array
    {
        $this->history = [];

        $handler = new MockHandler($responses);

        $stack = HandlerStack::create(function (RequestInterface $request, array $options) use ($handler) {
            $this->history[] = ['request' => $request];

            return $handler($request, $options);
        });

        $client = new HttpClient([
            'handler' => $stack,
        ]);

        return [
            new GoogleCalendarService($client),
            $handler,
        ];
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

    /**
     * @param array<int, array<string, string>> $busy
     */
    private function freeBusyResponse(array $busy): Response
    {
        return $this->jsonResponse(200, [
            'kind' => 'calendar#freeBusy',
            'timeMin' => '2026-11-09T09:00:00+09:00',
            'timeMax' => '2026-11-09T10:00:00+09:00',
            'calendars' => [
                'primary' => [
                    'busy' => $busy,
                ],
            ],
        ]);
    }
}
