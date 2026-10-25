<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\ParentProfile;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Models\Gradebook;

class ImmediateSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_user_can_have_multiple_parent_profiles(): void
    {
        $institution = $this->createInstitution('PARENT-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $parentProfiles = $parentUser->parentProfiles;

        $this->assertCount(2, $parentProfiles);
        $this->assertEqualsCanonicalizing(
            [$firstStudent->id, $secondStudent->id],
            $parentProfiles->pluck('student_profile_id')->all()
        );
    }


    public function test_parent_dashboard_supports_multiple_children_and_blocks_unauthorized_children(): void
    {
        $institution = $this->createInstitution('PARENT-DASHBOARD');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveLinkedStudent] = $this->createStudent($institution);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveLinkedStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $this->getJson('/api/parent-dashboard/children')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $firstStudent->id])
            ->assertJsonFragment(['id' => $secondStudent->id])
            ->assertJsonMissing(['id' => $inactiveLinkedStudent->id]);

        $this->getJson('/api/parent-dashboard/' . $firstStudent->id)
            ->assertOk();

        $this->getJson('/api/parent-dashboard/' . $secondStudent->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $unrelatedStudent->id),
            'parent accessing an unrelated child dashboard'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $inactiveLinkedStudent->id),
            'parent accessing a child through an inactive link'
        );
    }

    public function test_parent_gradebook_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('GRADEBOOK-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveStudent] = $this->createStudent($institution);

        [, $teacher] = $this->createTeacher($institution);

        $firstCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-child-one'
        );

        $secondCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-child-two'
        );

        $unrelatedCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-unrelated'
        );

        $inactiveCourse = $this->createCourse(
            $institution,
            $teacher,
            'gradebook-inactive-child'
        );

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);
        $this->createEnrollment($unrelatedCourse, $unrelatedStudent);
        $this->createEnrollment($inactiveCourse, $inactiveStudent);

        $firstGradebook = Gradebook::create([
            'course_id' => $firstCourse->id,
            'student_profile_id' => $firstStudent->id,
            'assignment_marks' => 40,
            'quiz_marks' => 40,
            'total_marks' => 80,
            'maximum_marks' => 100,
            'percentage' => 80,
            'grade' => 'A',
            'result_status' => 'passed',
        ]);

        $secondGradebook = Gradebook::create([
            'course_id' => $secondCourse->id,
            'student_profile_id' => $secondStudent->id,
            'assignment_marks' => 35,
            'quiz_marks' => 35,
            'total_marks' => 70,
            'maximum_marks' => 100,
            'percentage' => 70,
            'grade' => 'B+',
            'result_status' => 'passed',
        ]);

        $unrelatedGradebook = Gradebook::create([
            'course_id' => $unrelatedCourse->id,
            'student_profile_id' => $unrelatedStudent->id,
            'assignment_marks' => 30,
            'quiz_marks' => 30,
            'total_marks' => 60,
            'maximum_marks' => 100,
            'percentage' => 60,
            'grade' => 'B',
            'result_status' => 'passed',
        ]);

        $inactiveGradebook = \App\Models\Gradebook::create([
            'course_id' => $inactiveCourse->id,
            'student_profile_id' => $inactiveStudent->id,
            'assignment_marks' => 20,
            'quiz_marks' => 20,
            'total_marks' => 40,
            'maximum_marks' => 100,
            'percentage' => 40,
            'grade' => 'D',
            'result_status' => 'passed',
        ]);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        // Parent can list gradebooks for all active linked children.
        $this->getJson('/api/gradebooks')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonFragment(['id' => $firstGradebook->id])
            ->assertJsonFragment(['id' => $secondGradebook->id])
            ->assertJsonMissing(['id' => $unrelatedGradebook->id])
            ->assertJsonMissing(['id' => $inactiveGradebook->id]);

        // Parent can view the first active linked child's gradebook.
        $this->getJson('/api/gradebooks/' . $firstGradebook->id)
            ->assertOk();

        // Parent can view the second active linked child's gradebook.
        $this->getJson('/api/gradebooks/' . $secondGradebook->id)
            ->assertOk();

        // Parent cannot view an unrelated child's gradebook.
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $unrelatedGradebook->id),
            'parent reading unrelated child gradebook'
        );

        // Parent cannot view a gradebook through an inactive parent link.
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $inactiveGradebook->id),
            'parent reading inactive linked child gradebook'
        );
    }

    public function test_non_parent_cannot_access_parent_dashboard(): void
    {
        $institution = $this->createInstitution('NON-PARENT-DASHBOARD');

        [, $student] = $this->createStudent($institution);

        $studentUser = User::findOrFail($student->user_id);

        $this->authenticate($studentUser);

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/children'),
            'student accessing the parent children endpoint'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $student->id),
            'student accessing the parent dashboard'
        );
    }


    public function test_certificate_access_is_scoped_for_every_restricted_role(): void
    {
        $firstInstitution = $this->createInstitution('CERT-A');
        $secondInstitution = $this->createInstitution('CERT-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'certificate-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'certificate-b');

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);

        $firstCertificate = $this->createCertificate($firstCourse, $firstStudent, 'CERT-A-001');
        $secondCertificate = $this->createCertificate($secondCourse, $secondStudent, 'CERT-B-001');
        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/my-certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'student reading another certificate'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->patchJson('/api/certificates/' . $firstCertificate->id, [
                'status' => 'revoked',
            ]),
            'parent updating a certificate'
        );

        $this->authenticate($firstTeacherUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'teacher reading another course certificate'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstCertificate->id);
        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $secondCertificate->id),
            'institution admin reading another institution certificate'
        );
    }

    public function test_parent_certificate_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('CERT-MULTI');

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);

        [, $teacher] = $this->createTeacher($institution);

        $firstCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-child-one'
        );

        $secondCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-child-two'
        );

        $unrelatedCourse = $this->createCourse(
            $institution,
            $teacher,
            'certificate-unrelated'
        );

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);
        $this->createEnrollment($unrelatedCourse, $unrelatedStudent);

        $firstCertificate = $this->createCertificate(
            $firstCourse,
            $firstStudent,
            'CERT-MULTI-001'
        );

        $secondCertificate = $this->createCertificate(
            $secondCourse,
            $secondStudent,
            'CERT-MULTI-002'
        );

        $unrelatedCertificate = $this->createCertificate(
            $unrelatedCourse,
            $unrelatedStudent,
            'CERT-MULTI-003'
        );

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        $inactiveStudent = $this->createStudent($institution)[1];

        $inactiveCertificate = $this->createCertificate(
            $firstCourse,
            $inactiveStudent,
            'CERT-MULTI-004'
        );

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $this->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonFragment(['id' => $firstCertificate->id])
            ->assertJsonFragment(['id' => $secondCertificate->id])
            ->assertJsonMissing(['id' => $unrelatedCertificate->id])
            ->assertJsonMissing(['id' => $inactiveCertificate->id]);

        $this->getJson('/api/certificates/' . $firstCertificate->id)
            ->assertOk();

        $this->getJson('/api/certificates/' . $secondCertificate->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $unrelatedCertificate->id),
            'parent reading unrelated child certificate'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/certificates/' . $inactiveCertificate->id),
            'parent reading inactive linked child certificate'
        );
    }

    public function test_lesson_progress_enforces_ownership_and_parent_read_only_access(): void
    {
        $firstInstitution = $this->createInstitution('PROGRESS-A');
        $secondInstitution = $this->createInstitution('PROGRESS-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'progress-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'progress-b');
        $firstEnrollment = $this->createEnrollment($firstCourse, $firstStudent);
        $secondEnrollment = $this->createEnrollment($secondCourse, $secondStudent);
        $firstLesson = $this->createLesson($firstCourse, 'First lesson');
        $secondLesson = $this->createLesson($secondCourse, 'Second lesson');
        $firstProgress = $this->createProgress($firstEnrollment, $firstLesson);
        $secondProgress = $this->createProgress($secondEnrollment, $secondLesson);
        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $firstProgress->id);
        $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
            'status' => 'completed',
        ])->assertUnprocessable();
        $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');
        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->authenticate($firstStudentUser);
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $secondProgress->id, [
                'status' => 'completed',
            ]),
            'student updating another student progress'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $secondProgress->id . '/complete'),
            'student completing another student lesson progress'
        );

        $this->authenticate($parentUser);
        $this->getJson('/api/lesson-progress/' . $firstProgress->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating child progress'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $firstProgress->id . '/complete'),
            'parent completing child lesson progress'
        );

        $this->authenticate($firstTeacherUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $secondProgress->id),
            'teacher reading another course progress'
        );

        $this->authenticate($adminUser);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $secondProgress->id),
            'institution admin reading another institution progress'
        );
    }

    public function test_publishing_and_unpublishing_lessons_recalculates_all_course_enrollments(): void
    {
        $institution = $this->createInstitution('LESSON-PUBLICATION-PROGRESS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'lesson-publication-progress');
        $publishedLesson = $this->createLesson($course, 'Existing published lesson');
        $firstEnrollment = $this->createEnrollment($course, $firstStudent);
        $secondEnrollment = $this->createEnrollment($course, $secondStudent);

        $firstProgress = $this->createProgress($firstEnrollment, $publishedLesson);
        $firstProgress->update(['status' => 'completed']);
        $secondProgress = $this->createProgress($secondEnrollment, $publishedLesson);
        $secondProgress->update(['status' => 'completed']);

        $enrollmentService = app(CourseEnrollmentService::class);
        $enrollmentService->recalculateProgress($firstEnrollment);
        $enrollmentService->recalculateProgress($secondEnrollment);

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('completed', $enrollment->fresh()->status);
            $this->assertNotNull($enrollment->fresh()->completed_at);
        }

        $this->authenticate($adminUser);
        $newLessonId = $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'New published lesson',
            'status' => 'published',
        ])->assertCreated()
            ->json('data.id');

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('active', $enrollment->fresh()->status);
            $this->assertNull($enrollment->fresh()->completed_at);
        }

        $this->patchJson('/api/lessons/' . $newLessonId, [
            'status' => 'draft',
        ])->assertOk();

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('completed', $enrollment->fresh()->status);
            $this->assertNotNull($enrollment->fresh()->completed_at);
        }

        $this->patchJson('/api/lessons/' . $newLessonId, [
            'status' => 'published',
        ])->assertOk();

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
            $this->assertSame('active', $enrollment->fresh()->status);
            $this->assertNull($enrollment->fresh()->completed_at);
        }
    }

    public function test_moving_and_deleting_a_published_lesson_recalculates_affected_courses(): void
    {
        $institution = $this->createInstitution('LESSON-COURSE-MOVE-PROGRESS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [, $sourceStudent] = $this->createStudent($institution);
        [, $targetStudent] = $this->createStudent($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-move-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-move-target');
        $sourceCompletedLesson = $this->createLesson($sourceCourse, 'Source completed lesson');
        $movingLesson = $this->createLesson($sourceCourse, 'Moving lesson');
        $targetCompletedLesson = $this->createLesson($targetCourse, 'Target completed lesson');
        $sourceEnrollment = $this->createEnrollment($sourceCourse, $sourceStudent);
        $targetEnrollment = $this->createEnrollment($targetCourse, $targetStudent);

        $sourceProgress = $this->createProgress($sourceEnrollment, $sourceCompletedLesson);
        $sourceProgress->update(['status' => 'completed']);
        $targetProgress = $this->createProgress($targetEnrollment, $targetCompletedLesson);
        $targetProgress->update(['status' => 'completed']);

        $enrollmentService = app(CourseEnrollmentService::class);
        $enrollmentService->recalculateProgress($sourceEnrollment);
        $enrollmentService->recalculateProgress($targetEnrollment);

        $this->assertSame(50.0, (float) $sourceEnrollment->fresh()->progress_percentage);
        $this->assertSame('active', $sourceEnrollment->fresh()->status);
        $this->assertNull($sourceEnrollment->fresh()->completed_at);
        $this->assertSame(100.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $targetEnrollment->fresh()->status);
        $this->assertNotNull($targetEnrollment->fresh()->completed_at);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $movingLesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertOk();

        $this->assertSame(100.0, (float) $sourceEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $sourceEnrollment->fresh()->status);
        $this->assertNotNull($sourceEnrollment->fresh()->completed_at);
        $this->assertSame(50.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('active', $targetEnrollment->fresh()->status);
        $this->assertNull($targetEnrollment->fresh()->completed_at);

        $this->deleteJson('/api/lessons/' . $movingLesson->id)->assertOk();

        $this->assertSoftDeleted('lessons', ['id' => $movingLesson->id]);
        $this->assertSame(100.0, (float) $targetEnrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $targetEnrollment->fresh()->status);
        $this->assertNotNull($targetEnrollment->fresh()->completed_at);
    }

    public function test_lesson_move_rejects_assessments_that_would_remain_in_the_source_course(): void
    {
        $institution = $this->createInstitution('LESSON-MOVE-ASSESSMENTS');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-assessment-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-assessment-target');
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $lesson = $this->createLesson($sourceCourse, 'Lesson with published assessments');
        $lesson->update(['course_section_id' => $sourceSection->id]);
        $quiz = Quiz::create([
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Source lesson quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $assignment = $this->createAssignment(
            $sourceCourse,
            $teacher,
            'Source lesson assignment'
        );
        $assignment->update(['lesson_id' => $lesson->id]);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'course_id' => $sourceCourse->id,
            'course_section_id' => $sourceSection->id,
        ]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
        ]);
        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'course_id' => $sourceCourse->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_moved_lesson_clears_foreign_section_and_target_assessments_still_require_evidence(): void
    {
        $institution = $this->createInstitution('LESSON-MOVE-TARGET-EVIDENCE');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $sourceCourse = $this->createCourse($institution, $teacher, 'lesson-section-source');
        $targetCourse = $this->createCourse($institution, $teacher, 'lesson-section-target');
        $enrollment = $this->createEnrollment($targetCourse, $student);
        $sourceSection = CourseSection::create([
            'course_id' => $sourceCourse->id,
            'title' => 'Source-only section',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        $lesson = $this->createLesson($sourceCourse, 'Moved assessment lesson');
        $lesson->update(['course_section_id' => $sourceSection->id]);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'course_id' => $targetCourse->id,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $targetCourse->id)
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.course_section', null);

        $quiz = Quiz::create([
            'course_id' => $targetCourse->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Target lesson quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $assignment = $this->createAssignment(
            $targetCourse,
            $teacher,
            'Target lesson assignment'
        );
        $assignment->update(['lesson_id' => $lesson->id]);

        // Simulate a pre-existing stale section reference and verify it is not serialized to students.
        $lesson->update(['course_section_id' => $sourceSection->id]);
        $this->authenticate($studentUser);
        $this->getJson('/api/student-courses/' . $targetCourse->id . '/lessons')
            ->assertOk()
            ->assertJsonPath('data.0.course_section_id', null)
            ->assertJsonPath('data.0.course_section', null);
        $this->getJson('/api/student-courses/' . $targetCourse->id . '/lessons/' . $lesson->id)
            ->assertOk()
            ->assertJsonPath('data.course_section_id', null)
            ->assertJsonPath('data.course_section', null);

        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();
        $progressId = $progressResponse->json('data.id');

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'submission_text' => 'Target-course assignment response',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_students_cannot_forge_lesson_completion_and_staff_can_complete_and_recalculate_progress(): void
    {
        $institution = $this->createInstitution('SECURE-COMPLETION');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'secure-completion');
        $enrollment = $this->createEnrollment($course, $student);
        $otherEnrollment = $this->createEnrollment($course, $otherStudent);
        $lesson = $this->createLesson($course, 'Reading lesson');
        $otherLesson = $this->createLesson($course, 'Other student lesson');
        $this->createLesson($course, 'Third lesson');
        $otherProgress = $this->createProgress($otherEnrollment, $otherLesson);

        $this->authenticate($studentUser);

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'progress_percentage' => 100,
            'status' => 'completed',
        ])->assertForbidden();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'progress_percentage' => 100,
            'status' => 'completed',
        ])->assertForbidden();

        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 35,
        ])->assertCreated()
            ->assertJsonPath('data.progress_percentage', '35.00');

        $progress = LessonProgress::where('course_enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'progress_percentage' => 100,
        ])->assertUnprocessable();

        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress/' . $otherProgress->id . '/complete'),
            'student completing another student progress'
        );

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percentage', '100.00');

        $this->assertSame(
            33.33,
            (float) $enrollment->fresh()->progress_percentage
        );
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);

        $secondProgress = $this->createProgress($enrollment, $otherLesson);
        $thirdProgress = $this->createProgress(
            $enrollment,
            Lesson::where('course_id', $course->id)
                ->where('title', 'Third lesson')
                ->firstOrFail()
        );

        $this->postJson('/api/lesson-progress/' . $secondProgress->id . '/complete')
            ->assertOk();
        $this->assertSame(66.67, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);

        $this->postJson('/api/lesson-progress/' . $thirdProgress->id . '/complete')
            ->assertOk();
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->completed_at);

        $this->authenticate($adminUser);
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'progress_percentage' => 0,
            'status' => 'active',
        ])->assertOk();
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);

        $this->deleteJson('/api/lesson-progress/' . $thirdProgress->id)->assertOk();
        $this->assertSame(66.67, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);
    }

    public function test_students_cannot_reassign_a_completed_progress_record_to_another_lesson(): void
    {
        $institution = $this->createInstitution('COMPLETED-LESSON-REASSIGNMENT');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'completed-lesson-reassignment');
        $enrollment = $this->createEnrollment($course, $student);
        $completedLesson = $this->createLesson($course, 'Completed lesson');
        $targetLesson = $this->createLesson($course, 'Target lesson');
        $progress = $this->createProgress($enrollment, $completedLesson);

        $this->authenticate($adminUser);
        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);

        $this->authenticate($studentUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'lesson_id' => $targetLesson->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_id');

        $this->assertSame(
            (int) $completedLesson->id,
            (int) $progress->fresh()->lesson_id
        );
        $this->assertSame('completed', $progress->fresh()->status);
        $this->assertSame(
            0,
            LessonProgress::where('course_enrollment_id', $enrollment->id)
                ->where('lesson_id', $targetLesson->id)
                ->where('status', 'completed')
                ->count()
        );
        $this->assertSame(50.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_student_reopening_completed_lesson_resets_progress_and_enrollment_completion(): void
    {
        $institution = $this->createInstitution('REOPEN-COMPLETED-LESSON');
        [, $teacher] = $this->createTeacher($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'reopen-completed-lesson');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Reopened lesson');
        $progress = $this->createProgress($enrollment, $lesson);

        $this->authenticate($adminUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'status' => 'completed',
            'progress_percentage' => 35,
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percentage', '100.00');

        $progress->refresh();
        $this->assertNotNull($progress->completed_at);
        $this->assertSame(100.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('completed', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->completed_at);

        $this->authenticate($studentUser);
        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percentage', '0.00')
            ->assertJsonPath('data.completed_at', null);

        $progress->refresh();
        $this->assertSame('in_progress', $progress->status);
        $this->assertSame(0.0, (float) $progress->progress_percentage);
        $this->assertNull($progress->completed_at);
        $this->assertSame(0.0, (float) $enrollment->fresh()->progress_percentage);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->completed_at);

        $this->patchJson('/api/lesson-progress/' . $progress->id, [
            'progress_percentage' => 35,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percentage', '35.00')
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_attached_published_assessments_require_server_recorded_evidence_for_lesson_completion(): void
    {
        $institution = $this->createInstitution('ASSESSMENT-COMPLETION');
        [, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'assessment-completion');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Assessment lesson');
        $assignment = $this->createAssignment($course, $teacher, 'Lesson assignment');
        $assignment->update(['lesson_id' => $lesson->id]);
        $urlAssignment = $this->createAssignment($course, $teacher, 'URL assignment');
        $urlAssignment->update(['lesson_id' => $lesson->id]);
        $fileAssignment = $this->createAssignment($course, $teacher, 'File assignment');
        $fileAssignment->update(['lesson_id' => $lesson->id]);
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Lesson quiz',
            'passing_marks' => 1,
            'total_marks' => 1,
            'status' => 'published',
        ]);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();

        $progress = LessonProgress::where('course_enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $emptySubmissionId = $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $assignment->id,
            'student_profile_id' => $student->id,
            'status' => 'submitted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.submission_text', null)
            ->assertJsonPath('data.external_url', null)
            ->assertJsonPath('data.file_path', null)
            ->json('data.id');

        $url = 'https://example.test/lesson-assignment';
        $this->postJson('/api/assignment-submissions', [
            'assignment_id' => $urlAssignment->id,
            'student_profile_id' => $student->id,
            'external_url' => $url,
            'status' => 'submitted',
        ])->assertCreated();

        Storage::fake('public');
        $fileResponse = $this->post('/api/assignment-submissions', [
            'assignment_id' => $fileAssignment->id,
            'student_profile_id' => $student->id,
            'file' => UploadedFile::fake()->create('solution.pdf', 5, 'application/pdf'),
            'status' => 'submitted',
        ], ['Accept' => 'application/json'])->assertCreated();
        $filePath = $fileResponse->json('data.file_path');
        $this->assertStringStartsWith('assignment-submissions/', $filePath);
        Storage::disk('public')->assertExists($filePath);

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->patchJson('/api/assignment-submissions/' . $emptySubmissionId, [
            'submission_text' => 'My completed response',
        ])->assertOk()
            ->assertJsonPath('data.submission_text', 'My completed response');

        $this->postJson('/api/lesson-progress/' . $progress->id . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_manual_quiz_attempt_stays_pending_until_every_manual_answer_is_graded(): void
    {
        $institution = $this->createInstitution('MANUAL-QUIZ-GRADING');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'manual-quiz-grading');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Manual quiz lesson');

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Manual grading quiz',
            'total_marks' => 10,
            'passing_marks' => 4,
            'show_result_immediately' => true,
            'status' => 'published',
        ]);

        $shortAnswer = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the first concept.',
            'question_type' => 'short_answer',
            'marks' => 4,
            'status' => 'active',
        ]);
        $longAnswer = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the second concept.',
            'question_type' => 'long_answer',
            'marks' => 4,
            'status' => 'active',
        ]);
        $multipleChoice = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 2,
            'status' => 'active',
        ]);

        foreach ([$shortAnswer, $longAnswer, $multipleChoice] as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => $question->marks,
                'sort_order' => $index + 1,
            ]);
        }

        $correctOption = QuestionOption::create([
            'question_bank_id' => $multipleChoice->id,
            'option_text' => 'Correct option',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $this->authenticate($studentUser);
        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();
        $progressId = $progressResponse->json('data.id');

        $attemptResponse = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
        ])->assertCreated();
        $attemptId = $attemptResponse->json('data.id');

        $shortAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $shortAnswer->id,
            'answer_text' => 'First submitted response',
        ])->assertCreated()->json('data.id');
        $longAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $longAnswer->id,
            'answer_text' => 'Second submitted response',
        ])->assertCreated()->json('data.id');
        $multipleChoiceAnswerId = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $multipleChoice->id,
            'question_option_id' => $correctOption->id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('quiz_answers', [
            'id' => $multipleChoiceAnswerId,
            'marks_obtained' => 2,
        ]);

        $this->patchJson('/api/quiz-attempts/' . $attemptId, [
            'status' => 'submitted',
        ])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $shortAnswerId, [
            'marks_obtained' => 2,
        ])->assertOk();

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('pending', $attempt->result_status);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $shortAnswerId,
            'marks_obtained' => 2,
        ]);
        $this->assertNotNull(QuizAnswer::findOrFail($shortAnswerId)->manually_graded_at);
        $this->assertNull(QuizAnswer::findOrFail($longAnswerId)->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $longAnswerId, [
            'marks_obtained' => 0,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('evaluated', $attempt->status);
        $this->assertSame('passed', $attempt->result_status);
        $this->assertSame('4.00', $attempt->marks_obtained);
        $this->assertSame('40.00', $attempt->percentage);
        $this->assertNotNull(QuizAnswer::findOrFail($longAnswerId)->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressId . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_pending_manual_answers_block_evaluated_evidence_and_can_be_graded(): void
    {
        $institution = $this->createInstitution('UN-GRADED-MANUAL-EVIDENCE');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [$studentUser, $student] = $this->createStudent($institution);
        $course = $this->createCourse($institution, $teacher, 'ungraded-manual-evidence');
        $enrollment = $this->createEnrollment($course, $student);
        $lesson = $this->createLesson($course, 'Manual evidence lesson');

        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Manual evidence quiz',
            'total_marks' => 6,
            'passing_marks' => 2,
            'status' => 'published',
        ]);
        $manualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain this concept.',
            'question_type' => 'short_answer',
            'marks' => 2,
            'status' => 'active',
        ]);
        $secondManualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain another concept.',
            'question_type' => 'long_answer',
            'marks' => 2,
            'status' => 'active',
        ]);
        $automaticQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Select the correct option.',
            'question_type' => 'mcq',
            'marks' => 2,
            'status' => 'active',
        ]);

        foreach ([$manualQuestion, $secondManualQuestion, $automaticQuestion] as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_bank_id' => $question->id,
                'marks' => $question->marks,
                'sort_order' => $index + 1,
            ]);
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 6,
            'marks_obtained' => 2,
            'percentage' => 33.33,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $manualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'A submitted manual response',
            'marks_obtained' => 0,
        ]);
        $secondManualAnswer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $secondManualQuestion->id,
            'answer_text' => 'Another submitted manual response',
            'marks_obtained' => 0,
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $automaticQuestion->id,
            'marks_obtained' => 2,
            'is_correct' => true,
        ]);

        $this->authenticate($studentUser);
        $progressResponse = $this->postJson('/api/lesson-progress', [
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 80,
        ])->assertCreated();

        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $manualAnswer->id, [
            'marks_obtained' => 2,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('pending', $attempt->result_status);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessment');

        $this->authenticate($teacherUser);
        $this->patchJson('/api/quiz-answers/' . $secondManualAnswer->id, [
            'marks_obtained' => 0,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('evaluated', $attempt->status);
        $this->assertSame('passed', $attempt->result_status);
        $this->assertNotNull($secondManualAnswer->fresh()->manually_graded_at);

        $this->authenticate($studentUser);
        $this->postJson('/api/lesson-progress/' . $progressResponse->json('data.id') . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_parent_lesson_progress_access_supports_multiple_children_and_blocks_unauthorized_children(): void
    {
        $institution = $this->createInstitution('PROGRESS-MULTI');
        [, $teacher] = $this->createTeacher($institution);

        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveStudent] = $this->createStudent($institution);

        $course = $this->createCourse(
            $institution,
            $teacher,
            'progress-multiple-children'
        );

        $firstEnrollment = $this->createEnrollment($course, $firstStudent);
        $secondEnrollment = $this->createEnrollment($course, $secondStudent);
        $unrelatedEnrollment = $this->createEnrollment($course, $unrelatedStudent);
        $inactiveEnrollment = $this->createEnrollment($course, $inactiveStudent);

        $lesson = $this->createLesson($course, 'Shared progress test lesson');

        $firstProgress = $this->createProgress($firstEnrollment, $lesson);
        $secondProgress = $this->createProgress($secondEnrollment, $lesson);
        $unrelatedProgress = $this->createProgress($unrelatedEnrollment, $lesson);
        $inactiveProgress = $this->createProgress($inactiveEnrollment, $lesson);

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $response = $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $returnedProgressIds = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$firstProgress->id, $secondProgress->id],
            $returnedProgressIds,
            'The parent must see only progress records for actively linked children.'
        );

        $this->getJson('/api/lesson-progress/' . $firstProgress->id)
            ->assertOk();

        $this->getJson('/api/lesson-progress/' . $secondProgress->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $unrelatedProgress->id),
            'parent reading unrelated child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $inactiveProgress->id),
            'parent reading inactive linked child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $firstProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating first linked child lesson progress'
        );

        $this->assertForbiddenResponse(
            $this->patchJson('/api/lesson-progress/' . $secondProgress->id, [
                'status' => 'in_progress',
            ]),
            'parent updating second linked child lesson progress'
        );
    }

    public function test_assignment_evaluation_blocks_cross_course_grading_and_grade_tampering(): void
    {
        $firstInstitution = $this->createInstitution('EVAL-A');
        $secondInstitution = $this->createInstitution('EVAL-B');
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$firstStudentUser, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'evaluation-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'evaluation-b');

        $this->createEnrollment($firstCourse, $firstStudent);
        $this->createEnrollment($secondCourse, $secondStudent);

        $firstSubmission = $this->createSubmission(
            $this->createAssignment($firstCourse, $firstTeacher, 'First assignment'),
            $firstStudent
        );
        $secondSubmission = $this->createSubmission(
            $this->createAssignment($secondCourse, $secondTeacher, 'Second assignment'),
            $secondStudent
        );

        $this->authenticate($firstTeacherUser);
        $this->assertForbiddenResponse(
            $this->postJson('/api/assignment-evaluations', [
                'assignment_submission_id' => $secondSubmission->id,
                'marks_obtained' => 80,
            ]),
            'teacher evaluating another course submission'
        );

        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 101,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('marks_obtained');

        $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 80,
            'maximum_marks' => 200,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('maximum_marks');

        $evaluationId = $this->postJson('/api/assignment-evaluations', [
            'assignment_submission_id' => $firstSubmission->id,
            'marks_obtained' => 80,
            'result_status' => 'failed',
        ])
            ->assertCreated()
            ->assertJsonPath('data.teacher_profile_id', $firstTeacher->id)
            ->assertJsonPath('data.maximum_marks', '100.00')
            ->assertJsonPath('data.result_status', 'passed')
            ->json('data.id');

        $this->authenticate($firstStudentUser);
        $this->getJson('/api/assignment-evaluations/' . $evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignment-evaluations/' . $evaluationId, [
                'marks_obtained' => 100,
            ]),
            'student updating an evaluation'
        );

        $parentUser = $this->createParent($firstInstitution, $firstStudent);
        $this->authenticate($parentUser);
        $this->getJson('/api/assignment-evaluations/' . $evaluationId)->assertOk();
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignment-evaluations/' . $evaluationId),
            'parent deleting an evaluation'
        );

        $secondEvaluation = AssignmentEvaluation::create([
            'assignment_submission_id' => $secondSubmission->id,
            'teacher_profile_id' => $secondTeacher->id,
            'marks_obtained' => 75,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);

        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $this->authenticate($adminUser);
        $this->getJson('/api/assignment-evaluations')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $evaluationId);
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $secondEvaluation->id),
            'institution admin reading another institution evaluation'
        );
    }

    public function test_parent_assignment_evaluation_access_supports_multiple_children(): void
    {
        $institution = $this->createInstitution('EVAL-PARENT-MULTI');
        $secondInstitution = $this->createInstitution('EVAL-PARENT-CROSS');
        [, $teacher] = $this->createTeacher($institution);
        [, $crossInstitutionTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        [, $unrelatedStudent] = $this->createStudent($institution);
        [, $inactiveLinkedStudent] = $this->createStudent($institution);
        [, $crossInstitutionStudent] = $this->createStudent($secondInstitution);

        $course = $this->createCourse(
            $institution,
            $teacher,
            'parent-evaluation-access'
        );
        $crossInstitutionCourse = $this->createCourse(
            $secondInstitution,
            $crossInstitutionTeacher,
            'parent-evaluation-cross-institution'
        );

        $this->createEnrollment($course, $firstStudent);
        $this->createEnrollment($course, $secondStudent);
        $this->createEnrollment($course, $unrelatedStudent);
        $this->createEnrollment($course, $inactiveLinkedStudent);
        $this->createEnrollment($crossInstitutionCourse, $crossInstitutionStudent);

        $assignment = $this->createAssignment(
            $course,
            $teacher,
            'Parent evaluation assignment'
        );
        $crossInstitutionAssignment = $this->createAssignment(
            $crossInstitutionCourse,
            $crossInstitutionTeacher,
            'Cross institution parent evaluation assignment'
        );

        $firstEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $firstStudent),
            $teacher
        );
        $secondEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $secondStudent),
            $teacher
        );
        $unrelatedEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $unrelatedStudent),
            $teacher
        );
        $inactiveLinkedEvaluation = $this->createEvaluation(
            $this->createSubmission($assignment, $inactiveLinkedStudent),
            $teacher
        );
        $crossInstitutionEvaluation = $this->createEvaluation(
            $this->createSubmission(
                $crossInstitutionAssignment,
                $crossInstitutionStudent
            ),
            $crossInstitutionTeacher
        );

        $parentUser = $this->createParent($institution, $firstStudent);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $secondStudent->id,
            'status' => 'active',
        ]);

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parentUser->id,
            'student_profile_id' => $inactiveLinkedStudent->id,
            'status' => 'inactive',
        ]);

        $this->authenticate($parentUser);

        $listedEvaluationIds = $this->getJson('/api/assignment-evaluations')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->json('data.data.*.id');

        $this->assertEqualsCanonicalizing(
            [$firstEvaluation->id, $secondEvaluation->id],
            $listedEvaluationIds
        );

        $this->getJson('/api/assignment-evaluations/' . $firstEvaluation->id)
            ->assertOk();
        $this->getJson('/api/assignment-evaluations/' . $secondEvaluation->id)
            ->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $unrelatedEvaluation->id),
            'parent reading unrelated child assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $inactiveLinkedEvaluation->id),
            'parent reading inactive linked child assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-evaluations/' . $crossInstitutionEvaluation->id),
            'parent reading another institution assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignment-evaluations/' . $firstEvaluation->id, [
                'marks_obtained' => 90,
            ]),
            'parent updating an assignment evaluation'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignment-evaluations/' . $secondEvaluation->id),
            'parent deleting an assignment evaluation'
        );
    }

    public function test_institution_admin_without_an_active_profile_fails_closed(): void
    {
        $adminUser = $this->createUserWithRole('institution-admin');

        $this->authenticate($adminUser);

        $this->getJson('/api/certificates')->assertForbidden();
        $this->getJson('/api/lesson-progress')->assertForbidden();
        $this->getJson('/api/assignment-evaluations')->assertForbidden();
    }

    public function test_inactive_institution_admin_cannot_access_lessons_or_course_enrollments(): void
    {
        $institution = $this->createInstitution('INACTIVE-INSTITUTION-ADMIN');
        [, $teacher] = $this->createTeacher($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'inactive-admin-access');
        $lesson = $this->createLesson($course, 'Protected lesson');
        $enrollment = $this->createEnrollment($course, $firstStudent);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'inactive']);

        $this->authenticate($adminUser);

        $this->getJson('/api/lessons')->assertForbidden();
        $this->getJson('/api/lessons/' . $lesson->id)->assertForbidden();
        $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Unauthorized lesson',
            'status' => 'draft',
        ])->assertForbidden();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();
        $this->deleteJson('/api/lessons/' . $lesson->id)->assertForbidden();

        $this->getJson('/api/course-enrollments')->assertForbidden();
        $this->getJson('/api/course-enrollments/' . $enrollment->id)->assertForbidden();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertForbidden();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 10,
        ])->assertForbidden();
        $this->deleteJson('/api/course-enrollments/' . $enrollment->id)->assertForbidden();

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'Protected lesson',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'student_profile_id' => $firstStudent->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ]);
    }

    public function test_active_institution_admin_and_super_admin_can_access_lessons_and_course_enrollments(): void
    {
        $institution = $this->createInstitution('ACTIVE-INSTITUTION-ADMIN');
        [, $teacher] = $this->createTeacher($institution);
        [, $firstStudent] = $this->createStudent($institution);
        [, $secondStudent] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'active-admin-access');
        $lesson = $this->createLesson($course, 'Accessible lesson');
        $enrollment = $this->createEnrollment($course, $firstStudent);

        $this->authenticate($adminUser);

        $this->getJson('/api/lessons')->assertOk();
        $this->getJson('/api/lessons/' . $lesson->id)->assertOk();
        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson('/api/course-enrollments/' . $enrollment->id)->assertOk();

        $this->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Created by active admin',
            'status' => 'draft',
        ])->assertCreated();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Updated by active admin',
        ])->assertOk();
        $this->postJson('/api/course-enrollments', [
            'course_id' => $course->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 10,
        ])->assertOk();

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/lessons')->assertOk();
        $this->getJson('/api/course-enrollments')->assertOk();
        $this->patchJson('/api/lessons/' . $lesson->id, [
            'title' => 'Updated by super admin',
        ])->assertOk();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 15,
        ])->assertOk();
    }

    public function test_institution_admin_enrollment_list_and_show_exclude_existing_cross_institution_records(): void
    {
        $firstInstitution = $this->createInstitution('ENROLLMENT-TENANT-A');
        $secondInstitution = $this->createInstitution('ENROLLMENT-TENANT-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'enrollment-tenant-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'enrollment-tenant-b');

        $validEnrollment = $this->createEnrollment($firstCourse, $firstStudent);
        $courseScopedCrossTenantEnrollment = $this->createEnrollment(
            $firstCourse,
            $secondStudent
        );
        $studentScopedCrossTenantEnrollment = $this->createEnrollment(
            $secondCourse,
            $firstStudent
        );

        $this->authenticate($adminUser);

        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validEnrollment->id);

        $this->getJson('/api/course-enrollments/' . $validEnrollment->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->getJson('/api/course-enrollments/' . $courseScopedCrossTenantEnrollment->id),
            'institution admin reading enrollment with a foreign student'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/course-enrollments/' . $studentScopedCrossTenantEnrollment->id),
            'institution admin reading enrollment for a foreign course'
        );

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/course-enrollments')
            ->assertOk()
            ->assertJsonCount(3, 'data.data');
        $this->getJson('/api/course-enrollments/' . $courseScopedCrossTenantEnrollment->id)
            ->assertOk();
    }

    public function test_enrollment_creation_and_update_require_matching_institutions_for_admins_and_super_admins(): void
    {
        $firstInstitution = $this->createInstitution('ENROLLMENT-WRITE-A');
        $secondInstitution = $this->createInstitution('ENROLLMENT-WRITE-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [, $firstStudent] = $this->createStudent($firstInstitution);
        [, $secondStudent] = $this->createStudent($secondInstitution);
        [, $thirdStudent] = $this->createStudent($firstInstitution);
        [, $fourthStudent] = $this->createStudent($firstInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'enrollment-write-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'enrollment-write-b');
        $enrollment = $this->createEnrollment($firstCourse, $firstStudent);

        $this->authenticate($adminUser);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $thirdStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 12,
        ])->assertOk();

        $superAdmin = $this->createUserWithRole('super-admin');
        $this->authenticate($superAdmin);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $secondStudent->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'course_id' => $secondCourse->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('course_enrollments', [
            'id' => $enrollment->id,
            'course_id' => $firstCourse->id,
            'student_profile_id' => $firstStudent->id,
        ]);

        $this->postJson('/api/course-enrollments', [
            'course_id' => $firstCourse->id,
            'student_profile_id' => $fourthStudent->id,
        ])->assertCreated();
        $this->patchJson('/api/course-enrollments/' . $enrollment->id, [
            'payment_status' => 'paid',
            'amount_paid' => 15,
        ])->assertOk();
    }

    private function createInstitution(string $code): Institution
    {
        return Institution::create([
            'name' => 'Institution ' . $code,
            'code' => $code,
        ]);
    }

    private function assertForbiddenResponse(
        TestResponse $response,
        string $context
    ): void {
        $this->assertSame(
            403,
            $response->getStatusCode(),
            $context . ': ' . $response->getContent()
        );
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function createUserWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'display_name' => Str::headline($roleName),
            ]
        );
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createInstitutionAdmin(Institution $institution): User
    {
        $user = $this->createUserWithRole('institution-admin');

        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createTeacher(Institution $institution): array
    {
        $user = $this->createUserWithRole('teacher');
        $teacherProfile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $teacherProfile];
    }

    private function createStudent(Institution $institution): array
    {
        $user = $this->createUserWithRole('student');
        $studentProfile = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $studentProfile];
    }

    private function createParent(
        Institution $institution,
        StudentProfile $studentProfile
    ): User {
        $user = $this->createUserWithRole('parent');

        ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'student_profile_id' => $studentProfile->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createCourse(
        Institution $institution,
        TeacherProfile $teacherProfile,
        string $slug
    ): Course {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacherProfile->id,
            'title' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'published',
        ]);
    }

    private function createEnrollment(
        Course $course,
        StudentProfile $studentProfile
    ): CourseEnrollment {
        return CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function createCertificate(
        Course $course,
        StudentProfile $studentProfile,
        string $number
    ): Certificate {
        return Certificate::create([
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'certificate_number' => $number,
            'certificate_uuid' => (string) Str::uuid(),
            'verification_token' => Str::random(16),
            'issued_date' => now()->toDateString(),
            'final_percentage' => 80,
            'final_grade' => 'B',
            'status' => 'issued',
            'verification_status' => 'valid',
        ]);
    }

    private function createLesson(Course $course, string $title): Lesson
    {
        return Lesson::create([
            'course_id' => $course->id,
            'title' => $title,
            'status' => 'published',
        ]);
    }

    private function createProgress(
        CourseEnrollment $courseEnrollment,
        Lesson $lesson
    ): LessonProgress {
        return LessonProgress::create([
            'course_enrollment_id' => $courseEnrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 25,
        ]);
    }

    private function createAssignment(
        Course $course,
        TeacherProfile $teacherProfile,
        string $title
    ): Assignment {
        return Assignment::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacherProfile->id,
            'title' => $title,
            'maximum_marks' => 100,
            'status' => 'published',
        ]);
    }

    private function createSubmission(
        Assignment $assignment,
        StudentProfile $studentProfile
    ): AssignmentSubmission {
        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_profile_id' => $studentProfile->id,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }

    public function test_student_learning_access_is_scoped_to_enrolled_published_course_content(): void
    {
        $institution = $this->createInstitution('LEARNING-ACCESS');

        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        [, $teacher] = $this->createTeacher($institution);

        $enrolledCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-learning-course'
        );

        $otherCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-other-course'
        );

        $this->createEnrollment($enrolledCourse, $student);

        $this->createEnrollment($otherCourse, $otherStudent);

        $publishedLesson = $this->createLesson(
            $enrolledCourse,
            'Published lesson'
        );

        $otherCourseLesson = $this->createLesson(
            $otherCourse,
            'Other course lesson'
        );

        $draftLesson = $this->createLesson(
            $enrolledCourse,
            'Draft lesson'
        );

        $draftLesson->update([
            'status' => 'draft',
        ]);

        $this->authenticate($studentUser);

        // Student can list lessons from an enrolled course.
        $this->getJson(
            '/api/student-courses/' . $enrolledCourse->id . '/lessons'
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $publishedLesson->id,
            ])
            ->assertJsonMissing([
                'id' => $draftLesson->id,
            ]);

        // Student can view an individual published lesson
        // from the enrolled course.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $publishedLesson->id
        )
            ->assertOk()
            ->assertJsonPath('data.id', $publishedLesson->id);

        // Student cannot access a course in which they are not enrolled.
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' . $otherCourse->id . '/lessons'
            ),
            'student accessing an unenrolled course'
        );

        // Student cannot access another course's lesson,
        // even if the lesson ID is known.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $otherCourseLesson->id
        )
            ->assertNotFound();

        // Unpublished lessons must not be exposed.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $draftLesson->id
        )
            ->assertNotFound();
    }

    public function test_student_lesson_resources_are_scoped_to_enrolled_published_lessons(): void
    {
        $institution = $this->createInstitution('RESOURCE-ACCESS');

        [$studentUser, $student] = $this->createStudent($institution);
        [, $otherStudent] = $this->createStudent($institution);
        [, $teacher] = $this->createTeacher($institution);

        $enrolledCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-resource-course'
        );

        $otherCourse = $this->createCourse(
            $institution,
            $teacher,
            'student-other-resource-course'
        );

        $this->createEnrollment($enrolledCourse, $student);
        $this->createEnrollment($otherCourse, $otherStudent);

        $publishedLesson = $this->createLesson(
            $enrolledCourse,
            'Published resource lesson'
        );

        $otherCourseLesson = $this->createLesson(
            $otherCourse,
            'Other course resource lesson'
        );

        $draftLesson = $this->createLesson(
            $enrolledCourse,
            'Draft resource lesson'
        );

        $draftLesson->update([
            'status' => 'draft',
        ]);

        $publishedResource = \App\Models\LessonResource::create([
            'lesson_id' => $publishedLesson->id,
            'title' => 'Published PDF',
            'resource_type' => 'pdf',
            'content' => 'Published resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $inactiveResource = \App\Models\LessonResource::create([
            'lesson_id' => $publishedLesson->id,
            'title' => 'Inactive PDF',
            'resource_type' => 'pdf',
            'content' => 'Inactive resource content',
            'sort_order' => 2,
            'status' => 'inactive',
        ]);

        $otherCourseResource = \App\Models\LessonResource::create([
            'lesson_id' => $otherCourseLesson->id,
            'title' => 'Other course PDF',
            'resource_type' => 'pdf',
            'content' => 'Other course resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $draftLessonResource = \App\Models\LessonResource::create([
            'lesson_id' => $draftLesson->id,
            'title' => 'Draft lesson PDF',
            'resource_type' => 'pdf',
            'content' => 'Draft lesson resource content',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->authenticate($studentUser);

        // Student can access resources for a published lesson
        // from a course in which they are enrolled.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $publishedLesson->id .
                '/resources'
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $publishedResource->id,
            ])
            ->assertJsonMissing([
                'id' => $inactiveResource->id,
            ]);

        // Student cannot access resources from an unenrolled course.
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' .
                    $otherCourse->id .
                    '/lessons/' .
                    $otherCourseLesson->id .
                    '/resources'
            ),
            'student accessing resources from an unenrolled course'
        );

        // Student cannot access another course's lesson,
        // even if the lesson ID is known.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $otherCourseLesson->id .
                '/resources'
        )
            ->assertNotFound();

        // Resources from unpublished lessons must not be exposed.
        $this->getJson(
            '/api/student-courses/' .
                $enrolledCourse->id .
                '/lessons/' .
                $draftLesson->id .
                '/resources'
        )
            ->assertNotFound();

        // Keep the variable explicitly used so the test documents
        // that an active resource exists on the draft lesson.
        $this->assertDatabaseHas('lesson_resources', [
            'id' => $draftLessonResource->id,
            'status' => 'active',
        ]);
    }

    public function test_legacy_cross_institution_enrollments_are_hidden_and_denied_to_students(): void
    {
        $firstInstitution = $this->createInstitution('LEGACY-ENROLLMENT-A');
        $secondInstitution = $this->createInstitution('LEGACY-ENROLLMENT-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        [$studentUser, $student] = $this->createStudent($firstInstitution);

        $firstCourse = $this->createCourse(
            $firstInstitution,
            $firstTeacher,
            'legacy-enrollment-valid-course'
        );
        $secondCourse = $this->createCourse(
            $secondInstitution,
            $secondTeacher,
            'legacy-enrollment-foreign-course'
        );
        $firstLesson = $this->createLesson($firstCourse, 'Valid lesson');
        $foreignLesson = $this->createLesson($secondCourse, 'Foreign lesson');
        $foreignProgressLesson = $this->createLesson(
            $secondCourse,
            'Foreign progress mutation lesson'
        );

        $validEnrollment = $this->createEnrollment($firstCourse, $student);
        $legacyEnrollment = $this->createEnrollment($secondCourse, $student);
        $validProgress = $this->createProgress($validEnrollment, $firstLesson);
        $this->createProgress($legacyEnrollment, $foreignLesson);

        $firstQuiz = Quiz::create([
            'course_id' => $firstCourse->id,
            'teacher_profile_id' => $firstTeacher->id,
            'title' => 'Valid course quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $foreignQuiz = Quiz::create([
            'course_id' => $secondCourse->id,
            'teacher_profile_id' => $secondTeacher->id,
            'title' => 'Foreign course quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $validAttempt = QuizAttempt::create([
            'quiz_id' => $firstQuiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'total_marks' => 1,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'in_progress',
        ]);
        QuizAttempt::create([
            'quiz_id' => $foreignQuiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);

        $validAssignment = $this->createAssignment(
            $firstCourse,
            $firstTeacher,
            'Valid course assignment'
        );
        $foreignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign course assignment'
        );
        $secondForeignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign course assignment for mutation'
        );
        $validSubmission = $this->createSubmission($validAssignment, $student);
        $this->createSubmission($foreignAssignment, $student);

        $this->authenticate($studentUser);

        $this->getJson('/api/student-dashboard/' . $student->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.enrolled_courses')
            ->assertJsonPath('data.enrolled_courses.0.id', $validEnrollment->id);
        $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validProgress->id);
        $this->getJson('/api/quiz-attempts')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validAttempt->id);
        $this->getJson('/api/assignment-submissions')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $validSubmission->id);

        $this->assertForbiddenResponse(
            $this->getJson('/api/student-courses/' . $secondCourse->id . '/lessons'),
            'student listing lessons for a course with a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->getJson(
                '/api/student-courses/' .
                    $secondCourse->id .
                    '/lessons/' .
                    $foreignLesson->id
            ),
            'student reading a lesson for a course with a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/lesson-progress', [
                'course_enrollment_id' => $legacyEnrollment->id,
                'lesson_id' => $foreignProgressLesson->id,
                'status' => 'in_progress',
                'progress_percentage' => 20,
            ]),
            'student creating progress for a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/quiz-attempts', [
                'quiz_id' => $foreignQuiz->id,
                'student_profile_id' => $student->id,
            ]),
            'student creating a quiz attempt through a legacy mismatched enrollment'
        );
        $this->assertForbiddenResponse(
            $this->postJson('/api/assignment-submissions', [
                'assignment_id' => $secondForeignAssignment->id,
                'student_profile_id' => $student->id,
                'submission_text' => 'A response for the foreign course.',
                'status' => 'submitted',
            ]),
            'student submitting an assignment through a legacy mismatched enrollment'
        );
    }

    public function test_institution_admin_assignment_access_is_tenant_scoped_and_super_admin_access_is_preserved(): void
    {
        $firstInstitution = $this->createInstitution('ASSIGNMENT-AUTH-A');
        $secondInstitution = $this->createInstitution('ASSIGNMENT-AUTH-B');
        [, $firstTeacher] = $this->createTeacher($firstInstitution);
        [$secondTeacherUser, $secondTeacher] = $this->createTeacher($secondInstitution);
        $adminUser = $this->createInstitutionAdmin($firstInstitution);
        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher, 'assignment-auth-a');
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher, 'assignment-auth-b');
        $localAssignment = $this->createAssignment(
            $firstCourse,
            $firstTeacher,
            'Local assignment'
        );
        $foreignAssignment = $this->createAssignment(
            $secondCourse,
            $secondTeacher,
            'Foreign assignment'
        );

        $this->authenticate($adminUser);

        $this->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonFragment(['id' => $localAssignment->id])
            ->assertJsonMissingExact(['id' => $foreignAssignment->id]);
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignments/' . $foreignAssignment->id),
            'institution admin reading a foreign assignment'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
                'title' => 'Unauthorized changed title',
            ]),
            'institution admin updating a foreign assignment'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignments/' . $foreignAssignment->id),
            'institution admin deleting a foreign assignment'
        );
        $this->assertDatabaseHas('assignments', [
            'id' => $foreignAssignment->id,
            'title' => 'Foreign assignment',
            'deleted_at' => null,
        ]);

        $this->getJson('/api/assignments/' . $localAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $localAssignment->id, [
            'title' => 'Updated local assignment',
        ])->assertOk();
        $this->deleteJson('/api/assignments/' . $localAssignment->id)->assertOk();

        $this->authenticate($secondTeacherUser);
        $this->getJson('/api/assignments/' . $foreignAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
            'title' => 'Updated by course teacher',
        ])->assertOk();

        $this->authenticate($this->createUserWithRole('super-admin'));
        $this->getJson('/api/assignments/' . $foreignAssignment->id)->assertOk();
        $this->patchJson('/api/assignments/' . $foreignAssignment->id, [
            'title' => 'Updated by super admin',
        ])->assertOk();
    }

    public function test_inactive_institution_admin_cannot_read_or_grade_assessments(): void
    {
        $institution = $this->createInstitution('INACTIVE-ASSESSMENT-ADMIN');
        [$teacherUser, $teacher] = $this->createTeacher($institution);
        [, $student] = $this->createStudent($institution);
        $adminUser = $this->createInstitutionAdmin($institution);
        $course = $this->createCourse($institution, $teacher, 'inactive-assessment-admin');
        $lesson = $this->createLesson($course, 'Protected assessment lesson');
        $assignment = $this->createAssignment($course, $teacher, 'Protected assignment');
        $submission = $this->createSubmission($assignment, $student);
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Protected manual quiz',
            'total_marks' => 1,
            'passing_marks' => 1,
            'status' => 'published',
        ]);
        $manualQuestion = QuestionBank::create([
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'question_text' => 'Explain the answer.',
            'question_type' => 'short_answer',
            'marks' => 1,
            'status' => 'active',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $manualQuestion->id,
            'marks' => 1,
            'sort_order' => 1,
        ]);
        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 0,
            'percentage' => 0,
            'result_status' => 'pending',
            'status' => 'submitted',
        ]);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Student response.',
            'marks_obtained' => 0,
        ]);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'inactive']);
        $this->authenticate($adminUser);

        $this->assertForbiddenResponse($this->getJson('/api/assignments'), 'inactive admin listing assignments');
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignments/' . $assignment->id),
            'inactive admin reading an assignment'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/assignments/' . $assignment->id, [
                'title' => 'Unauthorized assignment update',
            ]),
            'inactive admin updating an assignment'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/assignments/' . $assignment->id),
            'inactive admin deleting an assignment'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quizzes'), 'inactive admin listing quizzes');
        $this->assertForbiddenResponse(
            $this->getJson('/api/quizzes/' . $quiz->id),
            'inactive admin reading a quiz'
        );
        $this->assertForbiddenResponse(
            $this->patchJson('/api/quizzes/' . $quiz->id, ['title' => 'Unauthorized update']),
            'inactive admin updating a quiz'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quiz-attempts'), 'inactive admin listing attempts');
        $this->assertForbiddenResponse(
            $this->getJson('/api/quiz-attempts/' . $attempt->id),
            'inactive admin reading a quiz attempt'
        );
        $this->assertForbiddenResponse(
            $this->deleteJson('/api/quiz-attempts/' . $attempt->id),
            'inactive admin deleting a quiz attempt'
        );
        $this->assertForbiddenResponse($this->getJson('/api/quiz-answers'), 'inactive admin listing quiz answers');
        $this->assertForbiddenResponse(
            $this->patchJson('/api/quiz-answers/' . $answer->id, ['marks_obtained' => 1]),
            'inactive admin grading a manual quiz answer'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-submissions'),
            'inactive admin listing assignment submissions'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/assignment-submissions/' . $submission->id),
            'inactive admin reading an assignment submission'
        );

        $this->assertNull($answer->fresh()->manually_graded_at);

        InstitutionUser::where('user_id', $adminUser->id)
            ->update(['status' => 'active']);
        $this->authenticate($adminUser);

        $this->getJson('/api/assignments')->assertOk();
        $this->getJson('/api/quizzes')->assertOk();
        $this->getJson('/api/quiz-attempts')->assertOk();
        $this->getJson('/api/quiz-answers')->assertOk();
        $this->getJson('/api/assignment-submissions')->assertOk();
        $this->patchJson('/api/quiz-answers/' . $answer->id, [
            'marks_obtained' => 1,
        ])->assertOk();
        $this->assertNotNull($answer->fresh()->manually_graded_at);
    }

    private function createEvaluation(
        AssignmentSubmission $submission,
        TeacherProfile $teacherProfile,
        float $marksObtained = 80
    ): AssignmentEvaluation {
        return AssignmentEvaluation::create([
            'assignment_submission_id' => $submission->id,
            'teacher_profile_id' => $teacherProfile->id,
            'marks_obtained' => $marksObtained,
            'maximum_marks' => 100,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
    }
}
