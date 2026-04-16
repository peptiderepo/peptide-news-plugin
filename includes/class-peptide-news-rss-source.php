<?php
declare( strict_types=1 );
/**
 * RSS/Atom feed source for the news fetcher.
 *
 * Fetches articles from user-configured RSS feed URLs using the
 * WordPress SimplePie integration. Processes items in memory-friendly
 * chunks and delegates source-name resolution to Source_Resolver.
 *
 * Called by Peptide_News_Fetcher::fetch_all_sources().
 * Depends on WordPress fetch_feed() and Peptide_News_Source_Resolver.
 *
 * @since 2.5.0
 * @see   class-peptide-news-fetcher.php          Orchestrator.
 * @see   class-peptide-news-source-resolver.php   Source name resolution.
 */
class Peptide_News_RSS_Source {

	/**
	 * Fetch articles from all configured RSS feeds.
	 *
	 * Side effects: outbound HTTP requests via SimplePie, logger writes.
	 *
	 * @return array[] Array of article data arrays.
	 */
	public static function fetch(): array {
		$feeds_raw = get_option( 'peptide_news_rss_feeds', '' );
		$feeds     = array_filter( array_map( 'trim', explode( "\n", $feeds_raw ) ) );
		$articles  = array();

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		foreach ( $feeds as $feed_url ) {
			$feed = fetch_feed( esc_url_raw( $feed_url ) );

			if ( is_wp_error( $feed ) ) {
				Peptide_News_Logger::error( 'RSS fetch failed for ' . $feed_url . ': ' . $feed->get_error_message(), 'fetch' );
				continue;
			}

			$max_items  = $feed->get_item_quantity( 50 );
			$chunk_size = 10;

			for ( $offset = 0; $offset < $max_items; $offset += $chunk_size ) {
				$items = $feed->get_items( $offset, $chunk_size );
				if ( empty( $items ) ) {
					break;
				}

				foreach ( $items as $item ) {
					$article = self::process_item( $item, $feed_url );
					if ( ! empty( $article ) ) {
						$articles[] = $article;
					}
				}
			}
		}

		return $articles;
	}

	/**
	 * Process a single RSS/Atom item into an article array.
	 *
	 * @param \SimplePie_Item $item     The feed item.
	 * @param string          $feed_url The originating feed URL.
	 * @return array Article data or empty array on failure.
	 */
	private static function process_item( $item, string $feed_url ): array {
		$pub_date = $item->get_date( 'Y-m-d H:i:s' );
		if ( empty( $pub_date ) ) {
			$pub_date = current_time( 'mysql' );
		}

		$article_url = esc_url_raw( $item->get_permalink() );
		if ( empty( $article_url ) ) {
			return array();
		}

		$source_name = Peptide_News_Source_Resolver::resolve( $item, $feed_url, $article_url );

		return array(
			'source'        => $source_name,
			'source_url'    => $article_url,
			'title'         => sanitize_text_field( $item->get_title() ),
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 40 ),
			'content'       => wp_kses_post( $item->get_content() ),
			'author'        => sanitize_text_field( $item->get_author() ? $item->get_author()->get_name() : '' ),
			'thumbnail_url' => '',
			'published_at'  => $pub_date,
			'categories'    => self::extract_categories( $item ),
			'tags'          => '',
			'language'      => 'en',
		);
	}

	/**
	 * Extract category labels from a SimplePie item.
	 *
	 * @param \SimplePie_Item $item
	 * @return string Comma-separated categories.
	 */
	private static function extract_categories( $item ): string {
		$categories = $item->get_categories();
		if ( empty( $categories ) ) {
			return '';
		}

		$names = array();
		foreach ( $categories as $cat ) {
			$names[] = sanitize_text_field( $cat->get_label() );
		}

		return implode( ', ', array_filter( $names ) );
	}
}
