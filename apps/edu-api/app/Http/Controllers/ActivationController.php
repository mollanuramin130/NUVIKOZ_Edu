<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivationRequest;
use App\Http\Requests\ProvisionRequest;
use App\Services\AccountActivation;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivationController extends Controller
{
    public function provision(ProvisionRequest $r, AccountActivation $service)
    {
        return Api::ok($service->provision($r->attributes->get('membership'), $r->validated(), $r->attributes->get('request_id'), $r->header('Idempotency-Key')), 201);
    }

    public function reissue(Request $r, AccountActivation $service, string $membership)
    {
        abort_unless($r->all() === [], 422);

        return Api::ok(['activation_status' => 'pending', 'local_handover' => $service->issue($r->attributes->get('membership'), $membership, $r->attributes->get('request_id'))]);
    }

    public function consume(ActivationRequest $r, AccountActivation $service)
    {
        $result = $service->consume($r->validated(), $r->attributes->get('request_id'), Auth::guard('web')->id());
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return Api::ok($result);
    }
}
