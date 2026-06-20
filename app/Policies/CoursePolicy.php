<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\InstitutionUser;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return true;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id;
        }

        if ($user->hasRole('student')) {
            return $course->enrollments()
                ->whereHas('studentProfile', fn($q) => $q->where('user_id', $user->id))
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super-admin', 'institution-admin', 'teacher']);
    }

    public function update(User $user, Course $course): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();
            return $institutionUser && (int) $course->institution_id === (int) $institutionUser->institution_id;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            return $teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id;
        }

        return false;
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course);
    }
}