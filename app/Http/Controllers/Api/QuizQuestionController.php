<?php

namespace App\Http\Controllers\Api;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class QuizQuestionController extends Controller
{
    public function index(): JsonResponse
    {
        $quizQuestions = QuizQuestion::with([
            'quiz',
            'questionBank.options'
        ])
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Quiz questions fetched successfully.',
            'data' => $quizQuestions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique('quiz_questions', 'question_bank_id')
                    ->where('quiz_id', $request->quiz_id),
            ],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $quizQuestion = QuizQuestion::create($validated);

        $this->recalculateQuizTotalMarks($quizQuestion->quiz);

        return response()->json([
            'message' => 'Question added to quiz successfully.',
            'data' => $quizQuestion->load([
                'quiz',
                'questionBank.options'
            ]),
        ], 201);
    }

    public function show(QuizQuestion $quizQuestion): JsonResponse
    {
        return response()->json([
            'message' => 'Quiz question fetched successfully.',
            'data' => $quizQuestion->load([
                'quiz',
                'questionBank.options'
            ]),
        ]);
    }

    public function update(Request $request, QuizQuestion $quizQuestion): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['sometimes', 'exists:quizzes,id'],
            'question_bank_id' => [
                'sometimes',
                'exists:question_banks,id',
                Rule::unique('quiz_questions', 'question_bank_id')
                    ->where('quiz_id', $request->quiz_id ?? $quizQuestion->quiz_id)
                    ->ignore($quizQuestion->id),
            ],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $quizQuestion->update($validated);

        $this->recalculateQuizTotalMarks($quizQuestion->fresh()->quiz);

        return response()->json([
            'message' => 'Quiz question updated successfully.',
            'data' => $quizQuestion->fresh()->load([
                'quiz',
                'questionBank.options'
            ]),
        ]);
    }

    public function destroy(QuizQuestion $quizQuestion): JsonResponse
    {
        $quiz = $quizQuestion->quiz;

        $quizQuestion->delete();

        $this->recalculateQuizTotalMarks($quiz);

        return response()->json([
            'message' => 'Quiz question removed successfully.',
        ]);
    }

    private function recalculateQuizTotalMarks(Quiz $quiz): void
    {
        $totalMarks = QuizQuestion::where('quiz_id', $quiz->id)->sum('marks');

        $quiz->update([
            'total_marks' => $totalMarks,
        ]);
    }
}
