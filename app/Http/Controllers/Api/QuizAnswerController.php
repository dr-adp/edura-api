<?php

namespace App\Http\Controllers\Api;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class QuizAnswerController extends Controller
{
    public function index(): JsonResponse
    {
        $answers = QuizAnswer::with([
            'quizAttempt.quiz',
            'questionBank.options',
            'questionOption'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Quiz answers fetched successfully.',
            'data' => $answers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_attempt_id' => ['required', 'exists:quiz_attempts,id'],
            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique('quiz_answers', 'question_bank_id')
                    ->where('quiz_attempt_id', $request->quiz_attempt_id),
            ],
            'question_option_id' => ['nullable', 'exists:question_options,id'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $attempt = QuizAttempt::with('quiz')->findOrFail($validated['quiz_attempt_id']);

        $quizQuestion = QuizQuestion::where('quiz_id', $attempt->quiz_id)
            ->where('question_bank_id', $validated['question_bank_id'])
            ->first();

        $marksForQuestion = $quizQuestion?->marks ?? 0;

        $isCorrect = false;
        $marksObtained = 0;

        if (!empty($validated['question_option_id'])) {
            $option = QuestionOption::find($validated['question_option_id']);

            if ($option && $option->is_correct) {
                $isCorrect = true;
                $marksObtained = $marksForQuestion;
            }
        }

        $answer = QuizAnswer::create([
            'quiz_attempt_id' => $validated['quiz_attempt_id'],
            'question_bank_id' => $validated['question_bank_id'],
            'question_option_id' => $validated['question_option_id'] ?? null,
            'answer_text' => $validated['answer_text'] ?? null,
            'is_correct' => $isCorrect,
            'marks_obtained' => $marksObtained,
        ]);

        $this->recalculateQuizAttempt($attempt);

        return response()->json([
            'message' => 'Quiz answer submitted successfully.',
            'data' => $answer->load([
                'quizAttempt.quiz',
                'questionBank.options',
                'questionOption'
            ]),
        ], 201);
    }

    public function show(QuizAnswer $quizAnswer): JsonResponse
    {
        return response()->json([
            'message' => 'Quiz answer fetched successfully.',
            'data' => $quizAnswer->load([
                'quizAttempt.quiz',
                'questionBank.options',
                'questionOption'
            ]),
        ]);
    }

    public function update(Request $request, QuizAnswer $quizAnswer): JsonResponse
    {
        $validated = $request->validate([
            'question_option_id' => ['nullable', 'exists:question_options,id'],
            'answer_text' => ['nullable', 'string'],
            'marks_obtained' => ['nullable', 'numeric', 'min:0'],
            'is_correct' => ['boolean'],
        ]);

        $attempt = $quizAnswer->quizAttempt;

        if (array_key_exists('question_option_id', $validated)) {
            $isCorrect = false;
            $marksObtained = 0;

            if (!empty($validated['question_option_id'])) {
                $option = QuestionOption::find($validated['question_option_id']);

                $quizQuestion = QuizQuestion::where('quiz_id', $attempt->quiz_id)
                    ->where('question_bank_id', $quizAnswer->question_bank_id)
                    ->first();

                if ($option && $option->is_correct) {
                    $isCorrect = true;
                    $marksObtained = $quizQuestion?->marks ?? 0;
                }
            }

            $validated['is_correct'] = $isCorrect;
            $validated['marks_obtained'] = $marksObtained;
        }

        $quizAnswer->update($validated);

        $this->recalculateQuizAttempt($attempt);

        return response()->json([
            'message' => 'Quiz answer updated successfully.',
            'data' => $quizAnswer->fresh()->load([
                'quizAttempt.quiz',
                'questionBank.options',
                'questionOption'
            ]),
        ]);
    }

    public function destroy(QuizAnswer $quizAnswer): JsonResponse
    {
        $attempt = $quizAnswer->quizAttempt;

        $quizAnswer->delete();

        $this->recalculateQuizAttempt($attempt);

        return response()->json([
            'message' => 'Quiz answer deleted successfully.',
        ]);
    }

    private function recalculateQuizAttempt(QuizAttempt $attempt): void
    {
        $marksObtained = QuizAnswer::where('quiz_attempt_id', $attempt->id)->sum('marks_obtained');
        $totalMarks = $attempt->total_marks > 0 ? $attempt->total_marks : 1;

        $percentage = round(($marksObtained / $totalMarks) * 100, 2);

        $resultStatus = $marksObtained >= $attempt->quiz->passing_marks ? 'passed' : 'failed';

        $attempt->update([
            'marks_obtained' => $marksObtained,
            'percentage' => $percentage,
            'result_status' => $resultStatus,
        ]);
    }
}
