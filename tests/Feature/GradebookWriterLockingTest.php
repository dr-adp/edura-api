<?php

namespace Tests\Feature;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\Institution;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\GradebookCalculationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GradebookWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_direct_update_locks_course_student_enrollment_and_gradebook(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $admin = $this->createUserWithRole('super-admin');
        $locks = new RecordingGradebookWriterLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->patchJson('/api/gradebooks/' . $gradebook->id, [
            'assignment_marks' => 45,
        ])->assertOk()
            ->assertJsonPath('message', 'Gradebook record updated successfully.')
            ->assertJsonPath('data.assignment_marks', '45.00')
            ->assertJsonPath('data.total_marks', '60.00')
            ->assertJsonPath('data.percentage', '60.00')
            ->assertJsonPath('data.grade', 'B')
            ->assertJsonPath('data.result_status', 'passed');

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertSame([1, 1, 1, 1], $locks->transactionLevels);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 45,
            'quiz_marks' => 15,
            'total_marks' => 60,
            'maximum_marks' => 100,
            'percentage' => 60,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);
    }

    public function test_direct_update_rechecks_super_admin_authorization_after_acquiring_locks(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $admin = $this->createUserWithRole('super-admin');
        $locks = new RecordingGradebookWriterLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($admin): void {
            $admin->removeRole('super-admin');
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->patchJson('/api/gradebooks/' . $gradebook->id, [
            'assignment_marks' => 45,
        ])->assertForbidden();

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 25,
            'total_marks' => 40,
        ]);
    }

    public function test_teacher_mark_edits_remain_rejected_after_locked_authorization(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $locks = new RecordingGradebookWriterLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($pair['teacher']->user, 'web');

        $this->patchJson('/api/gradebooks/' . $gradebook->id, [
            'assignment_marks' => 45,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('gradebook');

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 25,
            'total_marks' => 40,
        ]);
    }

    public function test_direct_update_requests_retry_if_gradebook_relationship_changes_after_discovery(): void
    {
        $pair = $this->createCourseStudentPair();
        $targetCourse = $this->createCourse($pair['institution'], $pair['teacher']);
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $admin = $this->createUserWithRole('super-admin');
        $locks = new RecordingGradebookWriterLockService();
        $locks->beforeLockGradebooks = function () use ($gradebook, $targetCourse): void {
            DB::table('gradebooks')
                ->where('id', $gradebook->id)
                ->update(['course_id' => $targetCourse->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        try {
            DB::transaction(function () use ($gradebook): void {
                $this->withoutExceptionHandling()->patchJson(
                    '/api/gradebooks/' . $gradebook->id,
                    ['assignment_marks' => 45]
                );
            });
            $this->fail('Changed gradebook relationships must request a full lock-set retry.');
        } catch (AssessmentLockSetChanged $exception) {
            $this->assertSame(
                [min($pair['course']->id, $targetCourse->id), max($pair['course']->id, $targetCourse->id)],
                $exception->courseIds
            );
            $this->assertSame([(int) $pair['student']->id], $exception->studentProfileIds);
        }

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'course_id' => $pair['course']->id,
            'assignment_marks' => 25,
        ]);
    }

    public function test_direct_gradebook_deletion_locks_in_order_and_preserves_related_records(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $certificate = $this->createCertificate($pair['course'], $pair['student'], $gradebook);
        $admin = $this->createUserWithRole('super-admin');
        $locks = new RecordingGradebookWriterLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->deleteJson('/api/gradebooks/' . $gradebook->id)->assertOk()
            ->assertJsonPath('message', 'Gradebook record deleted successfully.');

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertSame([1, 1, 1, 1], $locks->transactionLevels);
        $this->assertSoftDeleted('gradebooks', ['id' => $gradebook->id]);
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'gradebook_id' => $gradebook->id,
            'deleted_at' => null,
            'final_percentage' => 80,
            'final_grade' => 'B',
        ]);
    }

    public function test_direct_deletion_rechecks_teacher_ownership_after_acquiring_locks(): void
    {
        $pair = $this->createCourseStudentPair();
        $replacementTeacher = $this->createTeacher($pair['institution']);
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $locks = new RecordingGradebookWriterLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($pair, $replacementTeacher): void {
            DB::table('courses')
                ->where('id', $pair['course']->id)
                ->update(['teacher_profile_id' => $replacementTeacher->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($pair['teacher']->user, 'web');

        $this->deleteJson('/api/gradebooks/' . $gradebook->id)->assertForbidden();

        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
        $this->assertNotSoftDeleted('gradebooks', ['id' => $gradebook->id]);
    }

    public function test_direct_mutation_and_recalculation_share_the_course_first_gate(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $admin = $this->createUserWithRole('super-admin');
        $locks = new RecordingGradebookWriterLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->patchJson('/api/gradebooks/' . $gradebook->id, [
            'assignment_marks' => 40,
        ])->assertOk();
        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);

        $locks->events = [];
        app(GradebookCalculationService::class)->recalculate(
            (int) $pair['course']->id,
            (int) $pair['student']->id
        );
        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
        ], array_slice($locks->events, 0, 3));

        $locks->events = [];
        $this->deleteJson('/api/gradebooks/' . $gradebook->id)->assertOk();
        $this->assertSame([
            'course:' . $pair['course']->id,
            'student:' . $pair['student']->id,
            'enrollment:' . $pair['course']->id . ':' . $pair['student']->id,
            'gradebook:' . $gradebook->id,
        ], $locks->events);
    }

    public function test_recalculation_updates_the_existing_active_gradebook_row(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);

        $recalculated = app(GradebookCalculationService::class)->recalculate(
            (int) $pair['course']->id,
            (int) $pair['student']->id
        );

        $this->assertSame($gradebook->id, $recalculated->id);
        $this->assertSame('0.00', $recalculated->total_marks);
        $this->assertSame('pending', $recalculated->result_status);
        $this->assertNotSoftDeleted('gradebooks', ['id' => $gradebook->id]);
        $this->assertSame(1, DB::table('gradebooks')
            ->where('course_id', $pair['course']->id)
            ->where('student_profile_id', $pair['student']->id)
            ->count());
    }

    public function test_recalculation_restores_the_locked_soft_deleted_row_and_preserves_certificate_snapshot(): void
    {
        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $certificate = $this->createCertificate($pair['course'], $pair['student'], $gradebook);
        $gradebook->delete();

        $recalculated = app(GradebookCalculationService::class)->recalculate(
            (int) $pair['course']->id,
            (int) $pair['student']->id
        );

        $this->assertSame($gradebook->id, $recalculated->id);
        $this->assertNotSoftDeleted('gradebooks', ['id' => $gradebook->id]);
        $this->assertSame('0.00', $recalculated->total_marks);
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'gradebook_id' => $gradebook->id,
            'final_percentage' => 80,
            'final_grade' => 'B',
            'deleted_at' => null,
        ]);
        $this->assertSame(1, DB::table('gradebooks')
            ->where('course_id', $pair['course']->id)
            ->where('student_profile_id', $pair['student']->id)
            ->count());
    }

    public function test_recalculation_failure_rolls_back_soft_deleted_gradebook_restoration(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $gradebook->delete();
        DB::unprepared(
            "CREATE TRIGGER reject_gradebook_restore BEFORE UPDATE ON gradebooks
            WHEN OLD.id = {$gradebook->id} AND NEW.deleted_at IS NULL
            BEGIN SELECT RAISE(ABORT, 'forced gradebook restore failure'); END"
        );

        try {
            try {
                DB::transaction(function () use ($pair): void {
                    app(GradebookCalculationService::class)->recalculateWithinTransaction(
                        (int) $pair['course']->id,
                        (int) $pair['student']->id
                    );
                });
                $this->fail('The gradebook restore trigger should reject recalculation.');
            } catch (QueryException) {
                // The caller-owned transaction rolls restoration back with calculation.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_gradebook_restore');
        }

        $this->assertSoftDeleted('gradebooks', ['id' => $gradebook->id]);
        $this->assertSame(1, DB::table('gradebooks')
            ->where('course_id', $pair['course']->id)
            ->where('student_profile_id', $pair['student']->id)
            ->count());
    }

    public function test_direct_gradebook_update_failure_rolls_back_the_mark_change(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        $pair = $this->createCourseStudentPair();
        $gradebook = $this->createGradebook($pair['course'], $pair['student']);
        $admin = $this->createUserWithRole('super-admin');
        DB::unprepared(
            "CREATE TRIGGER reject_manual_gradebook_update BEFORE UPDATE ON gradebooks
            WHEN OLD.id = {$gradebook->id} AND NEW.assignment_marks = 45
            BEGIN SELECT RAISE(ABORT, 'forced manual gradebook update failure'); END"
        );
        $this->actingAs($admin, 'web');

        try {
            try {
                DB::transaction(function () use ($gradebook): void {
                    $this->withoutExceptionHandling()->patchJson(
                        '/api/gradebooks/' . $gradebook->id,
                        ['assignment_marks' => 45]
                    );
                });
                $this->fail('The gradebook update trigger should reject the write.');
            } catch (QueryException) {
                // The caller-owned transaction rolls back the failed direct update.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_manual_gradebook_update');
        }

        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'assignment_marks' => 25,
            'total_marks' => 40,
        ]);
    }

    /** @return array{institution:Institution, teacher:TeacherProfile, student:StudentProfile, course:Course, enrollment:CourseEnrollment} */
    private function createCourseStudentPair(): array
    {
        $institution = Institution::create([
            'name' => 'Gradebook Writer ' . Str::random(8),
            'code' => 'GBW-' . Str::upper(Str::random(8)),
        ]);
        $teacher = $this->createTeacher($institution);
        $student = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'roll_number' => 'GBW-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $course = Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Gradebook Writer Course ' . Str::random(8),
            'slug' => 'gradebook-writer-' . Str::lower(Str::random(12)),
            'status' => 'published',
        ]);
        $enrollment = CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);

        return compact('institution', 'teacher', 'student', 'course', 'enrollment');
    }

    private function createTeacher(Institution $institution): TeacherProfile
    {
        $user = $this->createUserWithRole('teacher');
        $teacher = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'employee_code' => 'GBW-EMP-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $teacher->setRelation('user', $user);

        return $teacher;
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ], [
            'display_name' => Str::headline($roleName),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createCourse(Institution $institution, TeacherProfile $teacher): Course
    {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Gradebook Target Course ' . Str::random(8),
            'slug' => 'gradebook-target-' . Str::lower(Str::random(12)),
            'status' => 'published',
        ]);
    }

    private function createGradebook(Course $course, StudentProfile $student): Gradebook
    {
        return Gradebook::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'assignment_marks' => 25,
            'quiz_marks' => 15,
            'total_marks' => 40,
            'maximum_marks' => 100,
            'percentage' => 40,
            'grade' => 'D',
            'result_status' => 'passed',
        ]);
    }

    private function createCertificate(
        Course $course,
        StudentProfile $student,
        Gradebook $gradebook
    ): Certificate {
        return Certificate::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'gradebook_id' => $gradebook->id,
            'certificate_number' => 'GBW-CERT-' . Str::upper(Str::random(8)),
            'certificate_uuid' => (string) Str::uuid(),
            'verification_token' => Str::upper(Str::random(16)),
            'issued_date' => now()->toDateString(),
            'final_percentage' => 80,
            'final_grade' => 'B',
            'status' => 'issued',
            'verification_status' => 'valid',
        ]);
    }
}

final class RecordingGradebookWriterLockService extends CourseAssessmentLockService
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?\Closure $beforeLockCourse = null;

    public ?\Closure $beforeLockGradebooks = null;

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->record('course:' . $courseId);

        if ($this->beforeLockCourse !== null) {
            ($this->beforeLockCourse)($courseId);
        }

        return parent::lockCourse($courseId, $withTrashed);
    }

    public function lockStudent(int $studentProfileId): StudentProfile
    {
        $this->record('student:' . $studentProfileId);

        return parent::lockStudent($studentProfileId);
    }

    public function lockCourseEnrollments(array $pairs): \Illuminate\Support\Collection
    {
        foreach ($pairs as $pair) {
            $this->record(
                'enrollment:' . (int) $pair['course_id'] . ':' . (int) $pair['student_profile_id']
            );
        }

        return parent::lockCourseEnrollments($pairs);
    }

    public function lockGradebooks(array $ids): \Illuminate\Support\Collection
    {
        foreach ($ids as $id) {
            $this->record('gradebook:' . (int) $id);
        }

        if ($this->beforeLockGradebooks !== null) {
            ($this->beforeLockGradebooks)();
        }

        return parent::lockGradebooks($ids);
    }

    private function record(string $event): void
    {
        $this->events[] = $event;
        $this->transactionLevels[] = DB::transactionLevel();
    }
}
