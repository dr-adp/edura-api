<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuizWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_creation_locks_parents_and_allocates_unique_attempt_numbers(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['student_user']);

        $first = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $pair['student']->id,
        ])->assertCreated();

        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'attempts'],
            array_column($locks->events, 0)
        );
        $firstAttemptId = (int) $first->json('data.id');
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $firstAttemptId,
            'attempt_number' => 1,
        ]);

        $this->patchJson('/api/quiz-attempts/' . $firstAttemptId, [
            'status' => 'submitted',
        ])->assertOk()->assertJsonPath('data.status', 'evaluated');

        $locks->events = [];
        $second = $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $pair['student']->id,
        ])->assertCreated();

        $this->assertSame(2, (int) $second->json('data.attempt_number'));
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'attempts'],
            array_column($locks->events, 0)
        );

        try {
            QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_profile_id' => $pair['student']->id,
                'attempt_number' => 2,
                'status' => 'in_progress',
            ]);
            $this->fail('The quiz/student/attempt-number unique key must reject duplicates.');
        } catch (QueryException) {
            $this->assertDatabaseCount('quiz_attempts', 2);
        }
    }

    public function test_submission_and_answer_mutations_lock_in_canonical_order_and_preserve_grading(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair, totalMarks: 2, passingMarks: 1);
        $manualQuestion = $this->createQuestion($pair, $quiz, 'short_answer', 1);
        $automaticQuestion = $this->createQuestion($pair, $quiz, 'mcq', 1);
        $correctOption = QuestionOption::create([
            'question_bank_id' => $automaticQuestion->id,
            'option_text' => 'Correct',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $wrongOption = QuestionOption::create([
            'question_bank_id' => $automaticQuestion->id,
            'option_text' => 'Wrong',
            'is_correct' => false,
            'sort_order' => 2,
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['student_user']);

        $attemptId = (int) $this->postJson('/api/quiz-attempts', [
            'quiz_id' => $quiz->id,
            'student_profile_id' => $pair['student']->id,
        ])->assertCreated()->json('data.id');
        $locks->events = [];

        $manualAnswer = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Student response',
        ])->assertCreated();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'quiz-question', 'attempt'],
            array_column($locks->events, 0)
        );
        $manualAnswerId = (int) $manualAnswer->json('data.id');

        $locks->events = [];
        $automaticAnswer = $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $automaticQuestion->id,
            'question_option_id' => $wrongOption->id,
        ])->assertCreated();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'quiz-question', 'question-option', 'attempt'],
            array_column($locks->events, 0)
        );
        $automaticAnswerId = (int) $automaticAnswer->json('data.id');

        $locks->events = [];
        $this->patchJson('/api/quiz-answers/' . $automaticAnswerId, [
            'question_option_id' => $correctOption->id,
        ])->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'quiz-question', 'question-option', 'attempt', 'answer'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $automaticAnswerId,
            'is_correct' => true,
            'marks_obtained' => 1,
        ]);

        $locks->events = [];
        $this->deleteJson('/api/quiz-answers/' . $automaticAnswerId)->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'quiz-question', 'question-option', 'attempt', 'answer'],
            array_column($locks->events, 0)
        );
        $this->assertSoftDeleted('quiz_answers', ['id' => $automaticAnswerId]);

        $locks->events = [];
        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attemptId,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Duplicate response',
        ])->assertUnprocessable()->assertJsonValidationErrors('question_bank_id');
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $manualAnswerId,
            'answer_text' => 'Student response',
        ]);

        $this->patchJson('/api/quiz-attempts/' . $attemptId, [
            'status' => 'submitted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.result_status', 'pending');
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'attempt', 'answers'],
            array_column($locks->events, 0)
        );
        $this->assertSame('0.00', QuizAttempt::findOrFail($attemptId)->marks_obtained);

        $this->authenticate($pair['teacher_user']);
        $locks->events = [];
        $this->patchJson('/api/quiz-answers/' . $manualAnswerId, [
            'marks_obtained' => 1,
        ])->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'quiz-question', 'attempt', 'answer'],
            array_column($locks->events, 0)
        );
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attemptId,
            'status' => 'evaluated',
            'marks_obtained' => 1,
        ]);
    }

    public function test_attempt_deletion_uses_shared_parent_and_evidence_locks(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair);
        $attempt = $this->createAttempt($quiz, $pair['student']);
        $question = $this->createQuestion($pair, $quiz, 'mcq', 1);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $question->id,
            'answer_text' => 'Response',
            'is_correct' => false,
        ]);
        $admin = $this->createUserWithRole('institution-admin');
        InstitutionUser::create([
            'institution_id' => $pair['institution']->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($admin);

        $this->deleteJson('/api/quiz-attempts/' . $attempt->id)->assertOk();
        $this->assertSame(
            ['course', 'student', 'enrollments', 'quiz', 'attempt', 'answers'],
            array_column($locks->events, 0)
        );
        $this->assertSoftDeleted('quiz_attempts', ['id' => $attempt->id]);
    }

    public function test_answer_relationships_enrollment_and_closed_state_are_revalidated(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair);
        $attempt = $this->createAttempt($quiz, $pair['student']);
        $question = $this->createQuestion($pair, $quiz, 'mcq', 1);
        $otherQuestion = QuestionBank::create([
            'course_id' => $pair['course']->id,
            'question_text' => 'Not linked to this quiz',
            'question_type' => 'mcq',
            'marks' => 1,
            'status' => 'active',
        ]);
        $wrongOption = QuestionOption::create([
            'question_bank_id' => $otherQuestion->id,
            'option_text' => 'Other question option',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $this->authenticate($pair['student_user']);

        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $otherQuestion->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('question_bank_id');

        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $question->id,
            'question_option_id' => $wrongOption->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('question_option_id');

        $pair['enrollment']->update(['status' => 'cancelled']);
        $this->postJson('/api/quiz-answers', [
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $question->id,
        ])->assertForbidden();

        $pair['enrollment']->update(['status' => 'active']);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $question->id,
            'answer_text' => 'Existing',
            'is_correct' => false,
        ]);
        $attempt->update(['status' => 'evaluated']);
        $this->patchJson('/api/quiz-answers/' . $answer->id, [
            'answer_text' => 'Changed after evaluation',
        ])->assertForbidden();
        $this->deleteJson('/api/quiz-answers/' . $answer->id)->assertForbidden();
    }

    public function test_outer_rollback_reverses_attempt_answer_and_grading_changes(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair, totalMarks: 2, passingMarks: 1);
        $manualQuestion = $this->createQuestion($pair, $quiz, 'short_answer', 2);
        $attempt = $this->createAttempt($quiz, $pair['student']);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $manualQuestion->id,
            'answer_text' => 'Response',
            'is_correct' => false,
            'marks_obtained' => 0,
        ]);
        $this->authenticate($pair['student_user']);

        try {
            DB::transaction(function () use ($attempt): void {
                $this->patchJson('/api/quiz-attempts/' . $attempt->id, [
                    'status' => 'submitted',
                ])->assertOk();
                throw new RuntimeException('Force outer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->id,
            'status' => 'in_progress',
            'submitted_at' => null,
            'result_status' => 'pending',
        ]);

        $this->authenticate($pair['teacher_user']);
        try {
            DB::transaction(function () use ($answer): void {
                $this->patchJson('/api/quiz-answers/' . $answer->id, [
                    'marks_obtained' => 1,
                ])->assertOk();
                throw new RuntimeException('Force outer answer rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer answer rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'marks_obtained' => 0,
            'manually_graded_at' => null,
        ]);
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->id,
            'status' => 'in_progress',
            'marks_obtained' => 0,
        ]);
    }

    private function createPair(): array
    {
        $institution = Institution::create([
            'name' => 'Quiz Writer ' . Str::random(8),
            'code' => 'QW-' . Str::upper(Str::random(12)),
        ]);
        $teacherUser = $this->createUserWithRole('teacher');
        $teacher = TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);
        $studentUser = $this->createUserWithRole('student');
        $student = StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'roll_number' => 'QW-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $course = Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => 'Quiz Writer Course',
            'slug' => 'quiz-writer-' . Str::lower(Str::random(12)),
            'status' => 'published',
        ]);
        $enrollment = CourseEnrollment::create([
            'course_id' => $course->id,
            'student_profile_id' => $student->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);

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

    private function createQuiz(
        array $pair,
        float $totalMarks = 1,
        float $passingMarks = 1
    ): Quiz {
        return Quiz::create([
            'course_id' => $pair['course']->id,
            'teacher_profile_id' => $pair['teacher']->id,
            'title' => 'Quiz ' . Str::random(8),
            'total_marks' => $totalMarks,
            'passing_marks' => $passingMarks,
            'status' => 'published',
        ]);
    }

    private function createQuestion(
        array $pair,
        Quiz $quiz,
        string $type,
        float $marks
    ): QuestionBank {
        $question = QuestionBank::create([
            'course_id' => $pair['course']->id,
            'question_text' => 'Question ' . Str::random(8),
            'question_type' => $type,
            'marks' => $marks,
            'status' => 'active',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $question->id,
            'marks' => $marks,
            'sort_order' => 1,
        ]);

        return $question;
    }

    private function createAttempt(Quiz $quiz, StudentProfile $student): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now(),
            'total_marks' => $quiz->total_marks,
            'status' => 'in_progress',
            'result_status' => 'pending',
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

    private function recordingLocks(): RecordingQuizCourseAssessmentLockService
    {
        $service = new RecordingQuizCourseAssessmentLockService();
        $this->app->instance(CourseAssessmentLockService::class, $service);

        return $service;
    }
}

final class RecordingQuizCourseAssessmentLockService extends CourseAssessmentLockService
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $course = parent::lockCourse($courseId, $withTrashed);
        $this->events[] = ['course', $courseId];

        return $course;
    }

    public function lockStudent(int $studentProfileId): StudentProfile
    {
        $student = parent::lockStudent($studentProfileId);
        $this->events[] = ['student', $studentProfileId];

        return $student;
    }

    public function lockCourseEnrollments(array $pairs): \Illuminate\Support\Collection
    {
        $enrollments = parent::lockCourseEnrollments($pairs);
        $this->events[] = ['enrollments', $pairs];

        return $enrollments;
    }

    public function lockQuizDefinitions(array $quizIds): \Illuminate\Support\Collection
    {
        $quizzes = parent::lockQuizDefinitions($quizIds);
        $this->events[] = ['quiz', $quizIds];

        return $quizzes;
    }

    public function lockQuizQuestionForPair(int $quizId, int $questionBankId): ?QuizQuestion
    {
        $question = parent::lockQuizQuestionForPair($quizId, $questionBankId);
        $this->events[] = ['quiz-question', [$quizId, $questionBankId]];

        return $question;
    }

    public function lockQuestionOptionForBank(
        int $questionOptionId,
        ?int $questionBankId
    ): ?QuestionOption {
        $option = parent::lockQuestionOptionForBank($questionOptionId, $questionBankId);
        $this->events[] = ['question-option', [$questionOptionId, $questionBankId]];

        return $option;
    }

    public function lockAttemptsForQuizAndStudent(
        int $quizId,
        int $studentProfileId
    ): \Illuminate\Support\Collection {
        $attempts = parent::lockAttemptsForQuizAndStudent($quizId, $studentProfileId);
        $this->events[] = ['attempts', [$quizId, $studentProfileId]];

        return $attempts;
    }

    public function lockAttemptForMutation(
        int $attemptId,
        ?int $questionBankId = null,
        ?int $questionOptionId = null
    ): QuizAttempt {
        $attempt = parent::lockAttemptForMutation(
            $attemptId,
            $questionBankId,
            $questionOptionId
        );
        $this->events[] = ['attempt', $attemptId];

        return $attempt;
    }

    public function lockQuizAnswerForAttempt(int $answerId, int $attemptId): QuizAnswer
    {
        $answer = parent::lockQuizAnswerForAttempt($answerId, $attemptId);
        $this->events[] = ['answer', [$answerId, $attemptId]];

        return $answer;
    }

    public function lockQuizAnswersForAttempt(int $attemptId): \Illuminate\Support\Collection
    {
        $answers = parent::lockQuizAnswersForAttempt($attemptId);
        $this->events[] = ['answers', $attemptId];

        return $answers;
    }
}
