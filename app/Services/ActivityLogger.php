<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public function log(string $action, ?Model $subject = null, array $meta = [], ?Branch $branch = null): ActivityLog
    {
        $branch ??= \App\Support\CurrentBranch::get();
        $user = auth()->user();

        return ActivityLog::create([
            'branch_id' => $branch?->id ?? $subject?->branch_id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'business_date' => $branch?->businessDateFor()->toDateString(),
            'ip_address' => request()->ip(),
        ]);
    }
}
