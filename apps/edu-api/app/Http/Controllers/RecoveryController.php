<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivationRequest;
use App\Services\AccountRecovery;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecoveryController extends Controller
{
    public function request(Request $r, AccountRecovery $service)
    {
        abort_unless(array_diff(array_keys($r->all()), ['school_code', 'identifier']) === [], 422);

        return Api::ok($service->request($r->all()));
    }

    public function issue(Request $r, AccountRecovery $service, string $membership)
    {
        return Api::ok(['local_handover' => $service->issue($r->attributes->get('membership'), $membership, $r->all(), $r->attributes->get('request_id'))]);
    }

    public function consume(ActivationRequest $r, AccountRecovery $service)
    {
        $result = $service->consume($r->validated(), $r->attributes->get('request_id'), Auth::guard('web')->id());
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return Api::ok($result);
    }
}
