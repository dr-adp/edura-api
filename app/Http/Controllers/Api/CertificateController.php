<?php

namespace App\Http\Controllers\Api;

use App\Models\Certificate;
use App\Models\Gradebook;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class CertificateController extends Controller
{
    public function index(): JsonResponse
    {
        $certificates = Certificate::with([
            'course',
            'studentProfile.user',
            'gradebook'
        ])->latest()->paginate(20);

        return response()->json([
            'message' => 'Certificates fetched successfully.',
            'data' => $certificates,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        $gradebook = Gradebook::where('course_id', $validated['course_id'])
            ->where('student_profile_id', $validated['student_profile_id'])
            ->first();

        if (!$gradebook) {
            return response()->json([
                'message' => 'Gradebook record not found. Please calculate gradebook first.',
            ], 422);
        }

        if ($gradebook->result_status !== 'passed') {
            return response()->json([
                'message' => 'Certificate cannot be issued because student has not passed.',
            ], 422);
        }

        $certificate = Certificate::updateOrCreate(
            [
                'course_id' => $validated['course_id'],
                'student_profile_id' => $validated['student_profile_id'],
            ],
            [
                'gradebook_id' => $gradebook->id,
                'certificate_number' => 'EDURA-' . strtoupper(Str::random(10)),
                'issued_date' => now()->toDateString(),
                'final_percentage' => $gradebook->percentage,
                'final_grade' => $gradebook->grade,
                'status' => 'issued',
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        $this->generateCertificatePdf($certificate);

        return response()->json([
            'message' => 'Certificate issued successfully.',
            'data' => $certificate->fresh()->load([
                'course',
                'studentProfile.user',
                'gradebook'
            ]),
        ], 201);
    }

    public function show(Certificate $certificate): JsonResponse
    {
        return response()->json([
            'message' => 'Certificate fetched successfully.',
            'data' => $certificate->load([
                'course',
                'studentProfile.user',
                'gradebook'
            ]),
        ]);
    }

    public function update(Request $request, Certificate $certificate): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,issued,revoked'],
            'remarks' => ['nullable', 'string'],
        ]);

        $certificate->update($validated);

        return response()->json([
            'message' => 'Certificate updated successfully.',
            'data' => $certificate->fresh()->load([
                'course',
                'studentProfile.user',
                'gradebook'
            ]),
        ]);
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        if ($certificate->certificate_file && Storage::disk('public')->exists($certificate->certificate_file)) {
            Storage::disk('public')->delete($certificate->certificate_file);
        }

        $certificate->delete();

        return response()->json([
            'message' => 'Certificate deleted successfully.',
        ]);
    }

    public function generate(Certificate $certificate): JsonResponse
    {
        $this->generateCertificatePdf($certificate);

        return response()->json([
            'message' => 'Certificate PDF generated successfully.',
            'data' => $certificate->fresh()->load([
                'course',
                'studentProfile.user',
                'gradebook'
            ]),
        ]);
    }

    public function download(Certificate $certificate)
    {
        if (!$certificate->certificate_file || !Storage::disk('public')->exists($certificate->certificate_file)) {
            $this->generateCertificatePdf($certificate);
            $certificate = $certificate->fresh();
        }

        return Storage::disk('public')->download($certificate->certificate_file);
    }

    private function generateCertificatePdf(Certificate $certificate): void
    {
        $certificate->load([
            'course',
            'studentProfile.user',
            'gradebook'
        ]);

        $fileName = 'certificate-' . $certificate->certificate_number . '.pdf';
        $filePath = 'certificates/' . $fileName;

        $pdf = Pdf::loadView('certificates.template', [
            'certificate' => $certificate,
        ])->setPaper('a4', 'landscape');

        Storage::disk('public')->put($filePath, $pdf->output());

        $certificate->update([
            'certificate_file' => $filePath,
        ]);
    }
}
