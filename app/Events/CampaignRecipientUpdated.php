<?php

namespace App\Events;

use App\Models\CampaignRecipient;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Abonent holati o‘zgarganda Vue ga real vaqtda yuboriladi (Reverb/Echo orqali).
 *
 * Diqqat: hozir ochiq kanal. Login tizimi qo‘shilgach PrivateChannel ga o‘tkazing,
 * chunki payload ichida telefon raqamlari bor.
 */
class CampaignRecipientUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public CampaignRecipient $recipient
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("campaign.{$this->recipient->campaign_id}")];
    }

    public function broadcastAs(): string
    {
        return 'recipient.updated';
    }

    public function broadcastWith(): array
    {
        $r = $this->recipient;

        return [
            'id' => $r->id,
            'campaign_id' => $r->campaign_id,
            'name' => $r->name,
            'phone' => $r->phone,
            'status' => $r->status,
            'attempt' => $r->attempt,
            'max_attempts' => $r->max_attempts,
            'played' => (bool) $r->played,
            'duration' => $r->duration,
            'sip_reason' => $r->sip_reason,
            'error_message' => $r->error_message,
            'answered_at' => $r->answered_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'next_attempt_at' => $r->next_attempt_at?->toIso8601String(),
        ];
    }
}
