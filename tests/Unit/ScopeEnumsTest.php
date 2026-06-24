<?php

namespace Tests\Unit;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use Tests\TestCase;

class ScopeEnumsTest extends TestCase
{
    public function test_scope_area_has_the_four_areas(): void
    {
        $this->assertEqualsCanonicalizing(
            ['projects', 'tasks', 'leads', 'support'],
            ScopeArea::values(),
        );
    }

    public function test_access_scope_is_the_three_states(): void
    {
        $this->assertEqualsCanonicalizing(
            ['all', 'assigned', 'none'],
            AccessScope::values(),
        );
    }

    public function test_labels_are_title_cased(): void
    {
        $this->assertSame('Projects', ScopeArea::Projects->label());
        $this->assertSame('Support', ScopeArea::Support->label());
        $this->assertSame('All', AccessScope::All->label());
        $this->assertSame('Assigned', AccessScope::Assigned->label());
        $this->assertSame('None', AccessScope::None->label());
    }
}
