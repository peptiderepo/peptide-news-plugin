<?php
declare( strict_types=1 );
/**
 * Fired during plugin deactivation.
 *
 * Clears scheduled cron events. Does NOT drop tables (data preservation).
 *
 * @since 1.0.0
 */
class Peptide_News_Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'peptide_news_cron_fetch' );
	}
}
