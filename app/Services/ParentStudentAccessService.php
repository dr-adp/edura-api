<?php

namespace App\Services;

use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ParentStudentAccessService
{
    /**
     * Return active links owned by the parent whose institution matches the
     * linked student's institution.
     *
     * @param User $parent The authenticated parent user.
     */
    public function validLinksFor(User $parent): Builder
    {
        return ParentProfile::query()
            ->where('parent_profiles.user_id', $parent->getKey())
            ->where('parent_profiles.status', 'active')
            ->whereNotNull('parent_profiles.student_profile_id')
            ->whereHas('studentProfile', static function (Builder $studentProfiles): void {
                $studentProfiles->whereColumn(
                    'student_profiles.institution_id',
                    'parent_profiles.institution_id'
                );
            });
    }

    /**
     * Return the student IDs authorized by the parent's valid links as a
     * subquery, so listing endpoints can scope their result in SQL.
     */
    public function studentProfileIdsFor(User $parent): Builder
    {
        return $this->validLinksFor($parent)
            ->select('parent_profiles.student_profile_id')
            ->distinct();
    }

    /**
     * Scope records carrying a student_profile_id to the parent's valid
     * linked students.
     */
    public function scopeStudentRecords(
        Builder $records,
        User $parent,
        string $studentProfileColumn = 'student_profile_id'
    ): Builder {
        return $records->whereIn(
            $studentProfileColumn,
            $this->studentProfileIdsFor($parent)
        );
    }

    /**
     * Check whether the parent has an active, institution-consistent link to
     * the requested student.
     */
    public function allowsStudent(User $parent, StudentProfile|int $studentProfile): bool
    {
        $studentProfileId = $studentProfile instanceof StudentProfile
            ? $studentProfile->getKey()
            : $studentProfile;

        return $this->validLinksFor($parent)
            ->where('parent_profiles.student_profile_id', $studentProfileId)
            ->exists();
    }

    /**
     * Abort when the parent has no valid link to the requested student.
     */
    public function authorizeStudent(
        User $parent,
        StudentProfile|int $studentProfile,
        string $message = 'Unauthorized: You can only access students linked to your active parent profile.'
    ): void {
        abort_unless(
            $this->allowsStudent($parent, $studentProfile),
            403,
            $message
        );
    }

    /**
     * Prevent an inconsistent legacy link from serializing another
     * institution's student through its eager-loaded studentProfile relation.
     */
    public function hideInconsistentStudent(ParentProfile $parentProfile): ParentProfile
    {
        $parentProfile->loadMissing('studentProfile');
        $studentProfile = $parentProfile->getRelation('studentProfile');

        if (
            $studentProfile instanceof StudentProfile &&
            (int) $parentProfile->institution_id !== (int) $studentProfile->institution_id
        ) {
            $parentProfile->setRelation('studentProfile', null);
        }

        return $parentProfile;
    }
}
