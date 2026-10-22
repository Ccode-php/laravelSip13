<?php

namespace App\Http\Controllers;

use App\Models\SubscriberGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SubscriberGroupController extends Controller
{
    /**
     * Barcha kategoriyalarni tekis ro'yxatda qaytaradi.
     * Vue filterlari va kategoriya tanlash formasi uchun ishlatiladi.
     */
    public function index()
    {
        $groups = SubscriberGroup::query()
            ->withCount(['subscribers', 'children'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * Kategoriyalarni ichma-ich daraxt ko'rinishida qaytaradi.
     */
    public function tree()
    {
        $groups = SubscriberGroup::query()
            ->withCount(['subscribers', 'children'])
            ->orderBy('name')
            ->get();

        $tree = $this->buildTree($groups);

        return response()->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    /**
     * Tekis ro'yxatdan ko'p darajali daraxt yaratadi.
     */
    private function buildTree(
        Collection $groups,
        $parentId = null,
        array $ancestors = []
    ): array {
        $result = [];

        foreach ($groups as $group) {
            if ($group->parent_id != $parentId) {
                continue;
            }

            // Noto'g'ri ma'lumotlar sabab cheksiz rekursiyani oldini oladi.
            if (in_array((string) $group->id, $ancestors, true)) {
                continue;
            }

            $nextAncestors = [
                ...$ancestors,
                (string) $group->id,
            ];

            $item = $group->toArray();

            $item['children'] = $this->buildTree(
                $groups,
                $group->id,
                $nextAncestors
            );

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Yangi kategoriya yaratish.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => [
                'nullable',
                'integer',
                'exists:subscriber_groups,id',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'enabled' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $parentId = $validated['parent_id'] ?? null;

        // Bir ota kategoriya ichida nomlar takrorlanmasin.
        $duplicate = SubscriberGroup::query()
            ->where('name', $validated['name'])
            ->when(
                $parentId === null,
                fn($query) => $query->whereNull('parent_id'),
                fn($query) => $query->where('parent_id', $parentId)
            )
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Bu ota kategoriya ichida shunday nomli kategoriya mavjud.',
            ], 422);
        }

        $group = SubscriberGroup::create([
            'parent_id' => $parentId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
        ]);

        $group->load('parent');

        $group->loadCount(['subscribers', 'children']);

        return response()->json([
            'success' => true,
            'message' => 'Kategoriya muvaffaqiyatli yaratildi.',
            'data' => $group,
        ], 201);
    }

    /**
     * Bitta kategoriya, uning ichki kategoriyalari,
     * abonentlari va telefon raqamlarini qaytaradi.
     */
    public function show(SubscriberGroup $subscriberGroup)
    {
        $subscriberGroup->load([
            'parent',
            'children',
            'subscribers.phones',
        ]);

        $subscriberGroup->loadCount([
            'subscribers',
            'children',
        ]);

        return response()->json([
            'success' => true,
            'data' => $subscriberGroup,
        ]);
    }

    /**
     * Kategoriya ota kategoriyasini yoki boshqa maydonlarini yangilash.
     */
    public function update(
        Request $request,
        SubscriberGroup $subscriberGroup
    ) {
        $validated = $request->validate([
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:subscriber_groups,id',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'enabled' => [
                'sometimes',
                'boolean',
            ],
        ]);

        // parent_id yuborilmagan bo'lsa, amaldagi ota saqlanadi.
        $parentId = array_key_exists('parent_id', $validated)
            ? $validated['parent_id']
            : $subscriberGroup->parent_id;

        // Kategoriya o'zining ota kategoriyasi bo'la olmaydi.
        if (
            $parentId !== null &&
            (string) $parentId === (string) $subscriberGroup->id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kategoriya o‘zini ota kategoriya qilib tanlay olmaydi.',
            ], 422);
        }

        // O'zining istalgan avlodiga ko'chirishni taqiqlash.
        if ($parentId !== null) {
            $descendantIds = $this->getDescendantIds(
                $subscriberGroup->id
            );

            if (
                in_array(
                    (string) $parentId,
                    $descendantIds,
                    true
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategoriyani o‘zining ichki kategoriyasi ichiga ko‘chirib bo‘lmaydi.',
                ], 422);
            }
        }

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'success' => false,
                'message' => 'Kategoriya nomi bo‘sh bo‘lishi mumkin emas.',
            ], 422);
        }

        // Yangi ota kategoriya ichida bir xil nomni taqiqlash.
        $duplicate = SubscriberGroup::query()
            ->where('id', '!=', $subscriberGroup->id)
            ->where('name', $name)
            ->when(
                $parentId === null,
                fn($query) => $query->whereNull('parent_id'),
                fn($query) => $query->where('parent_id', $parentId)
            )
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Tanlangan ota kategoriya ichida bu nom allaqachon mavjud.',
            ], 422);
        }

        $changes = [
            'parent_id' => $parentId,
            'name' => $name,
        ];

        if (array_key_exists('description', $validated)) {
            $changes['description'] = $validated['description'];
        }

        if (array_key_exists('enabled', $validated)) {
            $changes['enabled'] = $validated['enabled'];
        }

        $subscriberGroup->update($changes);

        $subscriberGroup->refresh();

        $subscriberGroup->load('parent');

        $subscriberGroup->loadCount([
            'subscribers',
            'children',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategoriya muvaffaqiyatli yangilandi.',
            'data' => $subscriberGroup,
        ]);
    }

    /**
     * Berilgan kategoriyaning barcha avlodlari ID larini topadi.
     * Istalgan darajadagi ichki kategoriyalarni tekshiradi.
     */
    private function getDescendantIds($groupId): array
    {
        $ids = array_diff(
            SubscriberGroup::withDescendantIds([(int) $groupId]),
            [(int) $groupId]
        );

        return array_map('strval', $ids);
    }

    /**
     * Abonentlari yoki ichki kategoriyalari bor kategoriyani
     * o'chirishga ruxsat bermaydi.
     */
    public function destroy(SubscriberGroup $subscriberGroup)
    {
        if ($subscriberGroup->subscribers()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Abonentlari mavjud kategoriyani o‘chirib bo‘lmaydi. Avval abonentlarni boshqa kategoriyaga o‘tkazing.',
            ], 422);
        }

        if ($subscriberGroup->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Ichki kategoriyalari mavjud kategoriyani o‘chirib bo‘lmaydi. Avval ichki kategoriyalarni ko‘chiring yoki o‘chiring.',
            ], 422);
        }

        $subscriberGroup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategoriya muvaffaqiyatli o‘chirildi.',
        ]);
    }
}
