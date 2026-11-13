<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CourseSectionParentWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_locks_and_rereads_course_before_authorization_and_creation(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $owner);
        $locks = new RecordingCourseSectionLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($course, $otherTeacher): void {
            DB::table('courses')
                ->where('id', $courseId)
                ->update(['teacher_profile_id' => $otherTeacher->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->postJson('/api/course-sections', [
            'course_id' => $course->id,
            'title' => 'New section',
        ])->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertNotEmpty($locks->transactionLevels);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
        $this->assertSame([], $locks->sectionIds);
        $this->assertDatabaseMissing('course_sections', [
            'course_id' => $course->id,
            'title' => 'New section',
        ]);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'teacher_profile_id' => $otherTeacher->id,
        ]);
    }

    public function test_store_creates_section_inside_locked_course_transaction(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->postJson('/api/course-sections', [
            'course_id' => $course->id,
            'title' => 'Foundations',
            'description' => 'Course introduction',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('message', 'Course section created successfully.')
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.title', 'Foundations');

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame(['course:' . $course->id], $locks->events);
        $this->assertNotEmpty($locks->transactionLevels);
        $this->assertGreaterThanOrEqual(1, $locks->transactionLevels[0]);
        $this->assertDatabaseHas('course_sections', [
            'course_id' => $course->id,
            'title' => 'Foundations',
        ]);
    }

    public function test_same_course_update_is_allowed_with_lessons_and_locks_course_before_section(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Existing lesson',
        ]);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/course-sections/' . $section->id, [
            'title' => 'Updated section',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated section')
            ->assertJsonPath('data.course_id', $course->id);

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([$section->id], $locks->sectionIds);
        $this->assertSame([
            'course:' . $course->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertGreaterThanOrEqual(1, $locks->sectionTransactionLevels[0]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_section_id' => $section->id,
        ]);
    }

    public function test_section_move_locks_both_courses_in_ascending_order(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $targetCourse = $this->createCourse($institution, $teacher);
        $sourceCourse = $this->createCourse($institution, $teacher);
        $section = $this->createSection($sourceCourse);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/course-sections/' . $section->id, [
            'course_id' => $targetCourse->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id);

        $this->assertLessThan($sourceCourse->id, $targetCourse->id);
        $this->assertSame([
            $targetCourse->id,
            $sourceCourse->id,
        ], $locks->courseIds);
        $this->assertSame([
            'course:' . $targetCourse->id,
            'course:' . $sourceCourse->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'course_id' => $targetCourse->id,
        ]);
    }

    public function test_section_move_rejects_direct_lesson_assignment_quiz_and_live_class_references(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $teacher);
        $targetCourse = $this->createCourse($institution, $teacher);
        $section = $this->createSection($sourceCourse);

        Lesson::create([
            'course_id' => $sourceCourse->id,
            'course_section_id' => $section->id,
            'title' => 'Linked lesson',
        ]);
        Assignment::create([
            'course_id' => $sourceCourse->id,
            'course_section_id' => $section->id,
            'title' => 'Linked assignment',
        ]);
        DB::table('quizzes')->insert([
            'course_id' => $sourceCourse->id,
            'course_section_id' => $section->id,
            'title' => 'Linked quiz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('live_classes')->insert([
            'course_id' => $sourceCourse->id,
            'course_section_id' => $section->id,
            'title' => 'Linked live class',
            'meeting_url' => 'https://example.test/class',
            'scheduled_start_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($teacher->user, 'web');

        $this->patchJson('/api/course-sections/' . $section->id, [
            'course_id' => $targetCourse->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');

        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'course_id' => $sourceCourse->id,
        ]);
    }

    public function test_section_update_rechecks_course_ownership_after_parent_lock(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $owner);
        $section = $this->createSection($course);
        $locks = new RecordingCourseSectionLockService();
        $locks->beforeLockCourse = function (int $courseId) use ($otherTeacher): void {
            DB::table('courses')
                ->where('id', $courseId)
                ->update(['teacher_profile_id' => $otherTeacher->id]);
        };
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->patchJson('/api/course-sections/' . $section->id, [
            'title' => 'Must not update',
        ])->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([$section->id], $locks->sectionIds);
        $this->assertSame([
            'course:' . $course->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'title' => $section->title,
        ]);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'teacher_profile_id' => $otherTeacher->id,
        ]);
    }

    public function test_section_move_rechecks_target_course_authorization_after_locking_both_parents(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $sourceCourse = $this->createCourse($institution, $owner);
        $targetCourse = $this->createCourse($institution, $otherTeacher);
        $section = $this->createSection($sourceCourse);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($owner->user, 'web');

        $this->patchJson('/api/course-sections/' . $section->id, [
            'course_id' => $targetCourse->id,
        ])->assertForbidden();

        $this->assertSame([
            min($sourceCourse->id, $targetCourse->id),
            max($sourceCourse->id, $targetCourse->id),
        ], $locks->courseIds);
        $this->assertSame([$section->id], $locks->sectionIds);
        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'course_id' => $sourceCourse->id,
        ]);
    }

    public function test_delete_rejects_lessons_assignments_quizzes_and_live_classes(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        Lesson::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Linked lesson',
        ]);
        Assignment::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Linked assignment',
        ]);
        DB::table('quizzes')->insert([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Linked quiz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('live_classes')->insert([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Linked live class',
            'meeting_url' => 'https://example.test/class',
            'scheduled_start_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/course-sections/' . $section->id)->assertStatus(422);

        $this->assertSame([
            'course:' . $course->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertNotSoftDeleted('course_sections', ['id' => $section->id]);
    }

    public function test_soft_deleted_direct_dependents_still_prevent_section_deletion(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Soft-deleted lesson',
        ]);
        $lesson->delete();
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Soft-deleted assignment',
        ]);
        $assignment->delete();
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Soft-deleted quiz',
        ]);
        $quiz->delete();
        $liveClassId = DB::table('live_classes')->insertGetId([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'title' => 'Soft-deleted live class',
            'meeting_url' => 'https://example.test/class',
            'scheduled_start_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('live_classes')->where('id', $liveClassId)->update([
            'deleted_at' => now(),
        ]);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/course-sections/' . $section->id)->assertStatus(422);

        $this->assertSoftDeleted('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_section_id' => $section->id,
        ]);
        $this->assertSoftDeleted('assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'course_section_id' => $section->id,
        ]);
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'course_section_id' => $section->id,
        ]);
        $this->assertSoftDeleted('live_classes', ['id' => $liveClassId]);
        $this->assertDatabaseHas('live_classes', [
            'id' => $liveClassId,
            'course_section_id' => $section->id,
        ]);
        $this->assertNotSoftDeleted('course_sections', ['id' => $section->id]);
    }

    public function test_delete_rechecks_authorization_after_course_and_section_locks(): void
    {
        [$institution, $owner, $otherTeacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $owner);
        $section = $this->createSection($course);
        $locks = new RecordingCourseSectionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $locks);
        $this->actingAs($otherTeacher->user, 'web');

        $this->deleteJson('/api/course-sections/' . $section->id)->assertForbidden();

        $this->assertSame([$course->id], $locks->courseIds);
        $this->assertSame([$section->id], $locks->sectionIds);
        $this->assertSame([
            'course:' . $course->id,
            'section:' . $section->id,
        ], $locks->events);
        $this->assertNotSoftDeleted('course_sections', ['id' => $section->id]);
    }

    public function test_delete_without_references_preserves_soft_delete_lifecycle(): void
    {
        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        $this->actingAs($teacher->user, 'web');

        $this->deleteJson('/api/course-sections/' . $section->id)->assertOk()
            ->assertJsonPath('message', 'Course section deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('course_sections', ['id' => $section->id]);
    }

    public function test_section_update_failure_rolls_back_changes(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback regression uses a SQLite test trigger.');
        }

        [$institution, $teacher] = $this->createTeacherPair();
        $course = $this->createCourse($institution, $teacher);
        $section = $this->createSection($course);
        DB::unprepared(
            "CREATE TRIGGER reject_section_title BEFORE UPDATE ON course_sections
            WHEN OLD.id = {$section->id} AND NEW.title = 'Rejected title'
            BEGIN SELECT RAISE(ABORT, 'forced section update failure'); END"
        );
        $this->actingAs($teacher->user, 'web');

        try {
            try {
                DB::transaction(function () use ($section): void {
                    $this->withoutExceptionHandling()->patchJson(
                        '/api/course-sections/' . $section->id,
                        ['title' => 'Rejected title']
                    );
                });
                $this->fail('The section update trigger should reject the write.');
            } catch (QueryException) {
                // The transaction owned by this caller rolls back the failed write.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_section_title');
        }

        $this->assertDatabaseHas('course_sections', [
            'id' => $section->id,
            'title' => $section->title,
        ]);
    }

    /** @return array{Institution, TeacherProfile, TeacherProfile} */
    private function createTeacherPair(): array
    {
        $institution = Institution::create([
            'name' => 'Section Lock ' . Str::random(8),
            'code' => 'SL-' . Str::upper(Str::random(10)),
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

    private function createCourse(
        Institution $institution,
        TeacherProfile $teacher
    ): Course {
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
}

final class RecordingCourseSectionLockService extends CourseAssessmentLockService
{
    /** @var list<int> */
    public array $courseIds = [];

    /** @var list<int> */
    public array $sectionIds = [];

    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<int> */
    public array $sectionTransactionLevels = [];

    public ?\Closure $beforeLockCourse = null;

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $this->courseIds[] = $courseId;
        $this->events[] = 'course:' . $courseId;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->beforeLockCourse !== null) {
            ($this->beforeLockCourse)($courseId);
        }

        return parent::lockCourse($courseId, $withTrashed);
    }

    public function lockCourseSection(
        int $courseSectionId,
        bool $withTrashed = true
    ): CourseSection {
        $this->sectionIds[] = $courseSectionId;
        $this->events[] = 'section:' . $courseSectionId;
        $this->sectionTransactionLevels[] = DB::transactionLevel();

        return parent::lockCourseSection($courseSectionId, $withTrashed);
    }
}
