<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\AssociationApplication;
use App\Models\ScanLog;
use App\Models\SuspiciousReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttentionController extends Controller
{
    /** How many of the newest items each list carries. The tabs hold the full history. */
    private const LATEST = 5;

    /**
     * What needs a person's eyes right now, for the dashboard's "Needs attention" panel,
     * the Reports badge and the 60-second refresh. Every number is limited to what the
     * signed-in user may already see in the tabs, and nothing here is new data:
     *
     *  - new_reports: reports still waiting for a first review. Admin only, because only the
     *    administrator reviews reports. Not time-limited: a report stays open until reviewed.
     *  - pending_applications: association applications waiting for a decision, however old.
     *    Admin only, because only the administrator approves applications.
     *  - wrong_center_tags: open alerts of tags a Negosyo Center tried to receive although the
 *    administrator assigned them to another center. Admin only.
 *  - suspicious_scans: scans flagged suspicious within the last weaver.attention_hours
     *    (admin: all, association: scans of their own tags). null for staff, who have no
     *    Scan Logs tab, so a count they cannot open would be a dead end.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $hours = (int) config('weaver.attention_hours');
        $since = now()->subHours($hours);

        if ($user->role === 'association' && ! $user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $reports = null;
        if ($user->role === 'admin') {
            $new = SuspiciousReport::manageableBy($user)->where('status', 'new');

            $reports = [
                'count' => (clone $new)->count(),
                'latest' => (clone $new)->with('nfcTag.product:id,title')->latest('id')->limit(self::LATEST)->get()
                    ->map(fn (SuspiciousReport $report) => [
                        'id' => $report->id,
                        'reference' => $report->reference,
                        'uid' => $report->uid,
                        'product' => $report->nfcTag?->product?->title,
                        'flag_reason' => $report->flag_reason,
                        'location' => $report->location,
                        'created_at' => $report->created_at,
                    ])->values(),
            ];
        }

        $applications = null;
        if ($user->role === 'admin') {
            $waiting = AssociationApplication::manageableBy($user)->pending();

            $applications = [
                'count' => (clone $waiting)->count(),
                'latest' => (clone $waiting)->latest('id')->limit(self::LATEST)->get()
                    ->map(fn (AssociationApplication $application) => [
                        'id' => $application->id,
                        'reference' => $application->reference,
                        'association_name' => $application->association_name,
                        'municipality' => $application->municipality,
                        'created_at' => $application->created_at,
                    ])->values(),
            ];
        }

        $scans = null;
        if (in_array($user->role, ['admin', 'association'], true)) {
            $recent = ScanLog::manageableBy($user)
                ->where('result', ScanResult::Suspicious->value)
                ->where('created_at', '>=', $since);

            $scans = [
                'count' => (clone $recent)->count(),
                'latest' => (clone $recent)->with('nfcTag.product:id,title')->latest('id')->limit(self::LATEST)->get()
                    ->map(fn (ScanLog $log) => [
                        'id' => $log->id,
                        'uid' => $log->uid,
                        'product' => $log->nfcTag?->product?->title,
                        'flag_reason' => $log->flag_reason,
                        'created_at' => $log->created_at,
                    ])->values(),
            ];
        }

        // Tags a Negosyo Center tried to receive although they are on another center's list. Admin only.
        $wrongCenter = null;
        if ($user->role === 'admin') {
            $open = \App\Models\TagAlert::where('status', 'open');
            $wrongCenter = [
                'count' => (clone $open)->count(),
                'latest' => (clone $open)->latest('id')->limit(self::LATEST)->get(['id', 'uid', 'assigned_municipality', 'attempted_municipality', 'created_at']),
            ];
        }

        return response()->json([
            'window_hours' => $hours,
            'since' => $since,
            'new_reports' => $reports,
            'pending_applications' => $applications,
            'suspicious_scans' => $scans,
            'wrong_center_tags' => $wrongCenter,
        ]);
    }
}
