<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GoogleCalendarCredential;
use Google\Client;
use Google\Service\Calendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarController extends Controller
{
    private const OAUTH_STATE_SESSION_KEY = 'google_calendar_oauth_state';

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Coach, 403);

        $client = $this->client();
        $state = Str::random(64);
        $request->session()->put(self::OAUTH_STATE_SESSION_KEY, $state);
        $client->setState($state);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes([
            Calendar::CALENDAR_READONLY,
            Calendar::CALENDAR_EVENTS,
        ]);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Coach, 403);

        $expectedState = $request->session()->pull(self::OAUTH_STATE_SESSION_KEY);
        $receivedState = $request->query('state');

        if (! is_string($expectedState)
            || ! is_string($receivedState)
            || ! hash_equals($expectedState, $receivedState)) {
            return redirect('/settings/availability')
                ->with('error', 'Google連携の認証を確認できませんでした。もう一度お試しください。');
        }

        if ($request->has('error') || ! is_string($request->query('code'))) {
            return redirect('/settings/availability')
                ->with('error', 'Google Calendarの連携がキャンセルされたか、認証に失敗しました。');
        }

        try {
            $client = $this->client();
            $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));

            if (! is_array($token)
                || isset($token['error'])
                || ! isset($token['access_token'])
                || ! is_string($token['access_token'])) {
                return redirect('/settings/availability')
                    ->with('error', 'Google Calendarの認証情報を取得できませんでした。');
            }

            $existing = $request->user()->googleCredential;
            $refreshToken = $token['refresh_token'] ?? $existing?->refresh_token;

            if (! is_string($refreshToken) || $refreshToken === '') {
                return redirect('/settings/availability')
                    ->with('error', 'Google Calendarの継続的な連携に必要な認証情報を取得できませんでした。');
            }

            GoogleCalendarCredential::query()->updateOrCreate(
                ['user_id' => $request->user()->getKey()],
                [
                    'access_token' => $token['access_token'],
                    'refresh_token' => $refreshToken,
                    'expires_at' => now()->addSeconds(max(0, (int) ($token['expires_in'] ?? 3600))),
                    'calendar_id' => $existing?->calendar_id ?? 'primary',
                    'connected_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Google Calendar OAuth connection failed', [
                'exception_class' => $exception::class,
            ]);

            return redirect('/settings/availability')
                ->with('error', 'Google Calendarの連携に失敗しました。時間をおいて再度お試しください。');
        }

        return redirect('/settings/availability')
            ->with('success', 'Google Calendarを連携しました。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Coach, 403);

        $request->user()->googleCredential()->delete();

        return redirect('/settings/availability')
            ->with('success', 'Google Calendarの連携を解除しました。');
    }

    private function client(): Client
    {
        $clientId = config('services.google_calendar.client_id');
        $clientSecret = config('services.google_calendar.client_secret');
        $redirectUri = config('services.google_calendar.redirect_uri');

        if (! is_string($clientId) || $clientId === ''
            || ! is_string($clientSecret) || $clientSecret === ''
            || ! is_string($redirectUri) || $redirectUri === '') {
            throw new \RuntimeException('Google Calendar OAuth configuration is incomplete.');
        }

        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);

        return $client;
    }
}
