<?php

namespace App\Http\Controllers\Api;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class QuestionOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $options = QuestionOption::with('questionBank')
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Question options fetched successfully.',
            'data' => $options,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_bank_id' => ['required', 'exists:question_banks,id'],
            'option_text' => ['required', 'string', 'max:1000'],
            'is_correct' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $option = QuestionOption::create($validated);

        return response()->json([
            'message' => 'Question option created successfully.',
            'data' => $option->load('questionBank'),
        ], 201);
    }

    public function show(QuestionOption $questionOption): JsonResponse
    {
        return response()->json([
            'message' => 'Question option fetched successfully.',
            'data' => $questionOption->load('questionBank'),
        ]);
    }

    public function update(Request $request, QuestionOption $questionOption): JsonResponse
    {
        $validated = $request->validate([
            'question_bank_id' => ['sometimes', 'exists:question_banks,id'],
            'option_text' => ['sometimes', 'string', 'max:1000'],
            'is_correct' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $questionOption->update($validated);

        return response()->json([
            'message' => 'Question option updated successfully.',
            'data' => $questionOption->fresh()->load('questionBank'),
        ]);
    }

    public function destroy(QuestionOption $questionOption): JsonResponse
    {
        $questionOption->delete();

        return response()->json([
            'message' => 'Question option deleted successfully.',
        ]);
    }
}
