<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomFieldResource;
use App\Models\CustomFieldDefinition;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    public function __construct(protected PlanService $plans) {}

    public function index(): AnonymousResourceCollection
    {
        return CustomFieldResource::collection(CustomFieldDefinition::orderBy('position')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'custom_fields');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(CustomFieldDefinition::TYPES)],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100'],
        ]);

        $base = Str::snake(Str::ascii($data['label'])) ?: 'field';
        $key = Str::limit(preg_replace('/[^a-z0-9_]/', '', $base), 40, '') ?: 'field';

        while (CustomFieldDefinition::where('entity', 'client')->where('key', $key)->exists()) {
            $key = $base.'_'.Str::lower(Str::random(3));
        }

        $field = CustomFieldDefinition::create($data + ['entity' => 'client', 'key' => $key, 'position' => CustomFieldDefinition::max('position') + 1]);

        return CustomFieldResource::make($field)->response()->setStatusCode(201);
    }

    public function update(Request $request, CustomFieldDefinition $customField): CustomFieldResource
    {
        $customField->update($request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]));

        return CustomFieldResource::make($customField);
    }

    public function destroy(CustomFieldDefinition $customField): JsonResponse
    {
        $customField->delete();

        return response()->json(['ok' => true]);
    }
}
