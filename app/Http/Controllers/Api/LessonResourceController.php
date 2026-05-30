<?php

namespace App\Http\Controllers\Api;

use App\Models\LessonResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class LessonResourceController extends Controller
{
    public function index(): JsonResponse
    {
        $resources = LessonResource::with('lesson')
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json([
            'message' => 'Lesson resources fetched successfully.',
            'data' => $resources,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => ['required', 'exists:lessons,id'],
            'title' => ['required', 'string', 'max:255'],
            'resource_type' => ['nullable', 'in:text,pdf,video,image,link,document,other'],
            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4,mov,avi', 'max:51200'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('lesson-resources', 'public');
        }

        unset($validated['file']);

        $resource = LessonResource::create($validated);

        return response()->json([
            'message' => 'Lesson resource created successfully.',
            'data' => $resource->load('lesson'),
        ], 201);
    }

    public function show(LessonResource $lessonResource): JsonResponse
    {
        return response()->json([
            'message' => 'Lesson resource fetched successfully.',
            'data' => $lessonResource->load('lesson'),
        ]);
    }

    public function update(Request $request, LessonResource $lessonResource): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => ['sometimes', 'exists:lessons,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'resource_type' => ['nullable', 'in:text,pdf,video,image,link,document,other'],
            'content' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4,mov,avi', 'max:51200'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($request->hasFile('file')) {
            if ($lessonResource->file_path && Storage::disk('public')->exists($lessonResource->file_path)) {
                Storage::disk('public')->delete($lessonResource->file_path);
            }

            $validated['file_path'] = $request->file('file')->store('lesson-resources', 'public');
        }

        unset($validated['file']);

        $lessonResource->update($validated);

        return response()->json([
            'message' => 'Lesson resource updated successfully.',
            'data' => $lessonResource->fresh()->load('lesson'),
        ]);
    }

    public function destroy(LessonResource $lessonResource): JsonResponse
    {
        if ($lessonResource->file_path && Storage::disk('public')->exists($lessonResource->file_path)) {
            Storage::disk('public')->delete($lessonResource->file_path);
        }

        $lessonResource->delete();

        return response()->json([
            'message' => 'Lesson resource deleted successfully.',
        ]);
    }
}
