<?php

namespace App\Services;

use App\Events\CampaignRecipientUpdated;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Kampaniya navbatini yuritadi.
 *
 * Faqat `asterisk:listen` jarayoni ichida ishlaydi (1 nusxa),
 * shuning uchun bir vaqtda ikki joydan qo‘ng‘iroq chiqib ketmaydi.
 *
 * Holatlar:
 *   pending   - navbatda (yoki qayta urinishni kutmoqda)
 *   ringing   - terilgan, javob kutilmoqda
 *   answered  - finished_at = null bo‘lsa SUHBAT DAVOM ETMOQDA, aks holda yakunlangan
 *   busy / no_answer / failed - yakuniy holatlar
 */
class CampaignDispatcher
{
    public function __construct(
        private AsteriskManagerService $asterisk
    ) {}

    /**
     * Listener har bir "tik"da chaqiradi.
     */
    public function tick(): void
    {
        $this->releaseStuck();

        Campaign::where('status', 'running')
            ->pluck('id')
            ->each(fn (int $id) => $this->dispatch($id));
    }

    /**
     * Bo‘sh kanallar qadar yangi qo‘ng‘iroq chiqaradi.
     */
    public function dispatch(int $campaignId): int
    {
        if (!$this->asterisk->isConnected()) {
            return 0;
        }

        $campaign = Campaign::with(['sipTrunk', 'audioFile'])->find($campaignId);

        if (!$campaign || $campaign->status !== 'running') {
            return 0;
        }

        $trunk = $campaign->sipTrunk;

        if (!$trunk || !$trunk->enabled) {
            $campaign->update(['status' => 'failed', 'finished_at' => now()]);

            return 0;
        }

        $free = $this->freeChannels($campaign);
        $started = 0;

        for ($i = 0; $i < $free; $i++) {
            $recipient = $this->claimNext($campaign->id);

            if (!$recipient) {
                break;
            }

            try {
                $this->asterisk->originate(
                    phone: $recipient->phone,
                    endpoint: AsteriskConfigService::endpointName($trunk),
                    audio: $campaign->audioFile->file_path,
                    recipientId: $recipient->id,
                    attempt: $recipient->attempt,
                    callerId: $trunk->caller_id,
                );

                $started++;
                $this->notify($recipient);
            } catch (InvalidArgumentException $e) {
                // Raqam noto‘g‘ri: qayta urinishning foydasi yo‘q
                $this->registerFailure($recipient, 'failed', $e->getMessage(), retry: false);
            } catch (Throwable $e) {
                if (!$this->asterisk->isConnected()) {
                    // Ulanish uzildi: urinishni sarflamasdan navbatga qaytaramiz
                    $recipient->update(['status' => 'pending', 'started_at' => null]);
                    break;
                }

                $this->registerFailure($recipient, 'failed', $e->getMessage());
            }

            usleep(((int) config('asterisk.dialer.originate_delay_ms', 150)) * 1000);
        }

        $this->completeIfDone($campaign);

        return $started;
    }

    /**
     * OriginateResponse hodisasi: qo‘ng‘iroqqa javob berildi yoki berilmadi.
     */
    public function handleOriginateResponse(
        int $recipientId,
        int $attempt,
        bool $success,
        int $reason,
        ?string $uniqueid
    ): void {
        $recipient = CampaignRecipient::find($recipientId);

        // Eskirgan yoki takroriy hodisalarni e’tiborsiz qoldiramiz
        if (!$recipient || $recipient->attempt !== $attempt || $recipient->status !== 'ringing') {
            return;
        }

        if ($success) {
            $recipient->update([
                'status' => 'answered',
                'answered_at' => now(),
                'uniqueid' => $uniqueid,
            ]);

            $this->notify($recipient);

            // Shu raqam ko'tardi: abonentning keyingi raqamiga qo'ng'iroq qilinmaydi
            $this->skipLaterNumbers($recipient);

            return;
        }

        $this->registerFailure(
            $recipient,
            $this->mapReason($reason),
            sipReason: "AMI reason {$reason}"
        );
    }

    /**
     * Dialplan dagi UserEvent(CampaignCallEnd): suhbat tugadi.
     */
    public function handleCallEnd(int $recipientId, int $attempt, bool $played): void
    {
        $recipient = CampaignRecipient::find($recipientId);

        if (
            !$recipient
            || $recipient->attempt !== $attempt
            || $recipient->finished_at !== null
            || !in_array($recipient->status, ['ringing', 'answered'], true)
        ) {
            return;
        }

        $answeredAt = $recipient->answered_at ?? now();

        $recipient->update([
            'status' => 'answered',
            'answered_at' => $answeredAt,
            'finished_at' => now(),
            'played' => $played,
            'duration' => abs((int) $answeredAt->diffInSeconds(now())),
        ]);

        $this->notify($recipient);
    }

    /**
     * Muvaffaqiyatsiz urinishni qayd etadi: urinish qolgan bo‘lsa kechiktirib
     * qayta navbatga qo‘yadi, aks holda yakuniy holatga o‘tkazadi.
     */
    public function registerFailure(
        CampaignRecipient $recipient,
        string $finalStatus,
        ?string $reason = null,
        bool $retry = true,
        ?string $sipReason = null
    ): void {
        if ($retry && $recipient->attempt < $recipient->max_attempts) {
            // 0 = keyingi raund boshlanishi bilan darrov (kutish yo'q)
            $delay = (int) ($recipient->campaign?->retry_delay_minutes
                ?? config('asterisk.dialer.retry_delay_minutes', 0));

            $recipient->update([
                'status' => 'pending',
                'attempt' => $recipient->attempt + 1,
                'next_attempt_at' => now()->addMinutes($delay),
                'finished_at' => null,
                'sip_reason' => $sipReason,
                'error_message' => $reason,
            ]);
        } else {
            $recipient->update([
                'status' => $finalStatus,
                'finished_at' => now(),
                'next_attempt_at' => null,
                'sip_reason' => $sipReason,
                'error_message' => $reason,
            ]);
        }

        $this->notify($recipient);
    }

    /**
     * Hodisasi yo‘qolgan ("qotib qolgan") qo‘ng‘iroqlar kanalni band qilib
     * turmasligi uchun ularni yopadi.
     */
    public function releaseStuck(): void
    {
        $threshold = now()->subSeconds((int) config('asterisk.dialer.stuck_after_seconds', 600));

        CampaignRecipient::whereIn('status', ['ringing', 'answered'])
            ->whereNull('finished_at')
            ->where('started_at', '<', $threshold)
            ->get()
            ->each(function (CampaignRecipient $recipient) {
                if ($recipient->status === 'answered') {
                    $answeredAt = $recipient->answered_at ?? $recipient->started_at;

                    $recipient->update([
                        'finished_at' => now(),
                        'duration' => abs((int) $answeredAt->diffInSeconds(now())),
                    ]);

                    $this->notify($recipient);

                    return;
                }

                $this->registerFailure($recipient, 'failed', 'Asterisk javob bermadi (timeout).');
            });
    }

    /**
     * Bo‘sh kanallar soni:
     * trunk limiti (barcha kampaniyalar yig‘indisi) va kampaniya limitining kichigi.
     */
    private function freeChannels(Campaign $campaign): int
    {
        $active = ['ringing', 'answered'];

        $trunkActive = CampaignRecipient::whereIn('status', $active)
            ->whereNull('finished_at')
            ->whereIn(
                'campaign_id',
                Campaign::where('sip_trunk_id', $campaign->sip_trunk_id)->select('id')
            )
            ->count();

        $campaignActive = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', $active)
            ->whereNull('finished_at')
            ->count();

        $trunkLimit = (int) $campaign->sipTrunk->max_concurrent_calls;
        $campaignLimit = min((int) $campaign->max_concurrent_calls, 100);

        return max(0, min($trunkLimit - $trunkActive, $campaignLimit - $campaignActive));
    }

    /**
     * Qo'ng'iroq RAUNDLAR bilan ishlaydi:
     *   1-raund: hamma abonentning 1-raqami (1-urinish)
     *   2-raund: ko'tarmaganlarning 1-raqami (2-urinish) ... max_attempts gacha
     *   keyin:   hali ko'tarmaganlarning 2-raqami (xuddi shu tartibda)
     *
     * raund = (raqam tartibi - 1) * max_attempts + urinish
     * Keyingi raund oldingisi to'liq tugamaguncha (navbatda yoki jiringlayotgan
     * qator qolmaguncha) boshlanmaydi.
     */
    private const ROUND_SQL = '((phone_order - 1) * max_attempts + attempt)';

    /**
     * Joriy raunddagi keyingi abonentni "ringing" qilib band qiladi.
     */
    private function claimNext(int $campaignId): ?CampaignRecipient
    {
        return DB::transaction(function () use ($campaignId) {
            // Ochiq (hali tugamagan) qatorlar orasidagi eng kichik raund.
            // Javob bergan (answered) qatorlar qayta urinmaydi, shuning uchun raundni to'smaydi.
            $round = CampaignRecipient::where('campaign_id', $campaignId)
                ->whereIn('status', ['pending', 'ringing'])
                ->selectRaw('MIN(' . self::ROUND_SQL . ') AS r')
                ->value('r');

            if ($round === null) {
                return null;
            }

            $recipient = CampaignRecipient::where('campaign_id', $campaignId)
                ->where('status', 'pending')
                ->whereRaw(self::ROUND_SQL . ' = ?', [(int) $round])
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$recipient) {
                return null;
            }

            $recipient->update([
                'status' => 'ringing',
                'started_at' => now(),
                'answered_at' => null,
                'finished_at' => null,
                'next_attempt_at' => null,
                'error_message' => null,
            ]);

            return $recipient;
        });
    }

    /**
     * Abonentning bir raqami ko'tarilsa, uning navbatdagi keyingi raqamlari
     * o'tkazib yuboriladi (qayta qo'ng'iroq qilinmaydi).
     */
    private function skipLaterNumbers(CampaignRecipient $answered): void
    {
        if (!$answered->subscriber_id) {
            return;
        }

        CampaignRecipient::where('campaign_id', $answered->campaign_id)
            ->where('subscriber_id', $answered->subscriber_id)
            ->where('phone_order', '>', $answered->phone_order)
            ->where('status', 'pending')
            ->get()
            ->each(function (CampaignRecipient $other) use ($answered) {
                $other->update([
                    'status' => 'skipped',
                    'finished_at' => now(),
                    'next_attempt_at' => null,
                    'error_message' => ($answered->phone_order . '-raqam javob berdi'),
                ]);

                $this->notify($other);
            });
    }

    private function completeIfDone(Campaign $campaign): void
    {
        $open = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where(function ($q) {
                $q->whereIn('status', ['pending', 'ringing'])
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'answered')->whereNull('finished_at');
                    });
            })
            ->exists();

        if (!$open) {
            $campaign->update(['status' => 'completed', 'finished_at' => now()]);
        }
    }

    /**
     * Originate "Reason" kodi (ast_control_frame_type):
     * 5 = band, 3 = jiringlagan lekin ko‘tarilmadi, 0 = javob yo‘q, boshqasi = xato.
     * Real loglaringizga qarab moslashtiring.
     */
    private function mapReason(int $reason): string
    {
        return match ($reason) {
            5 => 'busy',
            0, 3 => 'no_answer',
            default => 'failed',
        };
    }

    private function notify(CampaignRecipient $recipient): void
    {
        try {
            event(new CampaignRecipientUpdated($recipient));
        } catch (Throwable $e) {
            Log::warning('Broadcast xatosi: ' . $e->getMessage());
        }
    }
}