<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class FeatureNotAvailable extends RuntimeException
{
    public function __construct(public string $feature)
    {
        parent::__construct(__('crm.feature_not_available'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'feature_not_available',
            'feature' => $this->feature,
        ], 402);
    }
}
