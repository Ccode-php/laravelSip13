<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SipTrunkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'host' => $this->host,
            'port' => $this->port,
            'transport' => $this->transport,
            'auth_type' => $this->auth_type,
            'username' => $this->username,
            'realm' => $this->realm,
            'from_user' => $this->from_user,
            'from_domain' => $this->from_domain,
            'outbound_proxy' => $this->outbound_proxy,
            'codec' => $this->codec,
            'dtmf_mode' => $this->dtmf_mode,
            'caller_id' => $this->caller_id,
            'max_concurrent_calls' => $this->max_concurrent_calls,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}