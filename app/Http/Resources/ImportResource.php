<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'target' => $this->target,
            'original_name' => $this->original_name,
            'status' => $this->status,
            'sheet' => $this->sheet,
            'sheets' => $this->sheets ?? [],
            'headers' => $this->headers ?? [],
            'mapping' => $this->mapping ?? (object) [],
            'duplicate_mode' => $this->duplicate_mode,
            'total_rows' => $this->total_rows,
            'imported_count' => $this->imported_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,
            'error' => $this->error,
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
