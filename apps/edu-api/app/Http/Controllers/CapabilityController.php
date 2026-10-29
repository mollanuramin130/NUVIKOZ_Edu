<?php

namespace App\Http\Controllers;

use App\Services\Capabilities;
use App\Support\Api;
use Illuminate\Http\Request;

class CapabilityController extends Controller
{
    public function index(Request $r, Capabilities $service)
    {
        return Api::ok($service->view($r->attributes->get('membership')));
    }

    public function assign(Request $r, Capabilities $service, string $membership)
    {
        return Api::ok($service->assign($r->attributes->get('membership'), $membership, $r->all(), $r->attributes->get('request_id')));
    }

    public function probe(Request $r, Capabilities $service, string $capability)
    {
        abort_unless($service->allows($r->attributes->get('membership'), $capability), 403);

        return Api::ok(['authorized' => true, 'synthetic' => true, 'domain_implemented' => false]);
    }
}
