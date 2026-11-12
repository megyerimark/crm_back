<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class PlanLimitExceeded extends RuntimeException
{
    public function __construct(public string $limitKey, public ?int $limit, string $message = '')
    {
        parent::__construct($message ?: __('crm.plan_limit_reached'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_limit',
            'limit' => $this->limitKey,
            'max' => $this->limit,
        ], 402);
    }
}
