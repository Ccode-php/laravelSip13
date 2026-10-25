<?php

return [

    /*
    | AMI (Asterisk Manager Interface) ulanishi.
    | Qiymatlar k3s ConfigMap/Secret orqali env sifatida keladi.
    */
    'ami' => [
        'host' => env('ASTERISK_AMI_HOST', '127.0.0.1'),
        'port' => (int) env('ASTERISK_AMI_PORT', 5038),
        'username' => env('ASTERISK_AMI_USERNAME', 'laravel'),
        'secret' => env('ASTERISK_AMI_SECRET'),
        'timeout' => 5,
    ],

    /*
    | Podga hostPath orqali ulangan yo‘llar:
    |   /asterisk-config -> hostda /etc/asterisk/laravel
    |   /asterisk-sounds -> hostda /var/lib/asterisk/sounds/campaign
    */
    'trunk_conf_path' => env('ASTERISK_TRUNK_CONF_PATH', '/asterisk-config/pjsip_trunks.conf'),
    'sounds_path' => env('ASTERISK_SOUNDS_PATH', '/asterisk-sounds'),

    // Asterisk Playback() ko‘radigan nom: sounds/ ichidagi papka
    'sounds_prefix' => 'campaign',

    'dialer' => [
        'context' => 'campaign-playback',
        'originate_timeout_ms' => 30000,
        'originate_delay_ms' => 150,      // qo‘ng‘iroqlar orasidagi pauza (CPS ni cheklaydi)
        'tick_seconds' => 3,              // navbatni qanchalik tez-tez tekshirish
        'stuck_after_seconds' => 600,     // shundan keyin "qotib qolgan" qo‘ng‘iroq yopiladi
        'retry_delay_minutes' => 0,      // qayta urinishgacha kutish
        'default_caller_id' => env('ASTERISK_DEFAULT_CALLER_ID', '1000'),
    ],
];
