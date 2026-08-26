<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = (string) $request->validated('locale');

        $request->user()->update(['locale' => $locale]);
        app()->setLocale($locale);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('localization.language_updated'),
        ]);

        return back();
    }
}
