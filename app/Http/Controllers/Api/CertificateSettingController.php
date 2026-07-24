<?php

namespace App\Http\Controllers\Api;

use App\Models\CertificateSetting;
use App\Http\Requests\StoreCertificateSettingRequest;
use App\Http\Requests\UpdateCertificateSettingRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class CertificateSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = CertificateSetting::with('institution')
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Certificate settings fetched successfully.',
            'data' => $settings,
        ]);
    }

    public function store(StoreCertificateSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        foreach (
            [
                'logo' => 'certificate-settings/logos',
                'institution_seal' => 'certificate-settings/seals',
                'certificate_background' => 'certificate-settings/backgrounds',
                'signature_image' => 'certificate-settings/signatures',
                'secondary_signature_image' => 'certificate-settings/signatures',
            ] as $field => $path
        ) {
            if ($request->hasFile($field)) {
                $validated[$field] = $request->file($field)->store($path, 'public');
            }
        }

        $setting = CertificateSetting::create($validated);

        return response()->json([
            'message' => 'Certificate setting created successfully.',
            'data' => $setting->load('institution'),
        ], 201);
    }

    public function show(CertificateSetting $certificateSetting): JsonResponse
    {
        return response()->json([
            'message' => 'Certificate setting fetched successfully.',
            'data' => $certificateSetting->load('institution'),
        ]);
    }

    public function update(UpdateCertificateSettingRequest $request, CertificateSetting $certificateSetting): JsonResponse
    {
        $validated = $request->validated();

        foreach (
            [
                'logo' => 'certificate-settings/logos',
                'institution_seal' => 'certificate-settings/seals',
                'certificate_background' => 'certificate-settings/backgrounds',
                'signature_image' => 'certificate-settings/signatures',
                'secondary_signature_image' => 'certificate-settings/signatures',
            ] as $field => $path
        ) {
            if ($request->hasFile($field)) {
                if ($certificateSetting->{$field} && Storage::disk('public')->exists($certificateSetting->{$field})) {
                    Storage::disk('public')->delete($certificateSetting->{$field});
                }

                $validated[$field] = $request->file($field)->store($path, 'public');
            }
        }

        $certificateSetting->update($validated);

        return response()->json([
            'message' => 'Certificate setting updated successfully.',
            'data' => $certificateSetting->fresh()->load('institution'),
        ]);
    }

    public function destroy(CertificateSetting $certificateSetting): JsonResponse
    {
        foreach (
            [
                'logo',
                'institution_seal',
                'certificate_background',
                'signature_image',
                'secondary_signature_image',
            ] as $field
        ) {
            if ($certificateSetting->{$field} && Storage::disk('public')->exists($certificateSetting->{$field})) {
                Storage::disk('public')->delete($certificateSetting->{$field});
            }
        }

        $certificateSetting->delete();

        return response()->json([
            'message' => 'Certificate setting deleted successfully.',
        ]);
    }
}
