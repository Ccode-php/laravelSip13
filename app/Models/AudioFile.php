<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioFile extends Model
{
    protected $fillable = [
        'name',
        'original_name',
        'file_path',
        'mime_type',
        'size',
        'duration',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}