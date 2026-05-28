<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

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

    public function uploadInstitutionLogo(Request $request, Institution $institution): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

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
