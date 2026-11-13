<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GoogleCalendarService
{
    /**
     * 通常はGoogle標準のHTTPクライアントを使用します。
     * テスト時のみモックHTTPクライアントを注入できます。
     */
    public function __construct(
        private readonly ?ClientInterface $httpClient = null
    ) {}

    /**
     * 指定時間帯にGoogleカレンダーの予定があるか確認します。
     * API障害時はLMSの予約処理を妨げないよう、予定なしとして扱います。
     */
    public function isBusy(
        User $coach,
        CarbonInterface $start,
        CarbonInterface $end
    ): bool {
        $credential = $coach->googleCredential;

        if (! $credential instanceof GoogleCalendarCredential) {
            return false;
        }

        try {
            $client = $this->authenticatedClient($credential);
            $calendar = new Calendar($client);

            $item = new FreeBusyRequestItem;
            $item->setId($credential->calendar_id);

            $request = new FreeBusyRequest;
            $request->setTimeMin($start->toRfc3339String());
            $request->setTimeMax($end->toRfc3339String());
            $request->setItems([$item]);

            $response = $calendar->freebusy->query($request);
            $calendars = $response->getCalendars();
            $result = $calendars[$credential->calendar_id] ?? null;

            if ($result === null) {
                throw new RuntimeException('Google Calendar free/busy response is missing.');
            }

            if ($result->getErrors()) {
                throw new RuntimeException('Google Calendar free/busy response contains errors.');
            }

            return count($result->getBusy() ?? []) > 0;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar free/busy lookup failed', [
                'coach_id' => $coach->getKey(),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            return false;
        }
    }

    /**
     * 確定済みのLMS面談をコーチのGoogleカレンダーに登録します。
     * Google API障害が発生してもLMS面談は取り消しません。
     */
    public function createMeetingEvent(Meeting $meeting): void
    {
        if ($meeting->google_event_id !== null) {
            return;
        }

        $coach = $meeting->coach;
        $credential = $coach?->googleCredential;

        if (! $credential instanceof GoogleCalendarCredential) {
            return;
        }

        // Googleが許可するbase32hex文字だけを使い、同一面談の再実行で重複登録を防ぎます。
        $eventId = 'lms'.hash('sha256', (string) $meeting->getKey());

        try {
            $client = $this->authenticatedClient($credential);
            $calendar = new Calendar($client);

            $start = new EventDateTime;
            $start->setDateTime($meeting->scheduled_at->toRfc3339String());

            $end = new EventDateTime;
            $end->setDateTime($meeting->scheduled_at->copy()->addHour()->toRfc3339String());

            $event = new Event;
            $event->setId($eventId);
            $event->setSummary('Certify LMS 面談');
            $event->setDescription('Certify LMSで予約された面談です。');
            $event->setStart($start);
            $event->setEnd($end);

            try {
                $calendar->events->insert($credential->calendar_id, $event);
            } catch (GoogleServiceException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }

                // 同じ面談の再実行で既に登録されている場合は成功として扱います。
            }

            $meeting->update(['google_event_id' => $eventId]);
        } catch (Throwable $exception) {
            Log::warning('Google Calendar meeting event creation failed', [
                'meeting_id' => $meeting->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * キャンセル済みのLMS面談に対応するGoogleイベントを削除します。
     * API障害時もLMSのキャンセルは維持し、再確認できるようイベントIDを残します。
     */
    public function deleteMeetingEvent(Meeting $meeting): void
    {
        if (! is_string($meeting->google_event_id) || $meeting->google_event_id === '') {
            return;
        }

        $credential = $meeting->coach?->googleCredential;

        if (! $credential instanceof GoogleCalendarCredential) {
            Log::warning('Google Calendar event deletion skipped: coach is not connected', [
                'meeting_id' => $meeting->getKey(),
            ]);

            return;
        }

        try {
            $calendar = new Calendar($this->authenticatedClient($credential));

            try {
                $calendar->events->delete($credential->calendar_id, $meeting->google_event_id);
            } catch (GoogleServiceException $exception) {
                if ($exception->getCode() !== 404 && $exception->getCode() !== 410) {
                    throw $exception;
                }

                // Google側ですでに削除されている場合も削除済みとして扱います。
            }

            $meeting->update(['google_event_id' => null]);
        } catch (Throwable $exception) {
            Log::warning('Google Calendar meeting event deletion failed', [
                'meeting_id' => $meeting->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * 保存済みOAuth認証情報からGoogle APIクライアントを生成します。
     * アクセストークンの期限が切れている場合は更新します。
     */
    private function authenticatedClient(GoogleCalendarCredential $credential): Client
    {
        $clientId = config('services.google_calendar.client_id');
        $clientSecret = config('services.google_calendar.client_secret');

        if (! is_string($clientId) || $clientId === ''
            || ! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('Google Calendar OAuth configuration is incomplete.');
        }

        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);

        if ($this->httpClient !== null) {
            $client->setHttpClient($this->httpClient);
        }

        $client->setAccessToken([
            'access_token' => $credential->access_token,
            'expires_in' => 3600,
            'created' => time(),
        ]);

        if ($credential->expires_at === null
            || $credential->expires_at->lte(now()->addMinute())) {
            if (! is_string($credential->refresh_token) || $credential->refresh_token === '') {
                throw new RuntimeException('Google Calendar refresh token is missing.');
            }

            $token = $client->fetchAccessTokenWithRefreshToken($credential->refresh_token);

            if (! is_array($token) || isset($token['error'])
                || ! isset($token['access_token']) || ! is_string($token['access_token'])) {
                throw new RuntimeException('Google Calendar access token refresh failed.');
            }

            $credential->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $credential->refresh_token,
                'expires_at' => now()->addSeconds(max(0, (int) ($token['expires_in'] ?? 3600))),
            ]);
        }

        return $client;
    }
}
