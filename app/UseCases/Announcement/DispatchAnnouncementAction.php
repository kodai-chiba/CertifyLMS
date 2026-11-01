<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Support\Facades\DB;

class DispatchAnnouncementAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function __invoke(User $admin, array $data): Announcement
    {
        $targetType = AnnouncementTargetType::from($data['target_type']);

        $recipients = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value);

        if ($targetType === AnnouncementTargetType::Certification) {
            $recipients->whereHas('enrollments', function ($query) use ($data) {
                $query
                    ->where('certification_id', $data['target_certification_id'])
                    ->learning();
            });
        }

        if ($targetType === AnnouncementTargetType::User) {
            $recipients->whereKey($data['target_user_id']);
        }

        $recipientIds = $recipients->pluck('id');

        return DB::transaction(function () use ($admin, $data, $targetType, $recipientIds): Announcement {
            $announcement = Announcement::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'target_type' => $targetType,
                'target_certification_id' => $targetType === AnnouncementTargetType::Certification
                    ? $data['target_certification_id']
                    : null,
                'target_user_id' => $targetType === AnnouncementTargetType::User
                    ? $data['target_user_id']
                    : null,
                'created_by' => $admin->id,
                'dispatched_count' => 0,
                'dispatched_at' => null,
            ]);

            User::query()
                ->whereKey($recipientIds)
                ->chunkById(100, function ($users) use ($announcement): void {
                    foreach ($users as $user) {
                        $user->notify(new AdminAnnouncementNotification($announcement));
                    }
                });

            $announcement->update([
                'dispatched_count' => $recipientIds->count(),
                'dispatched_at' => now(),
            ]);

            return $announcement;
        });
    }
}
