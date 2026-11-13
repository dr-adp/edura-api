<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\GradebookCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssessmentWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_create_update_and_delete_use_shared_lock_order(): void
    {
        $pair = $this->createPair();
        $assignment = $this->createAssignment($pair['course'], $pair['teacher']);
        $submission = $this->createSubmission($assignment, $pair['student']);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['teacher_user']);

        $created = $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $submission->id,
            'marks_obtained' => 78,
        ])->assertCreated()
            ->assertJsonPath('data.maximum_marks', '100.00')
            ->assertJsonPath('data.result_status', 'passed');

        $evaluationId = (int) $created->json('data.id');
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'status' => 'reviewed',
        ]);

        $locks->events = [];
        $this->patchJson('/api/assignment-evaluations/' . $evaluationId, [
            'marks_obtained' => 42,
        ])->assertOk()
            ->assertJsonPath('data.marks_obtained', '42.00')
            ->assertJsonPath('data.result_status', 'needs_improvement');

        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );

        $locks->events = [];
        $this->deleteJson('/api/assignment-evaluations/' . $evaluationId)->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );
        $this->assertSoftDeleted('assignment_evaluations', ['id' => $evaluationId]);
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'status' => 'submitted',
        ]);
    }

    public function test_evaluation_reassignment_locks_both_course_student_pairs_in_sorted_order(): void
    {
        $first = $this->createPair();
        $second = $this->createPair($first['institution'], $first['teacher']);
        $firstSubmission = $this->createSubmission(
            $this->createAssignment($first['course'], $first['teacher']),
            $first['student']
        );
        $secondSubmission = $this->createSubmission(
            $this->createAssignment($second['course'], $first['teacher']),
            $second['student']
        );
        $evaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $firstSubmission->id,
            'teacher_profile_id' => $first['teacher']->id,
            'marks_obtained' => 60,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($first['teacher_user']);

        $this->patchJson('/api/assignment-evaluations/' . $evaluation->id, [
            'assignment_submission_id' => $secondSubmission->id,
            'marks_obtained' => 85,
        ])->assertOk();

        $this->assertSame(
            ['course', 'course', 'student', 'student', 'enrollments', 'assignment', 'assignment', 'submission', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );

        $lockedCourseIds = array_values(array_map(
            static fn (array $event): int => $event[1],
            array_values(array_filter($locks->events, static fn (array $event): bool => $event[0] === 'course'))
        ));
        $lockedStudentIds = array_values(array_map(
            static fn (array $event): int => $event[1],
            array_values(array_filter($locks->events, static fn (array $event): bool => $event[0] === 'student'))
        ));

        $expectedCourseIds = [$first['course']->id, $second['course']->id];
        $expectedStudentIds = [$first['student']->id, $second['student']->id];
        sort($expectedCourseIds, SORT_NUMERIC);
        sort($expectedStudentIds, SORT_NUMERIC);
        $this->assertSame($expectedCourseIds, $lockedCourseIds);
        $this->assertSame($expectedStudentIds, $lockedStudentIds);
        $this->assertEqualsCanonicalizing(
            [
                [
                    'course_id' => min($first['course']->id, $second['course']->id),
                    'student_profile_id' => $first['course']->id < $second['course']->id
                        ? $first['student']->id
                        : $second['student']->id,
                ],
                [
                    'course_id' => max($first['course']->id, $second['course']->id),
                    'student_profile_id' => $first['course']->id < $second['course']->id
                        ? $second['student']->id
                        : $first['student']->id,
                ],
            ],
            $locks->events[4][1]
        );
        $this->assertDatabaseHas('assignment_evaluations', [
            'id' => $evaluation->id,
            'assignment_submission_id' => $secondSubmission->id,
        ]);
    }

    public function test_completed_enrollment_remains_eligible_for_evaluation_and_cross_institution_is_rejected(): void
    {
        $completed = $this->createPair(enrollmentStatus: 'completed');
        $submission = $this->createSubmission(
            $this->createAssignment($completed['course'], $completed['teacher']),
            $completed['student']
        );
        $this->authenticate($completed['teacher_user']);

        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $submission->id,
            'marks_obtained' => 50,
        ])->assertCreated();

        $foreign = $this->createPair();
        $foreignSubmission = $this->createSubmission(
            $this->createAssignment($foreign['course'], $foreign['teacher']),
            $foreign['student']
        );
        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $foreignSubmission->id,
            'marks_obtained' => 50,
        ])->assertForbidden();

        $inconsistentSubmission = $this->createSubmission(
            $this->createAssignment($foreign['course'], $foreign['teacher']),
            $completed['student']
        );
        $foreignAdmin = $this->createUserWithRole('institution-admin');
        InstitutionUser::create([
            'institution_id' => $foreign['institution']->id,
            'user_id' => $foreignAdmin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);
        $this->authenticate($foreignAdmin);
        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $inconsistentSubmission->id,
            'marks_obtained' => 50,
        ])->assertForbidden();

        $this->assertDatabaseMissing('assignment_evaluations', [
            'assignment_submission_id' => $inconsistentSubmission->id,
        ]);
    }

    public function test_assignment_writers_lock_courses_before_definitions_and_preserve_submission_history(): void
    {
        $first = $this->createPair();
        $second = $this->createPair($first['institution'], $first['teacher'], createEnrollment: false);
        $locks = $this->recordingLocks();
        $this->authenticate($first['teacher_user']);

        $created = $this->postJson('/api/assignments', [
            'course_id' => $first['course']->id,
            'title' => 'Stage 2B assignment',
            'maximum_marks' => 100,
            'status' => 'published',
        ])->assertCreated();
        $assignmentId = (int) $created->json('data.id');
        $this->assertSame(['course'], array_column($locks->events, 0));

        $locks->events = [];
        $this->patchJson('/api/assignments/' . $assignmentId, [
            'course_id' => $second['course']->id,
            'title' => 'Moved Stage 2B assignment',
        ])->assertOk();

        $this->assertSame(['course', 'course', 'assignment'], array_column($locks->events, 0));
        $lockedCourseIds = array_values(array_map(
            static fn (array $event): int => (int) $event[1],
            array_values(array_filter(
                $locks->events,
                static fn (array $event): bool => $event[0] === 'course'
            ))
        ));
        $expectedCourseIds = [(int) $first['course']->id, (int) $second['course']->id];
        sort($expectedCourseIds, SORT_NUMERIC);
        $this->assertSame($expectedCourseIds, $lockedCourseIds);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignmentId,
            'course_id' => $second['course']->id,
        ]);

        $assignment = Assignment::findOrFail($assignmentId);
        $submission = $this->createSubmission($assignment, $second['student'], 'draft');
        $evaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $first['teacher']->id,
            'marks_obtained' => 65,
            'maximum_marks' => 100,
            'result_status' => 'needs_improvement',
            'evaluated_at' => now(),
        ]);

        $locks->events = [];
        $this->deleteJson('/api/assignments/' . $assignmentId)->assertOk();
        $this->assertSame(['course', 'assignment'], array_column($locks->events, 0));
        $this->assertSoftDeleted('assignments', ['id' => $assignmentId]);
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('assignment_evaluations', [
            'id' => $evaluation->id,
            'deleted_at' => null,
        ]);
    }

    public function test_submission_create_update_and_delete_use_parent_then_evidence_lock_order(): void
    {
        $pair = $this->createPair();
        $assignment = $this->createAssignment($pair['course'], $pair['teacher']);
        $otherPair = $this->createPair($pair['institution'], $pair['teacher']);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['student_user']);

        $created = $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
            'submission_text' => 'Initial draft',
            'status' => 'draft',
        ])->assertCreated();
        $submissionId = (int) $created->json('data.id');
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission_pair'],
            array_column($locks->events, 0)
        );

        $locks->events = [];
        $this->patchJson('/api/assignment-submissions/' . $submissionId, [
            'assignment_id' => $otherPair['course']->id,
            'student_profile_id' => $otherPair['student']->id,
            'submission_text' => 'Updated draft',
        ])->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId,
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
            'submission_text' => 'Updated draft',
        ]);

        $locks->events = [];
        $this->deleteJson('/api/assignment-submissions/' . $submissionId)->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );
        $this->assertSoftDeleted('assignment_submissions', ['id' => $submissionId]);
    }

    public function test_submission_creation_revalidates_enrollment_under_the_shared_locks(): void
    {
        $pair = $this->createPair();
        $assignment = $this->createAssignment($pair['course'], $pair['teacher']);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['student_user']);

        $pair['enrollment']->update(['status' => 'cancelled']);
        $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
            'status' => 'draft',
        ])->assertForbidden();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission_pair'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseMissing('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
        ]);

        $pair['enrollment']->update(['status' => 'completed']);
        $locks->events = [];
        $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $pair['student']->id,
            'status' => 'draft',
        ])->assertCreated();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission_pair'],
            array_column($locks->events, 0)
        );
    }

    public function test_evaluated_submission_cannot_be_updated_or_deleted_and_evaluation_is_preserved(): void
    {
        $pair = $this->createPair();
        $submission = $this->createSubmission(
            $this->createAssignment($pair['course'], $pair['teacher']),
            $pair['student'],
            'returned'
        );
        $evaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $pair['teacher']->id,
            'marks_obtained' => 60,
            'maximum_marks' => 100,
            'result_status' => 'needs_improvement',
            'evaluated_at' => now(),
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['student_user']);

        $this->patchJson('/api/assignment-submissions/' . $submission->id, [
            'submission_text' => 'Must not overwrite evaluated work',
        ])->assertForbidden();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );

        $locks->events = [];
        $this->deleteJson('/api/assignment-submissions/' . $submission->id)->assertForbidden();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'assignment', 'submission', 'evaluations'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'submission_text' => null,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('assignment_evaluations', [
            'id' => $evaluation->id,
            'deleted_at' => null,
        ]);
    }

    public function test_submission_mutation_rolls_back_with_its_callers_transaction(): void
    {
        $pair = $this->createPair();
        $submission = $this->createSubmission(
            $this->createAssignment($pair['course'], $pair['teacher']),
            $pair['student'],
            'draft'
        );
        $this->authenticate($pair['student_user']);

        try {
            DB::transaction(function () use ($submission): void {
                $this->patchJson('/api/assignment-submissions/' . $submission->id, [
                    'submission_text' => 'Transaction-only change',
                ])->assertOk();

                throw new RuntimeException('Force outer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'submission_text' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_outer_rollback_restores_evaluation_submission_and_gradebook_changes(): void
    {
        $pair = $this->createPair();
        $submission = $this->createSubmission(
            $this->createAssignment($pair['course'], $pair['teacher']),
            $pair['student'],
            'reviewed'
        );
        $evaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $pair['teacher']->id,
            'marks_obtained' => 80,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
        $gradebook = Gradebook::create([
            'course_id' => $pair['course']->id,
            'student_profile_id' => $pair['student']->id,
            'assignment_marks' => 1,
            'quiz_marks' => 0,
            'total_marks' => 1,
            'maximum_marks' => 100,
            'percentage' => 1,
            'grade' => 'F',
            'result_status' => 'failed',
        ]);
        $this->authenticate($pair['teacher_user']);

        try {
            DB::transaction(function () use ($pair, $evaluation): void {
                // The gradebook calculation takes the full canonical input lock
                // set before the evaluation writer reuses these same records.
                app(GradebookCalculationService::class)->recalculateWithinTransaction(
                    (int) $pair['course']->id,
                    (int) $pair['student']->id
                );

                $this->patchJson('/api/assignment-evaluations/' . $evaluation->id, [
                    'marks_obtained' => 20,
                ])->assertOk();

                throw new RuntimeException('Force outer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('assignment_evaluations', [
            'id' => $evaluation->id,
            'marks_obtained' => 80,
        ]);
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission->id,
            'status' => 'reviewed',
        ]);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 1,
            'percentage' => 1,
            'result_status' => 'failed',
        ]);
    }

    public function test_enrollment_create_update_reassign_and_delete_use_parent_then_evidence_locks(): void
    {
        $first = $this->createPair(createEnrollment: false);
        $second = $this->createPair($first['institution'], $first['teacher'], createEnrollment: false);
        $actor = $this->createUserWithRole('super-admin');
        $locks = $this->recordingLocks();
        $this->authenticate($actor);

        $created = $this->postJson('/api/course-enrollments', [
            'course_id' => $first['course']->id,
            'student_profile_id' => $first['student']->id,
        ])->assertCreated();
        $createdId = (int) $created->json('data.id');
        $this->assertSame(['course', 'student', 'enrollments'], array_column($locks->events, 0));
        $this->assertSame('active', $created->json('data.status'));

        $this->postJson('/api/course-enrollments', [
            'course_id' => $first['course']->id,
            'student_profile_id' => $first['student']->id,
        ])->assertUnprocessable();

        $locks->events = [];
        $this->patchJson('/api/course-enrollments/' . $createdId, [
            'status' => 'cancelled',
        ])->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $createdId,
            'status' => 'cancelled',
        ]);

        $locks->events = [];
        $this->patchJson('/api/course-enrollments/' . $createdId, [
            'payment_status' => 'paid',
            'amount_paid' => 10,
        ])->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments'],
            array_column($locks->events, 0)
        );

        $locks->events = [];
        $this->patchJson('/api/course-enrollments/' . $createdId, [
            'course_id' => $second['course']->id,
            'student_profile_id' => $second['student']->id,
        ])->assertOk();
        $this->assertSame(
            ['course', 'course', 'student', 'student', 'enrollments'],
            array_column($locks->events, 0)
        );
        $this->assertSame(
            [min($first['course']->id, $second['course']->id), max($first['course']->id, $second['course']->id)],
            array_values(array_map(
                static fn (array $event): int => $event[1],
                array_values(array_filter($locks->events, static fn (array $event): bool => $event[0] === 'course'))
            ))
        );
        $expectedPairs = [
            [
                'course_id' => (int) $first['course']->id,
                'student_profile_id' => (int) $first['student']->id,
            ],
            [
                'course_id' => (int) $second['course']->id,
                'student_profile_id' => (int) $second['student']->id,
            ],
        ];
        usort($expectedPairs, static fn (array $left, array $right): int =>
            [$left['course_id'], $left['student_profile_id']] <=>
            [$right['course_id'], $right['student_profile_id']]
        );
        $this->assertSame($expectedPairs, $locks->events[4][1]);

        $locks->events = [];
        $this->deleteJson('/api/course-enrollments/' . $createdId)->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments'],
            array_column($locks->events, 0)
        );
        $this->assertSoftDeleted('course_enrollments', ['id' => $createdId]);
    }

    public function test_completed_enrollment_cannot_be_deleted_and_outer_rollback_restores_enrollment_and_gradebook(): void
    {
        $pair = $this->createPair();
        $completed = $this->createPair($pair['institution'], $pair['teacher'], 'completed');
        $actor = $this->createUserWithRole('super-admin');
        $this->authenticate($actor);

        $this->deleteJson('/api/course-enrollments/' . $completed['enrollment']->id)
            ->assertUnprocessable();
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $completed['enrollment']->id,
            'deleted_at' => null,
        ]);

        try {
            DB::transaction(function () use ($pair): void {
                $this->patchJson('/api/course-enrollments/' . $pair['enrollment']->id, [
                    'payment_status' => 'paid',
                    'amount_paid' => 20,
                ])->assertOk();

                app(GradebookCalculationService::class)->recalculateWithinTransaction(
                    (int) $pair['course']->id,
                    (int) $pair['student']->id
                );

                throw new RuntimeException('Force outer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('course_enrollments', [
            'id' => $pair['enrollment']->id,
            'payment_status' => 'free',
            'amount_paid' => 0,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('gradebooks', [
            'course_id' => $pair['course']->id,
            'student_profile_id' => $pair['student']->id,
        ]);
    }

    public function test_invalid_cross_institution_enrollment_creation_is_still_rejected(): void
    {
        $first = $this->createPair();
        $foreign = $this->createPair(createEnrollment: false);
        $this->authenticate($this->createUserWithRole('super-admin'));

        $this->postJson('/api/course-enrollments', [
            'course_id' => $first['course']->id,
            'student_profile_id' => $foreign['student']->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $first['course']->id,
            'student_profile_id' => $foreign['student']->id,
        ]);

        $this->patchJson('/api/course-enrollments/' . $first['enrollment']->id, [
            'student_profile_id' => $foreign['student']->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('course_enrollments', [
            'id' => $first['enrollment']->id,
            'course_id' => $first['course']->id,
            'student_profile_id' => $first['student']->id,
        ]);
    }

    private function createPair(
        ?Institution $institution = null,
        ?TeacherProfile $teacher = null,
        string $enrollmentStatus = 'active',
        bool $createEnrollment = true
    ): array {
        $institution ??= Institution::create([
            'name' => 'Assessment Writer ' . Str::random(8),
            'code' => 'AW-' . Str::upper(Str::random(12)),
        ]);

        if (! $teacher) {
            $teacherUser = $this->createUserWithRole('teacher');
            $teacher = TeacherProfile::create([
                'institution_id' => $institution->id,
                'user_id' => $teacherUser->id,
                'status' => 'active',
            ]);
        } else {
            $teacherUser = User::findOrFail($teacher->user_id);
        }

        $studentUser = $this->createUserWithRole('student');
        $student = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'roll_number' => 'AW-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $course = Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Assessment Writer ' . Str::random(8),
            'slug' => 'assessment-writer-' . Str::lower(Str::random(14)),
            'status' => 'published',
        ]);

        $enrollment = $createEnrollment
            ? CourseEnrollment::create([
                'course_id' => $course->id,
                'student_profile_id' => $student->id,
                'enrollment_date' => now()->toDateString(),
                'status' => $enrollmentStatus,
            ])
            : null;

        return [
            'institution' => $institution,
            'teacher_user' => $teacherUser,
            'teacher' => $teacher,
            'student_user' => $studentUser,
            'student' => $student,
            'course' => $course,
            'enrollment' => $enrollment,
        ];
    }

    private function createAssignment(Course $course, TeacherProfile $teacher): Assignment
    {
        return Assignment::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Assignment ' . Str::random(8),
            'maximum_marks' => 100,
            'status' => 'published',
        ]);
    }

    private function createSubmission(
        Assignment $assignment,
        StudentProfile $student,
        string $status = 'submitted'
    ): AssignmentSubmission {
        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'status' => $status,
        ]);
    }

    private function createUserWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'web'],
            ['display_name' => Str::headline($roleName)]
        );
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function recordingLocks(): RecordingCourseAssessmentLockService
    {
        $service = new RecordingCourseAssessmentLockService();
        $this->app->instance(CourseAssessmentLockService::class, $service);

        return $service;
    }
}

final class RecordingCourseAssessmentLockService extends CourseAssessmentLockService
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->events[] = ['course', $courseId];

        return parent::lockCourse($courseId, $withTrashed);
    }

    public function lockStudent(int $studentProfileId): StudentProfile
    {
        $this->events[] = ['student', $studentProfileId];

        return parent::lockStudent($studentProfileId);
    }

    public function lockCourseEnrollments(array $pairs): \Illuminate\Support\Collection
    {
        $this->events[] = ['enrollments', $pairs];

        return parent::lockCourseEnrollments($pairs);
    }

    public function lockEnrollment(int $enrollmentId): CourseEnrollment
    {
        $this->events[] = ['enrollment', $enrollmentId];

        return parent::lockEnrollment($enrollmentId);
    }

    public function lockAssignments(array $ids): \Illuminate\Support\Collection
    {
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $this->events[] = ['assignment', (int) $id];
        }

        return parent::lockAssignments($ids);
    }

    public function lockAssignmentSubmissions(array $ids): \Illuminate\Support\Collection
    {
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $this->events[] = ['submission', (int) $id];
        }

        return parent::lockAssignmentSubmissions($ids);
    }

    public function lockAssignmentSubmissionForPair(
        int $assignmentId,
        int $studentProfileId
    ): ?AssignmentSubmission {
        $this->events[] = ['submission_pair', [$assignmentId, $studentProfileId]];

        return parent::lockAssignmentSubmissionForPair($assignmentId, $studentProfileId);
    }

    public function lockAssignmentEvaluationsForSubmissions(
        array $submissionIds,
        array $evaluationIds = []
    ): \Illuminate\Support\Collection {
        $this->events[] = ['evaluations', [$submissionIds, $evaluationIds]];

        return parent::lockAssignmentEvaluationsForSubmissions($submissionIds, $evaluationIds);
    }
}
