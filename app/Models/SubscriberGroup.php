<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SubscriberGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            SubscriberGroup::class,
            'parent_id'
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            SubscriberGroup::class,
            'parent_id'
        )->orderBy('name');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(
            Subscriber::class,
            'subscriber_group_id'
        );
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(
            Campaign::class,
            'campaign_groups'
        );
    }
}