<?php

namespace App\Http\Controllers\Api;

use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class CourseSectionController extends Controller
{
    public function index(): JsonResponse
    {
        $sections = CourseSection::with('course')
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Course sections fetched successfully.',
            'data' => $sections,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $section = CourseSection::create($validated);

        return response()->json([
            'message' => 'Course section created successfully.',
            'data' => $section->load('course'),
        ], 201);
    }

    public function show(CourseSection $courseSection): JsonResponse
    {
        return response()->json([
            'message' => 'Course section fetched successfully.',
            'data' => $courseSection->load('course'),
        ]);
    }

    public function update(Request $request, CourseSection $courseSection): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $courseSection->update($validated);

        return response()->json([
            'message' => 'Course section updated successfully.',
            'data' => $courseSection->load('course'),
        ]);
    }

    public function destroy(CourseSection $courseSection): JsonResponse
    {
        $courseSection->delete();

        return response()->json([
            'message' => 'Course section deleted successfully.',
        ]);
    }
}
