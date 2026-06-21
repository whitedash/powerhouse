<?php

namespace Tests\Feature;

use App\Services\WorkflowConditionEvaluator;
use Tests\TestCase;

/**
 * Unit coverage for the conditions evaluator — one test per operator (scalar AND
 * array/checkbox cases for equals/contains), group AND / cross-group OR, the four
 * is_empty cases, and the null/empty "no gating" shortcut.
 */
class WorkflowConditionEvaluatorTest extends TestCase
{
    private WorkflowConditionEvaluator $eval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eval = new WorkflowConditionEvaluator();
    }

    /** Build a single-condition gate (one group, AND). */
    private function gate(string $key, string $operator, ?string $value = null): array
    {
        return ['logic' => 'or', 'groups' => [
            ['logic' => 'and', 'conditions' => [
                ['field_key' => $key, 'operator' => $operator, 'value' => $value],
            ]],
        ]];
    }

    private function evaluate(array $gate, array $payload): bool
    {
        return $this->eval->matches($gate, $payload);
    }

    public function test_equals_scalar_and_array(): void
    {
        $this->assertTrue($this->evaluate($this->gate('color', 'equals', 'red'), ['color' => 'red']));
        $this->assertFalse($this->evaluate($this->gate('color', 'equals', 'red'), ['color' => 'blue']));
        // checkbox: true only when the sole element equals the value
        $this->assertTrue($this->evaluate($this->gate('opts', 'equals', 'a'), ['opts' => ['a']]));
        $this->assertFalse($this->evaluate($this->gate('opts', 'equals', 'a'), ['opts' => ['a', 'b']]));
    }

    public function test_not_equals(): void
    {
        $this->assertTrue($this->evaluate($this->gate('color', 'not_equals', 'red'), ['color' => 'blue']));
        $this->assertFalse($this->evaluate($this->gate('color', 'not_equals', 'red'), ['color' => 'red']));
    }

    public function test_contains_scalar_and_array(): void
    {
        $this->assertTrue($this->evaluate($this->gate('msg', 'contains', 'bill'), ['msg' => 'billing question']));
        $this->assertFalse($this->evaluate($this->gate('msg', 'contains', 'bill'), ['msg' => 'sales']));
        // checkbox: membership
        $this->assertTrue($this->evaluate($this->gate('opts', 'contains', 'b'), ['opts' => ['a', 'b']]));
        $this->assertFalse($this->evaluate($this->gate('opts', 'contains', 'b'), ['opts' => ['a', 'c']]));
    }

    public function test_not_contains(): void
    {
        $this->assertTrue($this->evaluate($this->gate('msg', 'not_contains', 'bill'), ['msg' => 'sales']));
        $this->assertFalse($this->evaluate($this->gate('msg', 'not_contains', 'bill'), ['msg' => 'billing']));
    }

    public function test_starts_with(): void
    {
        $this->assertTrue($this->evaluate($this->gate('name', 'starts_with', 'Jo'), ['name' => 'John']));
        $this->assertFalse($this->evaluate($this->gate('name', 'starts_with', 'Jo'), ['name' => 'Bob']));
    }

    public function test_ends_with(): void
    {
        $this->assertTrue($this->evaluate($this->gate('email', 'ends_with', '.com'), ['email' => 'a@b.com']));
        $this->assertFalse($this->evaluate($this->gate('email', 'ends_with', '.com'), ['email' => 'a@b.org']));
    }

    public function test_is_empty_across_absent_null_empty_string_and_empty_array(): void
    {
        $g = $this->gate('x', 'is_empty');
        $this->assertTrue($this->evaluate($g, []));                 // absent key
        $this->assertTrue($this->evaluate($g, ['x' => null]));      // null (multi-step)
        $this->assertTrue($this->evaluate($g, ['x' => '']));        // empty string
        $this->assertTrue($this->evaluate($g, ['x' => []]));        // empty array
        $this->assertFalse($this->evaluate($g, ['x' => '0']));      // present, non-empty
        $this->assertFalse($this->evaluate($g, ['x' => ['a']]));    // non-empty array
    }

    public function test_is_not_empty_across_absent_null_empty_string_and_empty_array(): void
    {
        $g = $this->gate('x', 'is_not_empty');
        $this->assertFalse($this->evaluate($g, []));
        $this->assertFalse($this->evaluate($g, ['x' => null]));
        $this->assertFalse($this->evaluate($g, ['x' => '']));
        $this->assertFalse($this->evaluate($g, ['x' => []]));
        $this->assertTrue($this->evaluate($g, ['x' => 'hi']));
        $this->assertTrue($this->evaluate($g, ['x' => ['a']]));
    }

    public function test_greater_than_and_non_numeric_does_not_match(): void
    {
        $this->assertTrue($this->evaluate($this->gate('budget', 'greater_than', '10000'), ['budget' => '15000']));
        $this->assertFalse($this->evaluate($this->gate('budget', 'greater_than', '10000'), ['budget' => '5000']));
        $this->assertFalse($this->evaluate($this->gate('budget', 'greater_than', '10000'), ['budget' => 'abc']));
        $this->assertFalse($this->evaluate($this->gate('budget', 'greater_than', '10000'), []));
    }

    public function test_less_than(): void
    {
        $this->assertTrue($this->evaluate($this->gate('budget', 'less_than', '10000'), ['budget' => '5000']));
        $this->assertFalse($this->evaluate($this->gate('budget', 'less_than', '10000'), ['budget' => '15000']));
    }

    public function test_greater_than_or_equal(): void
    {
        $this->assertTrue($this->evaluate($this->gate('n', 'greater_than_or_equal', '10'), ['n' => '10']));
        $this->assertFalse($this->evaluate($this->gate('n', 'greater_than_or_equal', '10'), ['n' => '9']));
    }

    public function test_less_than_or_equal(): void
    {
        $this->assertTrue($this->evaluate($this->gate('n', 'less_than_or_equal', '10'), ['n' => '10']));
        $this->assertFalse($this->evaluate($this->gate('n', 'less_than_or_equal', '10'), ['n' => '11']));
    }

    public function test_and_within_a_group_requires_all(): void
    {
        $gate = ['logic' => 'or', 'groups' => [['logic' => 'and', 'conditions' => [
            ['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
            ['field_key' => 'region', 'operator' => 'equals', 'value' => 'eu'],
        ]]]];
        $this->assertTrue($this->evaluate($gate, ['budget' => '20000', 'region' => 'eu']));
        $this->assertFalse($this->evaluate($gate, ['budget' => '20000', 'region' => 'us']));
        $this->assertFalse($this->evaluate($gate, ['budget' => '5000', 'region' => 'eu']));
    }

    public function test_or_between_groups_and_mixed_two_groups(): void
    {
        // Group A: budget > 10000 AND region = eu.  Group B: priority = urgent.
        $gate = ['logic' => 'or', 'groups' => [
            ['logic' => 'and', 'conditions' => [
                ['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
                ['field_key' => 'region', 'operator' => 'equals', 'value' => 'eu'],
            ]],
            ['logic' => 'and', 'conditions' => [
                ['field_key' => 'priority', 'operator' => 'equals', 'value' => 'urgent'],
            ]],
        ]];
        $this->assertTrue($this->evaluate($gate, ['budget' => '20000', 'region' => 'eu']));    // group A
        $this->assertTrue($this->evaluate($gate, ['priority' => 'urgent']));                    // group B
        $this->assertTrue($this->evaluate($gate, ['region' => 'eu', 'priority' => 'urgent']));  // B matches though A fails
        $this->assertFalse($this->evaluate($gate, ['budget' => '5000', 'priority' => 'low']));  // neither
    }

    public function test_null_or_empty_conditions_match_no_gating(): void
    {
        $this->assertTrue($this->eval->matches(null, ['anything' => 'x']));
        $this->assertTrue($this->eval->matches([], ['anything' => 'x']));
        $this->assertTrue($this->eval->matches(['groups' => []], ['anything' => 'x']));
    }
}
