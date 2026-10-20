<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use App\Models\SubscriberGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriberController extends Controller
{
    public function index(Request $request)
    {
        $query = Subscriber::query()
            ->with([
                'group.parent',
                'phones',
            ])
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('phones', function ($phoneQuery) use ($search) {
                        $phoneQuery->where(
                            'phone',
                            'like',
                            "%{$search}%"
                        );
                    });
            });
        }

        if ($request->filled('group_id')) {
            $selectedGroupId = (int) $request->input('group_id');

            // Barcha kategoriyalarni bir marta olamiz.
            $allGroups = SubscriberGroup::query()
                ->get(['id', 'parent_id']);

            // Tanlangan kategoriya ham natijaga kiradi.
            $groupIds = [$selectedGroupId];
            $knownIds = [$selectedGroupId => true];

            // Har bir darajadagi ichki kategoriyalarni topamiz.
            do {
                $newIds = [];

                foreach ($allGroups as $group) {
                    $parentId = $group->parent_id;

                    if (
                        $parentId !== null &&
                        isset($knownIds[(int) $parentId]) &&
                        !isset($knownIds[(int) $group->id])
                    ) {
                        $id = (int) $group->id;

                        $knownIds[$id] = true;
                        $newIds[] = $id;
                    }
                }

                $groupIds = array_merge($groupIds, $newIds);
            } while (!empty($newIds));

            // Tanlangan kategoriya va barcha ichki kategoriyalarning abonentlari.
            $query->whereIn('subscriber_group_id', $groupIds);
        }

        if ($request->has('enabled')) {
            $query->where(
                'enabled',
                filter_var(
                    $request->input('enabled'),
                    FILTER_VALIDATE_BOOLEAN
                )
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subscriber_group_id' => [
                'nullable',
                'exists:subscriber_groups,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'required',
                Rule::in([
                    'student',
                    'employee',
                ]),
            ],

            'enabled' => [
                'sometimes',
                'boolean',
            ],

            'phones' => [
                'required',
                'array',
                'min:1',
                'max:2',
            ],

            'phones.*.phone' => [
                'required',
                'string',
                'max:30',
            ],

            'phones.*.label' => [
                'nullable',
                'string',
                'max:50',
            ],

            'phones.*.is_primary' => [
                'boolean',
            ],

            'phones.*.enabled' => [
                'boolean',
            ],
        ]);

        $subscriber = DB::transaction(function () use ($validated) {

            $subscriber = Subscriber::create([
                'subscriber_group_id' =>
                $validated['subscriber_group_id'] ?? null,

                'name' =>
                $validated['name'],

                'type' =>
                $validated['type'],

                'enabled' =>
                $validated['enabled'] ?? true,
            ]);

            $phones = $validated['phones'];

            foreach ($phones as $index => $phone) {
                $subscriber->phones()->create([
                    'phone' => trim($phone['phone']),
                    'label' => $phone['label'] ?? null,
                    'is_primary' =>
                    $phone['is_primary']
                        ?? ($index === 0),
                    'enabled' =>
                    $phone['enabled'] ?? true,
                ]);
            }

            return $subscriber;
        });

        $subscriber->load([
            'group.parent',
            'phones',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Abonent yaratildi.',
            'data' => $subscriber,
        ], 201);
    }

    public function show(Subscriber $subscriber)
    {
        $subscriber->load([
            'group.parent',
            'phones',
        ]);

        return response()->json([
            'success' => true,
            'data' => $subscriber,
        ]);
    }

    public function update(
        Request $request,
        Subscriber $subscriber
    ) {
        $validated = $request->validate([
            'subscriber_group_id' => [
                'nullable',
                'exists:subscriber_groups,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'required',
                Rule::in([
                    'student',
                    'employee',
                ]),
            ],

            'enabled' => [
                'sometimes',
                'boolean',
            ],

            'phones' => [
                'required',
                'array',
                'min:1',
                'max:2',
            ],

            'phones.*.phone' => [
                'required',
                'string',
                'max:30',
            ],

            'phones.*.label' => [
                'nullable',
                'string',
                'max:50',
            ],

            'phones.*.is_primary' => [
                'boolean',
            ],

            'phones.*.enabled' => [
                'boolean',
            ],
        ]);

        DB::transaction(function () use (
            $validated,
            $subscriber
        ) {
            $subscriber->update([
                'subscriber_group_id' =>
                $validated['subscriber_group_id'] ?? null,

                'name' =>
                $validated['name'],

                'type' =>
                $validated['type'],

                'enabled' =>
                $validated['enabled'] ?? true,
            ]);

            $subscriber->phones()->delete();

            foreach (
                $validated['phones'] as $index => $phone
            ) {
                $subscriber->phones()->create([
                    'phone' => trim($phone['phone']),
                    'label' => $phone['label'] ?? null,
                    'is_primary' =>
                    $phone['is_primary']
                        ?? ($index === 0),
                    'enabled' =>
                    $phone['enabled'] ?? true,
                ]);
            }
        });

        $subscriber->load([
            'group.parent',
            'phones',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Abonent yangilandi.',
            'data' => $subscriber,
        ]);
    }

    public function destroy(Subscriber $subscriber)
    {
        $subscriber->delete();

        return response()->json([
            'success' => true,
            'message' => 'Abonent o‘chirildi.',
        ]);
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'subscribers' => [
                'required',
                'array',
                'min:1',
            ],

            'subscribers.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'subscribers.*.phone' => [
                'required',
                'string',
                'max:100',
            ],

            'subscribers.*.category' => [
                'required',
                'string',
                'max:255',
            ],

            'subscribers.*.type' => [
                'required',
                Rule::in([
                    'student',
                    'employee',
                ]),
            ],
        ]);

        $added = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use (
            $validated,
            &$added,
            &$updated,
            &$errors
        ) {
            foreach (
                $validated['subscribers']
                as $index => $row
            ) {
                $rowNumber = $index + 2;

                try {
                    $name = trim($row['name']);
                    $phoneText = trim($row['phone']);
                    $category = trim($row['category']);
                    $type = trim($row['type']);

                    /*
                     * 998912345678:998932165498
                     */
                    $phones = array_values(
                        array_filter(
                            array_map(
                                'trim',
                                explode(':', $phoneText)
                            )
                        )
                    );

                    if (count($phones) < 1) {
                        throw new \Exception(
                            'Telefon raqami mavjud emas.'
                        );
                    }

                    if (count($phones) > 2) {
                        throw new \Exception(
                            'Abonentda maksimal 2 ta telefon bo‘lishi mumkin.'
                        );
                    }

                    $group = SubscriberGroup::firstOrCreate(
                        [
                            'parent_id' => null,
                            'name' => $category,
                        ],
                        [
                            'description' => null,
                            'enabled' => true,
                        ]
                    );

                    /*
                     * Telefonlardan biri mavjud bo‘lsa,
                     * shu abonentni topamiz.
                     */
                    $subscriber = null;

                    foreach ($phones as $phone) {
                        $subscriber = Subscriber::whereHas(
                            'phones',
                            function ($query) use ($phone) {
                                $query->where('phone', $phone);
                            }
                        )->first();

                        if ($subscriber) {
                            break;
                        }
                    }

                    if ($subscriber) {
                        $subscriber->update([
                            'subscriber_group_id' => $group->id,
                            'name' => $name,
                            'type' => $type,
                            'enabled' => true,
                        ]);

                        $subscriber->phones()->delete();

                        foreach ($phones as $phoneIndex => $phone) {
                            $subscriber->phones()->create([
                                'phone' => $phone,
                                'label' =>
                                $phoneIndex === 0
                                    ? 'Asosiy'
                                    : 'Qo‘shimcha',
                                'is_primary' =>
                                $phoneIndex === 0,
                                'enabled' => true,
                            ]);
                        }

                        $updated++;
                    } else {
                        $subscriber = Subscriber::create([
                            'subscriber_group_id' => $group->id,
                            'name' => $name,
                            'type' => $type,
                            'enabled' => true,
                        ]);

                        foreach ($phones as $phoneIndex => $phone) {
                            $subscriber->phones()->create([
                                'phone' => $phone,
                                'label' =>
                                $phoneIndex === 0
                                    ? 'Asosiy'
                                    : 'Qo‘shimcha',
                                'is_primary' =>
                                $phoneIndex === 0,
                                'enabled' => true,
                            ]);
                        }

                        $added++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Excel import yakunlandi.',
            'data' => [
                'added' => $added,
                'updated' => $updated,
                'errors' => $errors,
                'total' => count(
                    $validated['subscribers']
                ),
            ],
        ]);
    }
}
