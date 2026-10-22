<?php

namespace App\Services;

use App\Models\SipTrunk;
use RuntimeException;

class AsteriskConfigService
{
    public function __construct(
        private AsteriskManagerService $ami
    ) {}

    /**
     * Asterisk dagi endpoint nomi. Trunk nomiga emas, ID ga bog‘langan:
     * nom o‘zgarsa yoki ikki trunk nomi o‘xshash bo‘lsa ham to‘qnashuv bo‘lmaydi.
     */
    public static function endpointName(SipTrunk $trunk): string
    {
        return 'trunk-' . $trunk->id;
    }

    public function generate(SipTrunk $trunk): string
    {
        $name = self::endpointName($trunk);

        $transport = in_array($trunk->transport, ['udp', 'tcp', 'tls'], true)
            ? $trunk->transport
            : 'udp';

        $dtmf = in_array($trunk->dtmf_mode, ['rfc4733', 'inband', 'info', 'auto', 'auto_info'], true)
            ? $trunk->dtmf_mode
            : 'rfc4733';

        $usesAuth = $trunk->auth_type === 'username_password';

        $contact = 'sip:' . $this->v($trunk->host) . ':' . (int) $trunk->port;

        if ($transport !== 'udp') {
            $contact .= '\;transport=' . $transport;
        }

        $lines = [
            "; trunk #{$trunk->id}",
            "[{$name}]",
            'type=endpoint',
            "transport=transport-{$transport}",
            // Provayderdan kelgan kiruvchi qo‘ng‘iroqlar rad etiladi
            'context=campaign-reject',
            'disallow=all',
            'allow=' . $this->codecs($trunk->codec),
            "aors={$name}-aor",
            'direct_media=no',
            'rtp_symmetric=yes',
            'force_rport=yes',
            'rewrite_contact=yes',
            "dtmf_mode={$dtmf}",
        ];

        if ($usesAuth) {
            $lines[] = "outbound_auth={$name}-auth";
        }

        if ($trunk->from_user) {
            $lines[] = 'from_user=' . $this->v($trunk->from_user);
        }

        if ($trunk->from_domain) {
            $lines[] = 'from_domain=' . $this->v($trunk->from_domain);
        }

        $callerId = preg_replace('/[^0-9+]/', '', (string) $trunk->caller_id);

        if ($callerId !== '') {
            $lines[] = "callerid=\"{$callerId}\" <{$callerId}>";
        }

        if ($trunk->outbound_proxy) {
            $lines[] = 'outbound_proxy=' . $this->v($trunk->outbound_proxy);
        }

        $lines[] = '';
        $lines[] = "[{$name}-aor]";
        $lines[] = 'type=aor';
        $lines[] = "contact={$contact}";
        $lines[] = '';

        if ($usesAuth) {
            $lines[] = "[{$name}-auth]";
            $lines[] = 'type=auth';
            $lines[] = 'auth_type=userpass';
            $lines[] = 'username=' . $this->v($trunk->username);
            $lines[] = 'password=' . $this->v($trunk->password);

            if ($trunk->realm) {
                $lines[] = 'realm=' . $this->v($trunk->realm);
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Barcha faol trunklarni faylga atomik yozadi va PJSIP ni qayta yuklaydi.
     * Xato bo‘lsa istisno tashlaydi (jimgina o‘tkazib yubormaydi).
     */
    public function syncAll(): void
    {
        $content = '; Laravel tomonidan avtomatik yaratilgan. QO‘LDA TAHRIRLAMANG.' . PHP_EOL
            . '; ' . now()->toDateTimeString() . PHP_EOL . PHP_EOL;

        foreach (SipTrunk::where('enabled', true)->orderBy('id')->get() as $trunk) {
            $content .= $this->generate($trunk) . PHP_EOL;
        }

        $path = config('asterisk.trunk_conf_path');
        $dir = dirname($path);

        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("Asterisk config papkasi topilmadi yoki yozib bo‘lmaydi: {$dir}");
        }

        // Avval vaqtinchalik faylga yozib, keyin almashtiramiz:
        // Asterisk hech qachon yarim yozilgan faylni o‘qimaydi.
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Faylga yozib bo‘lmadi: {$tmp}");
        }

        // asterisk foydalanuvchisi o‘qiy olishi uchun. Qattiqroq (0640) qilish uchun
        // faylni asterisk UID si bilan yozing (README ga qarang).
        @chmod($tmp, 0644);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Faylni almashtirib bo‘lmadi: {$path}");
        }

        $this->ami->reloadPjsip();
    }

    /**
     * Konfiguratsiyaga yozilayotgan qiymatni tozalaydi:
     * boshqaruv belgilari (yangi qator!) olib tashlanadi,
     * ';' (Asterisk da izoh belgisi) escape qilinadi.
     */
    private function v(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $value);

        return str_replace(';', '\;', trim($value));
    }

    private function codecs(?string $codec): string
    {
        $list = array_filter(array_map(
            fn ($c) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($c))),
            explode(',', (string) $codec)
        ));

        return $list ? implode(',', $list) : 'ulaw,alaw';
    }
}
