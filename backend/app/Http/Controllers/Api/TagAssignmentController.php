<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\TagAlert;
use App\Models\TagAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Delivery lists and wrong-center alerts (administrator only).
 *
 * Before tags ship, the administrator lists which UIDs go to which Negosyo Center. When a center
 * receives a UID that is on another center's list, the receive is refused and an alert opens here
 * (see InventoryController::refuseWrongCenter). The administrator then moves the tag to the
 * center that has it, or dismisses the alert. UIDs on no list can still be received, so tags
 * that arrived before this check keep working.
 */
class TagAssignmentController extends Controller
{
    private const MAX_UIDS = 500;

    /** Per center: assigned, received, still waiting. Plus the open alerts. */
    public function index(): JsonResponse
    {
        $rows = TagAssignment::selectRaw('municipality, COUNT(*) AS assigned, COUNT(received_at) AS received')
            ->groupBy('municipality')->orderBy('municipality')->get()
            ->map(fn ($row) => [
                'municipality' => $row->municipality,
                'assigned' => (int) $row->assigned,
                'received' => (int) $row->received,
                'waiting' => (int) $row->assigned - (int) $row->received,
            ]);

        $alerts = TagAlert::with('attempter:id,name')->where('status', 'open')->latest('id')->limit(200)->get()
            ->map(fn (TagAlert $alert) => $this->presentAlert($alert));

        return response()->json([
            'municipalities' => $rows,
            'alerts' => $alerts,
            'open_alerts' => TagAlert::where('status', 'open')->count(),
        ]);
    }

    /** UIDs on the lists, 50 per page. Optional ?municipality= and ?state=waiting|received. */
    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'municipality' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(['waiting', 'received'])],
        ]);

        $page = TagAssignment::with('assigner:id,name')
            ->when($validated['municipality'] ?? null, fn ($q, $m) => $q->where('municipality', $m))
            ->when(($validated['state'] ?? null) === 'waiting', fn ($q) => $q->whereNull('received_at'))
            ->when(($validated['state'] ?? null) === 'received', fn ($q) => $q->whereNotNull('received_at'))
            ->latest('id')
            ->paginate(50);

        return response()->json($page);
    }

    /** Assign UIDs to one Negosyo Center (a delivery list). All or nothing. */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeUids($request);

        $validated = $request->validate([
            'municipality' => ['required', 'string', Rule::in(config('weaver.municipalities'))],
            'delivery_ref' => ['nullable', 'string', 'max:50'],
            'uids' => ['required', 'array', 'min:1', 'max:'.self::MAX_UIDS],
            'uids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-F]{8,20}$/'],
        ], [
            'uids.required' => 'Read or type at least one tag UID.',
            'uids.*.distinct' => 'A UID is listed twice.',
            'uids.*.regex' => 'Each UID must be 8 to 20 hexadecimal characters.',
        ]);

        $uids = $validated['uids'];
        $municipality = $validated['municipality'];

        $problems = [];
        foreach (TagAssignment::whereIn('uid', $uids)->get() as $existing) {
            $problems[] = $existing->uid.' is already assigned to '.$existing->municipality.'. Use Move instead.';
        }
        $received = NfcTag::with('batch:id,municipality')->whereIn('uid', $uids)->get()->keyBy('uid');
        foreach ($received as $uid => $tag) {
            $at = $tag->batch?->municipality;
            if ($at && strcasecmp($at, $municipality) !== 0) {
                $problems[] = $uid.' was already received by '.$at.'.';
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['uids' => $problems]);
        }

        DB::transaction(function () use ($uids, $municipality, $validated, $request, $received) {
            foreach ($uids as $uid) {
                TagAssignment::create([
                    'uid' => $uid,
                    'municipality' => $municipality,
                    'delivery_ref' => filled($validated['delivery_ref'] ?? null) ? trim($validated['delivery_ref']) : null,
                    'assigned_by' => $request->user()->id,
                    // Already in that center's stock: nothing to wait for.
                    'received_at' => $received->has($uid) ? now() : null,
                ]);
            }
        });

        return response()->json([
            'message' => count($uids).' tags assigned to the '.$municipality.' Negosyo Center.',
        ], 201);
    }

    /**
     * Move UIDs to another center's list, for example after an alert shows a box went to the
     * wrong place. Only tags not received yet can move; open alerts for that center close.
     */
    public function move(Request $request): JsonResponse
    {
        $this->normalizeUids($request);

        $validated = $request->validate([
            'municipality' => ['required', 'string', Rule::in(config('weaver.municipalities'))],
            'uids' => ['required', 'array', 'min:1', 'max:'.self::MAX_UIDS],
            'uids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-F]{8,20}$/'],
        ]);

        $moved = $this->moveTo($validated['uids'], $validated['municipality'], $request->user()->id);

        return response()->json([
            'message' => $moved.' tags moved to the '.$validated['municipality'].' Negosyo Center list. That center can now receive them.',
        ]);
    }

    /** Move the alert's tag to the center that actually has it. */
    public function moveAlert(Request $request, TagAlert $alert): JsonResponse
    {
        if ($alert->status !== 'open') {
            return response()->json(['message' => 'This alert is already closed.'], 422);
        }

        $this->moveTo([$alert->uid], $alert->attempted_municipality, $request->user()->id);

        return response()->json([
            'message' => $alert->uid.' moved to the '.$alert->attempted_municipality.' Negosyo Center list. Ask them to receive it again.',
        ]);
    }

    /** Close the alert without moving the tag (for example, the box was sent on to the right center). */
    public function dismissAlert(Request $request, TagAlert $alert): JsonResponse
    {
        if ($alert->status !== 'open') {
            return response()->json(['message' => 'This alert is already closed.'], 422);
        }

        $alert->update(['status' => 'dismissed', 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);

        return response()->json(['message' => 'Alert dismissed. The tag stays on the '.$alert->assigned_municipality.' list.']);
    }

    private function moveTo(array $uids, string $municipality, int $userId): int
    {
        $received = TagAssignment::whereIn('uid', $uids)->whereNotNull('received_at')->pluck('municipality', 'uid');
        if ($received->isNotEmpty()) {
            throw ValidationException::withMessages(['uids' => $received->map(
                fn ($at, $uid) => $uid.' was already received by '.$at.' and cannot move on the list.'
            )->values()->all()]);
        }

        return DB::transaction(function () use ($uids, $municipality, $userId) {
            foreach ($uids as $uid) {
                TagAssignment::updateOrCreate(['uid' => $uid], ['municipality' => $municipality, 'assigned_by' => $userId]);
            }

            TagAlert::whereIn('uid', $uids)->where('status', 'open')
                ->where('attempted_municipality', $municipality)
                ->update(['status' => 'moved', 'resolved_by' => $userId, 'resolved_at' => now()]);

            return count($uids);
        });
    }

    private function presentAlert(TagAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'uid' => $alert->uid,
            'assigned_municipality' => $alert->assigned_municipality,
            'attempted_municipality' => $alert->attempted_municipality,
            'attempted_by' => $alert->attempter?->name,
            'created_at' => $alert->created_at,
        ];
    }

    private function normalizeUids(Request $request): void
    {
        $uids = $request->input('uids');
        if (is_string($uids)) {
            $uids = preg_split('/[\r\n,;]+/', $uids);
        }
        if (is_array($uids)) {
            $request->merge(['uids' => array_values(array_filter(
                array_map(fn ($uid) => is_string($uid) ? NfcTag::normalizeUid(trim($uid)) : $uid, $uids),
                fn ($uid) => $uid !== '',
            ))]);
        }
    }
}
