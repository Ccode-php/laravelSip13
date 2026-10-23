<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use App\Models\SubscriberGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    /**
     * Campaignlar ro‘yxati (har biri uchun jarayon hisobi bilan).
     */
    public function index()
    {
        $campaigns = Campaign::with([
            'sipTrunk:id,name',
            'audioFile:id,name,original_name',
            'groups:id,name,parent_id',
        ])
            ->withCount([
                'recipients',
                // Yakunlangan: javobsiz/band/xato yoki suhbati tugagan
                'recipients as finished_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->whereIn('status', ['busy', 'no_answer', 'failed'])
                            ->orWhere(function ($q2) {
                                $q2->where('status', 'answered')
                                    ->whereNotNull('finished_at');
                            });
                    });
                },
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $campaigns,
        ]);
    }

    /**
     * Berilgan kategoriyalar va ularning barcha ichki kategoriyalari ID lari.
     */
    private function expandGroupIds(array $selectedIds): array
    {
        $allGroups = SubscriberGroup::query()->get(['id', 'parent_id']);

        $knownIds = [];

        foreach ($selectedIds as $id) {
            $knownIds[(int) $id] = true;
        }

        do {
            $foundNew = false;

            foreach ($allGroups as $group) {
                $id = (int) $group->id;

                if (isset($knownIds[$id])) {
                    continue;
                }

                if ($group->parent_id !== null && isset($knownIds[(int) $group->parent_id])) {
                    $knownIds[$id] = true;
                    $foundNew = true;
                }
            }
        } while ($foundNew);

        return array_map('intval', array_keys($knownIds));
    }

    /**
     * Campaign yaratish. Bu metod faqat qabul qiluvchilarni tayyorlaydi,
     * qo‘ng‘iroqni boshlamaydi (start() ni qarang).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sip_trunk_id' => ['required', 'integer', 'exists:sip_trunks,id'],
            'audio_file_id' => ['required', 'integer', 'exists:audio_files,id'],
            'target_type' => ['required', Rule::in(['all', 'groups', 'individual'])],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'distinct', 'exists:subscriber_groups,id'],
            'subscriber_ids' => ['nullable', 'array'],
            'subscriber_ids.*' => ['integer', 'distinct', 'exists:subscribers,id'],
            'max_concurrent_calls' => ['required', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $selectedGroupIds = array_map('intval', $validated['group_ids'] ?? []);
        $selectedSubscriberIds = array_map('intval', $validated['subscriber_ids'] ?? []);

        if ($validated['target_type'] === 'groups' && empty($selectedGroupIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Kamida bitta kategoriya tanlang.',
            ], 422);
        }

        if ($validated['target_type'] === 'individual' && empty($selectedSubscriberIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Kamida bitta abonent tanlang.',
            ], 422);
        }

        $expandedGroupIds = $validated['target_type'] === 'groups'
            ? $this->expandGroupIds($selectedGroupIds)
            : [];

        $campaign = DB::transaction(function () use ($validated, $expandedGroupIds, $selectedSubscriberIds) {
            $campaign = Campaign::create([
                'name' => $validated['name'],
                'sip_trunk_id' => $validated['sip_trunk_id'],
                'audio_file_id' => $validated['audio_file_id'],
                'target_type' => $validated['target_type'],
                'max_concurrent_calls' => $validated['max_concurrent_calls'],
                'max_attempts' => $validated['max_attempts'],
                'status' => 'draft',
            ]);

            if ($validated['target_type'] === 'groups') {
                $campaign->groups()->sync($expandedGroupIds);
            }

            if ($validated['target_type'] === 'individual') {
                $campaign->subscribers()->sync($selectedSubscriberIds);
            }

            // Faqat faol abonent va faol telefonlar
            $subscriberQuery = Subscriber::query()
                ->where('enabled', true)
                ->with([
                    'phones' => function ($query) {
                        $query->where('enabled', true)
                            ->orderByDesc('is_primary')
                            ->orderBy('id');
                    },
                ]);

            if ($validated['target_type'] === 'groups') {
                $subscriberQuery->whereIn('subscriber_group_id', $expandedGroupIds);
            }

            if ($validated['target_type'] === 'individual') {
                $subscriberQuery->whereIn('id', $selectedSubscriberIds);
            }

            $subscribers = $subscriberQuery->orderBy('id')->get();

            $now = now();
            $rows = [];

            foreach ($subscribers as $subscriber) {
                foreach ($subscriber->phones as $phone) {
                    $rows[] = [
                        'campaign_id' => $campaign->id,
                        'subscriber_id' => $subscriber->id,
                        'subscriber_phone_id' => $phone->id,
                        'name' => $subscriber->name,
                        'phone' => $phone->phone,
                        'attempt' => 1,
                        'max_attempts' => $validated['max_attempts'],
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            abort_if(empty($rows), 422, 'Tanlangan abonentlar orasida faol telefon raqami yo‘q.');

            foreach (array_chunk($rows, 500) as $chunk) {
                CampaignRecipient::insert($chunk);
            }

            return $campaign;
        });

        $campaign->load(['sipTrunk', 'audioFile', 'groups']);
        $campaign->loadCount('recipients');

        return response()->json([
            'success' => true,
            'message' => 'Campaign yaratildi.',
            'data' => $campaign,
        ], 201);
    }

    /**
     * Campaign tafsilotlari. Abonentlar ro‘yxati bu yerda QAYTARILMAYDI
     * (minglab bo‘lishi mumkin): ular recipients() orqali sahifalab olinadi.
     */
    public function show(Campaign $campaign)
    {
        $campaign->load([
            'sipTrunk:id,name,enabled',
            'audioFile:id,name,original_name',
            'groups:id,name,parent_id',
        ]);

        $data = $campaign->toArray();

        $data['stats'] = $this->statsFor($campaign->id);
        $data['subscribers_count'] = $campaign->subscribers()->count();
        $data['subscriber_names'] = $campaign->subscribers()
            ->orderBy('subscribers.name')
            ->limit(20)
            ->pluck('subscribers.name');

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Yengil so‘rov: faqat holat va hisoblagichlar.
     * Vue sahifasi real-time hodisalardan keyin shuni chaqiradi.
     */
    public function stats(Campaign $campaign)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $campaign->id,
                'status' => $campaign->status,
                'started_at' => $campaign->started_at,
                'finished_at' => $campaign->finished_at,
                'stats' => $this->statsFor($campaign->id),
            ],
        ]);
    }

    /**
     * Campaign abonentlari (sahifalangan, holat bo‘yicha filtr bilan).
     */
    public function recipients(Request $request, Campaign $campaign)
    {
        $request->validate([
            'status' => [
                'nullable',
                Rule::in(['pending', 'ringing', 'answered', 'busy', 'no_answer', 'failed']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $campaign->recipients()
            ->select([
                'id', 'campaign_id', 'name', 'phone', 'attempt', 'max_attempts',
                'status', 'sip_reason', 'error_message', 'started_at',
                'answered_at', 'finished_at', 'next_attempt_at', 'duration',
                'played', 'created_at',
            ])
            ->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(
            $query->paginate((int) $request->input('per_page', 50))
        );
    }

    /**
     * Faqat qoralama campaignni yangilash.
     */
    public function update(Request $request, Campaign $campaign)
    {
        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Boshlangan campaignni o‘zgartirib bo‘lmaydi.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_concurrent_calls' => ['required', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $campaign->update($validated);

        // Qabul qiluvchilarda ham urinishlar soni yangilanadi
        $campaign->recipients()->update(['max_attempts' => $validated['max_attempts']]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign yangilandi.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Ishlayotgan campaignni o‘chirishga ruxsat yo‘q.
     */
    public function destroy(Campaign $campaign)
    {
        if ($campaign->status === 'running') {
            return response()->json([
                'success' => false,
                'message' => 'Ishlayotgan campaignni o‘chirib bo‘lmaydi.',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign o‘chirildi.',
        ]);
    }

    /**
     * Campaignni ishga tushiradi (yoki to‘xtatilganini davom ettiradi).
     * Qo‘ng‘iroqlarni asterisk:listen jarayoni chiqaradi.
     */
    public function start(Campaign $campaign)
    {
        DB::transaction(function () use ($campaign) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                in_array($locked->status, ['draft', 'stopped'], true),
                422,
                'Kampaniyani bu holatda boshlash mumkin emas.'
            );

            abort_unless($locked->sipTrunk?->enabled, 422, 'Faol SIP trunk tanlanmagan.');
            abort_unless($locked->audioFile, 422, 'Ovozli fayl tanlanmagan.');

            abort_unless(
                $locked->recipients()->where('status', 'pending')->exists(),
                422,
                'Navbatda abonentlar yo‘q.'
            );

            $locked->update([
                'status' => 'running',
                'started_at' => $locked->started_at ?? now(),
                'finished_at' => null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Kampaniya ishga tushirildi.',
            'status' => 'running',
        ]);
    }

    /**
     * Yangi qo‘ng‘iroq chiqarishni to‘xtatadi. Davom etayotgan suhbatlar tugaydi.
     */
    public function stop(Campaign $campaign)
    {
        abort_unless($campaign->status === 'running', 422, 'Kampaniya ishlamayapti.');

        $campaign->update(['status' => 'stopped']);

        return response()->json([
            'success' => true,
            'message' => 'Kampaniya to‘xtatildi.',
            'status' => 'stopped',
        ]);
    }

    /**
     * Holatlar bo‘yicha hisob (bitta so‘rovda).
     *
     * answered + finished_at yo‘q  -> in_call (suhbat hozir davom etmoqda)
     * answered + finished_at bor   -> answered (played: audio oxirigacha eshitilgan)
     */
    private function statsFor(int $campaignId): array
    {
        $rows = CampaignRecipient::where('campaign_id', $campaignId)
            ->selectRaw('status, (finished_at IS NOT NULL) AS done, COUNT(*) AS c, COALESCE(SUM(played), 0) AS p')
            ->groupBy('status', 'done')
            ->get();

        $stats = [
            'total' => 0,
            'pending' => 0,
            'ringing' => 0,
            'in_call' => 0,
            'answered' => 0,
            'played' => 0,
            'busy' => 0,
            'no_answer' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $count = (int) $row->c;

            $stats['total'] += $count;

            if ($row->status === 'answered') {
                if ((int) $row->done === 1) {
                    $stats['answered'] += $count;
                    $stats['played'] += (int) $row->p;
                } else {
                    $stats['in_call'] += $count;
                }

                continue;
            }

            if (array_key_exists($row->status, $stats) && $row->status !== 'total') {
                $stats[$row->status] += $count;
            }
        }

        $stats['finished'] = $stats['answered'] + $stats['busy'] + $stats['no_answer'] + $stats['failed'];
        $stats['percent'] = $stats['total'] > 0
            ? (int) round($stats['finished'] / $stats['total'] * 100)
            : 0;

        return $stats;
    }
}
