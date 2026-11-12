<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\PlanSetting;
use App\Services\AuditLogger;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan limits, features and prices editable from the Super Admin console (overrides config/plans.php).
 */
class PlanAdminController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(Plan::cases())->map(fn (Plan $plan) => [
            'key' => $plan->value,
            'effective' => $this->plans->definition($plan),
            'defaults' => config('plans.plans.'.$plan->value),
            'overrides' => $this->plans->overrides()[$plan->value] ?? (object) [],
        ])]);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $plan = Plan::tryFrom($plan) ?? abort(404);
        $defaults = config('plans.plans.'.$plan->value);

        $rules = [
            'name' => ['sometimes', 'string', 'max:50'],
            'prices' => ['sometimes', 'array'],
            'prices.monthly' => ['required_with:prices', 'integer', 'min:0', 'max:10000000'],
            'prices.yearly' => ['required_with:prices', 'integer', 'min:0', 'max:100000000'],
            'stripe_prices' => ['sometimes', 'array'],
            'stripe_prices.monthly' => ['nullable', 'string', 'max:100', 'regex:/^price_[A-Za-z0-9]+$/'],
            'stripe_prices.yearly' => ['nullable', 'string', 'max:100', 'regex:/^price_[A-Za-z0-9]+$/'],
            'limits' => ['sometimes', 'array'],
            'features' => ['sometimes', 'array'],
        ];

        foreach (array_keys($defaults['limits']) as $key) {
            $rules["limits.{$key}"] = ['nullable', 'integer', 'min:0', 'max:10000000'];
        }

        foreach (array_keys($defaults['features']) as $key) {
            $rules["features.{$key}"] = ['sometimes', 'boolean'];
        }

        $data = $request->validate($rules);

        /* Only known keys are stored. */
        $overrides = array_filter([
            'name' => $data['name'] ?? null,
            'prices' => $data['prices'] ?? null,
            'stripe_prices' => isset($data['stripe_prices']) ? array_intersect_key($data['stripe_prices'], array_flip(['monthly', 'yearly'])) : null,
            'limits' => isset($data['limits']) ? array_intersect_key($data['limits'], $defaults['limits']) : null,
            'features' => isset($data['features']) ? array_intersect_key($data['features'], $defaults['features']) : null,
        ], fn ($value) => $value !== null);

        $before = $this->plans->definition($plan);
        $setting = PlanSetting::firstOrNew(['plan' => $plan->value]);
        $setting->fill(['overrides' => array_replace_recursive($setting->overrides ?? [], $overrides), 'updated_by' => $request->user()->id])->save();
        $this->plans->forgetOverrides();

        $this->audit->log('admin.plan.updated', null, [
            'plan' => $plan->value,
            'before' => array_intersect_key($before, $overrides),
            'after' => array_intersect_key($this->plans->definition($plan), $overrides),
        ], null, true);

        return response()->json(['data' => ['key' => $plan->value, 'effective' => $this->plans->definition($plan), 'overrides' => $setting->overrides]]);
    }

    public function reset(string $plan): JsonResponse
    {
        $plan = Plan::tryFrom($plan) ?? abort(404);
        PlanSetting::where('plan', $plan->value)->delete();
        $this->plans->forgetOverrides();
        $this->audit->log('admin.plan.reset', null, ['plan' => $plan->value], null, true);

        return response()->json(['data' => ['key' => $plan->value, 'effective' => $this->plans->definition($plan)]]);
    }
}
