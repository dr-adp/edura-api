<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\InstitutionUser;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CourseAssessmentLockService;
use App\Services\QuizAttemptGradingService;
use App\Services\GradebookCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Http\Requests\StoreCertificateRequest;
use App\Http\Requests\UpdateCertificateRequest;

class CertificateController extends BaseApiController
{
    private const CERTIFICATE_DISK = 'certificates';

    public function __construct(
        private QuizAttemptGradingService $quizAttemptGradingService,
        private GradebookCalculationService $gradebookCalculationService,
        private AuditLogService $auditLogService,
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

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
        $redactPendingResults = $this->isLearnerCertificateViewer($user);

        if ($redactPendingResults) {
            $this->quizAttemptGradingService
                ->withPendingManualGradingFlagForCertificates($query);
        }

        /*
        |--------------------------------------------------------------------------
        | Scoped Certificate Listing
        |--------------------------------------------------------------------------
        */
        $this->scopeCertificateQuery($query, $user);

        $certificates = $query
            ->latest()
            ->paginate(20);

        if ($this->isParentCertificateViewer($user)) {
            $certificates->getCollection()->each(function (Certificate $certificate) {
                $this->hideInconsistentCertificateGradebook($certificate);
            });
        }

        if ($redactPendingResults) {
            $certificates->getCollection()->each(function (Certificate $certificate) {
                $this->quizAttemptGradingService
                    ->redactPendingCertificateResults($certificate);
            });
        }

        return $this->successResponse(
            $certificates,
            'Certificates fetched successfully.'
        );
    }

    public function store(StoreCertificateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

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

        if ($this->hasPendingManualGrading($course->id, $studentProfile->id)) {
            return response()->json([
                'message' => 'Certificate cannot be issued while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        try {
            $gradebook = $this->gradebookCalculationService->recalculate(
                (int) $course->id,
                (int) $studentProfile->id
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Certificate cannot be issued because current grades could not be established.',
            ], 503);
        }

        if ($this->hasPendingManualGrading($course->id, $studentProfile->id)) {
            return response()->json([
                'message' => 'Certificate cannot be issued while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        if ($gradebook->result_status !== 'passed') {
            return response()->json([
                'message' => 'Certificate cannot be issued because student has not passed.',
            ], 422);
        }

        if (
            Certificate::withTrashed()
                ->where('course_id', $course->id)
                ->where('student_profile_id', $studentProfile->id)
                ->exists()
        ) {
            return $this->certificateAlreadyExistsResponse();
        }

        $privateFilePath = null;

        try {
            $certificate = DB::transaction(function () use (
                $course,
                $studentProfile,
                $gradebook,
                $validated,
                &$privateFilePath
            ): Certificate {
                $certificate = Certificate::create([
                    'course_id' => $course->id,
                    'student_profile_id' => $studentProfile->id,
                    'gradebook_id' => $gradebook->id,
                    'certificate_number' => 'EDURA-' . strtoupper(Str::random(10)),
                    'certificate_uuid' => (string) Str::uuid(),
                    'verification_token' => strtoupper(Str::random(16)),
                    'issued_date' => now()->toDateString(),
                    'final_percentage' => $gradebook->percentage,
                    'final_grade' => $gradebook->grade,
                    'status' => 'issued',
                    'verification_status' => 'valid',
                    'remarks' => $validated['remarks'] ?? null,
                ]);

                $privateFilePath = $this->generateCertificatePdf($certificate);

                if (! $certificate->update(['certificate_file' => $privateFilePath])) {
                    throw new RuntimeException('Certificate file path could not be saved.');
                }

                return $certificate;
            });
        } catch (Throwable $exception) {
            $this->discardPrivateCertificatePdf($privateFilePath);
            report($exception);

            if (
                Certificate::withTrashed()
                    ->where('course_id', $course->id)
                    ->where('student_profile_id', $studentProfile->id)
                    ->exists()
            ) {
                return $this->certificateAlreadyExistsResponse();
            }

            return response()->json([
                'message' => 'Certificate PDF generation failed.',
            ], 500);
        }

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate issued successfully.',
            201
        );
    }

    public function show(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateAccess($certificate);

        $certificate->load(self::CERTIFICATE_RELATIONS);
        $user = Auth::user();

        if ($this->isParentCertificateViewer($user)) {
            $this->hideInconsistentCertificateGradebook($certificate);
        }

        if ($this->isLearnerCertificateViewer($user)) {
            $this->quizAttemptGradingService
                ->redactPendingCertificateResults($certificate);
        }

        return $this->successResponse(
            $certificate,
            'Certificate fetched successfully.'
        );
    }

    public function update(UpdateCertificateRequest $request, Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        $validated = $request->validated();

        if (
            ($validated['status'] ?? $certificate->status) === 'issued' &&
            ($validated['verification_status'] ?? $certificate->verification_status) === 'valid' &&
            $this->hasPendingManualGrading(
                (int) $certificate->course_id,
                (int) $certificate->student_profile_id
            )
        ) {
            return response()->json([
                'message' => 'Certificate cannot be issued while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        if (
            ! $certificate->isIssuedAndValid() &&
            ($validated['status'] ?? $certificate->status) === 'issued' &&
            ($validated['verification_status'] ?? $certificate->verification_status) === 'valid'
        ) {
            return response()->json([
                'message' => 'Certificate reactivation requires a versioned reissue workflow.',
            ], 409);
        }

        $certificate->update($validated);

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate updated successfully.'
        );
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        $filePath = $certificate->certificate_file;

        try {
            if (! $certificate->delete()) {
                return response()->json([
                    'message' => 'Certificate could not be deleted.',
                ], 500);
            }
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Certificate could not be deleted.',
            ], 500);
        }

        $this->deletePrivateCertificatePdfIfUnreferenced(
            $filePath,
            (int) $certificate->id,
            (int) $certificate->id
        );

        return $this->successResponse(
            null,
            'Certificate deleted successfully.'
        );
    }

    public function generate(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        if ($this->hasPendingManualGrading(
            (int) $certificate->course_id,
            (int) $certificate->student_profile_id
        )) {
            return response()->json([
                'message' => 'Certificate PDF cannot be generated while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        if (! $certificate->isIssuedAndValid()) {
            return response()->json([
                'message' => 'Certificate is not available for PDF generation.',
            ], 422);
        }

        $previousFilePath = $certificate->certificate_file;
        $privateFilePath = null;

        try {
            $privateFilePath = $this->generateCertificatePdf($certificate);

            DB::transaction(function () use ($certificate, $privateFilePath): void {
                if (! $certificate->update(['certificate_file' => $privateFilePath])) {
                    throw new RuntimeException('Certificate file path could not be saved.');
                }
            });
        } catch (Throwable $exception) {
            $this->discardPrivateCertificatePdf($privateFilePath);
            report($exception);

            return response()->json([
                'message' => 'Certificate PDF generation failed.',
            ], 500);
        }

        $this->cleanupReplacedCertificatePdf(
            $previousFilePath,
            $privateFilePath,
            (int) $certificate->id
        );

        return $this->successResponse(
            $certificate->fresh()->load(self::CERTIFICATE_RELATIONS),
            'Certificate PDF generated successfully.'
        );
    }

    public function reconcile(Certificate $certificate): JsonResponse
    {
        $this->authorizeCertificateManagement($certificate);

        $certificate->loadMissing([
            'course.institution',
            'studentProfile.institution',
            'studentProfile.user',
            'gradebook',
        ]);

        if (
            $certificate->status === 'pending' ||
            ! $this->hasConsistentCertificateInstitutionRelationships($certificate)
        ) {
            return response()->json([
                'message' => 'Certificate cannot be reconciled.',
            ], 422);
        }

        if (
            $certificate->gradebook &&
            (
                (int) $certificate->gradebook->course_id !== (int) $certificate->course_id ||
                (int) $certificate->gradebook->student_profile_id !==
                    (int) $certificate->student_profile_id
            )
        ) {
            return response()->json([
                'message' => 'Certificate cannot be reconciled.',
            ], 422);
        }

        try {
            $reconciliation = DB::transaction(function () use ($certificate): array {
                $this->courseAssessmentLockService
                    ->lockCoursesStudentsAndAttempts(
                        [(int) $certificate->course_id],
                        (int) $certificate->student_profile_id
                    );

                if ($this->hasPendingManualGrading(
                    (int) $certificate->course_id,
                    (int) $certificate->student_profile_id
                )) {
                    return ['deferred' => 'grading_pending'];
                }

                $gradebook = $this->gradebookCalculationService->recalculate(
                    (int) $certificate->course_id,
                    (int) $certificate->student_profile_id
                );

                if ($this->hasPendingManualGrading(
                    (int) $certificate->course_id,
                    (int) $certificate->student_profile_id
                )) {
                    return ['deferred' => 'grading_pending'];
                }

                if (! in_array($gradebook->result_status, ['passed', 'failed'], true)) {
                    return ['deferred' => 'gradebook_pending'];
                }

                $mismatchedFields = [];
                $snapshotPercentage = round((float) $certificate->final_percentage, 2);
                $currentPercentage = round((float) $gradebook->percentage, 2);

                if ($snapshotPercentage !== $currentPercentage) {
                    $mismatchedFields[] = 'final_percentage';
                }

                if ($certificate->final_grade !== $gradebook->grade) {
                    $mismatchedFields[] = 'final_grade';
                }

                $detectedDiscrepancy = $mismatchedFields !== [];

                $this->auditLogService->recordCustom(
                    action: 'snapshot_reconciled',
                    description: 'Certificate snapshot compared with the current gradebook.',
                    auditable: $certificate->course,
                    metadata: [
                        'certificate_id' => $certificate->id,
                        'gradebook_id' => $gradebook->id,
                        'snapshot_discrepancy_detected' => $detectedDiscrepancy,
                        'mismatched_fields' => $mismatchedFields,
                        'current_result_status' => $gradebook->result_status,
                    ],
                    module: 'Certificate'
                );

                return [
                    'deferred' => null,
                    'report' => [
                        'certificate' => [
                            'id' => $certificate->id,
                            'course_id' => $certificate->course_id,
                            'student_profile_id' => $certificate->student_profile_id,
                            'institution_id' => $certificate->course->institution_id,
                            'status' => $certificate->status,
                            'verification_status' => $certificate->verification_status,
                            'currently_valid' => $certificate->isIssuedAndValid(),
                            'issued_date' => optional($certificate->issued_date)->format('Y-m-d'),
                            'final_percentage' => $certificate->final_percentage,
                            'final_grade' => $certificate->final_grade,
                        ],
                        'current_gradebook' => [
                            'id' => $gradebook->id,
                            'percentage' => $gradebook->percentage,
                            'grade' => $gradebook->grade,
                            'result_status' => $gradebook->result_status,
                            'currently_pass_eligible' => $gradebook->result_status === 'passed',
                        ],
                        'snapshot_comparison' => [
                            'discrepancy_detected' => $detectedDiscrepancy,
                            'mismatched_fields' => $mismatchedFields,
                            'interpretation' => $detectedDiscrepancy
                                ? 'A difference from the current gradebook does not establish that the original certificate was incorrect when issued.'
                                : 'The stored academic snapshot matches the current gradebook at the time of reconciliation.',
                        ],
                    ],
                ];
            });
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Certificate reconciliation could not be completed.',
            ], 503);
        }

        if (($reconciliation['deferred'] ?? null) === 'grading_pending') {
            return response()->json([
                'message' => 'Certificate reconciliation is deferred while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        if (($reconciliation['deferred'] ?? null) === 'gradebook_pending') {
            return response()->json([
                'message' => 'A final gradebook result is not available for reconciliation.',
            ], 422);
        }

        return $this->successResponse(
            $reconciliation['report'],
            'Certificate snapshot reconciliation completed.'
        );
    }

    public function download(Certificate $certificate)
    {
        $this->authorizeCertificateAccess($certificate);

        if ($this->hasPendingManualGrading(
            (int) $certificate->course_id,
            (int) $certificate->student_profile_id
        )) {
            return response()->json([
                'message' => 'Certificate download is unavailable while manual quiz grading is pending.',
                'grading_pending' => true,
            ], 422);
        }

        if (! $certificate->isIssuedAndValid()) {
            return response()->json([
                'message' => 'Certificate is not available for download.',
            ], 422);
        }

        $disk = Storage::disk(self::CERTIFICATE_DISK);

        if (! $certificate->certificate_file || ! $disk->exists($certificate->certificate_file)) {
            $privateFilePath = null;

            try {
                $privateFilePath = $this->generateCertificatePdf($certificate);
                $pdfContents = $disk->get($privateFilePath);

                if (! is_string($pdfContents)) {
                    throw new RuntimeException('Generated certificate PDF could not be read.');
                }

                // The response owns the buffered bytes, so the private temporary file can be removed now.
                $this->discardPrivateCertificatePdf($privateFilePath);

                $fileName = 'certificate-' . (Str::slug($certificate->certificate_number) ?: 'download') . '.pdf';

                return response($pdfContents, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            } catch (Throwable $exception) {
            $this->discardPrivateCertificatePdf($privateFilePath);
                report($exception);

                return response()->json([
                    'message' => 'Certificate PDF is currently unavailable.',
                ], 500);
            }
        }

        if (! $certificate->certificate_file || ! $disk->exists($certificate->certificate_file)) {
            return response()->json([
                'message' => 'Certificate PDF is unavailable.',
            ], 404);
        }

        return $disk->download($certificate->certificate_file);
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
            ! $certificate->isIssuedAndValid() ||
            ! $this->hasConsistentCertificateInstitutionRelationships($certificate) ||
            $this->hasPendingManualGrading(
                (int) $certificate->course_id,
                (int) $certificate->student_profile_id
            )
        ) {
            return response()->json([
                'message' => 'Certificate is not valid.',
                'valid' => false,
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

    private function generateCertificatePdf(Certificate $certificate): string
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

        $safeCertificateNumber = Str::slug($certificate->certificate_number, '-') ?: 'certificate';
        $filePath = $safeCertificateNumber . '-' . Str::uuid() . '.pdf';

        $pdf = Pdf::loadView('certificates.template', [
            'certificate' => $certificate,
            'setting' => $setting,
            'verificationUrl' => $verificationUrl,
        ])->setPaper('a4', 'landscape');

        $disk = Storage::disk(self::CERTIFICATE_DISK);

        try {
            if (! $disk->put($filePath, $pdf->output()) || ! $disk->exists($filePath)) {
                throw new RuntimeException('Certificate PDF could not be stored.');
            }
        } catch (Throwable $exception) {
            $this->discardPrivateCertificatePdf($filePath);

            throw $exception;
        }

        return $filePath;
    }

    private function discardPrivateCertificatePdf(?string $filePath): void
    {
        $this->deletePrivateCertificatePdfIfUnreferenced($filePath);
    }

    private function cleanupReplacedCertificatePdf(
        ?string $previousFilePath,
        ?string $replacementFilePath,
        int $certificateId
    ): void {
        if (! $previousFilePath || $previousFilePath === $replacementFilePath) {
            return;
        }

        $this->deletePrivateCertificatePdfIfUnreferenced(
            $previousFilePath,
            $certificateId,
            $certificateId
        );
    }

    private function deletePrivateCertificatePdfIfUnreferenced(
        ?string $filePath,
        ?int $certificateId = null,
        ?int $excludeCertificateId = null
    ): void {
        if (! $filePath) {
            return;
        }

        try {
            $references = Certificate::withTrashed()
                ->where('certificate_file', $filePath);

            if ($excludeCertificateId !== null) {
                $references->where('id', '!=', $excludeCertificateId);
            }

            if ($references->exists()) {
                Log::info('Private certificate PDF cleanup skipped because the file is still referenced.', [
                    'certificate_id' => $certificateId,
                    'file_path' => $filePath,
                ]);

                return;
            }

            if (Storage::disk('public')->exists($filePath)) {
                Log::info('Certificate PDF cleanup skipped for a file present on the public disk.', [
                    'certificate_id' => $certificateId,
                    'file_path' => $filePath,
                ]);

                return;
            }

            $disk = Storage::disk(self::CERTIFICATE_DISK);

            if (! $disk->exists($filePath)) {
                return;
            }

            $deleted = $disk->delete($filePath);
            $fileStillExists = $disk->exists($filePath);

            if (! $deleted || $fileStillExists) {
                Log::error('Private certificate PDF cleanup failed.', [
                    'certificate_id' => $certificateId,
                    'file_path' => $filePath,
                    'delete_returned' => $deleted,
                    'file_still_exists' => $fileStillExists,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Private certificate PDF cleanup failed.', [
                'certificate_id' => $certificateId,
                'file_path' => $filePath,
                'exception_class' => $exception::class,
            ]);
            report($exception);
        }
    }

    private function hasPendingManualGrading(int $courseId, int $studentProfileId): bool
    {
        return $this->quizAttemptGradingService
            ->hasPendingManualGradingForCourseStudent($courseId, $studentProfileId);
    }

    private function certificateAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'A certificate already exists for this student and course.',
        ], 409);
    }

    private function hasConsistentCertificateInstitutionRelationships(
        Certificate $certificate
    ): bool {
        $certificate->loadMissing([
            'course.institution',
            'studentProfile.institution',
            'studentProfile.user',
        ]);

        $course = $certificate->course;
        $studentProfile = $certificate->studentProfile;

        if (
            ! $course ||
            ! $course->institution ||
            ! $studentProfile ||
            ! $studentProfile->institution ||
            ! $studentProfile->user
        ) {
            return false;
        }

        $courseInstitutionId = (int) $course->institution_id;
        $studentInstitutionId = (int) $studentProfile->institution_id;

        return $courseInstitutionId > 0 &&
            $studentInstitutionId > 0 &&
            (int) $course->institution->id === $courseInstitutionId &&
            (int) $studentProfile->institution->id === $studentInstitutionId &&
            $courseInstitutionId === $studentInstitutionId;
    }

    private function isLearnerCertificateViewer(User $user): bool
    {
        return $user->hasAnyRole(['student', 'parent']) &&
            ! $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
            ]);
    }

    private function isParentCertificateViewer(User $user): bool
    {
        return $user->hasRole('parent') &&
            ! $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
            ]);
    }

    private function hideInconsistentCertificateGradebook(Certificate $certificate): void
    {
        if (! $certificate->relationLoaded('gradebook')) {
            return;
        }

        $gradebook = $certificate->getRelation('gradebook');

        if (
            $gradebook &&
            (
                (int) $gradebook->student_profile_id !== (int) $certificate->student_profile_id ||
                (int) $gradebook->course_id !== (int) $certificate->course_id
            )
        ) {
            $certificate->setRelation('gradebook', null);
        }
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

        $query->whereHas('course', function (Builder $courseQuery) {
            $courseQuery->whereIn('courses.institution_id', function ($studentQuery) {
                $studentQuery->select('student_profiles.institution_id')
                    ->from('student_profiles')
                    ->whereColumn(
                        'student_profiles.id',
                        'certificates.student_profile_id'
                    );
            });
        });

        if ($user->hasRole('institution-admin')) {
            $institutionIds = $this->authorizedInstitutionIdsFor($user);

            $query
                ->whereHas('course', function (Builder $courseQuery) use ($institutionIds) {
                    $courseQuery->whereIn('institution_id', $institutionIds);
                })
                ->whereHas('studentProfile', function (Builder $studentQuery) use ($institutionIds) {
                    $studentQuery->whereIn('institution_id', $institutionIds);
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
            $query->whereExists(function ($parentLinkQuery) use ($user) {
                $parentLinkQuery->selectRaw('1')
                    ->from('parent_profiles')
                    ->join(
                        'student_profiles as parent_students',
                        'parent_students.id',
                        '=',
                        'parent_profiles.student_profile_id'
                    )
                    ->join('courses as parent_courses', function ($join) {
                        $join->on('parent_courses.id', '=', 'certificates.course_id')
                            ->on(
                                'parent_courses.institution_id',
                                '=',
                                'parent_students.institution_id'
                            );
                    })
                    ->whereColumn(
                        'parent_profiles.student_profile_id',
                        'certificates.student_profile_id'
                    )
                    ->whereColumn(
                        'parent_profiles.institution_id',
                        'parent_students.institution_id'
                    )
                    ->whereColumn(
                        'parent_profiles.institution_id',
                        'parent_courses.institution_id'
                    )
                    ->where('parent_profiles.user_id', $user->id)
                    ->where('parent_profiles.status', 'active');
            });

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

        if (
            !$certificate->course ||
            !$certificate->studentProfile ||
            (int) $certificate->course->institution_id !==
                (int) $certificate->studentProfile->institution_id
        ) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        if ($user->hasRole('institution-admin')) {
            $institutionIds = $this->authorizedInstitutionIdsFor($user);

            if (
                $certificate->course &&
                $certificate->studentProfile &&
                in_array((int) $certificate->course->institution_id, $institutionIds, true) &&
                in_array((int) $certificate->studentProfile->institution_id, $institutionIds, true)
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
            $institutionId = (int) $certificate->studentProfile->institution_id;
            $hasActiveParentLink = ParentProfile::where('user_id', $user->id)
                ->where('status', 'active')
                ->where('student_profile_id', $certificate->student_profile_id)
                ->where('institution_id', $institutionId)
                ->exists();

            if ($hasActiveParentLink) {
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
            $institutionIds = $this->authorizedInstitutionIdsFor($user);

            if (
                ! in_array((int) $course->institution_id, $institutionIds, true) ||
                ! in_array((int) $studentProfile->institution_id, $institutionIds, true)
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
            ->institutionallyConsistent()
            ->where('student_profile_id', $studentProfileId)
            ->whereIn('status', self::VALID_ENROLLMENT_STATUSES)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Role Profiles
    |--------------------------------------------------------------------------
    */
    /** @return list<int> */
    private function authorizedInstitutionIdsFor(User $user): array
    {
        $institutionIds = InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('institution_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($institutionIds !== []) {
            return $institutionIds;
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
}
