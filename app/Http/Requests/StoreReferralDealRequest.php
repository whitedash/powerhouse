<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Referrer portal "Register a deal" submission. authorize() gates on the
 * LeadPolicy::register ability (active referrer). product_id is validated
 * but advisory — it is NOT persisted to the lead in Sprint 1; referrer_id
 * is taken from the authenticated referrer in the service, never here.
 */
class StoreReferralDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('register', Lead::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'company' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // GDPR attestation — must be ticked.
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent.accepted' => 'You must confirm you have the prospect’s consent to share their details.',
        ];
    }
}
