<?php
/**
 * Unit tests for Peptide_News_Fetcher.
 *
 * @package PeptideNews\Tests
 */

namespace PeptideNews\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load the class under test and its dependencies.
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-source-resolver.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rss-source.php';
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-fetcher.php';

class FetcherTest extends TestCase {

    /**
     * @var \Peptide_News_Fetcher
     */
    private $fetcher;

    protected function setUp(): void {
        $this->fetcher = new \Peptide_News_Fetcher();
    }

    /**
     * add_custom_cron_schedules() should add the expected intervals.
     */
    public function test_add_custom_cron_schedules_adds_intervals(): void {
        $existing = array(
            'hourly' => array(
                'interval' => 3600,
                'display'  => 'Once Hourly',
            ),
        );

        $result = $this->fetcher->add_custom_cron_schedules( $existing );

        // Original schedule must be preserved.
        $this->assertArrayHasKey( 'hourly', $result );

        // Custom schedules must be added.
        $this->assertArrayHasKey( 'every_fifteen_minutes', $result );
        $this->assertArrayHasKey( 'every_thirty_minutes', $result );
        $this->assertArrayHasKey( 'every_four_hours', $result );
        $this->assertArrayHasKey( 'every_six_hours', $result );
    }

    /**
     * Custom intervals should have correct durations.
     */
    public function test_cron_schedule_intervals_are_correct(): void {
        $result = $this->fetcher->add_custom_cron_schedules( array() );

        $this->assertEquals( 900, $result['every_fifteen_minutes']['interval'] );
        $this->assertEquals( 1800, $result['every_thirty_minutes']['interval'] );
        $this->assertEquals( 14400, $result['every_four_hours']['interval'] );
        $this->assertEquals( 21600, $result['every_six_hours']['interval'] );
    }

    /**
     * Custom schedules should have display names.
     */
    public function test_cron_schedules_have_display_names(): void {
        $result = $this->fetcher->add_custom_cron_schedules( array() );

        foreach ( $result as $key => $schedule ) {
            $this->assertArrayHasKey( 'display', $schedule, "Schedule '{$key}' missing 'display' key." );
            $this->assertNotEmpty( $schedule['display'], "Schedule '{$key}' has empty display name." );
        }
    }

    /**
     * Passing an empty array should still return all custom schedules.
     */
    public function test_cron_schedules_work_with_empty_input(): void {
        $result = $this->fetcher->add_custom_cron_schedules( array() );

        $this->assertCount( 4, $result );
    }
}
