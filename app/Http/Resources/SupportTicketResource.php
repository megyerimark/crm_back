<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'category' => $this->category,
            'status' => $this->status,
            'priority' => $this->priority,
            'plan' => $this->plan,
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? ['id' => $this->workspace->id, 'name' => $this->workspace->name] : null),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'assignee' => UserSummaryResource::make($this->whenLoaded('assignee')),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message) => [
                'id' => $message->id,
                'is_staff' => $message->is_staff,
                'author' => $message->is_staff ? 'VállalkozóOS Support' : $message->user?->name,
                'body' => $message->body,
                'created_at' => $message->created_at,
            ])),
            'messages_count' => $this->whenCounted('messages'),
            'last_customer_reply_at' => $this->last_customer_reply_at,
            'last_staff_reply_at' => $this->last_staff_reply_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
