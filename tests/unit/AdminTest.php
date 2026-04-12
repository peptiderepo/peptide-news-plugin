<?php
/**
 * Unit tests for Peptide_News_Admin.
 *
 * @package PeptideNews\Tests
 */

namespace PeptideNews\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load the class under test.
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-peptide-news-admin.php';

class AdminTest extends TestCase {

    /**
     * @var \Peptide_News_Admin
     */
    private $admin;

    protected function setUp(): void {
        $this->admin = new \Peptide_News_Admin( 'peptide-news', '1.2.1' );

        // Reset global test state.
        global $_test_settings_errors;
        $_test_settings_errors = array();
    }

    /**
     * Constructor should set plugin_name property.
     */
    public function test_constructor_sets_plugin_name(): void {
        $reflection = new \ReflectionClass( $this->admin );
        $prop       = $reflection->getProperty( 'plugin_name' );
        $prop->setAccessible( true );

        $this->assertEquals( 'peptide-news', $prop->getValue( $this->admin ) );
    }

    /**
     * Constructor should set version property.
     */
    public function test_constructor_sets_version(): void {
        $reflection = new \ReflectionClass( $this->admin );
        $prop       = $reflection->getProperty( 'version' );
        $prop->setAccessible( true );

        $this->assertEquals( '1.2.1', $prop->getValue( $this->admin ) );
    }

    /**
     * sanitize_model_id() should accept a valid model ID.
     */
    public function test_sanitize_model_id_accepts_valid_id(): void {
        $valid = 'openai/gpt-4o-mini';
        $this->assertEquals( $valid, $this->admin->sanitize_model_id( $valid ) );
    }

    /**
     * sanitize_model_id() should accept model IDs with colons and dots.
     */
    public function test_sanitize_model_id_accepts_complex_ids(): void {
        $valid = 'google/gemini-2.0-flash:free';
        $this->assertEquals( $valid, $this->admin->sanitize_model_id( $valid ) );
    }

    /**
     * sanitize_model_id() should reject empty strings.
     */
    public function test_sanitize_model_id_allows_empty_to_clear(): void {
        $result = $this->admin->sanitize_model_id( '' );
        $this->assertEquals( '', $result );
    }

    /**
     * sanitize_model_id() should reject strings with spaces.
     */
    public function test_sanitize_model_id_rejects_spaces(): void {
        global $_test_settings_errors;

        $result = $this->admin->sanitize_model_id( 'invalid model id' );

        $this->assertNotEmpty( $_test_settings_errors, 'Expected a settings error for invalid model ID.' );
    }

    /**
     * sanitize_model_id() should reject strings with special characters.
     */
    public function test_sanitize_model_id_rejects_special_chars(): void {
        global $_test_settings_errors;

        $result = $this->admin->sanitize_model_id( '<script>alert(1)</script>' );

        $this->assertNotEmpty( $_test_settings_errors, 'Expected a settings error for XSS attempt.' );
    }

    /**
     * enqueue_styles() should not enqueue on non-plugin pages.
     *
     * We verify it returns early by checking no errors are thrown
     * when called with a non-matching hook.
     */
    public function test_enqueue_styles_skips_non_plugin_pages(): void {
        // Should return without error when hook doesn't match.
        $this->admin->enqueue_styles( 'edit.php' );
        $this->assertTrue( true ); // No exception = pass.
    }
}
