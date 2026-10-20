<?php

namespace App\Services;

use App\Models\SipTrunk;

class AsteriskConfigService
{
    public function generate(SipTrunk $trunk): string
    {
        $name = $this->sanitizeName($trunk->name);

        $config = [];

        // Endpoint
        $config[] = "[$name]";
        $config[] = "type=endpoint";
        $config[] = "transport=transport-udp";
        $config[] = "context=from-trunk";
        $config[] = "disallow=all";

        if ($trunk->codec) {
            $codecs = array_map('trim', explode(',', $trunk->codec));

            foreach ($codecs as $codec) {
                $config[] = "allow=$codec";
            }
        }

        if ($trunk->auth_type === 'username_password') {
            $config[] = "outbound_auth={$name}-auth";
        }

        $config[] = "aors={$name}-aor";

        if ($trunk->from_user) {
            $config[] = "from_user={$trunk->from_user}";
        }

        if ($trunk->from_domain) {
            $config[] = "from_domain={$trunk->from_domain}";
        }

        $config[] = "";

        // AOR
        $config[] = "[$name-aor]";
        $config[] = "type=aor";
        $config[] = "contact=sip:{$trunk->host}:{$trunk->port}";
        $config[] = "";

        // Authentication
        if ($trunk->auth_type === 'username_password') {
            $config[] = "[$name-auth]";
            $config[] = "type=auth";
            $config[] = "auth_type=user_pass"; // userpass emas, user_pass bo'lishi shart!
            $config[] = "username={$trunk->username}";
            $config[] = "password={$trunk->password}";
            $config[] = "";
        }

        return implode(PHP_EOL, $config);
    }

    private function sanitizeName(string $name): string
    {
        return strtolower(
            preg_replace('/[^a-zA-Z0-9_-]/', '_', $name)
        );
    }
}