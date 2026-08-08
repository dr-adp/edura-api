<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AuditLogFilterRequest;
use App\Models\ActivityLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class AuditLogController extends BaseApiController
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    public function index(AuditLogFilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        return $this->successResponse(
            $this->auditLogService->timeline($request->user(), $request->validated()),
            'Audit logs fetched successfully.'
        );
    }

    public function show(ActivityLog $auditLog): JsonResponse
    {
        $this->authorize('view', $auditLog);

        return $this->successResponse(
            $auditLog->load([
                'institution:id,name,code',
                'user:id,name,email',
            ]),
            'Audit log fetched successfully.'
        );
    }
}
