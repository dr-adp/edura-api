<?php

namespace App\Http\Controllers\Api;

use App\Models\CertificateSetting;
use Illuminate\Http\Request;
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'exists:institutions,id', 'unique:certificate_settings,institution_id'],
            'certificate_title' => ['nullable', 'string', 'max:255'],
            'certificate_subtitle' => ['nullable', 'string', 'max:255'],
            'authorized_person_name' => ['nullable', 'string', 'max:255'],
            'authorized_person_designation' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'signature_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('certificate-settings/logos', 'public');
        }

        if ($request->hasFile('signature_image')) {
            $validated['signature_image'] = $request->file('signature_image')->store('certificate-settings/signatures', 'public');
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

    public function update(Request $request, CertificateSetting $certificateSetting): JsonResponse
    {
        $validated = $request->validate([
            'certificate_title' => ['nullable', 'string', 'max:255'],
            'certificate_subtitle' => ['nullable', 'string', 'max:255'],
            'authorized_person_name' => ['nullable', 'string', 'max:255'],
            'authorized_person_designation' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'signature_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($request->hasFile('logo')) {
            if ($certificateSetting->logo && Storage::disk('public')->exists($certificateSetting->logo)) {
                Storage::disk('public')->delete($certificateSetting->logo);
            }

            $validated['logo'] = $request->file('logo')->store('certificate-settings/logos', 'public');
        }

        if ($request->hasFile('signature_image')) {
            if ($certificateSetting->signature_image && Storage::disk('public')->exists($certificateSetting->signature_image)) {
                Storage::disk('public')->delete($certificateSetting->signature_image);
            }

            $validated['signature_image'] = $request->file('signature_image')->store('certificate-settings/signatures', 'public');
        }

        $certificateSetting->update($validated);

        return response()->json([
            'message' => 'Certificate setting updated successfully.',
            'data' => $certificateSetting->fresh()->load('institution'),
        ]);
    }

    public function destroy(CertificateSetting $certificateSetting): JsonResponse
    {
        if ($certificateSetting->logo && Storage::disk('public')->exists($certificateSetting->logo)) {
            Storage::disk('public')->delete($certificateSetting->logo);
        }

        if ($certificateSetting->signature_image && Storage::disk('public')->exists($certificateSetting->signature_image)) {
            Storage::disk('public')->delete($certificateSetting->signature_image);
        }

        $certificateSetting->delete();

        return response()->json([
            'message' => 'Certificate setting deleted successfully.',
        ]);
    }
}
