<?php

namespace App\Services;

use RuntimeException;
use InvalidArgumentException;

class AsteriskManagerService
{
    private $socket;

    private function readPacket(): array
    {
        $packet = [];

        while (($line = fgets($this->socket, 4096)) !== false) {
            $line = trim($line);

            if ($line === '') {
                if ($packet !== []) {
                    return $packet;
                }

                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $packet[trim($parts[0])] = trim($parts[1]);
            }
        }

        $meta = stream_get_meta_data($this->socket);

        if ($meta['timed_out']) {
            throw new RuntimeException('AMI javob berish vaqti tugadi.');
        }

        throw new RuntimeException('AMI ulanishi uzildi.');
    }

    private function sendAction(array $headers): array
    {
        $message = '';

        foreach ($headers as $key => $value) {
            $message .= $key . ': ' . $value . "\r\n";
        }

        $message .= "\r\n";

        if (fwrite($this->socket, $message) === false) {
            throw new RuntimeException('AMI buyrug‘ini yuborib bo‘lmadi.');
        }

        // AMI hodisalari javobdan oldin kelishi mumkin.
        // Shu sababli Response kelguncha paketlarni o‘qiymiz.
        do {
            $response = $this->readPacket();
        } while (!isset($response['Response']));

        if (strtolower($response['Response']) !== 'success') {
            throw new RuntimeException(
                $response['Message'] ?? 'AMI buyrug‘i bajarilmadi.'
            );
        }

        return $response;
    }

    public function ping(): array
    {
        $host = config('services.asterisk.ami_host');
        $port = config('services.asterisk.ami_port');
        $username = config('services.asterisk.ami_username');
        $secret = config('services.asterisk.ami_secret');

        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            throw new RuntimeException(
                "AMI ulanish xatosi: {$errstr} ({$errno})"
            );
        }

        stream_set_timeout($this->socket, 5);

        try {
            // Asterisk bannerini o‘qiymiz.
            $banner = fgets($this->socket, 4096);

            if ($banner === false) {
                throw new RuntimeException('AMI bannerini olish imkonsiz.');
            }

            $this->sendAction([
                'Action' => 'Login',
                'Username' => $username,
                'Secret' => $secret,
                'Events' => 'off',
            ]);

            return $this->sendAction([
                'Action' => 'Ping',
            ]);
        } finally {
            if (is_resource($this->socket)) {
                @fwrite($this->socket, "Action: Logoff\r\n\r\n");
                fclose($this->socket);
            }
        }
    }




    public function originate(
        string $phone,
        int $recipientId,
        string $trunkName,
        string $audioPath
    ): array {
        $phone = preg_replace('/[\s()\-]/', '', $phone);

        if (!is_string($phone) || !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            throw new InvalidArgumentException('Telefon raqami noto‘g‘ri.');
        }

        $host = config('services.asterisk.ami_host', '127.0.0.1');
        $port = config('services.asterisk.ami_port', 5038);
        $username = config('services.asterisk.ami_username', 'laravel');
        $secret = config('services.asterisk.ami_secret', '123456');

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);

        if (!$socket) {
            throw new RuntimeException("AMI ulanish xatosi: {$errstr} ({$errno})");
        }

        $this->socket = $socket;
        stream_set_timeout($socket, 5);

        try {
            if (fgets($socket, 4096) === false) {
                throw new RuntimeException('AMI bannerini olib bo‘lmadi.');
            }

            // Login
            $message = "Action: Login\r\nUsername: {$username}\r\nSecret: {$secret}\r\nEvents: off\r\n\r\n";
            fwrite($socket, $message);
            $this->readPacket();

            // Trunk nomini Asterisk mos formatga o'tkazish
            $sanitizedTrunk = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $trunkName));

            $headers = [
                'Action'   => 'Originate',
                'Channel'  => "PJSIP/{$phone}@{$sanitizedTrunk}",
                'Context'  => 'campaign-playback',
                'Exten'    => 's',
                'Priority' => '1',
                'Timeout'  => '30000',
                'Async'    => 'true',
                'CallerID' => 'AutoDialer <1000>',
                'Variable' => "RECIPIENT_ID={$recipientId},AUDIO_FILE={$audioPath}",
            ];

            return $this->sendAction($headers);
        } finally {
            @fwrite($socket, "Action: Logoff\r\n\r\n");
            fclose($socket);
        }
    }
}
