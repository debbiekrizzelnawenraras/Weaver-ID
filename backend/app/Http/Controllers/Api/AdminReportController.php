<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\Weaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminReportController extends Controller
{
    /**
     * Dashboard numbers for DTI: registry size, tag coverage, scan outcomes
     * and who issued the tags (the audit view for self-service linking).
     */
    public function summary(): JsonResponse
    {
        $scans = DB::table('scan_logs')
            ->selectRaw('result, COUNT(*) AS total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $tagsByIssuer = DB::table('nfc_tags')
            ->join('users', 'users.id', '=', 'nfc_tags.issued_by')
            ->selectRaw('users.role AS role, COUNT(*) AS total')
            ->groupBy('users.role')
            ->pluck('total', 'role');

        return response()->json([
            'weavers' => [
                'total' => Weaver::count(),
                'active' => Weaver::where('status', 'active')->count(),
            ],
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('status', 'active')->count(),
                'with_active_tag' => Product::whereHas('nfcTags', fn ($q) => $q->where('status', 'active'))->count(),
            ],
            'tags' => [
                'active' => NfcTag::where('status', 'active')->count(),
                'revoked' => NfcTag::where('status', 'revoked')->count(),
                'issued_by_role' => [
                    'admin' => (int) ($tagsByIssuer['admin'] ?? 0),
                    'staff' => (int) ($tagsByIssuer['staff'] ?? 0),
                    'association' => (int) ($tagsByIssuer['association'] ?? 0),
                ],
            ],
            'scans' => [
                'total' => (int) $scans->sum(),
                'authenticated' => (int) ($scans['authenticated'] ?? 0),
                'unregistered' => (int) ($scans['unregistered'] ?? 0),
                'suspicious' => (int) ($scans['suspicious'] ?? 0),
            ],
        ]);
    }

    /**
     * Scan history, newest first, 50 per page. Optional ?result=authenticated|unregistered|suspicious.
     */
    public function scanLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['nullable', Rule::enum(ScanResult::class)],
        ]);

        $logs = ScanLog::with('nfcTag.product')
            ->when($validated['result'] ?? null, fn ($query, $result) => $query->where('result', $result))
            ->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
