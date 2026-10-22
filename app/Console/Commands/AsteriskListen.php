<?php

namespace App\Console\Commands;

use App\Services\AsteriskManagerService;
use App\Services\CampaignDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class AsteriskListen extends Command
{
    protected $signature = 'asterisk:listen';

    protected $description = 'Asterisk AMI hodisalarini tinglaydi va kampaniya navbatini yuritadi (faqat 1 nusxa ishlasin)';

    public function handle(): void
    {
        // Dispatcher va listener AMI ning bitta ulanishidan foydalanadi
        $ami = new AsteriskManagerService();
        $dispatcher = new CampaignDispatcher($ami);

        $ami->onEvent(fn (array $event) => $this->routeEvent($dispatcher, $event));

        $tickEvery = (int) config('asterisk.dialer.tick_seconds', 3);
        $lastTick = 0;
        $lastPing = time();

        $this->info('AMI listener ishga tushdi.');

        while (true) {
            try {
                if (!$ami->isConnected()) {
                    $ami->connect(withEvents: true);
                    $ami->setReadTimeout(1);
                    $lastPing = time();
                    $this->info('AMI ga ulandi.');
                }

                // Hodisa kelishini 1 soniyagacha kutadi
                $ami->poll();

                if (time() - $lastTick >= $tickEvery) {
                    $lastTick = time();
                    $dispatcher->tick();
                }

                // Ulanish "jim o‘lib qolmasligi" uchun
                if (time() - $lastPing >= 30) {
                    $lastPing = time();
                    $ami->action(['Action' => 'Ping']);
                }
            } catch (Throwable $e) {
                Log::error('AMI listener: ' . $e->getMessage());
                $this->error($e->getMessage());

                $ami->close();
                sleep(3);
            }
        }
    }

    private function routeEvent(CampaignDispatcher $dispatcher, array $event): void
    {
        try {
            $name = $event['Event'] ?? '';

            // Qo‘ng‘iroqqa javob berildi / berilmadi
            if (
                $name === 'OriginateResponse'
                && preg_match('/^rcpt-(\d+)-(\d+)$/', $event['ActionID'] ?? '', $m)
            ) {
                $dispatcher->handleOriginateResponse(
                    (int) $m[1],
                    (int) $m[2],
                    strtolower($event['Response'] ?? '') === 'success',
                    (int) ($event['Reason'] ?? 0),
                    $event['Uniqueid'] ?? null
                );

                return;
            }

            // Dialplan dagi h extension yuborgan "suhbat tugadi" xabari
            if ($name === 'UserEvent' && ($event['UserEvent'] ?? '') === 'CampaignCallEnd') {
                $dispatcher->handleCallEnd(
                    (int) ($event['RecipientID'] ?? 0),
                    (int) ($event['Attempt'] ?? 0),
                    ($event['Played'] ?? '') === '1'
                );
            }
        } catch (Throwable $e) {
            Log::error('AMI hodisasini qayta ishlashda xato: ' . $e->getMessage());
        }
    }
}
