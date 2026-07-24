<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Institution;
use App\Http\Requests\UploadProfilePhotoRequest;
use App\Http\Requests\UploadInstitutionLogoRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function uploadProfilePhoto(UploadProfilePhotoRequest $request): JsonResponse
    {
        $request->validated();

        $user = $request->user();

        if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $path = $request->file('profile_photo')->store('profile-photos', 'public');

        $user->update([
            'profile_photo' => $path,
        ]);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    public function uploadInstitutionLogo(UploadInstitutionLogoRequest $request, Institution $institution): JsonResponse
    {
        $request->validated();

        if ($institution->logo && Storage::disk('public')->exists($institution->logo)) {
            Storage::disk('public')->delete($institution->logo);
        }

        $path = $request->file('logo')->store('institution-logos', 'public');

        $institution->update([
            'logo' => $path,
        ]);

        return response()->json([
            'message' => 'Institution logo uploaded successfully.',
            'data' => $institution->fresh(),
        ]);
    }
}
