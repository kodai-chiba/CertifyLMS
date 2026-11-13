<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\StripeCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetingQuotaPurchaseController extends Controller
{
    /**
     * 購入可能な面談パック一覧を表示する。
     */
    public function index(): View
    {
        $plans = MeetingPack::query()
            ->published()
            ->ordered()
            ->get();

        return view('meeting-quota.checkout-select', [
            'plans' => $plans,
        ]);
    }

    /**
     * Stripe Checkout Sessionを作成して決済画面へ遷移する。
     */
    public function checkout(
        CheckoutRequest $request,
        StripeCheckoutService $service,
    ): RedirectResponse {
        $validated = $request->validated();

        $meetingPack = MeetingPack::query()
            ->published()
            ->findOrFail($validated['meeting_pack_id']);

        $result = $service->createSession(
            $request->user(),
            $meetingPack,
            route('meeting-quota.success', [], true).'?session_id={CHECKOUT_SESSION_ID}',
            route('meeting-quota.purchase.index', [], true),
        );

        return redirect()->away($result['checkout_url']);
    }

    /**
     * Stripe Checkoutからの戻り先。
     *
     * 決済確定処理はWebhook側で行う。
     * この画面へのアクセスだけでは決済完了と判定しない。
     */
    public function success(Request $request): View
    {
        $payment = null;

        if ($request->filled('session_id')) {
            $payment = Payment::query()
                ->where(
                    'stripe_checkout_session_id',
                    $request->query('session_id')
                )
                ->where('user_id', $request->user()->id)
                ->with('meetingPack')
                ->first();
        }

        return view('meeting-quota.success', [
            'payment' => $payment,
        ]);
    }
}
