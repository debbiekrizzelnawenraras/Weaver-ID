<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\ScanLog;
use App\Support\TagSecurity;
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
            // Read counter of a secured tag. The app sends it when the tag has one.
            'counter' => ['nullable', 'integer', 'min:0', 'max:'.TagSecurity::MAX_COUNTER],
            // Tag code the app read from the tag's memory (see TagSecurity::code).
            'code' => ['nullable', 'string', 'max:64'],
        ]);

        $counter = isset($validated['counter']) ? (int) $validated['counter'] : null;

        $tag = NfcTag::with('product.weaver.association')
            ->where('uid', $validated['uid'])
            ->first();

        $scanner = $validated['device_id'] ?? $request->ip();

        [$result, $reason] = $this->evaluate($tag, $scanner, $counter, $validated['code'] ?? null);

        $this->rememberCounter($tag, $counter, $reason);

        ScanLog::create([
            'uid' => $validated['uid'],
            'nfc_tag_id' => $tag?->id,
            'result' => $result,
            'device_id' => $validated['device_id'] ?? null,
            'ip_address' => $request->ip(),
            'counter' => $counter,
            'flag_reason' => $reason,
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
                'photo_url' => $product->photo_url,
            ];
            // Public shows the business only. Member names are private.
            $association = $weaver->association;
            $payload['association'] = [
                'name' => $association->name,
                'municipality' => $association->municipality ?? $weaver->municipality,
            ];
        }

        return response()->json($payload);
    }

    /**
     * @return array{0: ScanResult, 1: string|null} the result and, for suspicious scans, the reason (audit only).
     */
    private function evaluate(?NfcTag $tag, string $scanner, ?int $counter, ?string $code): array
    {
        if (! $tag || ! $tag->product) {
            return [ScanResult::Unregistered, null];
        }

        $product = $tag->product;

        if ($tag->status !== 'active') {
            return [ScanResult::Suspicious, 'tag_revoked'];
        }

        if ($product->status !== 'active') {
            return [ScanResult::Suspicious, 'product_inactive'];
        }

        if ($product->weaver->status !== 'active') {
            return [ScanResult::Suspicious, 'weaver_inactive'];
        }

        if ($reason = $this->codeProblem($tag, $code)) {
            return [ScanResult::Suspicious, $reason];
        }

        if ($reason = $this->counterProblem($tag, $counter)) {
            return [ScanResult::Suspicious, $reason];
        }

        if ($this->scannedByTooManyDevices($tag, $scanner)) {
            return [ScanResult::Suspicious, 'many_devices'];
        }

        return [ScanResult::Authenticated, null];
    }

    /**
     * Tag code rule. A tag that was secured with a tag code must send it, and it
     * must match the code the server derives for this UID. Tags secured before
     * the code existed are not checked until they are secured again.
     *
     * Anyone who can read the genuine tag can copy the code with the UID, so
     * this stops typed, guessed, and UID-only copies, not a full copy.
     */
    private function codeProblem(NfcTag $tag, ?string $code): ?string
    {
        if (! $tag->code_enabled) {
            return null;
        }

        if ($code === null || trim($code) === '') {
            return 'code_missing';
        }

        return TagSecurity::codeMatches($tag->uid, $code) ? null : 'code_invalid';
    }

    /**
     * Counter rule for secured tags. A secured tag counts its own reads, so its
     * counter only goes up. A copy has a counter of its own, so a number lower
     * than one already seen means two tags share this UID. A secured tag that
     * arrives without a counter (typed UID, old app, chip that cannot count)
     * is flagged as well. Tags that were never secured are not checked.
     *
     * The counter comes from the phone and is not signed, so this is a
     * detection rule, not proof: anyone can post a fake number. A staff member
     * can re-secure the tag at the desk to reset the saved counter.
     *
     * A number far above the saved one is flagged and not saved (counter_jump),
     * so a forged value cannot make every genuine scan look like a rollback.
     */
    private function counterProblem(NfcTag $tag, ?int $counter): ?string
    {
        if (! $tag->counter_enabled) {
            return null;
        }

        if ($counter === null) {
            return 'counter_missing';
        }

        if ($tag->last_counter !== null && $counter < $tag->last_counter) {
            return 'counter_rollback';
        }

        if (TagSecurity::counterJumpTooBig($tag->last_counter, $counter, (int) config('weaver.counter_window'))) {
            return 'counter_jump';
        }

        return null;
    }

    /**
     * Remember the highest counter seen, unless this scan was flagged for the counter or the tag code
     * (a forged request must not move the saved counter).
     */
    private function rememberCounter(?NfcTag $tag, ?int $counter, ?string $reason): void
    {
        if (! $tag || ! $tag->counter_enabled || $counter === null) {
            return;
        }

        if (in_array($reason, ['counter_rollback', 'counter_missing', 'counter_jump', 'code_missing', 'code_invalid'], true)) {
            return;
        }

        if ($tag->last_counter === null || $counter > $tag->last_counter) {
            $tag->update(['last_counter' => $counter]);
        }
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
