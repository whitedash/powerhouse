<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;

/**
 * What CompanyProvisioningService::provision() created. contact/person are
 * null when the caller provisioned a bare company (no primary-contact data
 * — e.g. lead conversion where the lead has neither email nor phone).
 */
final readonly class CompanyProvisionResult
{
    public function __construct(
        public Company $company,
        public ?Contact $contact,
        public ?Person $person,
    ) {}
}
