<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\Gradebook;
use App\Models\Institution;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\UsageStatisticsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CourseParentWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_locks_and_rereads_course_before_calculating_usage_transition(): void
    {
        $institution = $this->createInstitution('LOCKED-UPDATE');
        $admin = $this->createSuperAdmin();
        $this->createSubscription($institution);
        $course = $this->createCourse($institution, status: 'archived');

        $locks = new RecordingCourseAssessmentLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($institution): void {
            // Simulate a transition committed after route binding but before the lock read.
            DB::table('courses')->where('id', $courseId)->update(['status' => 'published']);
            app(UsageStatisticsService::class)->increment(
                institution: $institution->id,
                metric: 'courses',
                amount: 1
            );
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->patchJson('/api/courses/' . $course->id, [
            'status' => 'archived',
        ])->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([false], $locks->withTrashedOptions);
        $this->assertNotEmpty($locks->transactionLevels);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => 'archived',
        ]);
        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => 0,
        ]);
    }

    public function test_update_rejects_cross_institution_relationships_and_allows_consistent_same_institution_ownership_change(): void
    {
        $institution = $this->createInstitution('RELATIONSHIPS-A');
        $otherInstitution = $this->createInstitution('RELATIONSHIPS-B');
        $admin = $this->createSuperAdmin();

        $department = $this->createDepartment($institution);
        $otherDepartment = $this->createDepartment($otherInstitution);
        $batch = $this->createBatch($institution, $department);
        $otherBatch = $this->createBatch($otherInstitution, $otherDepartment);
        $teacher = $this->createTeacherProfile($institution);
        $otherTeacher = $this->createTeacherProfile($otherInstitution);
        $replacementTeacher = $this->createTeacherProfile($institution);

        $course = $this->createCourse(
            $institution,
            teacherProfile: $teacher,
            department: $department,
            batch: $batch,
            status: 'archived'
        );
        Assignment::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Historical assignment',
        ]);
        $transferableCourse = $this->createCourse($institution, status: 'archived');

        $this->actingAs($admin, 'web');

        $this->patchJson('/api/courses/' . $course->id, [
            'department_id' => $otherDepartment->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->patchJson('/api/courses/' . $course->id, [
            'batch_id' => $otherBatch->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('batch_id');

        $this->patchJson('/api/courses/' . $course->id, [
            'teacher_profile_id' => $otherTeacher->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_profile_id');

        $this->patchJson('/api/courses/' . $course->id, [
            'batch_id' => $batch->id,
            'department_id' => $otherDepartment->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['batch_id', 'department_id']);

        $this->patchJson('/api/courses/' . $transferableCourse->id, [
            'institution_id' => $otherInstitution->id,
            'department_id' => $otherDepartment->id,
            'batch_id' => $otherBatch->id,
            'teacher_profile_id' => $otherTeacher->id,
        ])->assertOk()
            ->assertJsonPath('data.institution_id', $otherInstitution->id)
            ->assertJsonPath('data.department_id', $otherDepartment->id)
            ->assertJsonPath('data.batch_id', $otherBatch->id)
            ->assertJsonPath('data.teacher_profile_id', $otherTeacher->id);

        $this->patchJson('/api/courses/' . $course->id, [
            'teacher_profile_id' => $replacementTeacher->id,
        ])->assertOk()
            ->assertJsonPath('data.teacher_profile_id', $replacementTeacher->id);

        $this->assertDatabaseHas('assignments', [
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Historical assignment',
        ]);
    }

    public function test_institution_reassignment_is_rejected_when_soft_deleted_dependents_exist(): void
    {
        $institution = $this->createInstitution('DEPENDENT-SOURCE');
        $otherInstitution = $this->createInstitution('DEPENDENT-TARGET');
        $admin = $this->createSuperAdmin();
        $course = $this->createCourse($institution, status: 'archived');
        $student = $this->createStudent($institution);
        $enrollment = CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $enrollment->delete();

        $this->actingAs($admin, 'web');

        $this->patchJson('/api/courses/' . $course->id, [
            'institution_id' => $otherInstitution->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('institution_id');

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'institution_id' => $institution->id,
        ]);
        $this->assertSoftDeleted('course_enrollments', [
            'id' => $enrollment->id,
        ]);
    }

    public function test_safe_billable_institution_transfer_moves_usage_between_institutions(): void
    {
        $sourceInstitution = $this->createInstitution('TRANSFER-SOURCE');
        $targetInstitution = $this->createInstitution('TRANSFER-TARGET');
        $admin = $this->createSuperAdmin();
        $this->createSubscription($sourceInstitution);
        $this->createSubscription($targetInstitution);
        $course = $this->createCourse($sourceInstitution, status: 'published');
        app(UsageStatisticsService::class)->increment(
            institution: $sourceInstitution->id,
            metric: 'courses',
            amount: 1
        );
        $this->actingAs($admin, 'web');

        $this->patchJson('/api/courses/' . $course->id, [
            'institution_id' => $targetInstitution->id,
        ])->assertOk()
            ->assertJsonPath('data.institution_id', $targetInstitution->id);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $sourceInstitution->id,
            'metric' => 'courses',
            'used_value' => 0,
        ]);
        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $targetInstitution->id,
            'metric' => 'courses',
            'used_value' => 1,
        ]);
    }

    public function test_delete_locks_and_rereads_course_and_preserves_related_records(): void
    {
        $institution = $this->createInstitution('LOCKED-DELETE');
        $admin = $this->createSuperAdmin();
        $course = $this->createCourse($institution);
        $student = $this->createStudent($institution);
        $gradebook = $this->createGradebook($course, $student);
        $certificate = $this->createCertificate($course, $student, $gradebook);
        $locks = new RecordingCourseAssessmentLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($admin, 'web');

        $this->deleteJson('/api/courses/' . $course->id)->assertOk();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([false], $locks->withTrashedOptions);
        $this->assertNotEmpty($locks->transactionLevels);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('gradebooks', [
            'id' => $gradebook->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'deleted_at' => null,
            'course_id' => $course->id,
        ]);
    }

    public function test_delete_rechecks_authorization_after_locking_course(): void
    {
        $institution = $this->createInstitution('DELETE-AUTH');
        $owner = $this->createTeacherProfile($institution, assignRole: true);
        $otherTeacher = $this->createTeacherProfile($institution, assignRole: true);
        $course = $this->createCourse($institution, teacherProfile: $owner);
        $locks = new RecordingCourseAssessmentLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($otherTeacher->user, 'web');

        $this->deleteJson('/api/courses/' . $course->id)->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'deleted_at' => null,
        ]);
    }

    public function test_failed_usage_accounting_leaves_course_status_unchanged(): void
    {
        $institution = $this->createInstitution('USAGE-FAILURE');
        $admin = $this->createSuperAdmin();
        $course = $this->createCourse($institution, status: 'published');
        $this->actingAs($admin, 'web');

        try {
            $this->withoutExceptionHandling()->patchJson('/api/courses/' . $course->id, [
                'status' => 'archived',
            ]);
            $this->fail('Missing usage accounting should reject the transition.');
        } catch (\App\Exceptions\DomainException) {
            // Expected: the transaction exits before the course update.
        }

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => 'published',
        ]);
    }

    public function test_course_update_failure_rolls_back_usage_adjustment(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        $institution = $this->createInstitution('UPDATE-FAILURE');
        $admin = $this->createSuperAdmin();
        $this->createSubscription($institution);
        $course = $this->createCourse($institution, status: 'published');
        app(UsageStatisticsService::class)->increment(
            institution: $institution->id,
            metric: 'courses',
            amount: 1
        );
        DB::unprepared(
            "CREATE TRIGGER reject_course_archive BEFORE UPDATE ON courses
            WHEN OLD.id = {$course->id} AND NEW.status = 'archived'
            BEGIN SELECT RAISE(ABORT, 'forced course update failure'); END"
        );
        $this->actingAs($admin, 'web');

        try {
            try {
                DB::transaction(function () use ($course): void {
                    $this->withoutExceptionHandling()->patchJson('/api/courses/' . $course->id, [
                        'status' => 'archived',
                    ]);
                });
                $this->fail('The course update trigger should reject the write.');
            } catch (QueryException) {
                // Expected: the usage decrement and course update share one transaction.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_course_archive');
        }

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => 1,
        ]);
    }

    private function createInstitution(string $suffix): Institution
    {
        return Institution::create([
            'name' => 'Course Parent ' . $suffix,
            'code' => Str::upper($suffix) . '-' . Str::upper(Str::random(6)),
        ]);
    }

    private function createSuperAdmin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ], [
            'display_name' => 'Super Admin',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createTeacherProfile(
        Institution $institution,
        bool $assignRole = false
    ): TeacherProfile {
        $user = User::factory()->create();

        if ($assignRole) {
            $role = Role::firstOrCreate([
                'name' => 'teacher',
                'guard_name' => 'web',
            ], [
                'display_name' => 'Teacher',
            ]);
            $user->assignRole($role);
        }

        $teacherProfile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'employee_code' => 'EMP-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $teacherProfile->setRelation('user', $user);

        return $teacherProfile;
    }

    private function createDepartment(Institution $institution): Department
    {
        return Department::create([
            'institution_id' => $institution->id,
            'name' => 'Department ' . Str::random(5),
            'code' => 'DEP-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function createBatch(Institution $institution, Department $department): Batch
    {
        return Batch::create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'name' => 'Batch ' . Str::random(5),
            'code' => 'BAT-' . Str::upper(Str::random(8)),
            'mode' => 'online',
            'status' => 'active',
        ]);
    }

    private function createCourse(
        Institution $institution,
        ?TeacherProfile $teacherProfile = null,
        ?Department $department = null,
        ?Batch $batch = null,
        string $status = 'draft'
    ): Course {
        return Course::create([
            'institution_id' => $institution->id,
            'department_id' => $department?->id,
            'batch_id' => $batch?->id,
            'teacher_profile_id' => $teacherProfile?->id,
            'title' => 'Course ' . Str::random(8),
            'slug' => 'course-' . Str::lower(Str::random(12)),
            'status' => $status,
        ]);
    }

    private function createStudent(Institution $institution): StudentProfile
    {
        return StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'roll_number' => 'ROLL-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function createGradebook(Course $course, StudentProfile $student): Gradebook
    {
        return Gradebook::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'assignment_marks' => 80,
            'quiz_marks' => 75,
            'total_marks' => 155,
            'maximum_marks' => 200,
            'percentage' => 77.5,
            'grade' => 'B',
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
            'certificate_number' => 'EDURA-' . Str::upper(Str::random(10)),
            'certificate_uuid' => (string) Str::uuid(),
            'verification_token' => Str::upper(Str::random(16)),
            'issued_date' => now()->toDateString(),
            'final_percentage' => $gradebook->percentage,
            'final_grade' => $gradebook->grade,
            'status' => 'issued',
            'verification_status' => 'valid',
        ]);
    }

    private function createSubscription(Institution $institution): Subscription
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Course Parent Test Plan',
            'code' => 'CPT-' . Str::upper(Str::random(8)),
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 20,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'included_ai_credits' => 100,
            'status' => 'active',
        ]);

        return Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }
}

final class RecordingCourseAssessmentLockService extends CourseAssessmentLockService
{
    /** @var list<int> */
    public array $courseIds = [];

    /** @var list<bool> */
    public array $withTrashedOptions = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?\Closure $beforeLockCourse = null;

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->courseIds[] = $courseId;
        $this->withTrashedOptions[] = $withTrashed;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->beforeLockCourse !== null) {
            ($this->beforeLockCourse)($courseId);
        }

        return parent::lockCourse($courseId, $withTrashed);
    }
}
