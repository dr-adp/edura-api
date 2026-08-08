<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionSettingRequest;
use App\Http\Requests\UpdateInstitutionSettingRequest;
use App\Models\InstitutionSetting;
use App\Services\InstitutionSettingsService;
use App\Support\InstitutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstitutionSettingController extends Controller
{
    public function __construct(
        private readonly InstitutionSettingsService $settingsService,
        private readonly InstitutionAccess $institutionAccess
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InstitutionSetting::class);

        if ($request->user()->hasRole('institution-admin')) {
            $institutionId = $this->institutionAccess->institutionIdFor($request->user());
        } else {
            $institutionId = $request->integer('institution_id');
        }

        $settings = InstitutionSetting::with('institution')
            ->when($institutionId, fn($query) => $query->where('institution_id', $institutionId))
            ->when($request->filled('group'), fn($query) => $query->where('group', $request->query('group')))
            ->latest()
            ->paginate(20);

        $settings->getCollection()->transform(
            fn(InstitutionSetting $setting) => $this->maskEncryptedValue($setting)
        );

        return response()->json([
            'message' => 'Institution settings fetched successfully.',
            'data' => $settings,
        ]);
    }

    public function store(StoreInstitutionSettingRequest $request): JsonResponse
    {
        $this->authorize('create', InstitutionSetting::class);
        $this->authorizeInstitution($request, (int) $request->institution_id);

        $setting = $this->settingsService->set($request->validated());

        return response()->json([
            'message' => 'Institution setting saved successfully.',
            'data' => $this->maskEncryptedValue($setting),
        ], 201);
    }

    public function show(InstitutionSetting $institutionSetting): JsonResponse
    {
        $this->authorize('view', $institutionSetting);

        return response()->json([
            'message' => 'Institution setting fetched successfully.',
            'data' => $this->maskEncryptedValue(
                $institutionSetting->load('institution')
            ),
        ]);
    }

    public function update(
        UpdateInstitutionSettingRequest $request,
        InstitutionSetting $institutionSetting
    ): JsonResponse {
        $this->authorize('update', $institutionSetting);

        $setting = $this->settingsService->update(
            $institutionSetting,
            $request->validated()
        );

        return response()->json([
            'message' => 'Institution setting updated successfully.',
            'data' => $this->maskEncryptedValue($setting),
        ]);
    }

    public function destroy(InstitutionSetting $institutionSetting): JsonResponse
    {
        $this->authorize('delete', $institutionSetting);

        $this->settingsService->delete($institutionSetting);

        return response()->json([
            'message' => 'Institution setting deleted successfully.',
        ]);
    }

    private function authorizeInstitution(Request $request, int $institutionId): void
    {
        if (!$this->institutionAccess->canAccess($request->user(), $institutionId)) {
            throw new AuthorizationException('Unauthorized institution access.');
        }
    }

    private function maskEncryptedValue(InstitutionSetting $setting): InstitutionSetting
    {
        if ($setting->is_encrypted) {
            $setting->value = ['encrypted' => true];
        }

        return $setting;
    }
}
