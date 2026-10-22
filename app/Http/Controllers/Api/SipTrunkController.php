<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SipTrunk;
use App\Http\Resources\SipTrunkResource;
use App\Services\AsteriskConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SipTrunkController extends Controller
{
    public function __construct(
        private AsteriskConfigService $configService
    ) {}

    public function index()
    {
        $sipTrunks = SipTrunk::latest()->get();
        return SipTrunkResource::collection($sipTrunks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'auth_type' => ['sometimes', 'in:username_password,ip'],
            'username' => ['required_if:auth_type,username_password', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:auth_type,username_password', 'nullable', 'string', 'max:1000'],
            'realm' => ['nullable', 'string', 'max:255'],
            'from_user' => ['nullable', 'string', 'max:255'],
            'from_domain' => ['nullable', 'string', 'max:255'],
            'outbound_proxy' => ['nullable', 'string', 'max:255'],
            'codec' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_,\s]+$/'],
            'dtmf_mode' => ['nullable', 'in:rfc4733,inband,info,auto,auto_info'],
            'caller_id' => ['nullable', 'string', 'max:255'],
            'max_concurrent_calls' => ['sometimes', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $sipTrunk = SipTrunk::create($validated);

        // Asterisk faylini bazadagi yangi ma'lumotlar bilan sinxronizatsiya qilish
        

        $syncError = $this->syncAsteriskConfig();

        return (new SipTrunkResource($sipTrunk))->additional(['sync_error' => $syncError]);
    }

    public function show(SipTrunk $sipTrunk)
    {
        return new SipTrunkResource($sipTrunk);
    }

    public function update(Request $request, SipTrunk $sipTrunk)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'auth_type' => ['sometimes', 'in:username_password,ip'],
            'username' => ['required_if:auth_type,username_password', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:auth_type,username_password', 'nullable', 'string', 'max:1000'],
            'realm' => ['nullable', 'string', 'max:255'],
            'from_user' => ['nullable', 'string', 'max:255'],
            'from_domain' => ['nullable', 'string', 'max:255'],
            'outbound_proxy' => ['nullable', 'string', 'max:255'],
            'codec' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_,\s]+$/'],
            'dtmf_mode' => ['nullable', 'in:rfc4733,inband,info,auto,auto_info'],
            'caller_id' => ['nullable', 'string', 'max:255'],
            'max_concurrent_calls' => ['sometimes', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        // Agar tahrirlashda parol bo'sh kelsa, eskisini saqlab qolamiz
        if (array_key_exists('password', $validated) && empty($validated['password'])) {
            unset($validated['password']);
        }

        $sipTrunk->update($validated);

        // O'zgarishlarni Asterisk fayliga yozish
        $syncError = $this->syncAsteriskConfig();

        return (new SipTrunkResource($sipTrunk))->additional(['sync_error' => $syncError]);
    }

    public function destroy(SipTrunk $sipTrunk)
    {
        if (\App\Models\Campaign::where('sip_trunk_id', $sipTrunk->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu trunk kampaniyalarda ishlatilgan, o‘chirib bo‘lmaydi. Uni o‘chirib qo‘yish (enabled=false) mumkin.',
            ], 422);
        }
        
        $sipTrunk->delete();

        // O'chirilgan trunk Asterisk-dan ham yo'qolishi uchun faylni qayta yozamiz
        $this->syncAsteriskConfig();

        return response()->json([
            'success' => true,
            'message' => 'SIP Trunk o‘chirildi va Asterisk konfiguratsiyasi yangilandi.',
        ]);
    }

    /**
     * Ma'lumotlar bazasidagi barcha faol trunklarni yig'ib,
     * pjsip_trunk.conf faylini qayta yozuvchi xavfsiz metod.
     */
    private function syncAsteriskConfig(): ?string
    {
        try {
            $this->configService->syncAll();

            return null;
        } catch (\Throwable $e) {
            Log::error('Asterisk sinxronizatsiya xatosi: ' . $e->getMessage());

            return $e->getMessage();
        }
    }
}
