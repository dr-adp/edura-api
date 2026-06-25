<?php

namespace App\Observers;

use App\Models\Course;
use App\Support\ActivityLogger;

class CourseObserver
{
    /**
     * Handle the Course "created" event.
     */
    public function created(Course $course): void
    {
        ActivityLogger::log(
            'Course',
            'Created',
            "Course '{$course->title}' created.",
            $course
        );
    }

    /**
     * Handle the Course "updated" event.
     */
    public function updated(Course $course): void
    {
        ActivityLogger::log(
            'Course',
            'Updated',
            "Course '{$course->title}' updated.",
            $course
        );
    }

    /**
     * Handle the Course "deleted" event.
     */
    public function deleted(Course $course): void
    {
        ActivityLogger::log(
            'Course',
            'Deleted',
            "Course '{$course->title}' deleted.",
            $course
        );
    }

    public function restored(Course $course): void
    {
        //
    }

    public function forceDeleted(Course $course): void
    {
        //
    }
}
