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

    public static function withDescendantIds(array $ids): array
    {
        $byParent = static::query()->get(['id', 'parent_id'])->groupBy('parent_id');

        $result = array_map('intval', $ids);
        $queue = $result;

        while ($queue) {
            $current = array_pop($queue);

            foreach ($byParent->get($current, []) as $child) {
                if (!in_array($child->id, $result, true)) {
                    $result[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $result;
    }
}
