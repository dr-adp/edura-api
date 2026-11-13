<?php

namespace Tests\Unit;

use App\Services\CourseAssessmentLockService;
use App\Services\GradebookCalculationService;
use LogicException;
use Tests\TestCase;

class CourseAssessmentLockTransactionGuardTest extends TestCase
{
    public function test_lock_service_rejects_locking_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(CourseAssessmentLockService::class)->lockCourses([]);
    }

    public function test_gradebook_transaction_method_rejects_calls_without_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(GradebookCalculationService::class)
            ->recalculateWithinTransaction(1, 1);
    }
}
