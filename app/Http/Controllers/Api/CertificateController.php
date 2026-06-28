<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\InstitutionUser;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateController extends BaseApiController
{
    private const CERTIFICATE_RELATIONS = [
        'course.institution',
        'studentProfile.user',
        'gradebook',
    ];

    private const VALID_ENROLLMENT_STATUSES = [
        'active',
        'completed',
    ];

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Certificate::with(self::CERTIFICATE_RELATIONS);

        /*
        |--------------------------------------------------------------------------
        | Scoped Certificate Listing
        |--------------------------------------------------------------------------
        */
        $this->scopeCertificateQuery($query, $user);

        $certificates = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $certificates,
            'Certificates fetched successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $studentProfile = StudentProfile::findOrFail($validated['student_profile_id']);

        /*
        |--------------------------------------------------------------------------
        | Ownership And Enrollment Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeCertificatePairManagement(
            $course,
            $studentProfile,
            $user
        );

        $gradebook = Gradebook::where('course_id', $course->id)
            ->where('student_profile_id', $studentProfile->id)
            ->first();

        if (! $gradebook) {
            return response()->json([
                'message' => 'Gradebook record not found. Please calculate gradebook first.',
            ], 422);
        }

        if ($gradebook->result_status !== 'passed') {
            return response()->json([
                'message' => 'Certificate cannot be issued because student has not passed.',
            ], 422);
        }

        $existingCertificate = Certificate::where('course_id', $course->id)
            ->where('student_profile_id', $studentProfile->id)
            ->first();

        $certificate = Certificate::updateOrCreate(
            [
                'course_id' => $course->id,
                'student_profile_id' => $studentProfile->id,
            ],
            [
                'gradebook_id' => $gradebook->id,
                'certificate_number' => $existingCertificate?->certificate_number
                    ?? 'EDURA-' . strtoupper(Str::random(10)),
                'certificate_uuid' => $existingCertificate?->certificate_uuid
                    ?? (string) Str::uuid(),
                'verification_token' => $existingCertificate?->verification_token
                    ?? strtoupper(Str::random(16)),
                'issued_date' => now()->toDateString(),
                'final_percentage' => $gradebook->percentage,
                'final_grade' => $gradebook->grade,
                'status' => 'issued',
                'verification_status' => 'valid',
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        $this->generateCertificatePdf($certificate);

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate issued successfully.',
            201
        );
    }

    public function show(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateAccess($certificate);

        return $this->successResponse(
            $certificate->load(self::CERTIFICATE_RELATIONS),
            'Certificate fetched successfully.'
        );
    }

    public function update(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,issued,revoked'],
            'verification_status' => ['nullable', 'in:valid,revoked,expired'],
            'remarks' => ['nullable', 'string'],
        ]);

        $certificate->update($validated);

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate updated successfully.'
        );
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        if (
            $certificate->certificate_file &&
            Storage::disk('public')->exists($certificate->certificate_file)
        ) {
            Storage::disk('public')->delete($certificate->certificate_file);
        }

        $certificate->delete();

        return $this->successResponse(
            null,
            'Certificate deleted successfully.'
        );
    }

    public function generate(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        $this->generateCertificatePdf($certificate);

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate PDF generated successfully.'
        );
    }

    public function download(Certificate $certificate)
    {
        $this->authorizeCertificateAccess($certificate);

        if (
            ! $certificate->certificate_file ||
            ! Storage::disk('public')->exists($certificate->certificate_file)
        ) {
            $this->generateCertificatePdf($certificate);
            $certificate = $certificate->fresh();
        }

        return Storage::disk('public')->download($certificate->certificate_file);
    }

    public function verify(string $token): JsonResponse
    {
        $certificate = Certificate::with(self::CERTIFICATE_RELATIONS)
            ->where('verification_token', $token)
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'Certificate not found.',
                'valid' => false,
            ], 404);
        }

        if (
            $certificate->verification_status !== 'valid' ||
            $certificate->status !== 'issued'
        ) {
            return response()->json([
                'message' => 'Certificate is not valid.',
                'valid' => false,
                'status' => $certificate->verification_status,
            ], 422);
        }

        return response()->json([
            'message' => 'Certificate verified successfully.',
            'valid' => true,
            'data' => [
                'certificate_number' => $certificate->certificate_number,
                'verification_token' => $certificate->verification_token,
                'student_name' => $certificate->studentProfile->user->name ?? null,
                'course_title' => $certificate->course->title ?? null,
                'institution_name' => $certificate->course->institution->name ?? null,
                'final_percentage' => $certificate->final_percentage,
                'final_grade' => $certificate->final_grade,
                'issued_date' => optional($certificate->issued_date)->format('Y-m-d'),
            ],
        ]);
    }

    private function generateCertificatePdf(Certificate $certificate): void
    {
        $certificate->load(self::CERTIFICATE_RELATIONS);

        $setting = null;

        if ($certificate->course?->institution_id) {
            $setting = CertificateSetting::where(
                'institution_id',
                $certificate->course->institution_id
            )
                ->where('status', 'active')
                ->first();
        }

        $verificationUrl = $setting?->verification_url
            ? rtrim($setting->verification_url, '/') . '/' . $certificate->verification_token
            : url('/api/verify-certificate/' . $certificate->verification_token);

        $fileName = 'certificate-' . $certificate->certificate_number . '.pdf';
        $filePath = 'certificates/' . $fileName;

        $pdf = Pdf::loadView('certificates.template', [
            'certificate' => $certificate,
            'setting' => $setting,
            'verificationUrl' => $verificationUrl,
        ])->setPaper('a4', 'landscape');

        Storage::disk('public')->put($filePath, $pdf->output());

        $certificate->update([
            'certificate_file' => $filePath,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scoping
    |--------------------------------------------------------------------------
    */
    private function scopeCertificateQuery(Builder $query, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            $query
                ->whereHas('course', function (Builder $courseQuery) use ($institutionUser) {
                    $courseQuery->where('institution_id', $institutionUser->institution_id);
                })
                ->whereHas('studentProfile', function (Builder $studentQuery) use ($institutionUser) {
                    $studentQuery->where('institution_id', $institutionUser->institution_id);
                });

            return;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            $query
                ->whereHas('course', function (Builder $courseQuery) use ($teacherProfile) {
                    $courseQuery->where('teacher_profile_id', $teacherProfile->id);
                })
                ->whereExists(function ($enrollmentQuery) {
                    $enrollmentQuery->selectRaw('1')
                        ->from('course_enrollments')
                        ->whereColumn('course_enrollments.course_id', 'certificates.course_id')
                        ->whereColumn(
                            'course_enrollments.student_profile_id',
                            'certificates.student_profile_id'
                        )
                        ->whereIn(
                            'course_enrollments.status',
                            self::VALID_ENROLLMENT_STATUSES
                        );
                });

            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            $query->where('student_profile_id', $studentProfile->id);

            return;
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            $query->where('student_profile_id', $parentProfile->student_profile_id);

            return;
        }

        abort(403, 'Unauthorized role.');
    }

    /*
    |--------------------------------------------------------------------------
    | Record Authorization
    |--------------------------------------------------------------------------
    */
    private function authorizeCertificateAccess(
        Certificate $certificate,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        $certificate->loadMissing([
            'course',
            'studentProfile',
        ]);

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                $certificate->course &&
                $certificate->studentProfile &&
                (int) $certificate->course->institution_id ===
                (int) $institutionUser->institution_id &&
                (int) $certificate->studentProfile->institution_id ===
                (int) $institutionUser->institution_id
            ) {
                return;
            }

            abort(403, 'Unauthorized institution access.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if (
                $certificate->course &&
                (int) $certificate->course->teacher_profile_id ===
                (int) $teacherProfile->id &&
                $this->studentEnrolledInCourse(
                    (int) $certificate->course_id,
                    (int) $certificate->student_profile_id
                )
            ) {
                return;
            }

            abort(403, 'Unauthorized: Certificate does not belong to your course student.');
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            if ((int) $certificate->student_profile_id === (int) $studentProfile->id) {
                return;
            }

            abort(403, 'Unauthorized: You can only access your own certificates.');
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            if (
                (int) $certificate->student_profile_id ===
                (int) $parentProfile->student_profile_id
            ) {
                return;
            }

            abort(403, 'Unauthorized: You can only access your child certificates.');
        }

        abort(403, 'Unauthorized role.');
    }

    private function authorizeCertificateManagement(
        Certificate $certificate,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        if (! $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ])) {
            abort(403, 'Unauthorized: Certificates are read-only for your role.');
        }

        $this->authorizeCertificateAccess($certificate, $user);
    }

    private function authorizeCertificatePairManagement(
        Course $course,
        StudentProfile $studentProfile,
        User $user
    ): void {
        if (! $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ])) {
            abort(403, 'Unauthorized: You cannot issue certificates.');
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                (int) $course->institution_id !== (int) $institutionUser->institution_id ||
                (int) $studentProfile->institution_id !==
                (int) $institutionUser->institution_id
            ) {
                abort(403, 'Unauthorized institution access.');
            }
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if ((int) $course->teacher_profile_id !== (int) $teacherProfile->id) {
                abort(403, 'Unauthorized: This course is not assigned to you.');
            }
        }

        if ((int) $course->institution_id !== (int) $studentProfile->institution_id) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Student and course must belong to the same institution.',
            ]);
        }

        if (! $this->studentEnrolledInCourse($course->id, $studentProfile->id)) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Student is not enrolled in this course.',
            ]);
        }
    }

    private function studentEnrolledInCourse(int $courseId, int $studentProfileId): bool
    {
        return CourseEnrollment::where('course_id', $courseId)
            ->where('student_profile_id', $studentProfileId)
            ->whereIn('status', self::VALID_ENROLLMENT_STATUSES)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Role Profiles
    |--------------------------------------------------------------------------
    */
    private function institutionUserFor(User $user): InstitutionUser
    {
        $institutionUser = InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($institutionUser) {
            return $institutionUser;
        }

        abort(403, 'Unauthorized: Institution profile not found.');
    }

    private function teacherProfileFor(User $user): TeacherProfile
    {
        $teacherProfile = TeacherProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($teacherProfile) {
            return $teacherProfile;
        }

        abort(403, 'Unauthorized: Teacher profile not found.');
    }

    private function studentProfileFor(User $user): StudentProfile
    {
        $studentProfile = StudentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($studentProfile) {
            return $studentProfile;
        }

        abort(403, 'Unauthorized: Student profile not found.');
    }

    private function parentProfileFor(User $user): ParentProfile
    {
        $parentProfile = ParentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($parentProfile && $parentProfile->student_profile_id) {
            return $parentProfile;
        }

        abort(403, 'Unauthorized: Parent profile not found.');
    }
}
