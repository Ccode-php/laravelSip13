<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallResultController extends Controller
{
    /**
     * Barcha kampaniyalar bo‘yicha qo‘ng‘iroq natijalari (sahifalangan).
     *
     * Filtrlar: campaign_id, status, search (ism yoki telefon)
     */
    public function index(Request $request)
    {
        $request->validate([
            'campaign_id' => ['nullable', 'integer'],
            'status' => [
                'nullable',
                Rule::in(['pending', 'ringing', 'answered', 'busy', 'no_answer', 'failed', 'skipped']),
            ],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = CampaignRecipient::query()
            ->with('campaign:id,name')
            ->select([
                'id', 'campaign_id', 'subscriber_id', 'name','group_name', 'phone', 'attempt', 'max_attempts',
                'status', 'sip_reason', 'error_message', 'started_at',
                'answered_at', 'finished_at', 'duration', 'played', 'created_at',
            ])
            ->orderByDesc('id');

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', (int) $request->input('campaign_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('group_name', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate((int) $request->input('per_page', 50))
        );
    }
}
