<?php

namespace Tests\Feature;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Course;
use App\Models\Institution;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LiveClassAttendanceLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_locks_course_live_class_and_student_before_creating_attendance(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $locks = new RecordingLiveClassAttendanceLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $response = $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
            'remarks' => 'Joined on time',
        ])->assertCreated()
            ->assertJsonPath('message', 'Live class attendance recorded successfully.')
            ->assertJsonPath('data.live_class_id', $liveClass->id)
            ->assertJsonPath('data.student_profile_id', $student->id)
            ->assertJsonPath('data.attendance_status', 'present');

        $this->assertNotNull($response->json('data.id'));
        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
            'attendance-pair:' . $liveClass->id . ':' . $student->id,
        ], $locks->events);
        $this->assertSame(4, count(array_filter(
            $locks->transactionLevels,
            static fn (int $level): bool => $level >= 1
        )));
        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ]);
        // Existing behavior does not require a course enrollment to record class attendance.
        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
        ]);
    }

    public function test_store_revalidates_teacher_authorization_after_locking_current_live_class(): void
    {
        [$institution, $teacher, $replacementTeacher] = $this->createInstitutionAndTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass, $replacementTeacher): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'teacher_profile_id' => $replacementTeacher->id,
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ])->assertForbidden();

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
        ], $locks->events);
        $this->assertDatabaseMissing('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
        ]);
    }

    public function test_store_revalidates_institution_consistency_and_cancelled_status_after_locks(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $otherInstitution = $this->createInstitution();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $foreignStudent = $this->createStudent($otherInstitution);
        $locks = new RecordingLiveClassAttendanceLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $foreignStudent->id,
            'attendance_status' => 'present',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $foreignStudent->id,
        ]);

        $student = $this->createStudent($institution);
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'status' => 'cancelled',
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ])->assertStatus(422);

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $foreignStudent->id,
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
        ], $locks->events);
        $this->assertDatabaseMissing('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
        ]);
    }

    public function test_store_rechecks_duplicate_pair_after_request_validation(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass, $student): void {
            LiveClassAttendance::create([
                'live_class_id' => $liveClass->id,
                'student_profile_id' => $student->id,
                'attendance_status' => 'present',
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'late',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('student_profile_id');

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
            'attendance-pair:' . $liveClass->id . ':' . $student->id,
        ], $locks->events);
        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ]);
        $this->assertSame(1, LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('student_profile_id', $student->id)
            ->count());
    }

    public function test_store_preserves_unique_pair_behavior_for_soft_deleted_attendance(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $attendance->delete();
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'late',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('student_profile_id');

        $this->assertSoftDeleted('live_class_attendances', ['id' => $attendance->id]);
        $this->assertSame(1, DB::table('live_class_attendances')
            ->where('live_class_id', $liveClass->id)
            ->where('student_profile_id', $student->id)
            ->count());
    }

    public function test_store_does_not_create_attendance_if_live_class_was_deleted_before_its_locked_reread(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'deleted_at' => now(),
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-class-attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ])->assertNotFound();

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
        ], $locks->events);
        $this->assertDatabaseMissing('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
        ]);
    }

    public function test_update_locks_course_live_class_student_and_attendance_then_updates_allowed_fields(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $locks = new RecordingLiveClassAttendanceLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/live-class-attendances/' . $attendance->id, [
            'attendance_status' => 'late',
            'remarks' => 'Arrived after the session began',
            'student_profile_id' => 999999,
        ])->assertOk()
            ->assertJsonPath('message', 'Live class attendance updated successfully.')
            ->assertJsonPath('data.attendance_status', 'late')
            ->assertJsonPath('data.student_profile_id', $student->id)
            ->assertJsonPath('data.remarks', 'Arrived after the session began');

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
            'attendance:' . $attendance->id,
        ], $locks->events);
        $this->assertSame(4, count(array_filter(
            $locks->transactionLevels,
            static fn (int $level): bool => $level >= 1
        )));
        $this->assertDatabaseHas('live_class_attendances', [
            'id' => $attendance->id,
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'late',
            'remarks' => 'Arrived after the session began',
        ]);
    }

    public function test_update_revalidates_authorization_and_cancelled_status_after_locks(): void
    {
        [$institution, $teacher, $replacementTeacher] = $this->createInstitutionAndTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass, $replacementTeacher): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'teacher_profile_id' => $replacementTeacher->id,
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/live-class-attendances/' . $attendance->id, [
            'attendance_status' => 'late',
        ])->assertForbidden();

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
            'attendance:' . $attendance->id,
        ], $locks->events);
        $this->assertDatabaseHas('live_class_attendances', [
            'id' => $attendance->id,
            'attendance_status' => 'present',
        ]);

        $locks->beforeLockCourse = null;
        DB::table('live_classes')->where('id', $liveClass->id)->update([
            'teacher_profile_id' => $teacher->id,
            'status' => 'cancelled',
        ]);
        $this->patchJson('/api/live-class-attendances/' . $attendance->id, [
            'attendance_status' => 'late',
        ])->assertStatus(422);

        $this->assertDatabaseHas('live_class_attendances', [
            'id' => $attendance->id,
            'attendance_status' => 'present',
        ]);
    }

    public function test_update_detects_live_class_movement_after_discovering_old_course(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $targetCourse = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass, $targetCourse): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'course_id' => $targetCourse->id,
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        try {
            DB::transaction(function () use ($attendance): void {
                $this->withoutExceptionHandling()->patchJson(
                    '/api/live-class-attendances/' . $attendance->id,
                    ['attendance_status' => 'late']
                );
            });
            $this->fail('A changed course relationship must request a full lock-set retry.');
        } catch (AssessmentLockSetChanged $exception) {
            $this->assertSame(
                [min($course->id, $targetCourse->id), max($course->id, $targetCourse->id)],
                $exception->courseIds
            );
        }

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
        ], $locks->events);
        $this->assertDatabaseHas('live_class_attendances', [
            'id' => $attendance->id,
            'attendance_status' => 'present',
        ]);
    }

    public function test_destroy_locks_course_live_class_student_and_attendance_before_soft_deleting(): void
    {
        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $locks = new RecordingLiveClassAttendanceLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/live-class-attendances/' . $attendance->id)->assertOk()
            ->assertJsonPath('message', 'Live class attendance deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
            'student:' . $student->id,
            'attendance:' . $attendance->id,
        ], $locks->events);
        $this->assertSoftDeleted('live_class_attendances', ['id' => $attendance->id]);
    }

    public function test_destroy_revalidates_authorization_and_rejects_cancelled_class(): void
    {
        [$institution, $teacher, $replacementTeacher] = $this->createInstitutionAndTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        $locks = new RecordingLiveClassAttendanceLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($liveClass, $replacementTeacher): void {
            DB::table('live_classes')->where('id', $liveClass->id)->update([
                'teacher_profile_id' => $replacementTeacher->id,
            ]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/live-class-attendances/' . $attendance->id)->assertForbidden();
        $this->assertNotSoftDeleted('live_class_attendances', ['id' => $attendance->id]);

        $locks->beforeLockCourse = null;
        DB::table('live_classes')->where('id', $liveClass->id)->update([
            'teacher_profile_id' => $teacher->id,
            'status' => 'cancelled',
        ]);
        $this->deleteJson('/api/live-class-attendances/' . $attendance->id)->assertStatus(422);
        $this->assertNotSoftDeleted('live_class_attendances', ['id' => $attendance->id]);
    }

    public function test_failed_attendance_update_rolls_back_the_record_change(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        [$institution, $teacher] = $this->createInstitutionAndTeacher();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $student = $this->createStudent($institution);
        $attendance = $this->createAttendance($liveClass, $student);
        DB::unprepared(
            "CREATE TRIGGER reject_attendance_update BEFORE UPDATE ON live_class_attendances
            WHEN OLD.id = {$attendance->id} AND NEW.attendance_status = 'late'
            BEGIN SELECT RAISE(ABORT, 'forced attendance update failure'); END"
        );
        $this->actingAs($teacher->user, 'web');

        try {
            try {
                DB::transaction(function () use ($attendance): void {
                    $this->withoutExceptionHandling()->patchJson(
                        '/api/live-class-attendances/' . $attendance->id,
                        ['attendance_status' => 'late']
                    );
                });
                $this->fail('The attendance update trigger should reject the write.');
            } catch (QueryException) {
                // The write failure exits the same transaction that owns the locks.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_attendance_update');
        }

        $this->assertDatabaseHas('live_class_attendances', [
            'id' => $attendance->id,
            'attendance_status' => 'present',
        ]);
        $this->assertNotSoftDeleted('live_class_attendances', ['id' => $attendance->id]);
    }

    /** @return array{Institution, TeacherProfile} */
    private function createInstitutionAndTeacher(): array
    {
        $institution = $this->createInstitution();

        return [$institution, $this->createTeacher($institution)];
    }

    /** @return array{Institution, TeacherProfile, TeacherProfile} */
    private function createInstitutionAndTeacherPair(): array
    {
        $institution = $this->createInstitution();

        return [
            $institution,
            $this->createTeacher($institution),
            $this->createTeacher($institution),
        ];
    }

    private function createInstitution(): Institution
    {
        return Institution::create([
            'name' => 'Attendance Lock ' . Str::random(8),
            'code' => 'AL-' . Str::upper(Str::random(10)),
        ]);
    }

    private function createTeacher(Institution $institution): TeacherProfile
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate([
            'name' => 'teacher',
            'guard_name' => 'web',
        ], [
            'display_name' => 'Teacher',
        ]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $teacher = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'employee_code' => 'AL-EMP-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $teacher->setRelation('user', $user);

        return $teacher;
    }

    private function createCourse(
        Institution $institution,
        TeacherProfile $teacher
    ): Course {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Attendance Course ' . Str::random(8),
            'slug' => 'attendance-' . Str::lower(Str::random(12)),
            'status' => 'draft',
        ]);
    }

    private function createLiveClass(Course $course, TeacherProfile $teacher): LiveClass
    {
        return LiveClass::create([
            'institution_id' => $course->institution_id,
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Attendance live class ' . Str::random(8),
            'meeting_url' => 'https://example.test/' . Str::random(8),
            'scheduled_start_time' => now()->addDay(),
        ]);
    }

    private function createStudent(Institution $institution): StudentProfile
    {
        return StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'roll_number' => 'AL-ROLL-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function createAttendance(
        LiveClass $liveClass,
        StudentProfile $student
    ): LiveClassAttendance {
        return LiveClassAttendance::create([
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
        ]);
    }
}

final class RecordingLiveClassAttendanceLockService extends CourseAssessmentLockService
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?\Closure $beforeLockCourse = null;

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->record('course:' . $courseId);

        if ($this->beforeLockCourse !== null) {
            ($this->beforeLockCourse)($courseId);
        }

        return parent::lockCourse($courseId, $withTrashed);
    }

    public function lockLiveClass(
        int $liveClassId,
        bool $withTrashed = true
    ): LiveClass {
        $this->record('live-class:' . $liveClassId);

        return parent::lockLiveClass($liveClassId, $withTrashed);
    }

    public function lockStudent(int $studentProfileId): StudentProfile
    {
        $this->record('student:' . $studentProfileId);

        return parent::lockStudent($studentProfileId);
    }

    public function lockLiveClassAttendance(
        int $attendanceId,
        bool $withTrashed = false
    ): LiveClassAttendance {
        $this->record('attendance:' . $attendanceId);

        return parent::lockLiveClassAttendance($attendanceId, $withTrashed);
    }

    public function lockLiveClassAttendancePair(
        int $liveClassId,
        int $studentProfileId
    ): ?LiveClassAttendance {
        $this->record('attendance-pair:' . $liveClassId . ':' . $studentProfileId);

        return parent::lockLiveClassAttendancePair($liveClassId, $studentProfileId);
    }

    private function record(string $event): void
    {
        $this->events[] = $event;
        $this->transactionLevels[] = DB::transactionLevel();
    }
}
