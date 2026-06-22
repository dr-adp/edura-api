<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\Course;
use App\Models\StudentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\InstitutionUser;

class AttendanceRecordController extends Controller
{
    private const STATUSES = [
        'present',
        'absent',
        'late',
        'half_day',
        'excused',
    ];

    private const RELATIONS = [
        'institution',
        'batch',
        'course',
        'studentProfile.user',
        'studentProfile.batch',
        'markedBy',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateFilters($request);

        $records = $this->filteredQuery($validated)
            ->with(self::RELATIONS)
            ->latest('attendance_date')
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'message' => 'Attendance records fetched successfully.',
            'data' => $records,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRecord($request);
        $data = $this->prepareRecordData($validated, $request);

        $studentProfile = StudentProfile::findOrFail(
            $data['student_profile_id']
        );

        $course = null;

        if (!empty($data['course_id'])) {

            $course = Course::findOrFail(
                $data['course_id']
            );
        }

        $this->authorizeAttendanceRecord(
            course: $course,
            studentProfile: $studentProfile
        );
        $this->ensureCheckoutIsAfterCheckin($data);

        $this->ensureRecordIsUnique(
            (int) $data['student_profile_id'],
            $data['attendance_date']
        );

        $record = AttendanceRecord::create($data);

        return response()->json([
            'message' => 'Attendance record created successfully.',
            'data' => $record->load(self::RELATIONS),
        ], 201);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'attendance_date' => ['nullable', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'exists:student_profiles,id'],
            'records.*.attendance_status' => ['nullable', Rule::in(self::STATUSES)],
            'records.*.check_in_at' => ['nullable', 'date'],
            'records.*.check_out_at' => ['nullable', 'date'],
            'records.*.remarks' => ['nullable', 'string'],
        ]);

        $sharedData = array_filter([
            'institution_id' => $validated['institution_id'] ?? null,
            'batch_id' => $validated['batch_id'] ?? null,
            'course_id' => $validated['course_id'] ?? null,
            'attendance_date' => $validated['attendance_date'] ?? null,
        ], function ($value) {
            return $value !== null;
        });

        $records = DB::transaction(function () use ($validated, $sharedData, $request) {
            $savedRecords = collect();

            foreach ($validated['records'] as $recordInput) {
                $data = $this->prepareRecordData(
                    array_merge($sharedData, $recordInput),
                    $request
                );
                $studentProfile = StudentProfile::findOrFail(
                    $data['student_profile_id']
                );

                $course = null;

                if (!empty($data['course_id'])) {

                    $course = Course::findOrFail(
                        $data['course_id']
                    );
                }

                $this->authorizeAttendanceRecord(
                    course: $course,
                    studentProfile: $studentProfile
                );
                $this->ensureCheckoutIsAfterCheckin($data);

                $savedRecords->push($this->updateOrCreateRecordForDate($data));
            }

            return $savedRecords;
        });

        return response()->json([
            'message' => 'Attendance records saved successfully.',
            'data' => $records->map(function (AttendanceRecord $record) {
                return $record->load(self::RELATIONS);
            })->values(),
        ], 201);
    }

    public function show(AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorizeAttendanceRecord(
            attendanceRecord: $attendanceRecord
        );
        return response()->json([
            'message' => 'Attendance record fetched successfully.',
            'data' => $attendanceRecord->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorizeAttendanceRecord(
            attendanceRecord: $attendanceRecord
        );

        $validated = $this->validateRecord(
            $request,
            true
        );

        $data = $this->prepareRecordData(
            $validated,
            $request,
            $attendanceRecord
        );

        /*
    |--------------------------------------------------------------------------
    | Re-Authorize New Target Data
    |--------------------------------------------------------------------------
    */
        $studentProfile = StudentProfile::findOrFail(
            $data['student_profile_id']
        );

        $course = null;

        if (!empty($data['course_id'])) {

            $course = Course::findOrFail(
                $data['course_id']
            );
        }

        $this->authorizeAttendanceRecord(
            course: $course,
            studentProfile: $studentProfile
        );

        $this->ensureCheckoutIsAfterCheckin(
            $data
        );

        $this->ensureRecordIsUnique(
            (int) $data['student_profile_id'],
            $data['attendance_date'],
            $attendanceRecord->id
        );

        $attendanceRecord->update(
            $data
        );

        return response()->json([
            'message' => 'Attendance record updated successfully.',
            'data' => $attendanceRecord
                ->fresh()
                ->load(self::RELATIONS),
        ]);
    }

    public function destroy(AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorizeAttendanceRecord(
            attendanceRecord: $attendanceRecord
        );
        $attendanceRecord->delete();

        return response()->json([
            'message' => 'Attendance record deleted successfully.',
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $this->validateFilters($request);

        /*
    |--------------------------------------------------------------------------
    | Explicit Course Authorization
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['course_id'])) {

            $course = Course::findOrFail(
                $validated['course_id']
            );

            $this->authorizeAttendanceRecord(
                course: $course
            );
        }

        $fromDate = $validated['date']
            ?? $validated['from_date']
            ?? now()->startOfMonth()->toDateString();

        $toDate = $validated['date']
            ?? $validated['to_date']
            ?? now()->toDateString();

        $records = $this->filteredQuery($validated)
            ->with([
                'institution',
                'batch',
                'course',
                'studentProfile.user',
                'studentProfile.batch',
            ])
            ->whereDate(
                'attendance_date',
                '>=',
                $fromDate
            )
            ->whereDate(
                'attendance_date',
                '<=',
                $toDate
            )
            ->orderBy('attendance_date')
            ->get();

        $statusCounts = $this->statusCounts(
            $records
        );

        $effectivePresent =
            $statusCounts['present']
            + $statusCounts['late']
            + ($statusCounts['half_day'] * 0.5);

        $totalRecords = $records->count();

        $studentSummaries = $records
            ->groupBy('student_profile_id')
            ->map(function ($studentRecords) {

                $counts = $this->statusCounts(
                    $studentRecords
                );

                $effectiveStudentPresent =
                    $counts['present']
                    + $counts['late']
                    + ($counts['half_day'] * 0.5);

                $studentTotal =
                    $studentRecords->count();

                $firstRecord =
                    $studentRecords->first();

                return [
                    'student_profile_id' =>
                    $firstRecord->student_profile_id,

                    'student_name' =>
                    $firstRecord->studentProfile?->user?->name,

                    'roll_number' =>
                    $firstRecord->studentProfile?->roll_number,

                    'batch_id' =>
                    $firstRecord->batch_id,

                    'batch_name' =>
                    $firstRecord->batch?->name,

                    'total_records' =>
                    $studentTotal,

                    'status_counts' =>
                    $counts,

                    'attendance_percentage' =>
                    $studentTotal > 0
                        ? round(
                            ($effectiveStudentPresent / $studentTotal) * 100,
                            2
                        )
                        : 0,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Attendance report generated successfully.',
            'data' => [
                'filters' => [
                    'from_date' =>
                    $fromDate,

                    'to_date' =>
                    $toDate,

                    'institution_id' =>
                    $validated['institution_id'] ?? null,

                    'batch_id' =>
                    $validated['batch_id'] ?? null,

                    'course_id' =>
                    $validated['course_id'] ?? null,

                    'student_profile_id' =>
                    $validated['student_profile_id'] ?? null,

                    'attendance_status' =>
                    $validated['attendance_status'] ?? null,
                ],

                'summary' => [
                    'total_records' =>
                    $totalRecords,

                    'status_counts' =>
                    $statusCounts,

                    'effective_present_count' =>
                    $effectivePresent,

                    'attendance_percentage' =>
                    $totalRecords > 0
                        ? round(
                            ($effectivePresent / $totalRecords) * 100,
                            2
                        )
                        : 0,
                ],

                'students' =>
                $studentSummaries,
            ],
        ]);
    }

    private function validateRecord(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'student_profile_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'exists:student_profiles,id',
            ],
            'attendance_date' => [$isUpdate ? 'sometimes' : 'nullable', 'date'],
            'attendance_status' => ['nullable', Rule::in(self::STATUSES)],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'remarks' => ['nullable', 'string'],
        ]);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'student_profile_id' => ['nullable', 'exists:student_profiles,id'],
            'attendance_status' => ['nullable', Rule::in(self::STATUSES)],
            'date' => ['nullable', 'date'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function filteredQuery(array $filters)
    {
        /** @var User $user */
        $user = Auth::user();

        $query = AttendanceRecord::query();

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {

            // No restrictions
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            $query->where(
                'institution_id',
                $institutionUser->institution_id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $query->whereHas(
                'course',
                function ($q) use ($teacherProfile) {

                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    );
                }
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (!$studentProfile) {

                abort(
                    403,
                    'Student profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $studentProfile->id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Parent
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('parent')) {

            $parentProfile = $user->parentProfile;

            if (!$parentProfile) {

                abort(
                    403,
                    'Parent profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $parentProfile->student_profile_id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Unknown Role
    |--------------------------------------------------------------------------
    */ else {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        return $query
            ->when(
                $filters['institution_id'] ?? null,
                function ($query, $institutionId) {

                    $query->where(
                        'institution_id',
                        $institutionId
                    );
                }
            )
            ->when(
                $filters['batch_id'] ?? null,
                function ($query, $batchId) {

                    $query->where(
                        'batch_id',
                        $batchId
                    );
                }
            )
            ->when(
                $filters['course_id'] ?? null,
                function ($query, $courseId) {

                    $query->where(
                        'course_id',
                        $courseId
                    );
                }
            )
            ->when(
                $filters['student_profile_id'] ?? null,
                function ($query, $studentProfileId) {

                    $query->where(
                        'student_profile_id',
                        $studentProfileId
                    );
                }
            )
            ->when(
                $filters['attendance_status'] ?? null,
                function ($query, $status) {

                    $query->where(
                        'attendance_status',
                        $status
                    );
                }
            )
            ->when(
                $filters['date'] ?? null,
                function ($query, $date) {

                    $query->whereDate(
                        'attendance_date',
                        $date
                    );
                }
            )
            ->when(
                $filters['from_date'] ?? null,
                function ($query, $fromDate) {

                    $query->whereDate(
                        'attendance_date',
                        '>=',
                        $fromDate
                    );
                }
            )
            ->when(
                $filters['to_date'] ?? null,
                function ($query, $toDate) {

                    $query->whereDate(
                        'attendance_date',
                        '<=',
                        $toDate
                    );
                }
            );
    }

    private function prepareRecordData(
        array $validated,
        Request $request,
        ?AttendanceRecord $attendanceRecord = null
    ): array {
        $studentProfileId = $validated['student_profile_id']
            ?? $attendanceRecord?->student_profile_id;
        $studentProfile = StudentProfile::findOrFail($studentProfileId);

        $institutionId = $validated['institution_id']
            ?? $attendanceRecord?->institution_id
            ?? $studentProfile->institution_id;
        $batchId = array_key_exists('batch_id', $validated)
            ? $validated['batch_id']
            : ($attendanceRecord?->batch_id ?? $studentProfile->batch_id);
        $courseId = array_key_exists('course_id', $validated)
            ? $validated['course_id']
            : $attendanceRecord?->course_id;

        $this->ensureInstitutionMatchesStudent((int) $institutionId, $studentProfile);
        $this->ensureBatchMatchesStudentInstitution($batchId, $studentProfile);
        $this->ensureCourseMatchesStudentInstitution($courseId, $studentProfile);

        return [
            'institution_id' => $institutionId,
            'batch_id' => $batchId,
            'course_id' => $courseId,
            'student_profile_id' => $studentProfile->id,
            'marked_by_id' => $request->user()?->id ?? $attendanceRecord?->marked_by_id,
            'attendance_date' => $validated['attendance_date']
                ?? $attendanceRecord?->attendance_date?->toDateString()
                ?? now()->toDateString(),
            'attendance_status' => $validated['attendance_status']
                ?? $attendanceRecord?->attendance_status
                ?? 'present',
            'check_in_at' => array_key_exists('check_in_at', $validated)
                ? $validated['check_in_at']
                : $attendanceRecord?->check_in_at,
            'check_out_at' => array_key_exists('check_out_at', $validated)
                ? $validated['check_out_at']
                : $attendanceRecord?->check_out_at,
            'remarks' => array_key_exists('remarks', $validated)
                ? $validated['remarks']
                : $attendanceRecord?->remarks,
        ];
    }

    private function ensureRecordIsUnique(
        int $studentProfileId,
        string $attendanceDate,
        ?int $ignoreId = null
    ): void {
        $exists = AttendanceRecord::where('student_profile_id', $studentProfileId)
            ->whereDate('attendance_date', $attendanceDate)
            ->when($ignoreId, function ($query, $id) {
                $query->where('id', '!=', $id);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Attendance for this student and date already exists.',
            ]);
        }
    }

    private function updateOrCreateRecordForDate(array $data): AttendanceRecord
    {
        $record = AttendanceRecord::where('student_profile_id', $data['student_profile_id'])
            ->whereDate('attendance_date', $data['attendance_date'])
            ->first();

        if ($record) {
            $record->update($data);

            return $record->fresh();
        }

        return AttendanceRecord::create($data);
    }

    private function ensureCheckoutIsAfterCheckin(array $data): void
    {
        if (! $data['check_in_at'] || ! $data['check_out_at']) {
            return;
        }

        if (Carbon::parse($data['check_out_at'])->lt(Carbon::parse($data['check_in_at']))) {
            throw ValidationException::withMessages([
                'check_out_at' => 'Check-out time must be after or equal to check-in time.',
            ]);
        }
    }

    private function ensureInstitutionMatchesStudent(
        int $institutionId,
        StudentProfile $studentProfile
    ): void {
        if ($institutionId !== (int) $studentProfile->institution_id) {
            throw ValidationException::withMessages([
                'institution_id' => 'Institution must match the selected student profile.',
            ]);
        }
    }

    private function ensureBatchMatchesStudentInstitution(
        mixed $batchId,
        StudentProfile $studentProfile
    ): void {
        if (! $batchId) {
            return;
        }

        $batch = Batch::findOrFail($batchId);

        if ((int) $batch->institution_id !== (int) $studentProfile->institution_id) {
            throw ValidationException::withMessages([
                'batch_id' => 'Batch must belong to the student institution.',
            ]);
        }
    }

    private function ensureCourseMatchesStudentInstitution(
        mixed $courseId,
        StudentProfile $studentProfile
    ): void {
        if (! $courseId) {
            return;
        }

        $course = Course::findOrFail($courseId);

        if ($course->institution_id && (int) $course->institution_id !== (int) $studentProfile->institution_id) {
            throw ValidationException::withMessages([
                'course_id' => 'Course must belong to the student institution.',
            ]);
        }
    }

    private function statusCounts($records): array
    {
        return collect(self::STATUSES)
            ->mapWithKeys(function ($status) use ($records) {
                return [$status => $records->where('attendance_status', $status)->count()];
            })
            ->all();
    }

    private function authorizeAttendanceRecord(
        ?AttendanceRecord $attendanceRecord = null,
        ?Course $course = null,
        ?StudentProfile $studentProfile = null
    ): void {
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            $institutionId =
                $attendanceRecord?->institution_id
                ?? $course?->institution_id
                ?? $studentProfile?->institution_id;

            if (
                !$institutionId ||
                (int)$institutionId !==
                (int)$institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user
                ->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $targetCourse =
                $course
                ?? $attendanceRecord?->course;

            if (!$targetCourse) {

                abort(
                    403,
                    'Course not found.'
                );
            }

            if (
                (int)$targetCourse->teacher_profile_id !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized course access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $myProfile = $user
                ->studentProfile;

            if (!$myProfile) {

                abort(
                    403,
                    'Student profile not found.'
                );
            }

            $targetStudent =
                $studentProfile
                ?? $attendanceRecord?->studentProfile;

            if (
                !$targetStudent ||
                (int)$targetStudent->id !==
                (int)$myProfile->id
            ) {

                abort(
                    403,
                    'You can only access your own attendance.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Parent
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            $parentProfile = $user
                ->parentProfile;

            if (!$parentProfile) {

                abort(
                    403,
                    'Parent profile not found.'
                );
            }

            $targetStudent =
                $studentProfile
                ?? $attendanceRecord?->studentProfile;

            if (
                !$targetStudent ||
                (int)$targetStudent->id !==
                (int)$parentProfile->student_profile_id
            ) {

                abort(
                    403,
                    'You can only access your child attendance.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }
}
