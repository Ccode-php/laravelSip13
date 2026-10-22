<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sip_trunk_id',
        'audio_file_id',
        'target_type',
        'max_concurrent_calls',
        'max_attempts',
        'status',
        'started_at',
        'finished_at',
        'retry_delay_minutes',
    ];

    protected function casts(): array
    {
        return [
            'max_concurrent_calls' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_delay_minutes' => 'integer'
        ];
    }

    public function sipTrunk(): BelongsTo
    {
        return $this->belongsTo(
            SipTrunk::class
        );
    }

    public function audioFile(): BelongsTo
    {
        return $this->belongsTo(
            AudioFile::class
        );
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            SubscriberGroup::class,
            'campaign_groups'
        );
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(
            Subscriber::class,
            'campaign_subscribers'
        );
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(
            CampaignRecipient::class
        );
    }
}