<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveClassAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class LiveClassAttendanceController extends Controller
{
    public function index(): JsonResponse
    {
        $attendances = LiveClassAttendance::with([
            'liveClass.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Live class attendance records fetched successfully.',
            'data' => $attendances,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'live_class_id' => ['required', 'exists:live_classes,id'],
            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('live_class_attendances', 'student_profile_id')
                    ->where('live_class_id', $request->live_class_id),
            ],
            'attendance_status' => ['nullable', 'in:present,absent,late,excused'],
            'joined_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $attendance = LiveClassAttendance::create($validated);

        return response()->json([
            'message' => 'Live class attendance marked successfully.',
            'data' => $attendance->load([
                'liveClass.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(LiveClassAttendance $liveClassAttendance): JsonResponse
    {
        return response()->json([
            'message' => 'Live class attendance fetched successfully.',
            'data' => $liveClassAttendance->load([
                'liveClass.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, LiveClassAttendance $liveClassAttendance): JsonResponse
    {
        $validated = $request->validate([
            'live_class_id' => ['sometimes', 'exists:live_classes,id'],
            'student_profile_id' => [
                'sometimes',
                'exists:student_profiles,id',
                Rule::unique('live_class_attendances', 'student_profile_id')
                    ->where('live_class_id', $request->live_class_id ?? $liveClassAttendance->live_class_id)
                    ->ignore($liveClassAttendance->id),
            ],
            'attendance_status' => ['nullable', 'in:present,absent,late,excused'],
            'joined_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $liveClassAttendance->update($validated);

        return response()->json([
            'message' => 'Live class attendance updated successfully.',
            'data' => $liveClassAttendance->fresh()->load([
                'liveClass.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(LiveClassAttendance $liveClassAttendance): JsonResponse
    {
        $liveClassAttendance->delete();

        return response()->json([
            'message' => 'Live class attendance deleted successfully.',
        ]);
    }
}
