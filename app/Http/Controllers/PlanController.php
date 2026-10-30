<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\ArchiveAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\PublishAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UnarchiveAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 管理者向け受講プランマスタ管理 Controller。
 */
class PlanController extends Controller
{
    public function index(Request $request, IndexAction $action): View
    {
        $this->authorize('viewAny', Plan::class);

        $keyword = $request->string('keyword')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        return view('plan.management.index', [
            'plans' => $action(
                $keyword !== '' ? $keyword : null,
                $status !== '' ? $status : null,
            ),
            'keyword' => $keyword,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('plan.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action(
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを下書きとして作成しました。');
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('plan.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('plan.management.edit', [
            'plan' => $plan,
        ]);
    }

    public function update(
        Plan $plan,
        UpdateRequest $request,
        UpdateAction $action
    ): RedirectResponse {
        $action(
            $plan,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを更新しました。');
    }

    public function destroy(Plan $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        $action($plan);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'プランを削除しました。');
    }

    public function publish(Plan $plan, PublishAction $action, Request $request): RedirectResponse
    {
        $this->authorize('publish', $plan);

        $action($plan, $request->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを公開しました。');
    }

    public function archive(Plan $plan, ArchiveAction $action, Request $request): RedirectResponse
    {
        $this->authorize('archive', $plan);

        $action($plan, $request->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランをアーカイブしました。');
    }

    public function unarchive(Plan $plan, UnarchiveAction $action, Request $request): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        $action($plan, $request->user());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを下書きへ戻しました。');
    }
}
