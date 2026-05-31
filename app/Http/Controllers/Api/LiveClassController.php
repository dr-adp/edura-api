<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveClass;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class LiveClassController extends Controller
{
    public function index(): JsonResponse
    {
        $liveClasses = LiveClass::with([
            'institution',
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user',
            'batch'
        ])
            ->latest('scheduled_start_time')
            ->paginate(20);

        return response()->json([
            'message' => 'Live classes fetched successfully.',
            'data' => $liveClasses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'platform' => ['nullable', 'in:google_meet,zoom,jitsi,microsoft_teams,other'],
            'meeting_url' => ['required', 'string', 'max:1000'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:255'],

            'scheduled_start_time' => ['required', 'date'],
            'scheduled_end_time' => ['nullable', 'date', 'after:scheduled_start_time'],

            'recording_url' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:scheduled,live,completed,cancelled'],
        ]);

        $liveClass = LiveClass::create($validated);

        return response()->json([
            'message' => 'Live class created successfully.',
            'data' => $liveClass->load([
                'institution',
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user',
                'batch'
            ]),
        ], 201);
    }

    public function show(LiveClass $liveClass): JsonResponse
    {
        return response()->json([
            'message' => 'Live class fetched successfully.',
            'data' => $liveClass->load([
                'institution',
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user',
                'batch'
            ]),
        ]);
    }

    public function update(Request $request, LiveClass $liveClass): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'platform' => ['nullable', 'in:google_meet,zoom,jitsi,microsoft_teams,other'],
            'meeting_url' => ['sometimes', 'string', 'max:1000'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:255'],

            'scheduled_start_time' => ['sometimes', 'date'],
            'scheduled_end_time' => ['nullable', 'date', 'after:scheduled_start_time'],

            'recording_url' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:scheduled,live,completed,cancelled'],
        ]);

        $liveClass->update($validated);

        return response()->json([
            'message' => 'Live class updated successfully.',
            'data' => $liveClass->fresh()->load([
                'institution',
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user',
                'batch'
            ]),
        ]);
    }

    public function destroy(LiveClass $liveClass): JsonResponse
    {
        $liveClass->delete();

        return response()->json([
            'message' => 'Live class deleted successfully.',
        ]);
    }
}
