<?php
declare( strict_types=1 );
/**
 * Plugin Logger.
 *
 * Provides structured logging to a custom database table with levels,
 * contexts, and an admin-facing viewer with AJAX pagination and clear.
 *
 * @since 2.1.0
 */
class Peptide_News_Logger {

	/** Log level constants. */
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';
	const LEVEL_DEBUG   = 'debug';

	/** Maximum rows kept in the log table (auto-pruned on write). */
	const MAX_ROWS = 2000;

	/**
	 * Return the log table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'peptide_news_log';
	}

	/* ------------------------------------------------------------------
	 * Writing
	 * ----------------------------------------------------------------*/

	/**
	 * Write a log entry.
	 *
	 * @param string $level   One of the LEVEL_* constants.
	 * @param string $message Human-readable message.
	 * @param string $context Category tag (fetch, llm, admin, cron, general).
	 */
	public static function log( string $level, string $message, string $context = 'general' ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'level'      => $level,
				'context'    => $context,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Auto-prune old entries.
		self::maybe_prune();
	}

	/** Convenience: info level. */
	public static function info( string $message, string $context = 'general' ): void {
		self::log( self::LEVEL_INFO, $message, $context );
	}

	/** Convenience: warning level. */
	public static function warning( string $message, string $context = 'general' ): void {
		self::log( self::LEVEL_WARNING, $message, $context );
	}

	/** Convenience: error level. */
	public static function error( string $message, string $context = 'general' ): void {
		self::log( self::LEVEL_ERROR, $message, $context );
	}

	/** Convenience: debug level (only writes when WP_DEBUG is on). */
	public static function debug( string $message, string $context = 'general' ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::log( self::LEVEL_DEBUG, $message, $context );
		}
	}

	/* ------------------------------------------------------------------
	 * Reading
	 * ----------------------------------------------------------------*/

	/**
	 * Retrieve log entries.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $level   Filter by level.
	 *     @type string $context Filter by context.
	 *     @type int    $limit   Number of rows.
	 *     @type int    $offset  Pagination offset.
	 * }
	 * @return array { 'rows' => array, 'total' => int }
	 */
	public static function get_logs( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'level'   => '',
			'context' => '',
			'limit'   => 50,
			'offset'  => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['level'] ) ) {
			$where[] = 'level = %s';
			$vals[]  = $args['level'];
		}
		if ( ! empty( $args['context'] ) ) {
			$where[] = 'context = %s';
			$vals[]  = $args['context'];
		}

		$where_sql = implode( ' AND ', $where );

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = empty( $vals )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) );

		// Rows.
		$order_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$vals[]    = absint( $args['limit'] );
		$vals[]    = absint( $args['offset'] );
		$rows      = $wpdb->get_results( $wpdb->prepare( $order_sql, $vals ) );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/* ------------------------------------------------------------------
	 * Maintenance
	 * ----------------------------------------------------------------*/

	/**
	 * Remove rows beyond the MAX_ROWS cap.
	 */
	private static function maybe_prune(): void {
		global $wpdb;
		$table = self::table();

		// Only prune every ~50 writes (probabilistic).
		if ( wp_rand( 1, 50 ) !== 1 ) {
			return;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count > self::MAX_ROWS ) {
			$delete_count = $count - self::MAX_ROWS;
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} ORDER BY id ASC LIMIT %d",
				$delete_count
			) );
		}
	}

	/**
	 * Clear all log entries.
	 */
	public static function clear(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE " . self::table() );
	}

	/* ------------------------------------------------------------------
	 * AJAX handlers
	 * ----------------------------------------------------------------*/

	/**
	 * AJAX: fetch paginated log entries.
	 */
	public static function ajax_get_logs(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$level   = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : '';
		$context = isset( $_POST['context'] ) ? sanitize_text_field( $_POST['context'] ) : '';
		$per_page = 50;

		$result = self::get_logs( array(
			'level'   => $level,
			'context' => $context,
			'limit'   => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
		) );

		wp_send_json_success( array(
			'rows'       => $result['rows'],
			'total'      => $result['total'],
			'page'       => $page,
			'total_pages' => ceil( $result['total'] / $per_page ),
		) );
	}

	/**
	 * AJAX: clear all log entries.
	 */
	public static function ajax_clear_logs(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		self::clear();
		self::info( 'Log cleared by admin.', 'admin' );

		wp_send_json_success( array( 'message' => 'Log cleared.' ) );
	}

	/* ------------------------------------------------------------------
	 * Schema
	 * ----------------------------------------------------------------*/

	/**
	 * Create the log table (called from activator / upgrade check).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			context VARCHAR(30) NOT NULL DEFAULT 'general',
			message TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level (level),
			KEY idx_context (context),
			KEY idx_created (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
