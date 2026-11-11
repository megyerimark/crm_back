<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::with('user')->where('workspace_id', $this->workspace()->id)->where('is_admin_action', false)->latest('id')->paginate($this->perPage($request, 50));

        return AuditLogResource::collection($logs);
    }
}
