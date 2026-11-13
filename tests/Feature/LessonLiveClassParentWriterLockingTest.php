<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LiveClass;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\CourseEnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LessonLiveClassParentWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_creation_locks_course_and_rechecks_current_owner(): void
    {
        [$institution, $owner, $replacementOwner] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $owner);
        $locks = new RecordingLessonLiveClassLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($replacementOwner): void {
            DB::table('courses')
                ->where('id', $courseId)
                ->update(['teacher_profile_id' => $replacementOwner->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Must not be created',
        ])->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([false], $locks->courseWithTrashed);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
        $this->assertDatabaseMissing('lessons', [
            'course_id' => $course->id,
            'title' => 'Must not be created',
        ]);
    }

    public function test_published_lesson_creation_recalculates_enrollments_inside_the_same_transaction(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $student = $this->createStudent($institution);
        $enrollment = $this->createEnrollment($course, $student);
        $enrollment->update(['progress_percentage' => 25]);
        $locks = new RecordingLessonLiveClassLockService();
        $enrollmentService = new RecordingLessonEnrollmentService($locks);
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->app->instance(CourseEnrollmentService::class, $enrollmentService);
        $this->actingAs($teacher->user, 'web');

        $response = $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'New published lesson',
            'status' => 'published',
        ])->assertCreated()
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.status', 'published');

        $lessonId = (int) $response->json('data.id');
        $this->assertSame([$course->id], $enrollmentService->recalculatedCourseIds);
        $this->assertGreaterThanOrEqual(1, $enrollmentService->transactionLevels[0]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'course_id' => $course->id,
        ]);
        $this->assertSame(0.0, (float) $enrollment->fresh()->progress_percentage);
    }

    public function test_lesson_move_locks_old_and_target_courses_in_sorted_order_before_lesson(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $targetCourse = $this->createCourse($institution, $teacher);
        $sourceCourse = $this->createCourse($institution, $teacher);
        $sourceSection = $this->createSection($sourceCourse);
        $targetSection = $this->createSection($targetCourse);
        $lesson = $this->createLesson($sourceCourse, $sourceSection, 'Moving lesson');
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
            'course_section_id' => $targetSection->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id)
            ->assertJsonPath('data.course_section_id', $targetSection->id);

        $this->assertLessThan($sourceCourse->id, $targetCourse->id);
        $this->assertSame([
            $targetCourse->id,
            $sourceCourse->id,
        ], array_slice($locks->courseIds, 0, 2));
        $this->assertSame([
            'course:' . $targetCourse->id,
            'course:' . $sourceCourse->id,
            'lesson:' . $lesson->id,
        ], array_slice($locks->events, 0, 3));
    }

    public function test_lesson_move_rechecks_target_course_authorization_after_both_locks(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $owner);
        $targetCourse = $this->createCourse($institution, $otherTeacher);
        $lesson = $this->createLesson($sourceCourse, null, 'Unauthorized move');
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertForbidden();

        $this->assertSame([
            min($sourceCourse->id, $targetCourse->id),
            max($sourceCourse->id, $targetCourse->id),
        ], array_slice($locks->courseIds, 0, 2));
        $this->assertSame([
            'course:' . min($sourceCourse->id, $targetCourse->id),
            'course:' . max($sourceCourse->id, $targetCourse->id),
            'lesson:' . $lesson->id,
        ], array_slice($locks->events, 0, 3));
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $sourceCourse->id,
        ]);
    }

    public function test_lesson_move_rejects_linked_assessments_in_the_source_course(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $teacher);
        $targetCourse = $this->createCourse($institution, $teacher);
        $lesson = $this->createLesson($sourceCourse, null, 'Lesson with assignment');
        $assignment = Assignment::create([
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
            'title' => 'Attached assignment',
        ]);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $sourceCourse->id,
        ]);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_lesson_move_checks_soft_deleted_assessment_references(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $teacher);
        $targetCourse = $this->createCourse($institution, $teacher);
        $lesson = $this->createLesson($sourceCourse, null, 'Lesson with archived quiz');
        $quiz = Quiz::create([
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
            'title' => 'Soft-deleted quiz',
        ]);
        $quiz->delete();
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertStatus(422);

        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'lesson_id' => $lesson->id,
            'course_id' => $sourceCourse->id,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $sourceCourse->id,
        ]);
    }

    public function test_lesson_delete_preserves_soft_deleted_assessment_references(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $lesson = $this->createLesson($course, null, 'Lesson to delete');
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'Historical quiz',
        ]);
        $quiz->delete();
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/lessons/' . $lesson->id)->assertOk()
            ->assertJsonPath('message', 'Lesson deleted successfully.');

        $this->assertSame([
            'course:' . $course->id,
            'lesson:' . $lesson->id,
        ], $locks->events);
        $this->assertSoftDeleted('lessons', ['id' => $lesson->id]);
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_lesson_creation_rolls_back_when_progress_recalculation_fails(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $student = $this->createStudent($institution);
        $enrollment = $this->createEnrollment($course, $student);
        $enrollment->update(['progress_percentage' => 25]);
        DB::unprepared(
            "CREATE TRIGGER reject_lesson_progress_recalculation
            BEFORE UPDATE ON course_enrollments
            WHEN OLD.id = {$enrollment->id}
            BEGIN SELECT RAISE(ABORT, 'forced enrollment progress failure'); END"
        );
        $this->actingAs($teacher->user, 'web');

        try {
            try {
                DB::transaction(function () use ($course): void {
                    $this->withoutExceptionHandling()->postJson('/api/lessons', [
                        'course_id' => $course->id,
                        'title' => 'Published lesson that must roll back',
                        'status' => 'published',
                    ]);
                });
                $this->fail('Progress recalculation should fail through the trigger.');
            } catch (QueryException) {
                // The caller-owned transaction rolls back both writes together.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_lesson_progress_recalculation');
        }

        $this->assertDatabaseMissing('lessons', [
            'course_id' => $course->id,
            'title' => 'Published lesson that must roll back',
        ]);
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'progress_percentage' => 25,
        ]);
    }

    public function test_live_class_creation_locks_course_and_validates_section_and_lesson(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $otherCourse = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        $foreignSection = $this->createSection($otherCourse);
        $lesson = $this->createLesson($course, $section, 'Scheduled lesson');
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/live-classes', $this->liveClassAttributes(
            $course,
            $foreignSection->id,
            $lesson->id
        ))->assertStatus(422);

        $this->postJson('/api/live-classes', $this->liveClassAttributes(
            $course,
            $section->id,
            $lesson->id
        ))->assertCreated()
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.course_section_id', $section->id)
            ->assertJsonPath('data.lesson_id', $lesson->id);

        $this->assertSame([$course->id, $course->id], $locks->courseIds);
        $this->assertSame([false, false], $locks->courseWithTrashed);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
    }

    public function test_live_class_creation_rechecks_current_course_owner_after_locking(): void
    {
        [$institution, $owner, $replacementOwner] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $owner);
        $locks = new RecordingLessonLiveClassLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($replacementOwner): void {
            DB::table('courses')
                ->where('id', $courseId)
                ->update(['teacher_profile_id' => $replacementOwner->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->postJson('/api/live-classes', $this->liveClassAttributes(
            $course,
            null,
            null
        ))->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertDatabaseMissing('live_classes', [
            'course_id' => $course->id,
            'title' => 'Scheduled live class',
        ]);
    }

    public function test_live_class_update_locks_course_before_live_class(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/live-classes/' . $liveClass->id, [
            'title' => 'Updated class',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated class');

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
        ], $locks->events);
        $this->assertGreaterThanOrEqual(1, $locks->liveClassTransactionLevels[0]);
    }

    public function test_live_class_move_locks_both_courses_sorted_and_validates_target_links(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $targetCourse = $this->createCourse($institution, $teacher);
        $sourceCourse = $this->createCourse($institution, $teacher);
        $targetSection = $this->createSection($targetCourse);
        $targetLesson = $this->createLesson($targetCourse, $targetSection, 'Target lesson');
        $sourceSection = $this->createSection($sourceCourse);
        $sourceLesson = $this->createLesson($sourceCourse, $sourceSection, 'Source lesson');
        $liveClass = $this->createLiveClass($sourceCourse, $teacher, $sourceSection, $sourceLesson);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/live-classes/' . $liveClass->id, [
            'course_id' => $targetCourse->id,
            'course_section_id' => $targetSection->id,
            'lesson_id' => $targetLesson->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id)
            ->assertJsonPath('data.institution_id', $institution->id)
            ->assertJsonPath('data.course_section_id', $targetSection->id)
            ->assertJsonPath('data.lesson_id', $targetLesson->id);

        $this->assertLessThan($sourceCourse->id, $targetCourse->id);
        $this->assertSame([
            $targetCourse->id,
            $sourceCourse->id,
        ], array_slice($locks->courseIds, 0, 2));
        $this->assertSame([
            'course:' . $targetCourse->id,
            'course:' . $sourceCourse->id,
            'live-class:' . $liveClass->id,
        ], array_slice($locks->events, 0, 3));
    }

    public function test_live_class_move_rechecks_target_course_authorization_after_both_locks(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $owner);
        $targetCourse = $this->createCourse($institution, $otherTeacher);
        $liveClass = $this->createLiveClass($sourceCourse, $owner);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->patchJson('/api/live-classes/' . $liveClass->id, [
            'course_id' => $targetCourse->id,
            'course_section_id' => null,
            'lesson_id' => null,
        ])->assertForbidden();

        $this->assertSame([
            min($sourceCourse->id, $targetCourse->id),
            max($sourceCourse->id, $targetCourse->id),
        ], array_slice($locks->courseIds, 0, 2));
        $this->assertSame([
            'course:' . min($sourceCourse->id, $targetCourse->id),
            'course:' . max($sourceCourse->id, $targetCourse->id),
            'live-class:' . $liveClass->id,
        ], array_slice($locks->events, 0, 3));
        $this->assertDatabaseHas('live_classes', [
            'id' => $liveClass->id,
            'course_id' => $sourceCourse->id,
        ]);
    }

    public function test_live_class_delete_preserves_soft_delete_behavior(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $liveClass = $this->createLiveClass($course, $teacher);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/live-classes/' . $liveClass->id)->assertOk()
            ->assertJsonPath('message', 'Live class deleted successfully.');

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
        ], $locks->events);
        $this->assertSoftDeleted('live_classes', ['id' => $liveClass->id]);
    }

    public function test_live_class_delete_still_rejects_existing_attendance(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $student = $this->createStudent($institution);
        $liveClass = $this->createLiveClass($course, $teacher);
        DB::table('live_class_attendances')->insert([
            'live_class_id' => $liveClass->id,
            'student_profile_id' => $student->id,
            'attendance_status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/live-classes/' . $liveClass->id)->assertStatus(422);

        $this->assertSame([
            'course:' . $course->id,
            'live-class:' . $liveClass->id,
        ], $locks->events);
        $this->assertNotSoftDeleted('live_classes', ['id' => $liveClass->id]);
    }

    public function test_lesson_and_live_class_writers_share_course_gate_with_section_deletion(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        $locks = new RecordingLessonLiveClassLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $lessonId = $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Section owner lesson',
        ])->assertCreated()->json('data.id');
        $liveClassId = $this->postJson('/api/live-classes', $this->liveClassAttributes(
            $course,
            $section->id,
            $lessonId
        ))->assertCreated()->json('data.id');

        $this->deleteJson('/api/course-sections/' . $section->id)->assertStatus(422);

        $this->assertSame([
            'course:' . $course->id,
            'course:' . $course->id,
            'course:' . $course->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'course_section_id' => $section->id,
        ]);
        $this->assertDatabaseHas('live_classes', [
            'id' => $liveClassId,
            'course_section_id' => $section->id,
        ]);
        $this->assertNotSoftDeleted('course_sections', ['id' => $section->id]);
    }

    private function createTeacherPair(): array
    {
        $institution = Institution::create([
            'name' => 'Parent Writer ' . Str::random(8),
            'code' => 'PW-' . Str::upper(Str::random(10)),
        ]);

        return [
            $institution,
            $this->createTeacherProfile($institution),
            $this->createTeacherProfile($institution),
        ];
    }

    private function createTeacherProfile(Institution $institution): TeacherProfile
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

        $profile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'employee_code' => 'EMP-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $profile->setRelation('user', $user);

        return $profile;
    }

    private function createCourse(Institution $institution, TeacherProfile $teacher): Course
    {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Course ' . Str::random(8),
            'slug' => 'course-' . Str::lower(Str::random(12)),
            'status' => 'draft',
        ]);
    }

    private function createSection(Course $course): CourseSection
    {
        return CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Section ' . Str::random(6),
            'sort_order' => 1,
            'status' => 'active',
        ]);
    }

    private function createLesson(
        Course $course,
        ?CourseSection $section,
        string $title,
        string $status = 'draft'
    ): Lesson {
        return Lesson::create([
            'course_id' => $course->id,
            'course_section_id' => $section?->id,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function createLiveClass(
        Course $course,
        TeacherProfile $teacher,
        ?CourseSection $section = null,
        ?Lesson $lesson = null
    ): LiveClass {
        return LiveClass::create([
            'institution_id' => $course->institution_id,
            'course_id' => $course->id,
            'course_section_id' => $section?->id,
            'lesson_id' => $lesson?->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Live class ' . Str::random(8),
            'meeting_url' => 'https://example.test/' . Str::random(8),
            'scheduled_start_time' => now()->addDay(),
        ]);
    }

    private function liveClassAttributes(
        Course $course,
        ?int $sectionId,
        ?int $lessonId
    ): array {
        return [
            'course_id' => $course->id,
            'course_section_id' => $sectionId,
            'lesson_id' => $lessonId,
            'title' => 'Scheduled live class',
            'meeting_url' => 'https://example.test/scheduled',
            'scheduled_start_time' => now()->addDay()->toDateTimeString(),
        ];
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

    private function createEnrollment(Course $course, StudentProfile $student): CourseEnrollment
    {
        return CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'progress_percentage' => 0,
        ]);
    }
}

final class RecordingLessonLiveClassLockService extends CourseAssessmentLockService
{
    /** @var list<int> */
    public array $courseIds = [];

    /** @var list<bool> */
    public array $courseWithTrashed = [];

    /** @var list<int> */
    public array $lessonIds = [];

    /** @var list<int> */
    public array $liveClassIds = [];

    /** @var list<int> */
    public array $sectionIds = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<int> */
    public array $lessonTransactionLevels = [];

    /** @var list<int> */
    public array $liveClassTransactionLevels = [];

    /** @var list<string> */
    public array $events = [];

    public ?\Closure $beforeLockCourse = null;

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->courseIds[] = $courseId;
        $this->courseWithTrashed[] = $withTrashed;
        $this->events[] = 'course:' . $courseId;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->beforeLockCourse !== null) {
            ($this->beforeLockCourse)($courseId);
        }

        return parent::lockCourse($courseId, $withTrashed);
    }

    public function lockLesson(int $lessonId, bool $withTrashed = true): Lesson
    {
        $this->lessonIds[] = $lessonId;
        $this->lessonTransactionLevels[] = DB::transactionLevel();
        $this->events[] = 'lesson:' . $lessonId;

        return parent::lockLesson($lessonId, $withTrashed);
    }

    public function lockLiveClass(
        int $liveClassId,
        bool $withTrashed = true
    ): LiveClass {
        $this->liveClassIds[] = $liveClassId;
        $this->liveClassTransactionLevels[] = DB::transactionLevel();
        $this->events[] = 'live-class:' . $liveClassId;

        return parent::lockLiveClass($liveClassId, $withTrashed);
    }

    public function lockCourseSection(
        int $courseSectionId,
        bool $withTrashed = true
    ): CourseSection {
        $this->sectionIds[] = $courseSectionId;
        $this->events[] = 'section:' . $courseSectionId;

        return parent::lockCourseSection($courseSectionId, $withTrashed);
    }
}

final class RecordingLessonEnrollmentService extends CourseEnrollmentService
{
    /** @var list<int> */
    public array $recalculatedCourseIds = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function recalculateCourseEnrollments(int $courseId): void
    {
        $this->recalculatedCourseIds[] = $courseId;
        $this->transactionLevels[] = DB::transactionLevel();

        parent::recalculateCourseEnrollments($courseId);
    }
}
