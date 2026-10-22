<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Asterisk AMI klienti.
 *
 * - Bitta doimiy ulanish (har buyruqqa yangi login qilinmaydi).
 * - Javob kutayotganda kelgan hodisalar yo‘qolmaydi, onEvent() ga uzatiladi.
 * - Bir xil nomli sarlavhalar (masalan, bir nechta Variable) qo‘llab-quvvatlanadi.
 */
class AsteriskManagerService
{
    /** @var resource|null */
    private $socket = null;

    /** @var callable|null */
    private $eventHandler = null;

    public function __destruct()
    {
        $this->close();
    }

    public function onEvent(callable $handler): void
    {
        $this->eventHandler = $handler;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function setReadTimeout(int $seconds): void
    {
        if ($this->isConnected()) {
            stream_set_timeout($this->socket, $seconds);
        }
    }

    public function connect(bool $withEvents = false): void
    {
        if ($this->isConnected()) {
            return;
        }

        $cfg = config('asterisk.ami');

        $socket = @stream_socket_client(
            "tcp://{$cfg['host']}:{$cfg['port']}",
            $errno,
            $errstr,
            $cfg['timeout']
        );

        if (!$socket) {
            throw new RuntimeException("AMI ulanish xatosi: {$errstr} ({$errno})");
        }

        $this->socket = $socket;
        $this->setReadTimeout((int) $cfg['timeout']);

        if (fgets($this->socket, 4096) === false) {
            $this->close();
            throw new RuntimeException('AMI bannerini olib bo‘lmadi.');
        }

        try {
            $this->action([
                'Action' => 'Login',
                'Username' => $cfg['username'],
                'Secret' => $cfg['secret'],
                // Faqat kerakli hodisa sinflari: OriginateResponse (call) va UserEvent (user)
                'Events' => $withEvents ? 'call,user' : 'off',
            ]);
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "Action: Logoff\r\n\r\n");
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * Buyruq yuboradi va unga tegishli javobni qaytaradi.
     * Kutish paytida kelgan hodisalar onEvent() handleriga beriladi.
     */
    public function action(array $headers): array
    {
        if (!$this->isConnected()) {
            throw new RuntimeException('AMI ga ulanilmagan.');
        }

        $actionId = (string) ($headers['ActionID'] ?? bin2hex(random_bytes(6)));
        $headers['ActionID'] = $actionId;

        $message = '';

        foreach ($headers as $key => $values) {
            foreach ((array) $values as $value) {
                $message .= $key . ': ' . str_replace(["\r", "\n"], ' ', (string) $value) . "\r\n";
            }
        }

        $message .= "\r\n";

        if (@fwrite($this->socket, $message) === false) {
            $this->close();
            throw new RuntimeException('AMI buyrug‘ini yuborib bo‘lmadi.');
        }

        $deadline = microtime(true) + 10;

        while (true) {
            $packet = $this->readPacket();

            if ($packet === null) {
                if (microtime(true) > $deadline) {
                    $this->close();
                    throw new RuntimeException('AMI javob berish vaqti tugadi.');
                }

                continue;
            }

            if (isset($packet['Event'])) {
                $this->dispatchEvent($packet);
                continue;
            }

            if (($packet['ActionID'] ?? null) !== $actionId || !isset($packet['Response'])) {
                continue;
            }

            if (!in_array(strtolower($packet['Response']), ['success', 'follows'], true)) {
                throw new RuntimeException($packet['Message'] ?? 'AMI buyrug‘i bajarilmadi.');
            }

            return $packet;
        }
    }

    /**
     * Listener uchun: kamida bitta paketni (timeout gacha) o‘qiydi
     * va hodisa bo‘lsa handlerga beradi.
     */
    public function poll(): void
    {
        if (!$this->isConnected()) {
            throw new RuntimeException('AMI ga ulanilmagan.');
        }

        $packet = $this->readPacket();

        if ($packet !== null && isset($packet['Event'])) {
            $this->dispatchEvent($packet);
        }
    }

    public function ping(): array
    {
        $this->connect();

        return $this->action(['Action' => 'Ping']);
    }

    /**
     * Qo‘ng‘iroqni boshlaydi. Natija OriginateResponse hodisasi orqali keladi.
     * ActionID formati: rcpt-{recipientId}-{attempt}
     */
    public function originate(
        string $phone,
        string $endpoint,
        string $audio,
        int $recipientId,
        int $attempt,
        ?string $callerId = null
    ): string {
        $phone = preg_replace('/[\s()\-]/', '', $phone);

        if (!is_string($phone) || !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            throw new InvalidArgumentException('Telefon raqami noto‘g‘ri.');
        }

        $callerId = preg_replace('/[^0-9+]/', '', (string) $callerId);

        if ($callerId === '') {
            $callerId = (string) config('asterisk.dialer.default_caller_id');
        }

        $actionId = "rcpt-{$recipientId}-{$attempt}";

        $this->action([
            'Action' => 'Originate',
            'ActionID' => $actionId,
            'Channel' => "PJSIP/{$phone}@{$endpoint}",
            'Context' => config('asterisk.dialer.context'),
            'Exten' => 's',
            'Priority' => '1',
            'Timeout' => (string) config('asterisk.dialer.originate_timeout_ms'),
            'Async' => 'true',
            'CallerID' => "\"{$callerId}\" <{$callerId}>",
            // Har bir o‘zgaruvchi alohida sarlavha bo‘lishi kerak
            'Variable' => [
                "RECIPIENT_ID={$recipientId}",
                "ATTEMPT={$attempt}",
                "AUDIO_FILE={$audio}",
            ],
        ]);

        return $actionId;
    }

    /**
     * PJSIP modulini qayta yuklaydi (yangi/o‘zgargan trunklar uchun).
     */
    public function reloadPjsip(): void
    {
        $this->connect();

        $this->action([
            'Action' => 'Reload',
            'Module' => 'res_pjsip.so',
        ]);
    }

    private function dispatchEvent(array $packet): void
    {
        if ($this->eventHandler) {
            ($this->eventHandler)($packet);
        }
    }

    /**
     * Bitta AMI paketini o‘qiydi. Timeout bo‘lsa null qaytaradi.
     */
    private function readPacket(): ?array
    {
        $packet = [];

        while (true) {
            $line = fgets($this->socket, 8192);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                if (!empty($meta['timed_out'])) {
                    if ($packet === []) {
                        return null;
                    }

                    continue;
                }

                $this->close();
                throw new RuntimeException('AMI ulanishi uzildi.');
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if ($packet === []) {
                    continue;
                }

                return $packet;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $packet[trim($parts[0])] = ltrim($parts[1]);
            }
        }
    }
}
