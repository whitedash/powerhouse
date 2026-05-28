<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * IDOR (Insecure Direct Object Reference) regression suite.
 *
 * Each test asserts a specific cross-tenant/cross-role boundary; failures
 * here mean a route is leaking another user's data. Stubs only — the
 * testing sprint will fill bodies in. They're marked incomplete so a
 * `phpunit` run flags them rather than passing silently.
 */
class IdorTest extends TestCase
{
    /** @todo testing sprint — assert staff/admin role gating across customer fetch */
    public function test_staff_cannot_access_another_companys_customer(): void
    {
        $this->markTestIncomplete('Implement in testing sprint after multi-tenancy lands.');
    }

    /** @todo testing sprint */
    public function test_portal_user_cannot_access_other_customers_invoices(): void
    {
        $this->markTestIncomplete('Implement in testing sprint.');
    }

    /** @todo testing sprint */
    public function test_portal_user_cannot_access_other_customers_tickets(): void
    {
        $this->markTestIncomplete('Implement in testing sprint.');
    }

    /** @todo testing sprint */
    public function test_referrer_cannot_see_other_referrers_commissions(): void
    {
        $this->markTestIncomplete('Implement in testing sprint.');
    }

    /** @todo testing sprint */
    public function test_referrer_cannot_see_unattributed_customers(): void
    {
        $this->markTestIncomplete('Implement in testing sprint.');
    }
}
