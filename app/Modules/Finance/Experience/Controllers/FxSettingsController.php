<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FxSettingsController extends Controller
{
    private const EDITABLE_CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'PHP'];

    public function index(FxReferenceSettingsService $settings): Response
    {
        return Inertia::render('Settings/Fx', $settings->snapshot());
    }

    public function update(Request $request, FxReferenceSettingsService $settings): RedirectResponse
    {
        $rules = [
            'rates' => ['required', 'array:USD,EUR,GBP,CAD,AUD,PHP'],
            'automatic' => ['required', 'array:USD,EUR,GBP,CAD,AUD,PHP'],
        ];
        foreach (self::EDITABLE_CURRENCIES as $currency) {
            $rules['rates.'.$currency] = ['nullable', 'numeric', 'gt:0', 'max:100000'];
            $rules['automatic.'.$currency] = ['required', 'boolean'];
        }
        $validated = $request->validate($rules);

        foreach (self::EDITABLE_CURRENCIES as $currency) {
            $rate = $validated['rates'][$currency] ?? null;
            if (! (bool) $validated['automatic'][$currency] && ($rate === null || $rate === '')) {
                throw ValidationException::withMessages([
                    'rates.'.$currency => '切换到手动汇率前，请先填写手动汇率。',
                ]);
            }
        }

        $settings->save($validated['rates'], $validated['automatic'], $request->user()?->getAuthIdentifier());

        return back()->with('success', '汇率设置已保存。');
    }
}
