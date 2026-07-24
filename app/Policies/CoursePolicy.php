<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ]);
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();

            return $institutionUser
                && (int) $institutionUser->institution_id === (int) $course->institution_id;
        }

        if ($user->hasRole('teacher')) {
            return $user->teacherProfile
                && (int) $user->teacherProfile->id === (int) $course->teacher_profile_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ]);
    }

    public function update(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course);
    }
}
