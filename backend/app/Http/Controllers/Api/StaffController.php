<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\Product;
use App\Rules\StrongPassword;
use App\Support\ProductPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Associations and products.
 *
 * Staff file associations (as applications the administrator approves) and register products in their own municipality. Admin may
 * only list them (the write routes are staff-only), because the administrator
 * approves applications and manages accounts but does not register records.
 * Out-of-scope records return 403, an inactive association returns 422.
 */
class StaffController extends Controller
{
    public function associations(Request $request): JsonResponse
    {
        $associations = Association::manageableBy($request->user())
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json([
            'associations' => $associations,
        ]);
    }

    /**
     * Staff file an association on its behalf. This no longer creates the association: it makes
     * the same pending application the public form makes, and only the administrator approves it.
     * Staff set a temporary password that the association must change at its first sign-in.
     */
    public function storeAssociation(Request $request): JsonResponse
    {
        $user = $request->user();

        if (blank($user->municipality)) {
            return $this->forbidden('Your account has no municipality, so you cannot file associations.');
        }

        $request->merge([
            'association_name' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('association_name'))),
            'contact_name' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('contact_name'))),
            'contact_phone' => preg_replace('/[\s-]+/', '', (string) $request->input('contact_phone')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'association_name' => ['required', 'string', 'min:3', 'max:255'],
            'contact_name' => ['required', 'string', 'min:3', 'max:255'],
            'contact_phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', new StrongPassword($request->input('email'), $request->input('contact_name'))],
            'registration_details' => ['nullable', 'string', 'max:150'],
            'privacy_consent' => ['accepted'],
        ], [
            'contact_phone.regex' => 'Enter a mobile number such as 0917 123 4567.',
            'privacy_consent.accepted' => 'The association must agree to the privacy notice before you file it.',
        ]);

        $errors = [];
        if (Association::nameTaken($validated['association_name'])) {
            $errors['association_name'] = 'An association with this name is already registered.';
        } elseif (AssociationApplication::pendingNameTaken($validated['association_name'])) {
            $errors['association_name'] = 'An application for this association is already waiting for review.';
        }
        if (AssociationApplication::accountEmailTaken($validated['email']) || AssociationApplication::pendingEmailTaken($validated['email'])) {
            $errors['email'] = 'This email address is already used by an account or a pending application.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $application = AssociationApplication::create([
            'reference' => AssociationApplication::newReference(),
            'association_name' => $validated['association_name'],
            // Staff are matched by exact municipality, so store their canonical spelling.
            'municipality' => $user->municipality,
            'contact_name' => $validated['contact_name'],
            'contact_phone' => $validated['contact_phone'],
            'email' => $validated['email'],
            'email_verified_at' => null,
            'password_hash' => Hash::make($validated['password']),
            'registration_details' => filled($validated['registration_details'] ?? null) ? $validated['registration_details'] : null,
            'status' => 'pending',
            'consented_at' => now(),
            'notice_version' => (string) config('weaver.application_notice_version'),
            'submitted_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Sent to the administrator for approval. Reference '.$application->reference.'.',
            'application' => $application->only(['id', 'reference', 'association_name', 'municipality', 'status']),
        ], 201);
    }

    /** Applications in the staff member's municipality, read only, so staff can see what is still waiting. */
    public function applications(Request $request): JsonResponse
    {
        $applications = AssociationApplication::manageableBy($request->user())
            ->with('submitter:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AssociationApplication $a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'association_name' => $a->association_name,
                'municipality' => $a->municipality,
                'contact_name' => $a->contact_name,
                'status' => $a->status,
                'decision_note' => $a->decision_note,
                'submitted_by' => $a->submitter?->name,
                'created_at' => $a->created_at,
            ]);

        return response()->json(['applications' => $applications]);
    }

    public function products(Request $request): JsonResponse
    {
        $products = Product::manageableBy($request->user())
            ->with('association', 'latestPhotoEvent.user')
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Product::CATEGORIES)],
        ]);

        $association = Association::manageableBy($request->user())->find($validated['association_id']);

        if (! $association) {
            return $this->forbidden('You cannot manage this association.');
        }

        if ($association->status !== 'active') {
            throw ValidationException::withMessages([
                'association_id' => 'The selected association is inactive.',
            ]);
        }

        $product = Product::create([
            'association_id' => $association->id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => 'active',
        ]);

        return response()->json([
            // Names the association so the user can see where the product was registered.
            'message' => 'Product created for '.$association->name.'.',
            'product' => $product->load('association'),
        ], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if (! Product::manageableBy($user)->whereKey($product->id)->exists()) {
            return $this->forbidden('You cannot manage this product.');
        }

        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Product::CATEGORIES)],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $association = Association::manageableBy($user)->find($validated['association_id']);

        if (! $association) {
            return $this->forbidden('You cannot manage this association.');
        }

        $moving = $association->id !== $product->association_id;

        // A product with a tag stays with the association its tag was sent to.
        if ($moving && $product->nfcTags()->exists()) {
            throw ValidationException::withMessages([
                'association_id' => 'This product has a tag, so it cannot move to another association. Ask the administrator to reassign the tag first.',
            ]);
        }

        // An inactive association may not receive products or have products reactivated,
        // but its existing products can still be edited or deactivated.
        if ($association->status !== 'active' && ($moving || $validated['status'] === 'active')) {
            throw ValidationException::withMessages([
                'association_id' => 'The selected association is inactive.',
            ]);
        }

        $product->update([
            'association_id' => $association->id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load('association'),
        ]);
    }

    public function uploadProductPhoto(Request $request, Product $product): JsonResponse
    {
        if (! Product::manageableBy($request->user())->whereKey($product->id)->exists()) {
            return $this->forbidden('You cannot manage this product.');
        }

        $request->validate(['photo' => ProductPhoto::rules()]);

        return response()->json([
            'message' => 'Product photo saved.',
            'product' => ProductPhoto::replace($product, $request->file('photo'), $request->user())->load('association', 'latestPhotoEvent.user'),
        ]);
    }

    public function deleteProductPhoto(Request $request, Product $product): JsonResponse
    {
        if (! Product::manageableBy($request->user())->whereKey($product->id)->exists()) {
            return $this->forbidden('You cannot manage this product.');
        }

        return response()->json([
            'message' => 'Product photo removed.',
            'product' => ProductPhoto::remove($product, $request->user())->load('association', 'latestPhotoEvent.user'),
        ]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }
}
