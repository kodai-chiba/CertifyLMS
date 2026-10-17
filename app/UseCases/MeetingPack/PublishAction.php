<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublishAction
{
    public function __invoke(MeetingPack $plan, User $auth): MeetingPack
    {
        return DB::transaction(function () use ($plan, $auth) {
            $plan->update([
                'status' => MeetingPackStatus::Published,
                'updated_by_user_id' => $auth->id,
            ]);

            return $plan->fresh();
        });
    }
}
