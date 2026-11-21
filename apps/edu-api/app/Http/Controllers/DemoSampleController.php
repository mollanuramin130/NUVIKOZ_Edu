<?php

namespace App\Http\Controllers;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DemoSampleController extends Controller
{
    private function assets(Request $request): array
    {
        $school = $request->attributes->get('membership')->school;
        abort_unless($school->data_stage === 'demo' && $school->access_state === 'provisional' && $school->demo_ends_at?->isFuture(), 403);
        $path = base_path('../../assets/demo-samples/manifest.json');
        abort_unless(is_readable($path), 503);

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['assets'];
    }

    public function index(Request $request)
    {
        return Api::ok(['items' => array_map(fn ($asset) => Arr::only($asset, ['id', 'label', 'revision', 'watermark']), $this->assets($request))]);
    }

    public function preview(Request $request, string $id)
    {
        $asset = collect($this->assets($request))->firstWhere('id', $id);
        abort_unless($asset, 404);
        $file = base_path('../../assets/demo-samples/'.basename($asset['filename']));
        abort_unless(is_readable($file) && hash_file('sha256', $file) === $asset['sha256'], 503);

        return response()->file($file, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="sample-demo.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
