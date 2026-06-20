<?php

namespace App\Policies;

use App\Models\Gradebook;
use App\Models\User;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\ParentProfile;

class GradebookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher', 'student', 'parent']);
    }

    public function view(User $user, Gradebook $gradebook): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $gradebook->course->teacher_profile_id === (int) $teacherProfile->id;
        }

        if ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            return $studentProfile && (int) $gradebook->student_profile_id === (int) $studentProfile->id;
        }

        if ($user->hasRole('parent')) {
            $parentProfile = ParentProfile::where('user_id', $user->id)->first();
            return $parentProfile && (int) $parentProfile->student_profile_id === (int) $gradebook->student_profile_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher']);
    }

    public function update(User $user, Gradebook $gradebook): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $gradebook->course->teacher_profile_id === (int) $teacherProfile->id;
        }

        return false;
    }

    public function delete(User $user, Gradebook $gradebook): bool
    {
        return $this->update($user, $gradebook);
    }
}