<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SipTrunk extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'host',
        'port',
        'transport',
        'auth_type',
        'username',
        'password',
        'realm',
        'from_user',
        'from_domain',
        'outbound_proxy',
        'codec',
        'dtmf_mode',
        'caller_id',
        'max_concurrent_calls',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'max_concurrent_calls' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}