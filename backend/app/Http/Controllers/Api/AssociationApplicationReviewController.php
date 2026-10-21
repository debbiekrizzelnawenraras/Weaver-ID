<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff (their own municipality) and admin (all) decide association applications.
 * Approving creates the association and its sign-in account; rejecting needs a reason the
 * applicant can read. Neither touches tags, members or products.
 */
class AssociationApplicationReviewController extends Controller
{
    /**
     * Applications, newest first, 25 per page. Optional ?status=pending|approved|rejected.
     * Each pending one says whether approving it would run into a name or email that is now taken.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(AssociationApplication::STATUSES)],
        ]);

        $applications = AssociationApplication::manageableBy($request->user())
            ->with('decider:id,name')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25);

        $applications->getCollection()->transform(fn (AssociationApplication $application) => $this->present($application));

        return response()->json($applications);
    }

    /**
     * Approve: creates the association and the account the applicant will sign in with, using the
     * password the applicant chose (so no forced password change). All or nothing.
     */
    public function approve(Request $request, AssociationApplication $application): JsonResponse
    {
        if ($denied = $this->deny($request, $application)) {
            return $denied;
        }

        $result = DB::transaction(function () use ($request, $application) {
            // Read the row again inside the transaction so two staff members cannot both approve it.
            $fresh = AssociationApplication::whereKey($application->id)->lockForUpdate()->first();

            if ($fresh->status !== 'pending') {
                return null;
            }

            if ($conflict = $this->conflict($fresh)) {
                throw ValidationException::withMessages($conflict);
            }

            $association = Association::create([
                'name' => $fresh->association_name,
                'municipality' => $fresh->municipality,
                'status' => 'active',
            ]);

            $user = new User;
            $user->forceFill([
                'name' => $fresh->contact_name,
                'email' => $fresh->email,
                'role' => 'association',
                'association_id' => $association->id,
                'municipality' => null,
                'status' => 'active',
                // The applicant chose this password themselves, so there is nothing to replace.
                'must_change_password' => false,
            ]);
            // Store the hash exactly as it was made at application time. A normal assignment would
            // hash it a second time and the applicant could never sign in.
            $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $fresh->password_hash]));
            $user->save();

            $fresh->update([
                'status' => 'approved',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'association_id' => $association->id,
                'user_id' => $user->id,
                'password_hash' => null,
            ]);

            return $fresh;
        });

        if (! $result) {
            return $this->alreadyDecided();
        }

        return response()->json([
            'message' => 'Approved. The association and its account were created.',
            'application' => $this->present($result->load('decider:id,name')),
        ]);
    }

    /**
     * Reject with a reason. The applicant sees the reason when they check their application and may
     * apply again. The saved password hash is deleted.
     */
    public function reject(Request $request, AssociationApplication $application): JsonResponse
    {
        if ($denied = $this->deny($request, $application)) {
            return $denied;
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'note.required' => 'Write the reason. The applicant will read it.',
            'note.min' => 'Write the reason in a few words. The applicant will read it.',
        ]);

        $done = DB::transaction(function () use ($request, $application, $validated) {
            $fresh = AssociationApplication::whereKey($application->id)->lockForUpdate()->first();

            if ($fresh->status !== 'pending') {
                return null;
            }

            $fresh->update([
                'status' => 'rejected',
                'decision_note' => trim($validated['note']),
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'password_hash' => null,
            ]);

            return $fresh;
        });

        if (! $done) {
            return $this->alreadyDecided();
        }

        return response()->json([
            'message' => 'Application rejected. The applicant can read your reason.',
            'application' => $this->present($done->load('decider:id,name')),
        ]);
    }

    /** 403 for an application outside the caller's scope, 422 for one that was already decided. */
    private function deny(Request $request, AssociationApplication $application): ?JsonResponse
    {
        if (! AssociationApplication::manageableBy($request->user())->whereKey($application->id)->exists()) {
            return response()->json(['message' => 'You cannot manage this application.'], 403);
        }

        return $application->status === 'pending' ? null : $this->alreadyDecided();
    }

    private function alreadyDecided(): JsonResponse
    {
        return response()->json(['message' => 'This application was already decided.'], 422);
    }

    /**
     * What would stop an approval right now: the name or the email was taken after the applicant applied.
     *
     * @return array<string, string>
     */
    private function conflict(AssociationApplication $application): array
    {
        $problems = [];

        if (Association::nameTaken($application->association_name)) {
            $problems['association_name'] = 'An association with this name is now registered. Reject this application and say so in the note.';
        }
        if (AssociationApplication::accountEmailTaken($application->email)) {
            $problems['email'] = 'This email address now belongs to an account. Reject this application and say so in the note.';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function present(AssociationApplication $application): array
    {
        return [
            'id' => $application->id,
            'reference' => $application->reference,
            'association_name' => $application->association_name,
            'municipality' => $application->municipality,
            'contact_name' => $application->contact_name,
            'contact_phone' => $application->contact_phone,
            'email' => $application->email,
            'registration_details' => $application->registration_details,
            'status' => $application->status,
            'consented_at' => $application->consented_at,
            'notice_version' => $application->notice_version,
            'decision_note' => $application->decision_note,
            'decided_at' => $application->decided_at,
            'decided_by' => $application->decider?->name,
            'conflict' => $application->status === 'pending' ? array_values($this->conflict($application)) : [],
            'created_at' => $application->created_at,
        ];
    }
}
