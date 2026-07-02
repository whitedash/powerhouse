<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared contact-matching primitives — deliberately NOT import-specific.
 *
 * The people/contacts dedup sprint's PersonService funnel should converge
 * on these same normalization + lookup conventions instead of growing a
 * third one-off matcher (assertNetNew and PersonService each carry their
 * own today).
 *
 * Normalization is trim-only by design: every email/phone column is
 * utf8mb4_unicode_ci, so plain `=` comparison is already case-, accent-
 * and trailing-space-insensitive at the DB level. Leading whitespace is
 * the one thing the collation does NOT forgive — hence the trim. Phone
 * matching is knowingly weak (raw string equality, no E.164) — building
 * real phone normalization is separate scope.
 *
 * Cascade semantics mirror DealRegistrationService::assertNetNew: when an
 * email is present it alone decides the match; phone is consulted only
 * when the row has no email. The two fields are never checked
 * independently side by side.
 */
class ContactMatchService
{
    public function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : $email;
    }

    public function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }

    /**
     * Find an existing Lead by the email-then-phone cascade.
     *
     * @return array{model: Lead, field: string}|null which field produced
     *                                                the hit, for reporting
     */
    public function matchLead(?string $email, ?string $phone): ?array
    {
        return $this->cascade(Lead::query(), $email, $phone);
    }

    /**
     * Find an existing Contact (the per-customer operational layer) by the
     * same cascade. contacts.email/phone carry no DB index, so these are
     * plain table scans — acceptable at current contact counts.
     *
     * @return array{model: Contact, field: string}|null
     */
    public function matchContact(?string $email, ?string $phone): ?array
    {
        return $this->cascade(Contact::query(), $email, $phone);
    }

    /**
     * @param  Builder<Lead>|Builder<Contact>  $query
     * @return array{model: Lead|Contact, field: string}|null
     */
    private function cascade($query, ?string $email, ?string $phone): ?array
    {
        $email = $this->normalizeEmail($email);
        $phone = $this->normalizePhone($phone);

        if ($email !== null) {
            $model = (clone $query)->where('email', $email)->first();

            return $model !== null ? ['model' => $model, 'field' => 'email'] : null;
        }

        if ($phone !== null) {
            $model = (clone $query)->where('phone', $phone)->first();

            return $model !== null ? ['model' => $model, 'field' => 'phone'] : null;
        }

        return null;
    }
}
