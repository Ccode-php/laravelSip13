<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriberPhone extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscriber_id',
        'phone',
        'label',
        'is_primary',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'subscriber_id' => 'integer',
            'is_primary' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(
            Subscriber::class
        );
    }
}