<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscriber_group_id',
        'name',
        'type',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'subscriber_group_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(
            SubscriberGroup::class,
            'subscriber_group_id'
        );
    }

    public function phones(): HasMany
    {
        return $this->hasMany(
            SubscriberPhone::class
        )->orderByDesc('is_primary')->orderBy('id');
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(
            CampaignRecipient::class
        );
    }
}