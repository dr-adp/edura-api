<?php

namespace Tests\Unit;

use App\Exceptions\AssessmentLockSetChanged;
use App\Services\CourseAssessmentLockService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentLockRetryTest extends TestCase
{
    public function test_changed_lock_set_retries_only_after_the_owned_transaction_rolls_back(): void
    {
        $calls = 0;
        $transactionLevels = [];

        $result = app(CourseAssessmentLockService::class)
            ->transactionWithLockSetRetries(function () use (&$calls, &$transactionLevels): string {
                $calls++;
                $transactionLevels[] = DB::transactionLevel();

                if ($calls === 1) {
                    throw new AssessmentLockSetChanged([8, 3]);
                }

                return 'completed';
            });

        $this->assertSame('completed', $result);
        $this->assertSame(2, $calls);
        $this->assertSame([1, 1], $transactionLevels);
        $this->assertSame(0, DB::transactionLevel());
    }
}
