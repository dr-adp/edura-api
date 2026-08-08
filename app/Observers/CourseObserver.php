<?php

namespace App\Observers;

use App\Models\Course;
use App\Services\AuditLogService;

class CourseObserver
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    /**
     * Handle the Course "created" event.
     */
    public function created(Course $course): void
    {
        $this->auditLogService->recordCreated(
            $course,
            "Course '{$course->title}' created."
        );
    }

    /**
     * Handle the Course "updated" event.
     */
    public function updated(Course $course): void
    {
        $this->auditLogService->recordUpdated(
            $course,
            "Course '{$course->title}' updated."
        );
    }

    /**
     * Handle the Course "deleted" event.
     */
    public function deleted(Course $course): void
    {
        $this->auditLogService->recordDeleted(
            $course,
            "Course '{$course->title}' deleted."
        );
    }

    public function restored(Course $course): void
    {
        $this->auditLogService->recordRestored(
            $course,
            "Course '{$course->title}' restored."
        );
    }

    public function forceDeleted(Course $course): void
    {
        //
    }
}
