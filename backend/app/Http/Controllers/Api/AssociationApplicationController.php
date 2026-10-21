<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\AssociationApplication;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The public side of association registration: no login. An association applies, DTI-Mountain
 * Province staff decide (see AssociationApplicationReviewController). Nothing here creates an
 * association or an account.
 */
class AssociationApplicationController extends Controller
{
    /** The same words for every reason, so the form cannot be used to find out which emails have accounts. */
    private const EMAIL_UNAVAILABLE = 'This email address cannot be used for a new application. If you already applied, use "Check your application"; otherwise ask DTI-Mountain Province for help.';

    /** The municipalities offered on the form. */
    public function municipalities(): JsonResponse
    {
        return response()->json(['municipalities' => config('weaver.municipalities')]);
    }

    /**
     * Send an application. The password is checked against the password rules and saved only as a
     * hash. The decision comes later from staff; until then the applicant cannot sign in.
     */
    public function store(Request $request): JsonResponse
    {
        // A hidden field no person sees. A bot that fills it gets the same success answer and nothing is saved.
        if (filled($request->input('website'))) {
            return response()->json($this->received('AP-'.now()->format('Ymd').'-'.strtoupper(substr(md5(random_bytes(8)), 0, 5))), 201);
        }

        $request->merge($this->tidy($request));

        $validated = $request->validate([
            'association_name' => ['required', 'string', 'min:3', 'max:255'],
            'municipality' => ['required', 'string', Rule::in(config('weaver.municipalities'))],
            'contact_name' => ['required', 'string', 'min:3', 'max:255'],
            'contact_phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword($request->input('email'), $request->input('contact_name'))],
            'registration_details' => ['nullable', 'string', 'max:150'],
            'privacy_consent' => ['accepted'],
        ], [
            'association_name.required' => 'Enter the name of the association.',
            'association_name.min' => 'The association name is too short.',
            'municipality.required' => 'Choose the municipality.',
            'municipality.in' => 'Choose one of the municipalities in the list.',
            'contact_name.required' => 'Enter the name of the contact person.',
            'contact_name.min' => 'The contact person name is too short.',
            'contact_phone.required' => 'Enter a mobile number.',
            'contact_phone.regex' => 'Enter a mobile number such as 0917 123 4567.',
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Choose a password.',
            'password.confirmed' => 'The password and its confirmation do not match.',
            'privacy_consent.accepted' => 'You must agree to the privacy notice to apply.',
        ]);

        $errors = [];
        if (Association::nameTaken($validated['association_name'])) {
            $errors['association_name'] = 'An association with this name is already registered.';
        } elseif (AssociationApplication::pendingNameTaken($validated['association_name'])) {
            $errors['association_name'] = 'An application for this association is already waiting for review.';
        }
        if (AssociationApplication::accountEmailTaken($validated['email']) || AssociationApplication::pendingEmailTaken($validated['email'])) {
            $errors['email'] = self::EMAIL_UNAVAILABLE;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $application = AssociationApplication::create([
            'reference' => AssociationApplication::newReference(),
            'association_name' => $validated['association_name'],
            'municipality' => $validated['municipality'],
            'contact_name' => $validated['contact_name'],
            'contact_phone' => $validated['contact_phone'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'registration_details' => filled($validated['registration_details'] ?? null) ? $validated['registration_details'] : null,
            'status' => 'pending',
            'consented_at' => now(),
            'notice_version' => (string) config('weaver.application_notice_version'),
        ]);

        return response()->json($this->received($application->reference), 201);
    }

    /**
     * The applicant checks what happened. Needs the reference number and the email used to apply;
     * a wrong reference and a wrong email get the same answer.
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $application = AssociationApplication::where('reference', strtoupper(trim($validated['reference'])))
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['email']))])
            ->first();

        if (! $application) {
            return response()->json(['message' => 'No application matches that reference number and email.'], 404);
        }

        return response()->json([
            'reference' => $application->reference,
            'status' => $application->status,
            'association_name' => $application->association_name,
            'municipality' => $application->municipality,
            'submitted_at' => $application->created_at,
            'decided_at' => $application->decided_at,
            'decision_note' => $application->status === 'rejected' ? $application->decision_note : null,
            'message' => match ($application->status) {
                'approved' => 'Approved. You can now sign in with the email and password you chose when you applied.',
                'rejected' => 'This application was not approved. The reason is below. You may apply again after fixing it.',
                default => 'Your application is waiting for review by DTI-Mountain Province.',
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function received(string $reference): array
    {
        return [
            'reference' => $reference,
            'status' => 'pending',
            'message' => 'Application received. Keep this reference number: you need it, with your email, to check the result.',
        ];
    }

    /**
     * Trim the text, squeeze repeated spaces in names, and bring a mobile number written as
     * +63 917..., 63917... or with spaces and dashes to the local form 0917...
     *
     * @return array<string, string>
     */
    private function tidy(Request $request): array
    {
        $clean = [];

        foreach (['association_name', 'contact_name', 'registration_details'] as $field) {
            if (is_string($request->input($field))) {
                $clean[$field] = trim(preg_replace('/\s+/u', ' ', $request->input($field)));
            }
        }

        foreach (['email', 'municipality'] as $field) {
            if (is_string($request->input($field))) {
                $clean[$field] = trim($request->input($field));
            }
        }

        if (is_string($request->input('contact_phone'))) {
            $digits = preg_replace('/[\s\-().]+/', '', $request->input('contact_phone'));
            $clean['contact_phone'] = preg_match('/^(?:\+63|63|0)(9\d{9})$/', $digits, $m) ? '0'.$m[1] : $digits;
        }

        return $clean;
    }
}
