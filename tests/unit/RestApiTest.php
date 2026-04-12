<?php
/**
 * Unit tests for Peptide_News_Rest_API.
 *
 * @package PeptideNews\Tests
 */

namespace PeptideNews\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load the class under test.
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rest-api.php';

class RestApiTest extends TestCase {

    /**
     * @var \Peptide_News_Rest_API
     */
    private $api;

    protected function setUp(): void {
        $this->api = new \Peptide_News_Rest_API();
    }

    /**
     * validate_date_format() should accept valid Y-m-d dates.
     */
    public function test_validate_date_accepts_valid_format(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( '2026-01-15', $request, 'start_date' );

        $this->assertTrue( $result );
    }

    /**
     * validate_date_format() should accept today's date.
     */
    public function test_validate_date_accepts_today(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( date( 'Y-m-d' ), $request, 'end_date' );

        $this->assertTrue( $result );
    }

    /**
     * validate_date_format() should reject invalid formats.
     */
    public function test_validate_date_rejects_wrong_format(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( '01-15-2026', $request, 'start_date' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * validate_date_format() should reject empty strings.
     */
    public function test_validate_date_rejects_empty(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( '', $request, 'start_date' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * validate_date_format() should reject non-date strings.
     */
    public function test_validate_date_rejects_garbage(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( 'not-a-date', $request, 'start_date' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * validate_date_format() should reject dates with slashes.
     */
    public function test_validate_date_rejects_slashes(): void {
        $request = new \WP_REST_Request();
        $result  = $this->api->validate_date_format( '2026/01/15', $request, 'start_date' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * check_admin_permissions() should return WP_Error when user lacks capability.
     */
    public function test_check_admin_permissions_denies_non_admin(): void {
        // Stub current_user_can to return false.
        if ( ! function_exists( 'current_user_can' ) ) {
            function current_user_can() {
                return false;
            }
        }

        $request = new \WP_REST_Request();
        $result  = $this->api->check_admin_permissions( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }
}
