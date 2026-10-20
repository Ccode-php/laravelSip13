<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'subscriber_id',
        'subscriber_phone_id',
        'name',
        'phone',
        'attempt',
        'max_attempts',
        'status',
        'sip_code',
        'sip_reason',
        'started_at',
        'answered_at',
        'finished_at',
        'duration',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(
            Campaign::class
        );
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(
            Subscriber::class
        );
    }

    public function subscriberPhone(): BelongsTo
    {
        return $this->belongsTo(
            SubscriberPhone::class
        );
    }
}