<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\ParentProfile;
use App\Models\AttendanceRecord;
use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Http\Controllers\Api\AssignmentSubmissionController;
use App\Http\Controllers\Api\AssignmentEvaluationController;
use App\Http\Controllers\Api\AttendanceRecordController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\QuizAnswerController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Requests\AttendanceReportRequest;
use App\Services\ParentStudentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ParentStudentInstitutionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_link_requires_active_status_and_matching_institutions(): void
    {
        $firstInstitution = $this->createInstitution();
        $secondInstitution = $this->createInstitution();
        $parent = $this->createParentUser();
        $activeStudent = $this->createStudent($firstInstitution);
        $inactiveStudent = $this->createStudent($firstInstitution);
        $mismatchedStudent = $this->createStudent($secondInstitution);

        ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $activeStudent->id,
            'status' => 'active',
        ]);
        ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $inactiveStudent->id,
            'status' => 'inactive',
        ]);

        // Simulate a legacy row inserted without request validation.
        ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $mismatchedStudent->id,
            'status' => 'active',
        ]);

        $service = app(ParentStudentAccessService::class);

        $this->assertTrue($service->allowsStudent($parent, $activeStudent));
        $this->assertFalse($service->allowsStudent($parent, $inactiveStudent));
        $this->assertFalse($service->allowsStudent($parent, $mismatchedStudent));
    }

    public function test_parent_student_scope_returns_all_and_only_active_consistent_children(): void
    {
        $firstInstitution = $this->createInstitution();
        $secondInstitution = $this->createInstitution();
        $parent = $this->createParentUser();
        $firstChild = $this->createStudent($firstInstitution);
        $secondChild = $this->createStudent($secondInstitution);
        $inactiveChild = $this->createStudent($firstInstitution);
        $mismatchedChild = $this->createStudent($secondInstitution);

        foreach ([[$firstInstitution, $firstChild], [$secondInstitution, $secondChild]] as [$institution, $student]) {
            ParentProfile::create([
                'institution_id' => $institution->id,
                'user_id' => $parent->id,
                'student_profile_id' => $student->id,
                'status' => 'active',
            ]);
        }

        ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $inactiveChild->id,
            'status' => 'inactive',
        ]);
        ParentProfile::create([
            'institution_id' => $firstInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $mismatchedChild->id,
            'status' => 'active',
        ]);

        $service = app(ParentStudentAccessService::class);
        $scopedStudents = StudentProfile::query();
        $service->scopeStudentRecords($scopedStudents, $parent, 'student_profiles.id');

        $this->assertEqualsCanonicalizing(
            [$firstChild->id, $secondChild->id],
            $scopedStudents->pluck('student_profiles.id')->all()
        );

        $this->assertEqualsCanonicalizing(
            [$firstChild->id, $secondChild->id],
            $service->studentProfileIdsFor($parent)
                ->pluck('parent_profiles.student_profile_id')
                ->all()
        );
    }

    public function test_inconsistent_parent_profile_hides_its_loaded_student_relation(): void
    {
        $linkInstitution = $this->createInstitution();
        $studentInstitution = $this->createInstitution();
        $parent = $this->createParentUser();
        $student = $this->createStudent($studentInstitution);
        $parentProfile = ParentProfile::create([
            'institution_id' => $linkInstitution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $student->id,
            'status' => 'active',
        ])->load('studentProfile');

        $sanitized = app(ParentStudentAccessService::class)
            ->hideInconsistentStudent($parentProfile);

        $this->assertNull($sanitized->studentProfile);
        $this->assertNull($sanitized->toArray()['student_profile']);
    }

    public function test_parent_dashboard_supports_only_active_same_institution_children(): void
    {
        $firstInstitution = $this->createInstitution();
        $secondInstitution = $this->createInstitution();
        $parent = $this->createRoleUser('parent');
        $otherParent = $this->createRoleUser('parent');
        $firstChild = $this->createStudent($firstInstitution);
        $secondChild = $this->createStudent($secondInstitution);
        $inactiveChild = $this->createStudent($firstInstitution);
        $mismatchedChild = $this->createStudent($secondInstitution);
        $otherParentChild = $this->createStudent($firstInstitution);

        $this->createParentLink($parent, $firstInstitution, $firstChild);
        $this->createParentLink($parent, $secondInstitution, $secondChild);
        $this->createParentLink($parent, $firstInstitution, $inactiveChild, 'inactive');
        $this->createParentLink($parent, $firstInstitution, $mismatchedChild);
        $this->createParentLink($otherParent, $firstInstitution, $otherParentChild);

        $this->authenticate($parent);

        $children = $this->getJson('/api/parent-dashboard/children')
            ->assertOk()
            ->json('data');
        $this->assertEqualsCanonicalizing(
            [$firstChild->id, $secondChild->id],
            collect($children)->pluck('id')->all()
        );

        $this->getJson('/api/parent-dashboard/' . $firstChild->id)->assertOk();
        $this->getJson('/api/parent-dashboard/' . $secondChild->id)->assertOk();
        $this->getJson('/api/parent-dashboard/' . $firstChild->id . '/attendance')->assertOk();
        $this->getJson('/api/parent-dashboard/' . $firstChild->id . '/grades')->assertOk();
        $this->getJson('/api/parent-dashboard/' . $firstChild->id . '/assignments')->assertOk();
        $this->getJson('/api/parent-dashboard/' . $firstChild->id . '/courses')->assertOk();
        $this->getJson('/api/parent-dashboard/' . $firstChild->id . '/live-classes')->assertOk();

        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $inactiveChild->id),
            'parent accessing an inactive child link'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $mismatchedChild->id),
            'parent accessing a legacy cross-institution link'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/parent-dashboard/' . $otherParentChild->id),
            'parent accessing another parent\'s child'
        );
    }

    public function test_parent_quiz_and_assignment_submission_lists_and_details_use_valid_child_links(): void
    {
        $fixture = $this->createAcademicFixture();
        $this->authenticate($fixture['parent']);

        $attemptController = app(QuizAttemptController::class);
        $this->assertEqualsCanonicalizing(
            [$fixture['attempts']['first']->id, $fixture['attempts']['second']->id],
            $this->responseRecordIds($attemptController->index())
        );
        $this->assertSame(
            $fixture['attempts']['first']->id,
            $attemptController->show($fixture['attempts']['first'])->getData(true)['data']['id']
        );
        $this->assertControllerForbidden(
            fn () => $attemptController->show($fixture['attempts']['inactive']),
            'parent showing attempt for inactive child'
        );
        $this->assertControllerForbidden(
            fn () => $attemptController->show($fixture['attempts']['mismatched']),
            'parent showing attempt for legacy cross-institution child'
        );
        $this->assertControllerForbidden(
            fn () => $attemptController->show($fixture['attempts']['otherParent']),
            'parent showing another parent\'s attempt'
        );
        $this->assertControllerForbidden(
            fn () => $attemptController->show($fixture['attempts']['inconsistentFirst']),
            'parent showing an attempt from another institution'
        );

        $answerController = app(QuizAnswerController::class);
        $this->assertEqualsCanonicalizing(
            [$fixture['answers']['first']->id, $fixture['answers']['second']->id],
            $this->responseRecordIds($answerController->index())
        );
        $shownAnswer = $answerController->show($fixture['answers']['first'])->getData(true)['data'];
        $this->assertSame($fixture['answers']['first']->id, $shownAnswer['id']);
        $this->assertSame(
            $fixture['attempts']['first']->id,
            $shownAnswer['quiz_attempt']['id']
        );
        $this->assertSame(
            $fixture['firstChild']->id,
            $shownAnswer['quiz_attempt']['student_profile_id']
        );
        $this->assertControllerForbidden(
            fn () => $answerController->show($fixture['answers']['inactive']),
            'parent showing answer for inactive child'
        );
        $this->assertControllerForbidden(
            fn () => $answerController->show($fixture['answers']['mismatched']),
            'parent showing answer for legacy cross-institution child'
        );
        $this->assertControllerForbidden(
            fn () => $answerController->show($fixture['answers']['otherParent']),
            'parent showing another parent\'s answer'
        );
        $this->assertControllerForbidden(
            fn () => $answerController->show($fixture['answers']['inconsistentFirst']),
            'parent showing an answer from another institution'
        );

        $submissionController = app(AssignmentSubmissionController::class);
        $this->assertEqualsCanonicalizing(
            [$fixture['submissions']['first']->id, $fixture['submissions']['second']->id],
            $this->responseRecordIds($submissionController->index())
        );
        $this->assertSame(
            $fixture['submissions']['first']->id,
            $submissionController->show($fixture['submissions']['first'])->getData(true)['data']['id']
        );
        $this->assertControllerForbidden(
            fn () => $submissionController->show($fixture['submissions']['inactive']),
            'parent showing submission for inactive child'
        );
        $this->assertControllerForbidden(
            fn () => $submissionController->show($fixture['submissions']['mismatched']),
            'parent showing submission for legacy cross-institution child'
        );
        $this->assertControllerForbidden(
            fn () => $submissionController->show($fixture['submissions']['otherParent']),
            'parent showing another parent\'s submission'
        );
        $this->assertControllerForbidden(
            fn () => $submissionController->show($fixture['submissions']['inconsistentFirst']),
            'parent showing a submission from another institution'
        );
    }

    public function test_parent_attendance_evaluations_gradebooks_and_lesson_progress_are_scoped(): void
    {
        $fixture = $this->createAcademicFixture();
        $this->authenticate($fixture['parent']);

        $attendanceController = app(AttendanceRecordController::class);
        $attendanceRequest = $this->attendanceReportRequest();
        $this->assertEqualsCanonicalizing(
            [$fixture['attendance']['first']->id, $fixture['attendance']['second']->id],
            $this->responseRecordIds($attendanceController->index($attendanceRequest))
        );
        $this->assertSame(
            $fixture['attendance']['first']->id,
            $attendanceController->show($fixture['attendance']['first'])->getData(true)['data']['id']
        );
        $this->assertControllerForbidden(
            fn () => $attendanceController->show($fixture['attendance']['inactive']),
            'parent showing attendance for inactive child'
        );
        $this->assertControllerForbidden(
            fn () => $attendanceController->show($fixture['attendance']['mismatched']),
            'parent showing attendance for legacy cross-institution child'
        );
        $this->assertControllerForbidden(
            fn () => $attendanceController->show($fixture['attendance']['otherParent']),
            'parent showing another parent\'s attendance'
        );
        $this->assertControllerForbidden(
            fn () => $attendanceController->show($fixture['attendance']['inconsistentFirst']),
            'parent showing attendance from another institution'
        );

        $evaluationController = app(AssignmentEvaluationController::class);
        $this->assertEqualsCanonicalizing(
            [$fixture['evaluations']['first']->id, $fixture['evaluations']['second']->id],
            $this->responseRecordIds($evaluationController->index())
        );
        $this->assertSame(
            $fixture['evaluations']['first']->id,
            $evaluationController->show($fixture['evaluations']['first'])->getData(true)['data']['id']
        );
        $this->assertControllerForbidden(
            fn () => $evaluationController->show($fixture['evaluations']['mismatched']),
            'parent showing evaluation for legacy cross-institution child'
        );
        $this->assertControllerForbidden(
            fn () => $evaluationController->show($fixture['evaluations']['inactive']),
            'parent showing evaluation for inactive child'
        );
        $this->assertControllerForbidden(
            fn () => $evaluationController->show($fixture['evaluations']['otherParent']),
            'parent showing another parent\'s evaluation'
        );
        $this->assertControllerForbidden(
            fn () => $evaluationController->show($fixture['evaluations']['inconsistentFirst']),
            'parent showing an evaluation from another institution'
        );

        $gradebooks = $this->getJson('/api/gradebooks')
            ->assertOk()
            ->json('data.data');
        $this->assertEqualsCanonicalizing(
            [$fixture['gradebooks']['first']->id, $fixture['gradebooks']['second']->id],
            collect($gradebooks)->pluck('id')->all()
        );
        $this->getJson('/api/gradebooks/' . $fixture['gradebooks']['first']->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $fixture['gradebooks']['inactive']->id),
            'parent showing gradebook for inactive child'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $fixture['gradebooks']['mismatched']->id),
            'parent showing gradebook for legacy cross-institution child'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $fixture['gradebooks']['otherParent']->id),
            'parent showing another parent\'s gradebook'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/gradebooks/' . $fixture['gradebooks']['inconsistentFirst']->id),
            'parent showing a gradebook from another institution'
        );

        $progresses = $this->getJson('/api/lesson-progress')
            ->assertOk()
            ->json('data.data');
        $this->assertEqualsCanonicalizing(
            [$fixture['progress']['first']->id, $fixture['progress']['second']->id],
            collect($progresses)->pluck('id')->all()
        );
        $this->getJson('/api/lesson-progress/' . $fixture['progress']['first']->id)->assertOk();
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $fixture['progress']['inactive']->id),
            'parent showing progress for inactive child'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $fixture['progress']['mismatched']->id),
            'parent showing progress for legacy cross-institution child'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $fixture['progress']['otherParent']->id),
            'parent showing another parent\'s progress'
        );
        $this->assertForbiddenResponse(
            $this->getJson('/api/lesson-progress/' . $fixture['progress']['inconsistentFirst']->id),
            'parent showing progress for a lesson from another institution'
        );

        $childAssignments = $this->getJson(
            '/api/parent-dashboard/' . $fixture['firstChild']->id . '/assignments'
        )->assertOk()->json('data.pending_assignments');
        $this->assertSame(
            [$fixture['assignments']['pendingFirst']->id],
            collect($childAssignments)->pluck('id')->all()
        );

        $submittedAssignments = $this->getJson(
            '/api/parent-dashboard/' . $fixture['firstChild']->id . '/assignments'
        )->assertOk()->json('data.submitted_assignments');
        $this->assertSame(
            [$fixture['submissions']['first']->id],
            collect($submittedAssignments)->pluck('id')->all()
        );

        $childGrades = $this->getJson(
            '/api/parent-dashboard/' . $fixture['firstChild']->id . '/grades'
        )->assertOk()->json('data.gradebooks');
        $this->assertSame(
            [$fixture['gradebooks']['first']->id],
            collect($childGrades)->pluck('id')->all()
        );

        $childDashboard = $this->getJson(
            '/api/parent-dashboard/' . $fixture['firstChild']->id
        )->assertOk()->json('data');
        $this->assertSame(
            [$fixture['gradebooks']['first']->id],
            collect($childDashboard['gradebooks'])->pluck('id')->all()
        );
        $this->assertSame(
            [$fixture['attempts']['first']->id],
            collect($childDashboard['quiz_attempts'])->pluck('id')->all()
        );
        $this->assertSame(
            [$fixture['attendance']['first']->id],
            collect($childDashboard['recent_attendance'])->pluck('id')->all()
        );

        $childAttendance = $this->getJson(
            '/api/parent-dashboard/' . $fixture['firstChild']->id . '/attendance'
        )->assertOk()->json('data.records.data');
        $this->assertContains(
            $fixture['attendance']['first']->id,
            collect($childAttendance)->pluck('id')->all()
        );
        $this->assertNotContains(
            $fixture['attendance']['inconsistentFirst']->id,
            collect($childAttendance)->pluck('id')->all()
        );
    }

    public function test_parent_profile_admin_reads_hide_mismatched_nested_students_and_keep_valid_student_data(): void
    {
        $fixture = $this->createAcademicFixture();
        $admin = $this->createInstitutionAdmin($fixture['firstInstitution']);
        $validProfile = ParentProfile::where('user_id', $fixture['parent']->id)
            ->where('student_profile_id', $fixture['firstChild']->id)
            ->firstOrFail();
        $mismatchedProfile = ParentProfile::where('user_id', $fixture['parent']->id)
            ->where('student_profile_id', $fixture['mismatchedChild']->id)
            ->firstOrFail();

        $this->authenticate($admin);

        $listing = $this->getJson('/api/parent-profiles')
            ->assertOk()
            ->json('data.data');
        $listedValid = collect($listing)->firstWhere('id', $validProfile->id);
        $listedMismatched = collect($listing)->firstWhere('id', $mismatchedProfile->id);

        $this->assertSame($fixture['firstChild']->id, $listedValid['student_profile']['id']);
        $this->assertNull($listedMismatched['student_profile']);

        $this->getJson('/api/parent-profiles/' . $mismatchedProfile->id)
            ->assertOk()
            ->assertJsonPath('data.student_profile', null);
        $this->getJson('/api/parent-profiles/' . $validProfile->id)
            ->assertOk()
            ->assertJsonPath('data.student_profile.id', $fixture['firstChild']->id);
    }

    public function test_staff_and_institution_admin_academic_reads_remain_available(): void
    {
        $fixture = $this->createAcademicFixture();
        $admin = $this->createInstitutionAdmin($fixture['firstInstitution']);

        $this->authenticate($admin);
        $gradebooks = $this->getJson('/api/gradebooks')
            ->assertOk()
            ->json('data.data');
        $this->assertContains(
            $fixture['gradebooks']['first']->id,
            collect($gradebooks)->pluck('id')->all()
        );

        $this->authenticate($fixture['firstTeacherUser']);
        $evaluations = $this->responseRecordIds(
            app(AssignmentEvaluationController::class)->index()
        );
        $this->assertContains($fixture['evaluations']['first']->id, $evaluations);
    }

    private function createAcademicFixture(): array
    {
        $firstInstitution = $this->createInstitution();
        $secondInstitution = $this->createInstitution();
        [$firstTeacherUser, $firstTeacher] = $this->createTeacher($firstInstitution);
        [, $secondTeacher] = $this->createTeacher($secondInstitution);
        $parent = $this->createRoleUser('parent');
        $otherParent = $this->createRoleUser('parent');

        $firstChild = $this->createStudent($firstInstitution);
        $secondChild = $this->createStudent($secondInstitution);
        $inactiveChild = $this->createStudent($firstInstitution);
        $mismatchedChild = $this->createStudent($secondInstitution);
        $otherParentChild = $this->createStudent($firstInstitution);

        $this->createParentLink($parent, $firstInstitution, $firstChild);
        $this->createParentLink($parent, $secondInstitution, $secondChild);
        $this->createParentLink($parent, $firstInstitution, $inactiveChild, 'inactive');
        $this->createParentLink($parent, $firstInstitution, $mismatchedChild);
        $this->createParentLink($otherParent, $firstInstitution, $otherParentChild);

        $firstCourse = $this->createCourse($firstInstitution, $firstTeacher);
        $secondCourse = $this->createCourse($secondInstitution, $secondTeacher);
        $studentsByName = [
            'first' => $firstChild,
            'second' => $secondChild,
            'inactive' => $inactiveChild,
            'mismatched' => $mismatchedChild,
            'otherParent' => $otherParentChild,
        ];
        $coursesByName = [
            'first' => $firstCourse,
            'second' => $secondCourse,
            'inactive' => $firstCourse,
            'mismatched' => $secondCourse,
            'otherParent' => $firstCourse,
        ];

        $enrollments = [];
        foreach ($studentsByName as $name => $student) {
            $enrollments[$name] = $this->createEnrollment(
                $coursesByName[$name],
                $student
            );
        }

        $firstAssignment = $this->createAssignment($firstCourse, $firstTeacher);
        $pendingFirstAssignment = $this->createAssignment($firstCourse, $firstTeacher);
        $secondAssignment = $this->createAssignment($secondCourse, $secondTeacher);
        $assignmentsByName = [
            'first' => $firstAssignment,
            'second' => $secondAssignment,
            'inactive' => $firstAssignment,
            'mismatched' => $secondAssignment,
            'otherParent' => $firstAssignment,
        ];

        $submissions = [];
        $evaluations = [];
        $gradebooks = [];
        $attendance = [];
        $progress = [];
        $attempts = [];
        $answers = [];

        $quizzes = [
            'first' => $this->createQuiz($firstCourse, $firstTeacher),
            'second' => $this->createQuiz($secondCourse, $secondTeacher),
        ];
        $questions = [
            'first' => $this->createQuestion($firstCourse),
            'second' => $this->createQuestion($secondCourse),
        ];
        foreach (['first', 'second'] as $name) {
            QuizQuestion::create([
                'quiz_id' => $quizzes[$name]->id,
                'question_bank_id' => $questions[$name]->id,
                'marks' => 1,
                'sort_order' => 1,
            ]);
        }

        $lessons = [
            'first' => $this->createLesson($firstCourse),
            'second' => $this->createLesson($secondCourse),
        ];

        foreach ($studentsByName as $name => $student) {
            $course = $coursesByName[$name];
            $teacher = $name === 'second' || $name === 'mismatched'
                ? $secondTeacher
                : $firstTeacher;
            $quizName = $name === 'second' || $name === 'mismatched'
                ? 'second'
                : 'first';

            $submissions[$name] = AssignmentSubmission::create([
                'assignment_id' => $assignmentsByName[$name]->id,
                'student_profile_id' => $student->id,
                'submission_text' => 'Submission for ' . $name,
                'submitted_at' => now(),
                'status' => 'submitted',
            ]);
            $evaluations[$name] = AssignmentEvaluation::create([
                'assignment_submission_id' => $submissions[$name]->id,
                'teacher_profile_id' => $teacher->id,
                'marks_obtained' => 1,
                'maximum_marks' => 10,
                'result_status' => 'passed',
                'evaluated_at' => now(),
            ]);
            $gradebooks[$name] = Gradebook::create([
                'course_id' => $course->id,
                'student_profile_id' => $student->id,
                'assignment_marks' => 1,
                'quiz_marks' => 1,
                'total_marks' => 2,
                'maximum_marks' => 20,
                'percentage' => 10,
                'grade' => 'F',
                'result_status' => 'failed',
            ]);
            $attendance[$name] = AttendanceRecord::create([
                'institution_id' => $student->institution_id,
                'course_id' => $course->id,
                'student_profile_id' => $student->id,
                'attendance_date' => now()->subDays(array_search($name, array_keys($studentsByName), true))->toDateString(),
                'attendance_status' => 'present',
            ]);
            $progress[$name] = $this->createProgress(
                $enrollments[$name],
                $lessons[$quizName]
            );
            $attempts[$name] = QuizAttempt::create([
                'quiz_id' => $quizzes[$quizName]->id,
                'student_profile_id' => $student->id,
                'attempt_number' => 1,
                'started_at' => now()->subMinutes(5),
                'submitted_at' => now()->subMinutes(1),
                'evaluated_at' => now(),
                'total_marks' => 1,
                'marks_obtained' => 1,
                'percentage' => 100,
                'result_status' => 'passed',
                'status' => 'evaluated',
            ]);
            $answers[$name] = QuizAnswer::create([
                'quiz_attempt_id' => $attempts[$name]->id,
                'question_bank_id' => $questions[$quizName]->id,
                'answer_text' => 'Answer for ' . $name,
                'is_correct' => true,
                'marks_obtained' => 1,
            ]);
        }

        $submissions['inconsistentFirst'] = AssignmentSubmission::create([
            'assignment_id' => $secondAssignment->id,
            'student_profile_id' => $firstChild->id,
            'submission_text' => 'Cross-institution submission',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
        $evaluations['inconsistentFirst'] = AssignmentEvaluation::create([
            'assignment_submission_id' => $submissions['inconsistentFirst']->id,
            'teacher_profile_id' => $secondTeacher->id,
            'marks_obtained' => 1,
            'maximum_marks' => 10,
            'result_status' => 'passed',
            'evaluated_at' => now(),
        ]);
        $gradebooks['inconsistentFirst'] = Gradebook::create([
            'course_id' => $secondCourse->id,
            'student_profile_id' => $firstChild->id,
            'assignment_marks' => 1,
            'quiz_marks' => 1,
            'total_marks' => 2,
            'maximum_marks' => 20,
            'percentage' => 10,
            'grade' => 'F',
            'result_status' => 'failed',
        ]);
        $progress['inconsistentFirst'] = $this->createProgress(
            $enrollments['first'],
            $lessons['second']
        );
        $attempts['inconsistentFirst'] = QuizAttempt::create([
            'quiz_id' => $quizzes['second']->id,
            'student_profile_id' => $firstChild->id,
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(1),
            'evaluated_at' => now(),
            'total_marks' => 1,
            'marks_obtained' => 1,
            'percentage' => 100,
            'result_status' => 'passed',
            'status' => 'evaluated',
        ]);
        $answers['inconsistentFirst'] = QuizAnswer::create([
            'quiz_attempt_id' => $attempts['inconsistentFirst']->id,
            'question_bank_id' => $questions['second']->id,
            'answer_text' => 'Cross-institution answer',
            'is_correct' => true,
            'marks_obtained' => 1,
        ]);
        $attendance['inconsistentFirst'] = AttendanceRecord::create([
            'institution_id' => $secondInstitution->id,
            'course_id' => $secondCourse->id,
            'student_profile_id' => $firstChild->id,
            'attendance_date' => now()->subDays(10)->toDateString(),
            'attendance_status' => 'present',
        ]);

        return [
            'firstInstitution' => $firstInstitution,
            'secondInstitution' => $secondInstitution,
            'firstTeacherUser' => $firstTeacherUser,
            'firstTeacher' => $firstTeacher,
            'parent' => $parent,
            'firstChild' => $firstChild,
            'secondChild' => $secondChild,
            'inactiveChild' => $inactiveChild,
            'mismatchedChild' => $mismatchedChild,
            'otherParentChild' => $otherParentChild,
            'attempts' => $attempts,
            'answers' => $answers,
            'submissions' => $submissions,
            'evaluations' => $evaluations,
            'gradebooks' => $gradebooks,
            'attendance' => $attendance,
            'progress' => $progress,
            'assignments' => [
                'pendingFirst' => $pendingFirstAssignment,
                'second' => $secondAssignment,
            ],
        ];
    }

    private function createTeacher(Institution $institution): array
    {
        $user = $this->createRoleUser('teacher');
        $teacherProfile = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $teacherProfile];
    }

    private function createCourse(Institution $institution, TeacherProfile $teacher): Course
    {
        $slug = Str::lower(Str::random(16));

        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Course ' . $slug,
            'slug' => $slug,
            'status' => 'published',
        ]);
    }

    private function createEnrollment(
        Course $course,
        StudentProfile $student
    ): CourseEnrollment {
        return CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function createAssignment(Course $course, TeacherProfile $teacher): Assignment
    {
        return Assignment::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Assignment ' . Str::random(8),
            'maximum_marks' => 10,
            'status' => 'published',
        ]);
    }

    private function createQuiz(Course $course, TeacherProfile $teacher): Quiz
    {
        return Quiz::create([
            'course_id' => $course->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Quiz ' . Str::random(8),
            'total_marks' => 1,
            'passing_marks' => 1,
            'show_result_immediately' => true,
            'status' => 'published',
        ]);
    }

    private function createQuestion(Course $course): QuestionBank
    {
        return QuestionBank::create([
            'course_id' => $course->id,
            'question_text' => 'Question ' . Str::random(8),
            'question_type' => 'mcq',
            'marks' => 1,
            'status' => 'active',
        ]);
    }

    private function createLesson(Course $course): Lesson
    {
        return Lesson::create([
            'course_id' => $course->id,
            'title' => 'Lesson ' . Str::random(8),
            'status' => 'published',
        ]);
    }

    private function createProgress(
        CourseEnrollment $enrollment,
        Lesson $lesson
    ): LessonProgress {
        return LessonProgress::create([
            'course_enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 25,
        ]);
    }

    private function createInstitutionAdmin(Institution $institution): User
    {
        $user = $this->createRoleUser('institution-admin');
        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createParentLink(
        User $parent,
        Institution $institution,
        StudentProfile $student,
        string $status = 'active'
    ): ParentProfile {
        return ParentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $parent->id,
            'student_profile_id' => $student->id,
            'status' => $status,
        ]);
    }

    private function attendanceReportRequest(): AttendanceReportRequest
    {
        $request = AttendanceReportRequest::create('/api/attendance-records', 'GET');
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    private function responseRecordIds(\Illuminate\Http\JsonResponse $response): array
    {
        $data = $response->getData(true)['data'] ?? [];
        $records = is_array($data) && array_key_exists('data', $data)
            ? $data['data']
            : $data;

        return collect($records)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function assertControllerForbidden(callable $callback, string $context): void
    {
        try {
            $callback();
            $this->fail($context . ' should be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode(), $context);
        }
    }

    private function assertForbiddenResponse(TestResponse $response, string $context): void
    {
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

    private function createRoleUser(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ], [
            'display_name' => Str::headline($roleName),
        ]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createInstitution(): Institution
    {
        return Institution::create([
            'name' => 'Institution ' . Str::random(8),
            'code' => Str::upper(Str::random(12)),
        ]);
    }

    private function createParentUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate([
            'name' => 'parent',
            'guard_name' => 'web',
        ], [
            'display_name' => 'Parent',
        ]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createStudent(Institution $institution): StudentProfile
    {
        $user = $this->createRoleUser('student');

        return StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }
}
