<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\SuspiciousReport;
use App\Support\ReportRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuspiciousReportController extends Controller
{
    /**
     * Public endpoint used by the mobile app, no login. The app offers it only
     * after a scan the server flagged; the server checks that again here.
     */
    public function store(Request $request): JsonResponse
    {
        $uid = $request->input('uid');

        if (is_string($uid)) {
            $request->merge(['uid' => NfcTag::normalizeUid($uid)]);
        }

        $validated = $request->validate([
            'uid' => ['required', 'string', 'regex:/^[0-9A-F]{8,20}$/'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'location' => ['required', 'string', 'min:3', 'max:150'],
            // Tomorrow is allowed because the phone's date can be a day ahead of the server's (Philippines is UTC+8).
            'seen_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
            'note' => ['nullable', 'string', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:120'],
        ]);

        $key = ReportRules::reporterKey($validated['device_id'] ?? null, $request->ip());

        $scan = ReportRules::eligibleScan($validated['uid'], $key);

        if (! $scan) {
            return response()->json([
                'message' => 'There is no recent flagged scan of this tag from this phone. Tap the tag again before reporting.',
            ], 422);
        }

        // One report per phone and tag per day. A second phone reporting the same tag is kept: it is useful to DTI.
        $existing = SuspiciousReport::where('uid', $validated['uid'])
            ->where('reporter_key', $key)
            ->where('created_at', '>=', now()->subHours((int) config('weaver.report_duplicate_hours')))
            ->first();

        if ($existing) {
            return response()->json([
                'reference' => $existing->reference,
                'duplicate' => true,
                'message' => 'You already reported this tag today. DTI-Mountain Province has your report.',
            ]);
        }

        $report = SuspiciousReport::create([
            'reference' => $this->newReference(),
            'uid' => $validated['uid'],
            'nfc_tag_id' => $scan->nfc_tag_id,
            'scan_log_id' => $scan->id,
            'flag_reason' => $scan->flag_reason,
            'reporter_key' => $key,
            'app_version' => $validated['app_version'] ?? null,
            'location' => trim($validated['location']),
            'seen_on' => $validated['seen_on'] ?? null,
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            'contact' => filled($validated['contact'] ?? null) ? trim($validated['contact']) : null,
        ]);

        return response()->json([
            'reference' => $report->reference,
            'duplicate' => false,
            'message' => 'Thank you. Your report was sent to DTI-Mountain Province for review.',
        ], 201);
    }

    /**
     * Reports for staff (their municipality) and admin (all), newest first, 25 per page.
     * Optional ?status=new|under_review|verified|dismissed|referred.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(SuspiciousReport::STATUSES)],
        ]);

        $reports = SuspiciousReport::manageableBy($request->user())
            ->with(['nfcTag.product.weaver.association', 'reviewer:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25);

        // How many reports exist for each tag on this page: several reports of one tag weigh more.
        $counts = SuspiciousReport::manageableBy($request->user())
            ->whereIn('uid', $reports->pluck('uid'))
            ->selectRaw('uid, COUNT(*) AS total')
            ->groupBy('uid')
            ->pluck('total', 'uid');

        $reports->getCollection()->transform(fn (SuspiciousReport $report) => $this->present($report, (int) ($counts[$report->uid] ?? 1)));

        return response()->json($reports);
    }

    /**
     * Review a report: set its status and an optional note. This never revokes or
     * changes the tag; DTI does that separately, on its own decision.
     */
    public function update(Request $request, SuspiciousReport $report): JsonResponse
    {
        if (! SuspiciousReport::manageableBy($request->user())->whereKey($report->id)->exists()) {
            return response()->json(['message' => 'You cannot manage this report.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(SuspiciousReport::STATUSES)],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report->update([
            'status' => $validated['status'],
            'review_note' => filled($validated['review_note'] ?? null) ? trim($validated['review_note']) : null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $report->load(['nfcTag.product.weaver.association', 'reviewer:id,name']);

        return response()->json($this->present($report, SuspiciousReport::where('uid', $report->uid)->count()));
    }

    private function present(SuspiciousReport $report, int $sameTagReports): array
    {
        $product = $report->nfcTag?->product;
        $association = $product?->weaver?->association;

        return [
            'id' => $report->id,
            'reference' => $report->reference,
            'status' => $report->status,
            'uid' => $report->uid,
            'flag_reason' => $report->flag_reason,
            'location' => $report->location,
            'seen_on' => $report->seen_on?->format('Y-m-d'),
            'note' => $report->note,
            'contact' => $report->contact,
            'app_version' => $report->app_version,
            'review_note' => $report->review_note,
            'reviewed_by' => $report->reviewer?->name,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'created_at' => $report->created_at->toIso8601String(),
            'same_tag_reports' => $sameTagReports,
            'tag_status' => $report->nfcTag?->status,
            'product' => $product ? ['title' => $product->title] : null,
            'association' => $association ? [
                'name' => $association->name,
                'municipality' => $association->municipality,
            ] : null,
        ];
    }

    private function newReference(): string
    {
        do {
            $reference = 'SR-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        } while (SuspiciousReport::where('reference', $reference)->exists());

        return $reference;
    }
}
