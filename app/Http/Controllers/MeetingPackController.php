<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\ArchiveAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\PublishAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UnarchiveAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetingPackController extends Controller
{
    public function index(Request $request, IndexAction $action): View
    {
        $this->authorize('viewAny', MeetingPack::class);

        $keyword = $request->string('keyword')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $plans = $action(
            $keyword !== '' ? $keyword : null,
            $status !== '' ? $status : null,
        );

        return view('meeting-pack.management.index', compact(
            'plans',
            'keyword',
            'status',
        ));
    }

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->validated(), $request->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを作成しました。');
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        $plan = $action($plan);

        return view('meeting-pack.management.show', compact('plan'));
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', compact('plan'));
    }

    public function update(
        UpdateRequest $request,
        MeetingPack $plan,
        UpdateAction $action,
    ): RedirectResponse {
        $plan = $action($plan, $request->validated(), $request->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        $action($plan);

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを削除しました。');
    }

    public function publish(
        Request $request,
        MeetingPack $plan,
        PublishAction $action,
    ): RedirectResponse {
        $this->authorize('publish', $plan);

        $plan = $action($plan, $request->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを公開しました。');
    }

    public function archive(
        Request $request,
        MeetingPack $plan,
        ArchiveAction $action,
    ): RedirectResponse {
        $this->authorize('archive', $plan);

        $plan = $action($plan, $request->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックをアーカイブしました。');
    }

    public function unarchive(
        Request $request,
        MeetingPack $plan,
        UnarchiveAction $action,
    ): RedirectResponse {
        $this->authorize('unarchive', $plan);

        $plan = $action($plan, $request->user());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを下書きに戻しました。');
    }
}
