<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Institution;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\GradebookCalculationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CourseAssessmentLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_courses_and_students_are_locked_in_ascending_id_order(): void
    {
        $first = $this->createStudentCoursePair();
        $second = $this->createStudentCoursePair($first['institution']);
        $third = $this->createStudentCoursePair($first['institution']);
        $lockQueries = [
            'courses' => [],
            'students' => [],
        ];

        DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
            $sql = strtolower(preg_replace('/\s+/', ' ', $query->sql));

            if (
                str_contains($sql, 'from "courses"') &&
                str_contains($sql, '"courses"."id" = ?')
            ) {
                $lockQueries['courses'][] = (int) $query->bindings[0];
            }

            if (
                str_contains($sql, 'from "student_profiles"') &&
                str_contains($sql, '"student_profiles"."id" = ?')
            ) {
                $lockQueries['students'][] = (int) $query->bindings[0];
            }
        });

        DB::transaction(function () use ($first, $second, $third): void {
            $locks = app(CourseAssessmentLockService::class);
            $locks->lockCourses([
                $third['course']->id,
                $first['course']->id,
                $second['course']->id,
            ]);
            $locks->lockStudents([
                $third['student']->id,
                $first['student']->id,
                $second['student']->id,
            ]);
        });

        $this->assertSame(
            [$first['course']->id, $second['course']->id, $third['course']->id],
            $lockQueries['courses']
        );
        $this->assertSame(
            [$first['student']->id, $second['student']->id, $third['student']->id],
            $lockQueries['students']
        );
    }

    public function test_multiple_enrollment_pairs_are_locked_in_course_student_order(): void
    {
        $first = $this->createStudentCoursePair();
        $second = $this->createStudentCoursePair($first['institution']);
        $third = $this->createStudentCoursePair($first['institution']);
        $lockedPairs = [];

        DB::listen(function (QueryExecuted $query) use (&$lockedPairs): void {
            $sql = strtolower(preg_replace('/\\s+/', ' ', $query->sql));

            if (
                str_contains($sql, 'from "course_enrollments"') &&
                str_contains($sql, '"course_id" = ?') &&
                str_contains($sql, '"student_profile_id" = ?')
            ) {
                $lockedPairs[] = [
                    'course_id' => (int) $query->bindings[0],
                    'student_profile_id' => (int) $query->bindings[1],
                ];
            }
        });

        DB::transaction(function () use ($first, $second, $third): void {
            $locks = app(CourseAssessmentLockService::class);
            $locks->lockCourses([
                $third['course']->id,
                $first['course']->id,
                $second['course']->id,
            ]);
            $locks->lockStudents([
                $third['student']->id,
                $first['student']->id,
                $second['student']->id,
            ]);
            $locks->lockCourseEnrollments([
                [
                    'course_id' => $third['course']->id,
                    'student_profile_id' => $third['student']->id,
                ],
                [
                    'course_id' => $first['course']->id,
                    'student_profile_id' => $first['student']->id,
                ],
                [
                    'course_id' => $second['course']->id,
                    'student_profile_id' => $second['student']->id,
                ],
            ]);
        });

        $expectedPairs = [
            [
                'course_id' => (int) $first['course']->id,
                'student_profile_id' => (int) $first['student']->id,
            ],
            [
                'course_id' => (int) $second['course']->id,
                'student_profile_id' => (int) $second['student']->id,
            ],
            [
                'course_id' => (int) $third['course']->id,
                'student_profile_id' => (int) $third['student']->id,
            ],
        ];
        usort($expectedPairs, static fn (array $left, array $right): int =>
            [$left['course_id'], $left['student_profile_id']] <=>
            [$right['course_id'], $right['student_profile_id']]
        );

        $this->assertSame($expectedPairs, $lockedPairs);
    }

    public function test_enrollment_is_revalidated_after_parent_rows_are_locked(): void
    {
        $pair = $this->createStudentCoursePair(enrollmentStatus: 'cancelled');
        $lockOrder = [];

        DB::listen(function (QueryExecuted $query) use (&$lockOrder): void {
            $sql = strtolower(preg_replace('/\s+/', ' ', $query->sql));

            foreach (['courses', 'student_profiles', 'course_enrollments'] as $table) {
                if (str_contains($sql, 'from "' . $table . '"')) {
                    $lockOrder[] = $table;
                    break;
                }
            }
        });

        try {
            DB::transaction(fn () => app(GradebookCalculationService::class)
                ->recalculateWithinTransaction(
                    (int) $pair['course']->id,
                    (int) $pair['student']->id
                ));
            $this->fail('Cancelled enrollment should fail eligibility revalidation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_profile_id', $exception->errors());
        }

        $this->assertSame(
            ['courses', 'student_profiles', 'course_enrollments'],
            array_values(array_unique($lockOrder))
        );
        $this->assertDatabaseMissing('gradebooks', [
            'course_id' => $pair['course']->id,
            'student_profile_id' => $pair['student']->id,
        ]);
    }

    public function test_outer_transaction_rollback_rolls_back_gradebook_changes(): void
    {
        $pair = $this->createStudentCoursePair();
        $this->createAssignmentEvaluation($pair, 80, 100);

        try {
            DB::transaction(function () use ($pair): void {
                app(GradebookCalculationService::class)
                    ->recalculateWithinTransaction(
                        (int) $pair['course']->id,
                        (int) $pair['student']->id
                    );

                throw new RuntimeException('Force outer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('gradebooks', [
            'course_id' => $pair['course']->id,
            'student_profile_id' => $pair['student']->id,
        ]);
    }

    public function test_standalone_recalculation_still_persists_the_calculated_gradebook(): void
    {
        // End RefreshDatabase's outer isolation transaction so this exercises
        // the service's standalone transaction wrapper.
        DB::rollBack();

        $pair = $this->createStudentCoursePair();
        $this->createAssignmentEvaluation($pair, 80, 100);

        $gradebook = app(GradebookCalculationService::class)->recalculate(
            (int) $pair['course']->id,
            (int) $pair['student']->id
        );

        $this->assertSame('80.00', $gradebook->total_marks);
        $this->assertSame('100.00', $gradebook->maximum_marks);
        $this->assertSame('80.00', $gradebook->percentage);
        $this->assertSame('A', $gradebook->grade);
        $this->assertSame('passed', $gradebook->result_status);
        $this->assertDatabaseHas('gradebooks', [
            'course_id' => $pair['course']->id,
            'student_profile_id' => $pair['student']->id,
            'total_marks' => 80,
            'maximum_marks' => 100,
            'result_status' => 'passed',
        ]);
    }

    private function createStudentCoursePair(
        ?Institution $institution = null,
        string $enrollmentStatus = 'active'
    ): array {
        $institution ??= Institution::create([
            'name' => 'Assessment Lock Test Institution',
            'code' => 'LOCK-' . Str::upper(Str::random(10)),
        ]);

        $student = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'roll_number' => 'ROLL-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $course = Course::create([
            'institution_id' => $institution->id,
            'title' => 'Assessment Lock Course ' . Str::random(8),
            'slug' => 'assessment-lock-' . Str::lower(Str::random(12)),
            'status' => 'published',
        ]);

        $enrollment = CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => $enrollmentStatus,
        ]);

        return compact('institution', 'student', 'course', 'enrollment');
    }

    private function createAssignmentEvaluation(array $pair, float $marks, float $maximum): void
    {
        $assignment = Assignment::create([
            'course_id' => $pair['course']->id,
            'title' => 'Assessment Lock Assignment ' . Str::random(8),
            'maximum_marks' => $maximum,
            'status' => 'published',
        ]);

        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
            'status' => 'reviewed',
        ]);

        AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'marks_obtained' => $marks,
            'maximum_marks' => $maximum,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
    }
}
