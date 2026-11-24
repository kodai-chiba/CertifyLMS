<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\CreateAction;
use App\UseCases\Meeting\CreateFallbackAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\GetRemainingQuotaAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\Meeting\UpsertMemoAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。
 * 面談の取得・予約・キャンセル・メモ保存処理を Action に委譲する。
 * 認可は $this->authorize() または FormRequest::authorize()。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。
     * tab を優先し、指定がなければ filter、さらに未指定なら upcoming を使用する。
     */
    public function index(
        IndexRequest $request,
        IndexAction $indexAction,
        GetRemainingQuotaAction $getRemainingQuotaAction,
    ): View {
        $filter = $request->validated('tab')
            ?? $request->validated('filter')
            ?? 'upcoming';

        $meetings = $indexAction($request->user(), $filter);

        return view('meeting.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'meetingsRemaining' => $getRemainingQuotaAction($request->user()),
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     * tab を優先し、指定がなければ filter、さらに未指定なら upcoming を使用する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, IndexAsCoachAction $indexAsCoachAction): View
    {
        $filters = $request->validated();

        $filter = $filters['tab']
            ?? $filters['filter']
            ?? 'upcoming';

        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $meetings = $indexAsCoachAction($request->user(), $filter, $studentId, $enrollmentId);

        return view('meeting.coach.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting, ShowAction $showAction): View
    {
        $this->authorize('view', $meeting);

        $meeting = $showAction($meeting);

        return view('meeting.show', [
            'meeting' => $meeting,
        ]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(
        Enrollment $enrollment,
        CreateAction $createAction,
        GetRemainingQuotaAction $getRemainingQuotaAction,
    ): View {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment = $createAction($enrollment);

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $getRemainingQuotaAction(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(CreateFallbackAction $createFallbackAction): View
    {
        $enrollments = $createFallbackAction(auth()->user());

        return view('meeting.empty-state', [
            'enrollments' => $enrollments,
        ]);
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(
        Enrollment $enrollment,
        AvailabilityRequest $request,
        FetchAvailabilityAction $fetchAvailabilityAction
    ): JsonResponse {
        $date = Carbon::parse($request->validated('date'));

        return response()->json($fetchAvailabilityAction($enrollment, $date));
    }

    /**
     * 受講生の予約申請。
     * 予約処理を StoreAction に委譲する。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        StoreAction $storeAction,
    ): RedirectResponse {
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
        $topic = $request->validated('topic');

        $meeting = $storeAction($enrollment, $scheduledAt, $topic);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * 認可後、キャンセル処理を CancelAction に委譲する。
     */
    public function cancel(
        Meeting $meeting,
        CancelAction $cancelAction,
    ): RedirectResponse {
        $this->authorize('cancel', $meeting);

        $cancelAction($meeting, auth()->user());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。
     * 認可・バリデーション後、メモ保存処理を UpsertMemoAction に委譲する。
     */
    public function upsertMemo(
        Meeting $meeting,
        UpsertMemoRequest $request,
        UpsertMemoAction $upsertMemoAction,
    ): RedirectResponse {
        $body = $request->validated('body');

        $upsertMemoAction($meeting, $body);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }
}
