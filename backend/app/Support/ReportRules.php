<?php

namespace App\Support;

use App\Enums\ScanResult;
use App\Models\ScanLog;

/**
 * When may a person report a tag? Only after the server itself flagged a scan
 * of that tag from the same phone, recently. This keeps made-up reports for
 * arbitrary UIDs out, and the server (not the app) decides what the reason was.
 *
 * A secured tag that arrives without its counter or tag code is most often a
 * read that was cut short (phone moved off the tag), not a copy. Such a flag
 * does not open reporting on its own: the person is asked to tap again, and
 * a second flag for the same tag opens it.
 */
class ReportRules
{
    /** Flag reasons that can come from an incomplete read. */
    public const INCOMPLETE_READ = ['counter_missing', 'code_missing'];

    /** Who is scanning: the device id, or the IP address when none is sent (same rule as the verify endpoint). */
    public static function reporterKey(?string $deviceId, ?string $ip): string
    {
        return $deviceId ?: (string) $ip;
    }

    /**
     * The flagged scan that lets this phone report this tag, or null when it may not report yet.
     * The phone's latest scan of the tag within the window must be a flagged one.
     */
    public static function eligibleScan(string $uid, string $reporterKey): ?ScanLog
    {
        $since = now()->subMinutes((int) config('weaver.report_window_minutes'));

        $recent = ScanLog::where('uid', $uid)
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($reporterKey) {
                $query->where('device_id', $reporterKey)
                    ->orWhere(fn ($q) => $q->whereNull('device_id')->where('ip_address', $reporterKey));
            })
            ->latest('id')
            ->get();

        // Only the run of flagged scans that ends at this phone's latest scan counts:
        // a scan that was authenticated in between starts the count again.
        $flagged = collect();

        foreach ($recent as $scan) {
            if ($scan->result !== ScanResult::Suspicious) {
                break;
            }

            $flagged->push($scan);
        }

        $firm = $flagged->first(fn (ScanLog $scan) => ! in_array($scan->flag_reason, self::INCOMPLETE_READ, true));

        if ($firm) {
            return $firm;
        }

        return $flagged->count() >= 2 ? $flagged->first() : null;
    }

    /** True when this scan was flagged only for a missing counter or tag code. */
    public static function isIncompleteRead(?string $reason): bool
    {
        return in_array($reason, self::INCOMPLETE_READ, true);
    }
}
