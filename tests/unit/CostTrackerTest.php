<?php
/**
 * Unit tests for Peptide_News_Cost_Tracker.
 *
 * Tests cost calculation, budget enforcement logic, and model pricing lookups.
 * Database-dependent methods (log_api_call, get_cost_summary, etc.) are covered
 * by integration tests; these unit tests focus on pure logic.
 *
 * @since 2.4.0
 */

use PHPUnit\Framework\TestCase;

class CostTrackerTest extends TestCase {

	/**
	 * Reset global test options before each test.
	 */
	protected function setUp(): void {
		global $_test_options;
		$_test_options = array();
	}

	// =============================================
	// calculate_cost() — pricing calculation tests
	// =============================================

	public function test_calculate_cost_with_known_model(): void {
		// google/gemini-2.0-flash-001: input $0.10/1M, output $0.40/1M
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'google/gemini-2.0-flash-001',
			1000, // 1K prompt tokens
			500   // 500 completion tokens
		);

		// Expected: (1000/1M * 0.10) + (500/1M * 0.40) = 0.0001 + 0.0002 = 0.0003
		$this->assertEqualsWithDelta( 0.0003, $cost, 0.000001 );
	}

	public function test_calculate_cost_with_free_model(): void {
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'google/gemma-3-27b-it:free',
			5000,
			2000
		);

		$this->assertEqualsWithDelta( 0.0, $cost, 0.000001 );
	}

	public function test_calculate_cost_with_unknown_model_returns_zero(): void {
		// Unknown models default to zero pricing to avoid overcharging estimates.
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'unknown/model-xyz',
			10000,
			5000
		);

		$this->assertEqualsWithDelta( 0.0, $cost, 0.000001 );
	}

	public function test_calculate_cost_with_expensive_model(): void {
		// anthropic/claude-3.5-sonnet: input $3.00/1M, output $15.00/1M
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'anthropic/claude-3.5-sonnet',
			2000,  // 2K prompt tokens
			1000   // 1K completion tokens
		);

		// Expected: (2000/1M * 3.00) + (1000/1M * 15.00) = 0.006 + 0.015 = 0.021
		$this->assertEqualsWithDelta( 0.021, $cost, 0.000001 );
	}

	public function test_calculate_cost_with_zero_tokens(): void {
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'anthropic/claude-3.5-sonnet',
			0,
			0
		);

		$this->assertEqualsWithDelta( 0.0, $cost, 0.000001 );
	}

	public function test_calculate_cost_precision_with_large_token_count(): void {
		// Simulate a large batch: 1M prompt tokens with gpt-4o ($2.50/1M)
		$cost = Peptide_News_Cost_Tracker::calculate_cost(
			'openai/gpt-4o',
			1000000, // 1M prompt tokens
			500000   // 500K completion tokens
		);

		// Expected: (1M/1M * 2.50) + (500K/1M * 10.00) = 2.50 + 5.00 = 7.50
		$this->assertEqualsWithDelta( 7.5, $cost, 0.000001 );
	}

	// =============================================
	// get_model_pricing() — pricing lookup tests
	// =============================================

	public function test_get_model_pricing_returns_known_defaults(): void {
		$pricing = Peptide_News_Cost_Tracker::get_model_pricing( 'openai/gpt-4o-mini' );

		$this->assertArrayHasKey( 'input', $pricing );
		$this->assertArrayHasKey( 'output', $pricing );
		$this->assertEqualsWithDelta( 0.15, $pricing['input'], 0.001 );
		$this->assertEqualsWithDelta( 0.60, $pricing['output'], 0.001 );
	}

	public function test_get_model_pricing_unknown_model_returns_zero(): void {
		$pricing = Peptide_News_Cost_Tracker::get_model_pricing( 'totally/unknown-model' );

		$this->assertEqualsWithDelta( 0.0, $pricing['input'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $pricing['output'], 0.001 );
	}

	public function test_get_model_pricing_respects_custom_overrides(): void {
		global $_test_options;
		$_test_options['peptide_news_custom_model_pricing'] = array(
			'custom/my-model' => array( 'input' => 1.50, 'output' => 5.00 ),
		);

		$pricing = Peptide_News_Cost_Tracker::get_model_pricing( 'custom/my-model' );

		$this->assertEqualsWithDelta( 1.50, $pricing['input'], 0.001 );
		$this->assertEqualsWithDelta( 5.00, $pricing['output'], 0.001 );
	}

	public function test_custom_pricing_overrides_defaults(): void {
		global $_test_options;
		// Override the default pricing for gemini flash.
		$_test_options['peptide_news_custom_model_pricing'] = array(
			'google/gemini-2.0-flash-001' => array( 'input' => 0.50, 'output' => 1.00 ),
		);

		$pricing = Peptide_News_Cost_Tracker::get_model_pricing( 'google/gemini-2.0-flash-001' );

		$this->assertEqualsWithDelta( 0.50, $pricing['input'], 0.001 );
		$this->assertEqualsWithDelta( 1.00, $pricing['output'], 0.001 );
	}

	// =============================================
	// is_budget_exceeded() — budget enforcement tests
	// =============================================

	public function test_budget_disabled_never_exceeds(): void {
		global $_test_options;
		$_test_options['peptide_news_budget_mode']    = 'disabled';
		$_test_options['peptide_news_monthly_budget'] = 10.0;

		$this->assertFalse( Peptide_News_Cost_Tracker::is_budget_exceeded() );
	}

	public function test_budget_zero_limit_never_exceeds(): void {
		global $_test_options;
		$_test_options['peptide_news_budget_mode']    = 'hard_stop';
		$_test_options['peptide_news_monthly_budget'] = 0.0;

		$this->assertFalse( Peptide_News_Cost_Tracker::is_budget_exceeded() );
	}

	public function test_budget_warn_only_never_exceeds(): void {
		global $_test_options;
		$_test_options['peptide_news_budget_mode']    = 'warn_only';
		$_test_options['peptide_news_monthly_budget'] = 1.0;

		// warn_only should never return true (it only logs warnings).
		$this->assertFalse( Peptide_News_Cost_Tracker::is_budget_exceeded() );
	}

	// =============================================
	// DEFAULT_MODEL_PRICING — structure validation
	// =============================================

	public function test_all_default_pricing_has_required_keys(): void {
		foreach ( Peptide_News_Cost_Tracker::DEFAULT_MODEL_PRICING as $model => $pricing ) {
			$this->assertArrayHasKey( 'input', $pricing, "Model {$model} missing 'input' key" );
			$this->assertArrayHasKey( 'output', $pricing, "Model {$model} missing 'output' key" );
			$this->assertIsFloat( $pricing['input'], "Model {$model} 'input' should be float" );
			$this->assertIsFloat( $pricing['output'], "Model {$model} 'output' should be float" );
			$this->assertGreaterThanOrEqual( 0.0, $pricing['input'], "Model {$model} 'input' should be non-negative" );
			$this->assertGreaterThanOrEqual( 0.0, $pricing['output'], "Model {$model} 'output' should be non-negative" );
		}
	}

	public function test_default_pricing_includes_key_models(): void {
		$required_models = array(
			'google/gemini-2.0-flash-001',
			'anthropic/claude-3.5-sonnet',
			'openai/gpt-4o-mini',
		);

		foreach ( $required_models as $model ) {
			$this->assertArrayHasKey(
				$model,
				Peptide_News_Cost_Tracker::DEFAULT_MODEL_PRICING,
				"Default pricing should include {$model}"
			);
		}
	}

	// =============================================
	// Budget mode constants — sanity checks
	// =============================================

	public function test_budget_mode_constants_are_distinct(): void {
		$modes = array(
			Peptide_News_Cost_Tracker::BUDGET_MODE_HARD_STOP,
			Peptide_News_Cost_Tracker::BUDGET_MODE_WARN_ONLY,
			Peptide_News_Cost_Tracker::BUDGET_MODE_DISABLED,
		);

		$this->assertCount( 3, array_unique( $modes ), 'Budget mode constants must be distinct' );
	}
}
