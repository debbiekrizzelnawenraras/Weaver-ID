<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\ScanLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyController extends Controller
{
    /**
     * Public endpoint used by the mobile app. No login is required.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $uid = $request->input('uid');

        if (is_string($uid)) {
            $request->merge(['uid' => NfcTag::normalizeUid($uid)]);
        }

        $validated = $request->validate([
            'uid' => ['required', 'string', 'regex:/^[0-9A-F]{8,20}$/'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        $tag = NfcTag::with('product.weaver.association')
            ->where('uid', $validated['uid'])
            ->first();

        $scanner = $validated['device_id'] ?? $request->ip();

        $result = $this->evaluate($tag, $scanner);

        ScanLog::create([
            'uid' => $validated['uid'],
            'nfc_tag_id' => $tag?->id,
            'result' => $result,
            'device_id' => $validated['device_id'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        $payload = [
            'result' => $result->value,
            'message' => $result->message(),
        ];

        // Product details are only shown for authenticated tags.
        if ($result === ScanResult::Authenticated) {
            $product = $tag->product;
            $weaver = $product->weaver;

            $payload['product'] = [
                'title' => $product->title,
                'category' => $product->category,
            ];
            $payload['weaver'] = [
                'name' => $weaver->name,
                'municipality' => $weaver->municipality,
            ];
            $payload['association'] = [
                'name' => $weaver->association->name,
            ];
        }

        return response()->json($payload);
    }

    private function evaluate(?NfcTag $tag, string $scanner): ScanResult
    {
        if (! $tag || ! $tag->product) {
            return ScanResult::Unregistered;
        }

        $product = $tag->product;

        if (
            $tag->status !== 'active' ||
            $product->status !== 'active' ||
            $product->weaver->status !== 'active'
        ) {
            return ScanResult::Suspicious;
        }

        if ($this->scannedByTooManyDevices($tag, $scanner)) {
            return ScanResult::Suspicious;
        }

        return ScanResult::Authenticated;
    }

    /**
     * Counts distinct scanners in the recent window, including the current scan.
     */
    private function scannedByTooManyDevices(NfcTag $tag, string $scanner): bool
    {
        $since = now()->subMinutes(config('weaver.suspicious_window_minutes'));

        $recentScanners = ScanLog::where('nfc_tag_id', $tag->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(device_id, ip_address) AS scanner')
            ->distinct()
            ->get()
            ->pluck('scanner');

        $distinct = $recentScanners->push($scanner)->unique()->count();

        return $distinct >= config('weaver.suspicious_distinct_scanners');
    }
}
