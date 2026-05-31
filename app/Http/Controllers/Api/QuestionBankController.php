<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionBank;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class QuestionBankController extends Controller
{
    public function index(): JsonResponse
    {
        $questions = QuestionBank::with(['course', 'lesson'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Question bank fetched successfully.',
            'data' => $questions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'question_text' => ['required', 'string', 'max:1000'],
            'question_description' => ['nullable', 'string'],

            'question_type' => ['nullable', 'in:mcq,true_false,short_answer,long_answer,fill_blank'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],

            'marks' => ['nullable', 'numeric', 'min:0'],
            'topic' => ['nullable', 'string', 'max:255'],
            'explanation' => ['nullable', 'string'],

            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $question = QuestionBank::create($validated);

        return response()->json([
            'message' => 'Question created successfully.',
            'data' => $question->load(['course', 'lesson']),
        ], 201);
    }

    public function show(QuestionBank $questionBank): JsonResponse
    {
        return response()->json([
            'message' => 'Question fetched successfully.',
            'data' => $questionBank->load(['course', 'lesson']),
        ]);
    }

    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'question_text' => ['sometimes', 'string', 'max:1000'],
            'question_description' => ['nullable', 'string'],

            'question_type' => ['nullable', 'in:mcq,true_false,short_answer,long_answer,fill_blank'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],

            'marks' => ['nullable', 'numeric', 'min:0'],
            'topic' => ['nullable', 'string', 'max:255'],
            'explanation' => ['nullable', 'string'],

            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $questionBank->update($validated);

        return response()->json([
            'message' => 'Question updated successfully.',
            'data' => $questionBank->fresh()->load(['course', 'lesson']),
        ]);
    }

    public function destroy(QuestionBank $questionBank): JsonResponse
    {
        $questionBank->delete();

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
    }
}
