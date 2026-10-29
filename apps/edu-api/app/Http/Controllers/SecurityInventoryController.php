<?php

namespace App\Http\Controllers;

use App\Services\SecurityInventory;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecurityInventoryController extends Controller
{
    public function sessions(Request $r, SecurityInventory $service)
    {
        return Api::ok(['sessions' => $service->sessions($r->attributes->get('membership'), $r)]);
    }

    public function devices(Request $r, SecurityInventory $service)
    {
        return Api::ok(['devices' => $service->devices($r->attributes->get('membership'), $r)]);
    }

    public function revokeSession(Request $r, SecurityInventory $service, string $id)
    {
        return $this->revoke($r, $service, 'session', $id);
    }

    public function revokeDevice(Request $r, SecurityInventory $service, string $id)
    {
        return $this->revoke($r, $service, 'device', $id);
    }

    private function revoke(Request $r, SecurityInventory $service, string $kind, string $id)
    {
        abort_unless(array_diff(array_keys($r->all()), ['password']) === [], 422);
        $data = $r->validate(['password' => ['required', 'string', 'max:72']]);
        $result = $service->revoke($r->attributes->get('membership'), $r, $data['password'], $kind, $id);
        if ($kind === 'session' && DB::table('session_revocations')->where('session_hash', hash('sha256', $r->session()->getId()))->exists()) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

        return Api::ok($result);
    }
}
