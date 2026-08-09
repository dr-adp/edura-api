<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Institution;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\UsageStatistic;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UsageReconciliationService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Reconcile current usage for an institution.
     *
     * Usage rules:
     * - Students: active student profiles
     * - Teachers: active teacher profiles
     * - Courses: draft and published courses
     */
    public function reconcileInstitution(
        Institution|int $institution,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null
    ): array {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;

        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        return DB::transaction(function () use (
            $institutionId,
            $periodStart,
            $periodEnd
        ) {
            $subscription = $this->subscriptionService
                ->currentForInstitution((int) $institutionId);

            if (!$subscription) {
                return [];
            }

            $metrics = [
                'students' => StudentProfile::query()
                    ->where('institution_id', $institutionId)
                    ->where('status', 'active')
                    ->count(),

                'teachers' => TeacherProfile::query()
                    ->where('institution_id', $institutionId)
                    ->where('status', 'active')
                    ->count(),

                'courses' => Course::query()
                    ->where('institution_id', $institutionId)
                    ->whereIn('status', ['draft', 'published'])
                    ->count(),
            ];

            $result = [];

            foreach ($metrics as $metric => $usedValue) {
                $statistic = UsageStatistic::query()
                    ->where('institution_id', $institutionId)
                    ->where('metric', $metric)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first();

                if ($statistic) {
                    $statistic->update([
                        'subscription_id' => $subscription->id,
                        'used_value' => $usedValue,
                        'limit_value' => $this->subscriptionService
                            ->currentPlanForInstitution((int) $institutionId)
                            ?->limit($metric),
                    ]);

                    $result[$metric] = $statistic->fresh();

                    continue;
                }

                $result[$metric] = UsageStatistic::create([
                    'institution_id' => $institutionId,
                    'metric' => $metric,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'subscription_id' => $subscription->id,
                    'used_value' => $usedValue,
                    'limit_value' => $this->subscriptionService
                        ->currentPlanForInstitution((int) $institutionId)
                        ?->limit($metric),
                ]);
            }

            return $result;
        });
    }
}
