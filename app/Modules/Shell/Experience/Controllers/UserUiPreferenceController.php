<?php
namespace App\Modules\Shell\Experience\Controllers;
use App\Modules\Shell\Application\UserUiPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class UserUiPreferenceController
{
    public function __construct(private readonly UserUiPreferenceService $preferences) {}
    public function show(Request $request, string $namespace): JsonResponse
    {
        return response()->json(['namespace' => $namespace, 'value' => $this->preferences->get($request->user(), $namespace)]);
    }
    public function update(Request $request, string $namespace): JsonResponse
    {
        $validated = $request->validate([
            'value' => [
                'required',
                'array',
                function (string $attribute, mixed $value, $fail): void {
                    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($encoded === false || strlen($encoded) > 65535) {
                        $fail('Preference payload is too large.');
                    }
                },
            ],
        ]);
        return response()->json(['namespace' => $namespace, 'value' => $this->preferences->put($request->user(), $namespace, $validated['value'])]);
    }
}
