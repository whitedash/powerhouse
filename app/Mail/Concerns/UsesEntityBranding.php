<?php

namespace App\Mail\Concerns;

use App\Models\BillingEntity;
use Illuminate\Support\Facades\Storage;

/**
 * Shared branding payload for entity-scoped transactional emails. Reads
 * the BillingEntity (legal name, JSON address, VAT, logo) and falls back
 * to the global mail config when no entity is attached. The logo is
 * resolved to a public URL; private-disk logos won't render in an email
 * client, so this expects entities that opt into email to use the public
 * disk (matching the rest of the branding pipeline).
 */
trait UsesEntityBranding
{
    /**
     * @return array<string, mixed>
     */
    private function getEntityData(?BillingEntity $entity): array
    {
        $address = is_array($entity?->address) ? $entity->address : [];

        return [
            'entityName' => $entity?->legal_name ?: config('mail.from.name'),
            'entityAddress' => $entity
                ? implode(', ', array_filter([
                    $address['line1'] ?? null,
                    $address['line2'] ?? null,
                    $address['city'] ?? null,
                    $address['postcode'] ?? null,
                ]))
                : null,
            'entityVatNumber' => $entity?->vat_number,
            'logoUrl' => $entity?->logo_path
                ? Storage::disk('public')->url($entity->logo_path)
                : null,
            'portalUrl' => rtrim((string) config('app.url'), '/').'/portal',
        ];
    }

    /**
     * The customer's contact email for an entity-branded document, with a
     * sensible fallback chain. Returns null when there's nothing to send to.
     */
    private function resolveRecipient(?string $primary, ?string $fallback = null): ?string
    {
        return $primary ?: $fallback;
    }
}
