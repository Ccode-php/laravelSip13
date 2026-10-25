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
            $query->whereIn(
                'subscriber_group_id',
                SubscriberGroup::withDescendantIds([(int) $request->input('group_id')])
            );
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

    /** Telefonni yagona ko'rinishga keltiradi: bo'shliq, qavs, tire olib tashlanadi. */
    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', (string) $phone) ?? '';
    }

    private function splitPhones(string $text): array
    {
        $parts = preg_split('/[;:]/', $text) ?: [];

        return array_values(array_unique(array_filter(
            array_map(fn($p) => $this->normalizePhone($p), $parts)
        )));
    }

    private function normalizeRequestPhones(Request $request): void
    {
        $phones = $request->input('phones');

        if (!is_array($phones)) {
            return;
        }

        foreach ($phones as $i => $p) {
            if (is_array($p) && isset($p['phone'])) {
                $phones[$i]['phone'] = $this->normalizePhone((string) $p['phone']);
            }
        }

        $request->merge(['phones' => $phones]);
    }


    private function phoneRules(?Subscriber $subscriber = null): array
    {
        $unique = Rule::unique('subscriber_phones', 'phone');

        if ($subscriber) {
            // Abonentning o'z raqamlari "band" hisoblanmaydi
            $unique->ignore($subscriber->id, 'subscriber_id');
        }

        return ['required', 'string', 'max:30', 'distinct', $unique];
    }

    private function phoneMessages(): array
    {
        return [
            'phones.*.phone.unique' => 'Bu telefon raqami bazada allaqachon bor: :input',
            'phones.*.phone.distinct' => 'Bir xil telefon raqami ikki marta kiritilgan: :input',
        ];
    }

    private function syncPhones(Subscriber $subscriber, array $phones): void
    {
        $keep = [];

        foreach ($phones as $i => $p) {
            $model = $subscriber->phones()->updateOrCreate(
                ['phone' => $p['phone']],
                [
                    'label' => $p['label'] ?? ($i === 0 ? 'Asosiy' : 'Qo‘shimcha'),
                    'is_primary' => $p['is_primary'] ?? ($i === 0),
                    'enabled' => $p['enabled'] ?? true,
                ]
            );

            $keep[] = $model->id;
        }

        $subscriber->phones()->whereNotIn('id', $keep)->delete();
    }

    public function store(Request $request)
    {
        $this->normalizeRequestPhones($request);

        $validated = $request->validate([
            'subscriber_group_id' => ['nullable', 'exists:subscriber_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['student', 'employee'])],
            'enabled' => ['sometimes', 'boolean'],
            'phones' => ['required', 'array', 'min:1', 'max:2'],
            'phones.*.phone' => $this->phoneRules(),
            'phones.*.label' => ['nullable', 'string', 'max:50'],
            'phones.*.is_primary' => ['boolean'],
            'phones.*.enabled' => ['boolean'],
        ], $this->phoneMessages());

        $subscriber = DB::transaction(function () use ($validated) {
            $subscriber = Subscriber::create([
                'subscriber_group_id' => $validated['subscriber_group_id'] ?? null,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'enabled' => $validated['enabled'] ?? true,
            ]);

            $this->syncPhones($subscriber, $validated['phones']);

            return $subscriber;
        });

        $subscriber->load(['group.parent', 'phones']);

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

    public function update(Request $request, Subscriber $subscriber)
    {
        $this->normalizeRequestPhones($request);

        $validated = $request->validate([
            'subscriber_group_id' => ['nullable', 'exists:subscriber_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['student', 'employee'])],
            'enabled' => ['sometimes', 'boolean'],
            'phones' => ['required', 'array', 'min:1', 'max:2'],
            'phones.*.phone' => $this->phoneRules($subscriber),
            'phones.*.label' => ['nullable', 'string', 'max:50'],
            'phones.*.is_primary' => ['boolean'],
            'phones.*.enabled' => ['boolean'],
        ], $this->phoneMessages());

        DB::transaction(function () use ($validated, $subscriber) {
            $subscriber->update([
                'subscriber_group_id' => $validated['subscriber_group_id'] ?? null,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'enabled' => $validated['enabled'] ?? true,
            ]);

            $this->syncPhones($subscriber, $validated['phones']);
        });

        $subscriber->load(['group.parent', 'phones']);

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
            'subscribers' => ['required', 'array', 'min:1'],
            'subscribers.*.name' => ['required', 'string', 'max:255'],
            'subscribers.*.phone' => ['required', 'string', 'max:100'],
            'subscribers.*.category' => ['required', 'string', 'max:255'],
            'subscribers.*.type' => ['required', Rule::in(['student', 'employee'])],
        ]);

        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($validated['subscribers'] as $index => $row) {
            $rowNumber = $index + 2; // Excel'da 1-qator sarlavha

            try {
                DB::transaction(function () use ($row, &$added) {
                    $phones = $this->splitPhones($row['phone']);

                    if (count($phones) < 1) {
                        throw new \Exception('Telefon raqami mavjud emas.');
                    }

                    if (count($phones) > 2) {
                        throw new \Exception('Abonentda maksimal 2 ta telefon bo‘lishi mumkin (; bilan ajrating).');
                    }

                    foreach ($phones as $phone) {
                        if (!preg_match('/^\+?[0-9]{3,15}$/', $phone)) {
                            throw new \Exception("Telefon raqami noto‘g‘ri: {$phone}");
                        }
                    }

                    // Raqam bazada bor bo'lsa, qator o'tkazib yuboriladi
                    $existing = \App\Models\SubscriberPhone::query()
                        ->whereIn('phone', $phones)
                        ->with('subscriber:id,name')
                        ->first();

                    if ($existing) {
                        throw new \RuntimeException(
                            "Raqam bazada bor: {$existing->phone} ({$existing->subscriber?->name})"
                        );
                    }

                    $group = SubscriberGroup::firstOrCreate(
                        ['parent_id' => null, 'name' => trim($row['category'])],
                        ['description' => null, 'enabled' => true]
                    );

                    $subscriber = Subscriber::create([
                        'subscriber_group_id' => $group->id,
                        'name' => trim($row['name']),
                        'type' => trim($row['type']),
                        'enabled' => true,
                    ]);

                    $this->syncPhones($subscriber, array_map(
                        fn($p) => ['phone' => $p],
                        $phones
                    ));

                    $added++;
                });
            } catch (\RuntimeException $e) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Excel import yakunlandi.',
            'data' => [
                'added' => $added,
                'updated' => 0,
                'skipped' => $skipped,
                'errors' => $errors,
                'total' => count($validated['subscribers']),
            ],
        ]);
    }
}