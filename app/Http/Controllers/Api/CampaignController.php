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
     * Campaignlar ro‘yxati.
     */
    public function index()
    {
        $campaigns = Campaign::with([
            'sipTrunk:id,name',
            'audioFile:id,name,original_name',
            'groups:id,name,parent_id',
        ])
            ->withCount('recipients')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $campaigns,
        ]);
    }

    /**
     * Berilgan kategoriyalar va ularning
     * barcha ichki kategoriyalari ID larini topadi.
     */
    private function expandGroupIds(array $selectedIds): array
    {
        return SubscriberGroup::withDescendantIds($selectedIds);
    }

    /**
     * Campaign yaratish.
     *
     * Eslatma: bu metod recipientlarni tayyorlaydi.
     * O‘zi telefon qo‘ng‘irog‘ini boshlamaydi.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'sip_trunk_id' => [
                'required',
                'integer',
                'exists:sip_trunks,id',
            ],

            'audio_file_id' => [
                'required',
                'integer',
                'exists:audio_files,id',
            ],

            'target_type' => [
                'required',
                Rule::in([
                    'all',
                    'groups',
                    'individual',
                ]),
            ],

            'group_ids' => [
                'nullable',
                'array',
            ],

            'group_ids.*' => [
                'integer',
                'distinct',
                'exists:subscriber_groups,id',
            ],

            'subscriber_ids' => [
                'nullable',
                'array',
            ],

            'subscriber_ids.*' => [
                'integer',
                'distinct',
                'exists:subscribers,id',
            ],

            'max_concurrent_calls' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],

            'max_attempts' => [
                'required',
                'integer',
                'min:1',
                'max:10',
            ],
        ]);

        $selectedGroupIds = array_map(
            'intval',
            $validated['group_ids'] ?? []
        );

        $selectedSubscriberIds = array_map(
            'intval',
            $validated['subscriber_ids'] ?? []
        );

        if (
            $validated['target_type'] === 'groups' &&
            empty($selectedGroupIds)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kamida bitta kategoriya tanlang.',
            ], 422);
        }

        if (
            $validated['target_type'] === 'individual' &&
            empty($selectedSubscriberIds)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kamida bitta abonent tanlang.',
            ], 422);
        }

        /*
         * Ota kategoriyalar tanlangan bo‘lsa,
         * ichki kategoriyalarni ham qo‘shamiz.
         */
        $expandedGroupIds = [];

        if ($validated['target_type'] === 'groups') {
            $expandedGroupIds = $this->expandGroupIds(
                $selectedGroupIds
            );
        }

        $campaign = DB::transaction(
            function () use (
                $validated,
                $expandedGroupIds,
                $selectedSubscriberIds
            ) {
                $campaign = Campaign::create([
                    'name' => $validated['name'],

                    'sip_trunk_id' =>
                    $validated['sip_trunk_id'],

                    'audio_file_id' =>
                    $validated['audio_file_id'],

                    'target_type' =>
                    $validated['target_type'],

                    'max_concurrent_calls' =>
                    $validated['max_concurrent_calls'],

                    'max_attempts' =>
                    $validated['max_attempts'],

                    'status' => 'draft',
                ]);

                /*
                 * Tanlangan va ichki kategoriyalarni
                 * campaignga biriktiramiz.
                 */
                if (
                    $validated['target_type'] === 'groups'
                ) {
                    $campaign->groups()->sync(
                        $expandedGroupIds
                    );
                }

                /*
                 * Alohida tanlangan abonentlarni biriktirish.
                 */
                if (
                    $validated['target_type'] === 'individual'
                ) {
                    $campaign->subscribers()->sync(
                        $selectedSubscriberIds
                    );
                }

                /*
                 * Recipientlar ro‘yxatini tayyorlash.
                 * Faqat faol abonent va faol telefon olinadi.
                 */
                $subscriberQuery = Subscriber::query()
                    ->where('enabled', true)
                    ->with([
                        'phones' => function ($query) {
                            $query
                                ->where('enabled', true)
                                ->orderByDesc('is_primary')
                                ->orderBy('id');
                        },
                    ]);

                if (
                    $validated['target_type'] === 'groups'
                ) {
                    $subscriberQuery->whereIn(
                        'subscriber_group_id',
                        $expandedGroupIds
                    );
                }

                if (
                    $validated['target_type'] === 'individual'
                ) {
                    $subscriberQuery->whereIn(
                        'id',
                        $selectedSubscriberIds
                    );
                }

                /*
                 * target_type = all bo‘lsa,
                 * kategoriya yoki abonent filtri yo‘q.
                 */
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
                            'max_attempts' => $validated['max_attempts'],   // 2 qat’iy emas
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
            }
        );

        $campaign->load([
            'sipTrunk',
            'audioFile',
            'groups',
        ]);

        $campaign->loadCount('recipients');

        return response()->json([
            'success' => true,
            'message' => 'Campaign yaratildi.',
            'data' => $campaign,
        ], 201);
    }

    /**
     * Campaign tafsilotlari va recipientlar.
     */
    public function show(Campaign $campaign)
    {
        $campaign->load([
            'sipTrunk',
            'audioFile',
            'groups',
            'subscribers.phones',
            'recipients.subscriber',
            'recipients.subscriberPhone',
        ]);

        $campaign->loadCount('recipients');

        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }

    /**
     * Faqat qoralama campaignni yangilash.
     */
    public function update(
        Request $request,
        Campaign $campaign
    ) {
        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' =>
                'Boshlangan campaignni o‘zgartirib bo‘lmaydi.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'max_concurrent_calls' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],

            'max_attempts' => [
                'required',
                'integer',
                'min:1',
                'max:10',
            ],
        ]);

        $campaign->update($validated);

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
                'message' =>
                'Ishlayotgan campaignni o‘chirib bo‘lmaydi.',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign o‘chirildi.',
        ]);
    }

    public function stop(Campaign $campaign)
    {
        abort_unless($campaign->status === 'running', 422, 'Kampaniya ishlamayapti.');

        // Davom etayotgan suhbatlar o‘zi tugaydi, yangi qo‘ng‘iroq chiqmaydi.
        $campaign->update(['status' => 'stopped']);

        return response()->json(['success' => true, 'status' => 'stopped']);
    }
}
