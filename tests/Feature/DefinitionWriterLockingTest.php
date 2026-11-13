<?php

namespace Tests\Feature;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Course;
use App\Models\Institution;
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
use App\Services\QuizAttemptGradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DefinitionWriterLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiz_writers_lock_course_gates_and_preserve_attempt_history(): void
    {
        $pair = $this->createPair();
        $secondCourse = $this->createCourse($pair, 'definition-quiz-target');
        $locks = $this->recordingLocks();
        $this->authenticate($pair['teacher_user']);

        $created = $this->postJson('/api/quizzes', [
            'course_id' => $pair['course']->id,
            'title' => 'Definition locking quiz',
            'total_marks' => 10,
            'passing_marks' => 5,
            'status' => 'published',
        ])->assertCreated();
        $quizId = (int) $created->json('data.id');
        $this->assertSame(
            [['course', (int) $pair['course']->id]],
            $locks->events
        );

        $locks->events = [];
        $this->patchJson('/api/quizzes/' . $quizId, [
            'course_id' => $secondCourse->id,
            'title' => 'Moved definition locking quiz',
            'total_marks' => 12,
            'passing_marks' => 8,
        ])->assertOk()
            ->assertJsonPath('data.course_id', $secondCourse->id)
            ->assertJsonPath('data.total_marks', '12.00')
            ->assertJsonPath('data.passing_marks', '8.00');

        $this->assertSame(
            [
                ['course', min((int) $pair['course']->id, (int) $secondCourse->id)],
                ['course', max((int) $pair['course']->id, (int) $secondCourse->id)],
                ['quiz', [(int) $quizId]],
            ],
            $locks->events
        );

        $quiz = Quiz::findOrFail($quizId);
        $question = $this->createQuestion($secondCourse, 'mcq', 12);
        $attempt = $this->createAttempt($quiz, $pair['student'], 12);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $question->id,
            'answer_text' => 'Historical response',
            'is_correct' => true,
            'marks_obtained' => 7,
        ]);

        $locks->events = [];
        $this->patchJson('/api/quizzes/' . $quizId, [
            'passing_marks' => 9,
        ])->assertOk()
            ->assertJsonPath('data.passing_marks', '9.00');
        $this->assertSame([
            ['course', (int) $secondCourse->id],
            ['quiz', [(int) $quizId]],
        ], $locks->events);
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->id,
            'total_marks' => 12,
            'marks_obtained' => 0,
        ]);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'question_bank_id' => $question->id,
            'marks_obtained' => 7,
        ]);

        try {
            DB::transaction(function () use ($quizId): void {
                $this->patchJson('/api/quizzes/' . $quizId, [
                    'status' => 'closed',
                ])->assertOk();
                throw new RuntimeException('Force outer quiz rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer quiz rollback.', $exception->getMessage());
        }
        $this->assertDatabaseHas('quizzes', [
            'id' => $quizId,
            'status' => 'published',
        ]);

        $this->deleteJson('/api/quizzes/' . $quizId)->assertOk();
        $this->assertSoftDeleted('quizzes', ['id' => $quizId]);
        $this->assertDatabaseHas('quiz_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('quiz_answers', ['id' => $answer->id]);
    }

    public function test_quiz_question_reassignment_recalculates_totals_atomically_and_keeps_answers(): void
    {
        $pair = $this->createPair();
        $secondCourse = $this->createCourse($pair, 'definition-link-target');
        $firstQuiz = $this->createQuiz($pair['course'], 'Old linked quiz', 2);
        $secondQuiz = $this->createQuiz($secondCourse, 'New linked quiz', 0);
        $firstQuestion = $this->createQuestion($pair['course'], 'mcq', 2);
        $secondQuestion = $this->createQuestion($secondCourse, 'mcq', 5);
        $link = QuizQuestion::create([
            'quiz_id' => $firstQuiz->id,
            'question_bank_id' => $firstQuestion->id,
            'marks' => 2,
            'sort_order' => 1,
        ]);
        $attempt = $this->createAttempt($firstQuiz, $pair['student'], 2);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $firstQuestion->id,
            'answer_text' => 'Saved response',
            'is_correct' => true,
            'marks_obtained' => 1.25,
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['teacher_user']);

        $this->patchJson('/api/quiz-questions/' . $link->id, [
            'quiz_id' => $secondQuiz->id,
            'question_bank_id' => $secondQuestion->id,
            'marks' => 5,
        ])->assertOk();

        $expectedCourses = [(int) $pair['course']->id, (int) $secondCourse->id];
        sort($expectedCourses, SORT_NUMERIC);
        $expectedBanks = [(int) $firstQuestion->id, (int) $secondQuestion->id];
        sort($expectedBanks, SORT_NUMERIC);
        $expectedQuizzes = [(int) $firstQuiz->id, (int) $secondQuiz->id];
        sort($expectedQuizzes, SORT_NUMERIC);
        $this->assertSame([
            ['course', $expectedCourses[0]],
            ['course', $expectedCourses[1]],
            ['question-banks', $expectedBanks],
            ['quiz', $expectedQuizzes],
            ['quiz-questions', [(int) $link->id]],
        ], $locks->events);
        $this->assertDatabaseHas('quizzes', [
            'id' => $firstQuiz->id,
            'total_marks' => 0,
        ]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $secondQuiz->id,
            'total_marks' => 5,
        ]);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'question_bank_id' => $firstQuestion->id,
            'marks_obtained' => 1.25,
        ]);

        try {
            DB::transaction(function () use ($link): void {
                $this->deleteJson('/api/quiz-questions/' . $link->id)->assertOk();
                throw new RuntimeException('Force outer link rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer link rollback.', $exception->getMessage());
        }
        $this->assertDatabaseHas('quiz_questions', ['id' => $link->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $secondQuiz->id,
            'total_marks' => 5,
        ]);

        $this->deleteJson('/api/quiz-questions/' . $link->id)->assertOk();
        $this->assertDatabaseMissing('quiz_questions', ['id' => $link->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $secondQuiz->id,
            'total_marks' => 0,
        ]);
        $this->assertDatabaseHas('quiz_answers', ['id' => $answer->id]);

        $this->postJson('/api/quiz-questions', [
            'quiz_id' => $secondQuiz->id,
            'question_bank_id' => $secondQuestion->id,
            'marks' => 5,
        ])->assertCreated();
        $this->postJson('/api/quiz-questions', [
            'quiz_id' => $secondQuiz->id,
            'question_bank_id' => $secondQuestion->id,
            'marks' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('question_bank_id');
    }

    public function test_shared_question_bank_locks_every_affected_course_and_retries_changed_sets(): void
    {
        $pair = $this->createPair();
        $secondCourse = $this->createCourse($pair, 'definition-shared-second');
        $thirdCourse = $this->createCourse($pair, 'definition-shared-third');
        $bank = $this->createQuestion($pair['course'], 'short_answer', 4);
        $firstQuiz = $this->createQuiz($pair['course'], 'Shared bank quiz one', 4);
        $secondQuiz = $this->createQuiz($secondCourse, 'Shared bank quiz two', 4);
        $firstLink = QuizQuestion::create([
            'quiz_id' => $firstQuiz->id,
            'question_bank_id' => $bank->id,
            'marks' => 2,
            'sort_order' => 1,
        ]);
        $secondLink = QuizQuestion::create([
            'quiz_id' => $secondQuiz->id,
            'question_bank_id' => $bank->id,
            'marks' => 3,
            'sort_order' => 1,
        ]);

        $locks = $this->recordingLocks();
        DB::transaction(function () use ($locks, $bank): void {
            $parents = $locks->lockQuestionBanksAndCourses([(int) $bank->id]);
            $this->assertCount(2, $parents['quizzes']);
            $this->assertCount(2, $parents['quiz_questions']);
        });
        $expectedCourses = [(int) $pair['course']->id, (int) $secondCourse->id];
        sort($expectedCourses, SORT_NUMERIC);
        $expectedQuizzes = [(int) $firstQuiz->id, (int) $secondQuiz->id];
        sort($expectedQuizzes, SORT_NUMERIC);
        $expectedLinks = [(int) $firstLink->id, (int) $secondLink->id];
        sort($expectedLinks, SORT_NUMERIC);
        $this->assertSame([
            ['course', $expectedCourses[0]],
            ['course', $expectedCourses[1]],
            ['question-banks', [(int) $bank->id]],
            ['quiz', $expectedQuizzes],
            ['quiz-questions', $expectedLinks],
        ], $locks->events);

        $locks->events = [];
        $this->authenticate($pair['teacher_user']);
        $this->patchJson('/api/question-banks/' . $bank->id, [
            'question_text' => 'Attempted update across shared course links',
        ])->assertUnprocessable();
        $this->assertSame([
            ['course', $expectedCourses[0]],
            ['course', $expectedCourses[1]],
            ['question-banks', [(int) $bank->id]],
            ['quiz', $expectedQuizzes],
            ['quiz-questions', $expectedLinks],
        ], $locks->events);

        $driftingLocks = new DriftingDefinitionLockService();
        $driftingLocks->quizToMove = (int) $firstQuiz->id;
        $driftingLocks->newCourseId = (int) $thirdCourse->id;
        try {
            DB::transaction(function () use ($driftingLocks, $bank): void {
                $driftingLocks->lockQuestionBanksAndCourses([(int) $bank->id]);
            });
            $this->fail('A changed course set must request a retry.');
        } catch (AssessmentLockSetChanged $exception) {
            $this->assertContains((int) $thirdCourse->id, $exception->courseIds);
        }
        $this->assertDatabaseHas('quizzes', [
            'id' => $firstQuiz->id,
            'course_id' => $pair['course']->id,
        ]);
    }

    public function test_question_bank_history_guards_and_delete_recalculate_linked_quizzes(): void
    {
        $pair = $this->createPair();
        $quiz = $this->createQuiz($pair['course'], 'Question bank history quiz', 3);
        $bank = $this->createQuestion($pair['course'], 'short_answer', 3);
        $link = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $bank->id,
            'marks' => 3,
            'sort_order' => 1,
        ]);
        $attempt = $this->createAttempt($quiz, $pair['student'], 3);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $bank->id,
            'answer_text' => 'Awaiting manual review',
            'is_correct' => false,
            'marks_obtained' => 0,
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['teacher_user']);
        $grading = app(QuizAttemptGradingService::class);
        $this->assertTrue($grading->hasPendingManualAnswers($attempt));

        $this->patchJson('/api/question-banks/' . $bank->id, [
            'question_text' => 'Updated wording',
        ])->assertOk();
        $this->assertSame([
            ['course', (int) $pair['course']->id],
            ['question-banks', [(int) $bank->id]],
            ['quiz', [(int) $quiz->id]],
            ['quiz-questions', [(int) $link->id]],
        ], $locks->events);

        $this->patchJson('/api/question-banks/' . $bank->id, [
            'question_type' => 'mcq',
        ])->assertStatus(409);
        $this->assertSame('short_answer', $bank->fresh()->question_type);
        $this->assertTrue($grading->hasPendingManualAnswers($attempt->fresh()));

        $this->deleteJson('/api/question-banks/' . $bank->id)->assertStatus(409);
        $this->assertDatabaseHas('question_banks', ['id' => $bank->id]);
        $this->assertDatabaseHas('quiz_questions', ['id' => $link->id]);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'marks_obtained' => 0,
        ]);

        $otherPair = $this->createPair();
        $this->authenticate($otherPair['teacher_user']);
        $this->patchJson('/api/question-banks/' . $bank->id, [
            'question_text' => 'Unauthorized cross-institution update',
        ])->assertForbidden();
        $this->authenticate($pair['teacher_user']);

        $locks->events = [];
        $createdBank = $this->postJson('/api/question-banks', [
            'course_id' => $pair['course']->id,
            'question_text' => 'Question to remove after locking',
            'question_type' => 'mcq',
            'marks' => 2,
        ])->assertCreated();
        $emptyBank = QuestionBank::findOrFail((int) $createdBank->json('data.id'));
        $this->assertSame([['course', (int) $pair['course']->id]], $locks->events);
        $emptyLink = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_bank_id' => $emptyBank->id,
            'marks' => 2,
            'sort_order' => 2,
        ]);
        $emptyOption = QuestionOption::create([
            'question_bank_id' => $emptyBank->id,
            'option_text' => 'Option deleted with its bank',
            'is_correct' => false,
            'sort_order' => 1,
        ]);
        $quiz->update(['total_marks' => 5]);
        $locks->events = [];
        $this->deleteJson('/api/question-banks/' . $emptyBank->id)->assertOk();
        $this->assertDatabaseMissing('question_banks', ['id' => $emptyBank->id]);
        $this->assertDatabaseMissing('quiz_questions', ['id' => $emptyLink->id]);
        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'total_marks' => 3,
        ]);
        $this->assertSame([
            ['course', (int) $pair['course']->id],
            ['question-banks', [(int) $emptyBank->id]],
            ['quiz', [(int) $quiz->id]],
            ['quiz-questions', [(int) $emptyLink->id]],
            ['question-options', [(int) $emptyOption->id]],
        ], $locks->events);
    }

    public function test_question_option_mutations_preserve_answers_and_lock_old_and_new_banks(): void
    {
        $pair = $this->createPair();
        $secondCourse = $this->createCourse($pair, 'definition-option-second');
        $firstQuiz = $this->createQuiz($pair['course'], 'Option first quiz', 2);
        $secondQuiz = $this->createQuiz($secondCourse, 'Option second quiz', 2);
        $firstBank = $this->createQuestion($pair['course'], 'mcq', 2);
        $secondBank = $this->createQuestion($secondCourse, 'mcq', 2);
        $firstLink = QuizQuestion::create([
            'quiz_id' => $firstQuiz->id,
            'question_bank_id' => $firstBank->id,
            'marks' => 2,
            'sort_order' => 1,
        ]);
        $secondLink = QuizQuestion::create([
            'quiz_id' => $secondQuiz->id,
            'question_bank_id' => $secondBank->id,
            'marks' => 2,
            'sort_order' => 1,
        ]);
        $option = QuestionOption::create([
            'question_bank_id' => $firstBank->id,
            'option_text' => 'Correct answer',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $attempt = $this->createAttempt($firstQuiz, $pair['student'], 2);
        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_bank_id' => $firstBank->id,
            'question_option_id' => $option->id,
            'answer_text' => null,
            'is_correct' => true,
            'marks_obtained' => 1.5,
        ]);
        $locks = $this->recordingLocks();
        $this->authenticate($pair['teacher_user']);

        $this->patchJson('/api/question-options/' . $option->id, [
            'is_correct' => false,
        ])->assertOk();
        $this->assertSame([
            ['course', (int) $pair['course']->id],
            ['question-banks', [(int) $firstBank->id]],
            ['quiz', [(int) $firstQuiz->id]],
            ['quiz-questions', [(int) $firstLink->id]],
            ['question-options', [(int) $option->id]],
        ], $locks->events);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'is_correct' => true,
            'marks_obtained' => 1.5,
        ]);

        try {
            DB::transaction(function () use ($option): void {
                $this->patchJson('/api/question-options/' . $option->id, [
                    'is_correct' => true,
                ])->assertOk();
                throw new RuntimeException('Force outer option rollback.');
            });
            $this->fail('The outer transaction should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer option rollback.', $exception->getMessage());
        }
        $this->assertFalse((bool) $option->fresh()->is_correct);
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'marks_obtained' => 1.5,
        ]);

        $this->patchJson('/api/question-options/' . $option->id, [
            'question_bank_id' => $secondBank->id,
        ])->assertStatus(409);
        $this->assertSame((int) $firstBank->id, (int) $option->fresh()->question_bank_id);

        $this->deleteJson('/api/question-options/' . $option->id)->assertOk();
        $this->assertDatabaseHas('quiz_answers', [
            'id' => $answer->id,
            'question_option_id' => null,
            'marks_obtained' => 1.5,
        ]);

        $locks->events = [];
        $unansweredResponse = $this->postJson('/api/question-options', [
            'question_bank_id' => $firstBank->id,
            'option_text' => 'Unanswered option',
            'is_correct' => false,
            'sort_order' => 2,
        ])->assertCreated();
        $unansweredOption = QuestionOption::findOrFail(
            (int) $unansweredResponse->json('data.id')
        );
        $this->assertSame([
            ['course', (int) $pair['course']->id],
            ['question-banks', [(int) $firstBank->id]],
            ['quiz', [(int) $firstQuiz->id]],
            ['quiz-questions', [(int) $firstLink->id]],
        ], $locks->events);

        $locks->events = [];
        $this->patchJson('/api/question-options/' . $unansweredOption->id, [
            'question_bank_id' => $secondBank->id,
        ])->assertOk()
            ->assertJsonPath('data.question_bank_id', $secondBank->id);
        $expectedCourses = [(int) $pair['course']->id, (int) $secondCourse->id];
        sort($expectedCourses, SORT_NUMERIC);
        $expectedBanks = [(int) $firstBank->id, (int) $secondBank->id];
        sort($expectedBanks, SORT_NUMERIC);
        $expectedQuizzes = [(int) $firstQuiz->id, (int) $secondQuiz->id];
        sort($expectedQuizzes, SORT_NUMERIC);
        $expectedLinks = [(int) $firstLink->id, (int) $secondLink->id];
        sort($expectedLinks, SORT_NUMERIC);
        $this->assertSame([
            ['course', $expectedCourses[0]],
            ['course', $expectedCourses[1]],
            ['question-banks', $expectedBanks],
            ['quiz', $expectedQuizzes],
            ['quiz-questions', $expectedLinks],
            ['question-options', [(int) $unansweredOption->id]],
        ], $locks->events);
    }

    private function createPair(): array
    {
        $institution = Institution::create([
            'name' => 'Definition Lock ' . Str::random(8),
            'code' => 'DL-' . Str::upper(Str::random(12)),
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
            'roll_number' => 'DL-' . Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        $course = $this->createCourseFor($institution, $teacher, 'definition-primary');

        return [
            'institution' => $institution,
            'teacher_user' => $teacherUser,
            'teacher' => $teacher,
            'student_user' => $studentUser,
            'student' => $student,
            'course' => $course,
        ];
    }

    private function createCourse(array $pair, string $slug): Course
    {
        return $this->createCourseFor($pair['institution'], $pair['teacher'], $slug);
    }

    private function createCourseFor(Institution $institution, TeacherProfile $teacher, string $slug): Course
    {
        return Course::create([
            'institution_id' => $institution->id,
            'teacher_profile_id' => $teacher->id,
            'title' => Str::headline($slug),
            'slug' => $slug . '-' . Str::lower(Str::random(8)),
            'status' => 'published',
        ]);
    }

    private function createQuiz(Course $course, string $title, float $totalMarks): Quiz
    {
        return Quiz::create([
            'course_id' => $course->id,
            'title' => $title,
            'total_marks' => $totalMarks,
            'passing_marks' => 0,
            'status' => 'published',
        ]);
    }

    private function createQuestion(Course $course, string $type, float $marks): QuestionBank
    {
        return QuestionBank::create([
            'course_id' => $course->id,
            'question_text' => 'Definition question ' . Str::random(8),
            'question_type' => $type,
            'marks' => $marks,
            'status' => 'active',
        ]);
    }

    private function createAttempt(Quiz $quiz, StudentProfile $student, float $totalMarks): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_profile_id' => $student->id,
            'attempt_number' => 1,
            'started_at' => now(),
            'total_marks' => $totalMarks,
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

    private function recordingLocks(): RecordingDefinitionLockService
    {
        $service = new RecordingDefinitionLockService();
        $this->app->instance(CourseAssessmentLockService::class, $service);

        return $service;
    }
}

class RecordingDefinitionLockService extends CourseAssessmentLockService
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function lockCourse(int $courseId, bool $withTrashed = true): Course
    {
        $course = parent::lockCourse($courseId, $withTrashed);
        $this->events[] = ['course', $courseId];

        return $course;
    }

    public function lockQuestionBanks(array $ids): \Illuminate\Support\Collection
    {
        $banks = parent::lockQuestionBanks($ids);
        $this->events[] = ['question-banks', $ids];

        return $banks;
    }

    public function lockQuizDefinitions(array $quizIds): \Illuminate\Support\Collection
    {
        $quizzes = parent::lockQuizDefinitions($quizIds);
        $this->events[] = ['quiz', $quizIds];

        return $quizzes;
    }

    public function lockQuizQuestions(array $ids): \Illuminate\Support\Collection
    {
        $links = parent::lockQuizQuestions($ids);
        $this->events[] = ['quiz-questions', $ids];

        return $links;
    }

    public function lockQuestionOptions(array $ids): \Illuminate\Support\Collection
    {
        $options = parent::lockQuestionOptions($ids);
        $this->events[] = ['question-options', $ids];

        return $options;
    }
}

final class DriftingDefinitionLockService extends RecordingDefinitionLockService
{
    public int $quizToMove;

    public int $newCourseId;

    private bool $didDrift = false;

    public function lockCourses(array $courseIds): void
    {
        parent::lockCourses($courseIds);
        if (!$this->didDrift) {
            $this->didDrift = true;
            Quiz::query()->whereKey($this->quizToMove)->update([
                'course_id' => $this->newCourseId,
            ]);
        }
    }
}
