<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WidgetAssetController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        abort_unless(is_file(public_path('widget.js')), 404);

        return response()->file(public_path('widget.js'), [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => app()->environment('local')
                ? 'no-cache, private'
                : 'public, max-age=300',
        ]);
    }
}
