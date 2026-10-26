<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AnnouncementController extends Controller
{
    /**
     * お知らせ配信履歴一覧
     */
    public function index(): View
    {
        $announcements = Announcement::query()
            ->with([
                'targetCertification',
                'targetUser',
                'createdBy',
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('announcement.management.index', [
            'announcements' => $announcements,
        ]);
    }

    /**
     * お知らせ作成画面
     */
    public function create(): View
    {
        $certifications = Certification::query()
            ->published()
            ->orderBy('name')
            ->get();

        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->orderBy('name')
            ->get();

        return view('announcement.management.create', [
            'certifications' => $certifications,
            'students' => $students,
        ]);
    }

    /**
     * お知らせ配信
     */
    public function store(
        StoreAnnouncementRequest $request,
        DispatchAnnouncementAction $dispatchAnnouncement
    ): RedirectResponse {
        $announcement = $dispatchAnnouncement(
            $request->user(),
            $request->validated()
        );

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('success', 'お知らせを配信しました。');
    }

    /**
     * お知らせ配信履歴詳細
     */
    public function show(Announcement $announcement): View
    {
        $announcement->load([
            'targetCertification',
            'targetUser',
            'createdBy',
        ]);

        return view('announcement.management.show', [
            'announcement' => $announcement,
        ]);
    }
}
