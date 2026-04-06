<?php
/**
 * Unit tests for the main plugin bootstrap file.
 *
 * Validates constants, version strings, and file structure.
 *
 * @package PeptideNews\Tests
 */

namespace PeptideNews\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginBootstrapTest extends TestCase {

    /**
     * Plugin version constant should be defined and non-empty.
     */
    public function test_version_constant_is_defined(): void {
        $this->assertTrue( defined( 'PEPTIDE_NEWS_VERSION' ) );
        $this->assertNotEmpty( PEPTIDE_NEWS_VERSION );
    }

    /**
     * Plugin directory constant should point to a real directory.
     */
    public function test_plugin_dir_constant_is_valid(): void {
        $this->assertTrue( defined( 'PEPTIDE_NEWS_PLUGIN_DIR' ) );
        $this->assertDirectoryExists( PEPTIDE_NEWS_PLUGIN_DIR );
    }

    /**
     * Main plugin file should exist.
     */
    public function test_main_plugin_file_exists(): void {
        $this->assertFileExists( PEPTIDE_NEWS_PLUGIN_DIR . 'peptide-news-plugin.php' );
    }

    /**
     * All required class files should exist.
     */
    public function test_required_class_files_exist(): void {
        $files = array(
            'includes/class-peptide-news.php',
            'includes/class-peptide-news-activator.php',
            'includes/class-peptide-news-deactivator.php',
            'includes/class-peptide-news-fetcher.php',
            'includes/class-peptide-news-analytics.php',
            'includes/class-peptide-news-rest-api.php',
            'admin/class-peptide-news-admin.php',
        );

        foreach ( $files as $file ) {
            $path = PEPTIDE_NEWS_PLUGIN_DIR . $file;
            $this->assertFileExists( $path, "Required file missing: {$file}" );
        }
    }

    /**
     * Admin JavaScript file should exist.
     */
    public function test_admin_script_exists(): void {
        $this->assertFileExists( PEPTIDE_NEWS_PLUGIN_DIR . 'admin/js/admin-script.js' );
    }

    /**
     * Admin stylesheet should exist.
     */
    public function test_admin_style_exists(): void {
        $this->assertFileExists( PEPTIDE_NEWS_PLUGIN_DIR . 'admin/css/admin-style.css' );
    }

    /**
     * Version string should follow SemVer pattern.
     */
    public function test_version_follows_semver(): void {
        // Allow semver with optional pre-release suffix (e.g. 1.2.1-test).
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[\w.]+)?$/',
            PEPTIDE_NEWS_VERSION,
            'Version does not follow SemVer pattern.'
        );
    }
}
