<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;
use Throwable;

class CampaignDispatcher
{
    public function __construct(
        private AsteriskManagerService $asterisk
    ) {}

    public function dispatch(int $campaignId): array
    {
        $campaign = Campaign::with(['sipTrunk', 'audioFile'])->findOrFail($campaignId);

        if (!in_array($campaign->status, ['queued', 'running'], true)) {
            return ['started' => 0, 'message' => 'Kampaniya faol emas.'];
        }

        if (!$campaign->sipTrunk || !$campaign->sipTrunk->enabled) {
            $campaign->update(['status' => 'failed']);
            throw new \RuntimeException('Faol SIP trunk topilmadi.');
        }

        // Bir vaqtda ruxsat etilgan parallel liniyalar limiti
        $limit = min(
            (int) $campaign->max_concurrent_calls,
            (int) $campaign->sipTrunk->max_concurrent_calls,
            100
        );

        // Hozirgi ayni vaqtdagi faol qo'ng'iroqlarni hisoblash
        $activeCalls = CampaignRecipient::whereIn('status', ['ringing', 'answered'])
            ->whereNull('finished_at')
            ->whereHas('campaign', function ($q) use ($campaign) {
                $q->where('sip_trunk_id', $campaign->sip_trunk_id);
            })
            ->count();

        $availableChannels = $limit - $activeCalls;

        if ($availableChannels <= 0) {
            return ['started' => 0, 'message' => 'Hamma liniyalar band. Navbat kutilmoqda.'];
        }

        // Asterisk o'qishi uchun audioning kengaytmasini (.mp3/.wav) qirqib tashlaymiz
        $audioPath = storage_path('app/public/' . $campaign->audioFile->file_path);
        $audioPath = preg_replace('/\\.[^.\\s]+$/', '', $audioPath);

        $started = 0;

        // Faqat bo'sh kanallar sonigacha abonentni chiqarish (Xavfsiz sikl)
        for ($i = 0; $i < $availableChannels; $i++) {
            
            $recipient = DB::transaction(function () use ($campaignId) {
                // Tartib bo'yicha pending holatdagi va urinishlari tugamagan raqamni olamiz
                $recipient = CampaignRecipient::where('campaign_id', $campaignId)
                    ->where('status', 'pending')
                    ->whereRaw('attempt <= max_attempts')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$recipient) {
                    return null;
                }

                $recipient->update([
                    'status' => 'ringing',
                    'started_at' => now(),
                    'finished_at' => null,
                ]);

                return $recipient;
            });

            if (!$recipient) {
                break;
            }

            try {
                $this->asterisk->originate(
                    $recipient->phone,
                    $recipient->id,
                    $campaign->sipTrunk->name,
                    $audioPath
                );

                $started++;
            } catch (Throwable $e) {
                $recipient->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        if ($started > 0 && $campaign->status === 'queued') {
            $campaign->update(['status' => 'running']);
        }

        // Kampaniya to'liq tugaganini tekshirish
        $remaining = CampaignRecipient::where('campaign_id', $campaignId)->where('status', 'pending')->count();
        $running = CampaignRecipient::where('campaign_id', $campaignId)->whereIn('status', ['ringing', 'answered'])->count();

        if ($remaining === 0 && $running === 0) {
            $campaign->update(['status' => 'completed', 'finished_at' => now()]);
        }

        return [
            'started' => $started,
            'message' => "{$started} ta yangi qo‘ng‘iroq kanallarga chiqarildi.",
        ];
    }
}
