<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\NfcTag;
use App\Models\TagAlert;
use App\Models\TagAssignment;
use App\Models\TagBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The NFC tag inventory, kept per Negosyo Center (municipality). There is no provincial
 * stock: each center receives its own batches.
 *
 * Staff receive batches into their municipality's stock, send tags to associations of their
 * municipality, take unused tags back and mark tags damaged or lost. Admin may only look
 * (the write routes are staff-only) and sees every municipality. Every move is written to
 * nfc_tag_events, so where each tag went can be traced.
 */
class InventoryController extends Controller
{
    /** Most UIDs accepted in one request, so one bad paste cannot flood the stock. */
    private const MAX_UIDS = 500;

    /**
     * Counts for the Inventory tab: overall, per municipality, per batch and per association.
     * Staff see only their own municipality.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $visible = NfcTag::manageableBy($user);

        $totals = array_fill_keys(NfcTag::STATUSES, 0);
        foreach ((clone $visible)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $totals[$status] = (int) $total;
        }

        $byBatch = (clone $visible)->selectRaw('tag_batch_id, status, COUNT(*) AS total')
            ->groupBy('tag_batch_id', 'status')->get()->groupBy('tag_batch_id');

        $batches = TagBatch::with('receiver:id,name')
            ->when($user->role === 'staff', fn ($q) => $q->where('municipality', $user->municipality ?? '-'))
            ->latest('received_on')->latest('id')->get()
            ->map(fn (TagBatch $batch) => [
                'id' => $batch->id,
                'code' => $batch->code,
                'municipality' => $batch->municipality,
                'supplier' => $batch->supplier,
                'received_on' => $batch->received_on?->format('Y-m-d'),
                'notes' => $batch->notes,
                'received_by' => $batch->receiver?->name,
                'counts' => $this->counts($byBatch->get($batch->id, collect())),
            ])
            ->values();

        $byAssociation = (clone $visible)->whereNotNull('association_id')
            ->selectRaw('association_id, status, COUNT(*) AS total')
            ->groupBy('association_id', 'status')->get()->groupBy('association_id');

        $associations = Association::manageableBy($user)->orderBy('name')->get(['id', 'name', 'municipality'])
            ->map(fn (Association $association) => [
                'id' => $association->id,
                'name' => $association->name,
                'municipality' => $association->municipality,
                'counts' => $this->counts($byAssociation->get($association->id, collect())),
            ])
            ->values();

        // One row per Negosyo Center: what it received and where those tags are now.
        $municipalities = $batches->groupBy(fn (array $batch) => $batch['municipality'] ?? 'Not set')
            ->map(function ($group, $municipality) {
                $counts = array_fill_keys(NfcTag::STATUSES, 0);
                foreach ($group as $batch) {
                    foreach ($batch['counts'] as $status => $n) {
                        $counts[$status] += $n;
                    }
                }

                return ['municipality' => $municipality, 'batches' => $group->count(), 'counts' => $counts];
            })
            ->sortKeys()
            ->values();

        return response()->json([
            'municipality' => $user->role === 'staff' ? $user->municipality : null,
            'totals' => $totals,
            'municipalities' => $municipalities,
            'batches' => $batches,
            'associations' => $associations,
        ]);
    }

    /**
     * Inventory tags, newest first, 100 per page. Optional ?status=, ?batch_id=, ?association_id=, ?uid=.
     */
    public function tags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(NfcTag::STATUSES)],
            'batch_id' => ['nullable', 'integer'],
            'association_id' => ['nullable', 'integer'],
            'uid' => ['nullable', 'string', 'max:40'],
        ]);

        $tags = NfcTag::manageableBy($request->user())
            ->with('batch:id,code', 'association:id,name', 'product:id,title', 'sender:id,name')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['batch_id'] ?? null, fn ($q, $id) => $q->where('tag_batch_id', $id))
            ->when($validated['association_id'] ?? null, fn ($q, $id) => $q->where('association_id', $id))
            ->when($validated['uid'] ?? null, fn ($q, $uid) => $q->where('uid', 'like', '%'.NfcTag::normalizeUid($uid).'%'))
            ->latest('id')
            ->paginate(100);

        return response()->json($tags);
    }

    /**
     * Receive a new batch into the staff member's own Negosyo Center stock, with the UIDs read at the tag desk.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        if (blank($request->user()->municipality)) {
            return response()->json(['message' => 'Your account has no municipality, so it has no Negosyo Center stock. Ask the administrator to set it.'], 403);
        }

        $this->normalizeUids($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('tag_batches', 'code')],
            'supplier' => ['nullable', 'string', 'max:255'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->uidRules(),
        ], $this->uidMessages() + [
            'code.unique' => 'A batch with this code is already recorded.',
        ]);

        $this->refuseWrongCenter($validated['uids'], $request->user());

        $batch = DB::transaction(function () use ($validated, $request) {
            $batch = TagBatch::create([
                'code' => trim($validated['code']),
                'municipality' => $request->user()->municipality,
                'supplier' => $validated['supplier'] ?? null,
                'received_on' => $validated['received_on'],
                'notes' => $validated['notes'] ?? null,
                'received_by' => $request->user()->id,
            ]);

            $this->receive($batch, $validated['uids'], $request->user()->id);

            return $batch;
        });

        return response()->json([
            'message' => count($validated['uids']).' tags received in batch '.$batch->code.'.',
            'batch' => $batch,
        ], 201);
    }

    /** Add more UIDs to a batch already recorded (for example, a box counted later). */
    public function addToBatch(Request $request, TagBatch $batch): JsonResponse
    {
        if ($batch->municipality !== $request->user()->municipality) {
            return response()->json(['message' => 'This batch belongs to another Negosyo Center.'], 403);
        }

        $this->normalizeUids($request);

        $validated = $request->validate($this->uidRules(), $this->uidMessages());

        $this->refuseWrongCenter($validated['uids'], $request->user());

        DB::transaction(fn () => $this->receive($batch, $validated['uids'], $request->user()->id));

        return response()->json([
            'message' => count($validated['uids']).' tags added to batch '.$batch->code.'.',
            'batch' => $batch,
        ], 201);
    }

    /**
     * Send tags from the stock to an association. Only tags in the staff member's own
     * municipality's stock can be sent, and only to associations of that municipality.
     */
    public function send(Request $request): JsonResponse
    {
        $this->normalizeUids($request);

        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'uids' => ['required', 'array', 'min:1', 'max:'.self::MAX_UIDS],
            'uids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-F]{8,20}$/'],
        ], $this->uidMessages());

        $user = $request->user();
        $association = Association::manageableBy($user)->find($validated['association_id']);

        if (! $association) {
            return response()->json(['message' => 'You cannot send tags to this association.'], 403);
        }

        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association_id' => 'This association is inactive.']);
        }

        DB::transaction(function () use ($validated, $association, $user) {
            $tags = NfcTag::with('batch:id,municipality')->whereIn('uid', $validated['uids'])->lockForUpdate()->get()->keyBy('uid');

            $problems = [];
            foreach ($validated['uids'] as $uid) {
                $tag = $tags->get($uid);
                if (! $tag) {
                    $problems[] = $uid.' is not in the inventory.';
                } elseif ($tag->status === 'in_stock' && $tag->batch?->municipality !== $user->municipality) {
                    $problems[] = $uid.' is in the stock of another Negosyo Center.';
                } elseif ($tag->status !== 'in_stock') {
                    $problems[] = $uid.' is not in stock (now: '.$this->label($tag->status).').';
                }
            }

            if ($problems) {
                throw ValidationException::withMessages(['uids' => $problems]);
            }

            foreach ($tags as $tag) {
                $tag->update([
                    'status' => 'sent',
                    'association_id' => $association->id,
                    'sent_by' => $user->id,
                    'sent_at' => now(),
                ]);

                $tag->events()->create([
                    'user_id' => $user->id,
                    'action' => 'sent',
                    'association_id' => $association->id,
                ]);
            }
        });

        return response()->json([
            'message' => count($validated['uids']).' tags sent to '.$association->name.'.',
        ]);
    }

    /** Take an unused tag back from an association into the stock. */
    public function returnToStock(Request $request, NfcTag $nfcTag): JsonResponse
    {
        if ($denied = $this->deny($request, $nfcTag)) {
            return $denied;
        }

        if ($nfcTag->status !== 'sent') {
            throw ValidationException::withMessages([
                'status' => 'Only a tag that was sent and not yet linked can go back to stock.',
            ]);
        }

        DB::transaction(function () use ($nfcTag, $request) {
            $from = $nfcTag->association_id;

            $nfcTag->update([
                'status' => 'in_stock',
                'association_id' => null,
                'sent_by' => null,
                'sent_at' => null,
            ]);

            $nfcTag->events()->create([
                'user_id' => $request->user()->id,
                'action' => 'returned',
                'association_id' => $from,
            ]);
        });

        return response()->json(['message' => 'Tag returned to stock.', 'tag' => $nfcTag->fresh()]);
    }

    /**
     * Mark a tag damaged or lost so it can never be linked. A linked tag must be
     * revoked first, so a tag in use is never taken out of service by accident.
     */
    public function markDamaged(Request $request, NfcTag $nfcTag): JsonResponse
    {
        if ($denied = $this->deny($request, $nfcTag)) {
            return $denied;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! in_array($nfcTag->status, ['in_stock', 'sent', 'revoked'], true)) {
            throw ValidationException::withMessages([
                'status' => $nfcTag->status === 'active'
                    ? 'This tag is linked to a product. Revoke it first.'
                    : 'This tag is already marked damaged or lost.',
            ]);
        }

        DB::transaction(function () use ($nfcTag, $request, $validated) {
            $nfcTag->update(['status' => 'damaged']);

            $nfcTag->events()->create([
                'user_id' => $request->user()->id,
                'action' => 'damaged',
                'association_id' => $nfcTag->association_id,
                'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            ]);
        });

        return response()->json(['message' => 'Tag marked damaged or lost.', 'tag' => $nfcTag->fresh()]);
    }

    /** @param list<string> $uids */
    private function receive(TagBatch $batch, array $uids, int $userId): void
    {
        foreach ($uids as $uid) {
            $tag = NfcTag::create([
                'uid' => $uid,
                'tag_batch_id' => $batch->id,
                'status' => 'in_stock',
            ]);

            $tag->events()->create([
                'user_id' => $userId,
                'action' => 'received',
                'note' => 'Batch '.$batch->code,
            ]);
        }

        // These UIDs reached the center they were assigned to.
        TagAssignment::whereIn('uid', $uids)->whereNull('received_at')->update(['received_at' => now()]);
    }

    /**
     * The wrong-center check: a UID the administrator assigned to another Negosyo Center is refused,
     * and an alert opens for the administrator (outside the refused request, so it is kept).
     * UIDs on no delivery list are allowed.
     */
    private function refuseWrongCenter(array $uids, \App\Models\User $user): void
    {
        $wrong = TagAssignment::whereIn('uid', $uids)->get()
            ->filter(fn (TagAssignment $a) => strcasecmp($a->municipality, (string) $user->municipality) !== 0);

        if ($wrong->isEmpty()) {
            return;
        }

        foreach ($wrong as $assignment) {
            TagAlert::firstOrCreate(
                ['uid' => $assignment->uid, 'attempted_municipality' => $user->municipality, 'status' => 'open'],
                ['assigned_municipality' => $assignment->municipality, 'attempted_by' => $user->id],
            );
        }

        throw ValidationException::withMessages([
            'uids' => $wrong->map(fn (TagAssignment $a) => $a->uid.' is assigned to the '.$a->municipality.' Negosyo Center, not yours. Nothing was received. The administrator has been alerted.')->values()->all(),
        ]);
    }

    private function normalizeUids(Request $request): void
    {
        $uids = $request->input('uids');

        // The dashboard may send one text box with a UID per line.
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

    private function uidRules(): array
    {
        return [
            'uids' => ['required', 'array', 'min:1', 'max:'.self::MAX_UIDS],
            'uids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9A-F]{8,20}$/', Rule::unique('nfc_tags', 'uid')],
        ];
    }

    private function uidMessages(): array
    {
        return [
            'uids.required' => 'Read or type at least one tag UID.',
            'uids.max' => 'At most '.self::MAX_UIDS.' tags at a time.',
            'uids.*.distinct' => 'A UID is listed twice.',
            'uids.*.regex' => 'Each UID must be 8 to 20 hexadecimal characters.',
            'uids.*.unique' => 'A UID is already in the inventory.',
        ];
    }

    private function deny(Request $request, NfcTag $nfcTag): ?JsonResponse
    {
        if (! NfcTag::manageableBy($request->user())->whereKey($nfcTag->id)->exists()) {
            return response()->json(['message' => 'You cannot manage this tag.'], 403);
        }

        return null;
    }

    /** @param Collection<int, object> $rows */
    private function counts(Collection $rows): array
    {
        $counts = array_fill_keys(NfcTag::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return $counts;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'in_stock' => 'in stock',
            'sent' => 'sent to an association',
            'active' => 'linked',
            'revoked' => 'revoked',
            'damaged' => 'damaged or lost',
            default => $status,
        };
    }
}
