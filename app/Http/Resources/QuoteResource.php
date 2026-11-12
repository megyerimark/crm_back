<?php

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'status' => $this->status,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? ['id' => $this->lead->id, 'name' => $this->lead->name] : null),
            'deal_id' => $this->deal_id,
            'deal' => $this->whenLoaded('deal', fn () => $this->deal ? ['id' => $this->deal->id, 'title' => $this->deal->title] : null),
            'owner_id' => $this->owner_id,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'recipient_name' => $this->recipient_name,
            'recipient_email' => $this->recipient_email,
            'currency' => $this->currency,
            'net_total' => (float) $this->net_total,
            'vat_total' => (float) $this->vat_total,
            'gross_total' => (float) $this->gross_total,
            'valid_until' => $this->valid_until?->toDateString(),
            'introduction' => $this->introduction,
            'terms' => $this->terms,
            'public_url' => $this->publicUrl(),
            'sent_at' => $this->sent_at,
            'first_viewed_at' => $this->first_viewed_at,
            'last_viewed_at' => $this->last_viewed_at,
            'view_count' => $this->view_count,
            'accepted_at' => $this->accepted_at,
            'accepted_by_name' => $this->accepted_by_name,
            'declined_at' => $this->declined_at,
            'decline_reason' => $this->decline_reason,
            'expired_at' => $this->expired_at,
            'follow_up_at' => $this->follow_up_at,
            'follow_up_count' => $this->follow_up_count,
            'source' => $this->source,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'vat_rate' => (float) $item->vat_rate,
                'net_amount' => $item->netAmount(),
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'meta' => $event->meta,
                'user' => $event->user?->name,
                'created_at' => $event->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
