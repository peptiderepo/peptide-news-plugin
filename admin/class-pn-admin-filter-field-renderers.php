<?php
declare( strict_types=1 );
/**
 * Settings field renderers for the Content Filter section.
 *
 * Renders filter enable/disable, sensitivity, LLM classification,
 * and keyword/domain customization fields.
 *
 * Called by: Peptide_News_Admin_Settings::register_settings() via add_settings_field().
 * Dependencies: Peptide_News_Content_Filter (for default keyword/domain text).
 *
 * @since 2.6.0
 * @see   admin/class-pn-admin-field-renderers.php — Other settings field renderers.
 * @see   admin/class-pn-admin-settings.php        — Settings registration.
 */
class Peptide_News_Admin_Filter_Field_Renderers {

	public static function render_filter_enabled_field(): void {
		$value = get_option( 'peptide_news_filter_enabled', 1 );
		printf(
			'<label><input type="checkbox" name="peptide_news_filter_enabled" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Filter out ads, press releases, and promotional content during fetch', 'peptide-news' )
		);
	}

	public static function render_filter_sensitivity_field(): void {
		$value   = get_option( 'peptide_news_filter_sensitivity', 'moderate' );
		$options = array(
			'strict'   => __( 'Strict — block aggressively (may catch some legitimate articles)', 'peptide-news' ),
			'moderate' => __( 'Moderate — balanced filtering (recommended)', 'peptide-news' ),
			'lenient'  => __( 'Lenient — only block obvious promotional content', 'peptide-news' ),
		);

		echo '<select name="peptide_news_filter_sensitivity" id="peptide_news_filter_sensitivity">';
		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function render_filter_llm_enabled_field(): void {
		$value = get_option( 'peptide_news_filter_llm_enabled', 0 );
		printf(
			'<label><input type="checkbox" name="peptide_news_filter_llm_enabled" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Use LLM to classify borderline articles (requires AI Analysis to be enabled)', 'peptide-news' )
		);
	}

	public static function render_filter_llm_model_field(): void {
		$value = get_option( 'peptide_news_filter_llm_model', '' );
		printf(
			'<input type="text" name="peptide_news_filter_llm_model" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'OpenRouter model for content classification. Leave blank to use the Keywords Model above.', 'peptide-news' ) . '</p>';
	}

	public static function render_filter_title_keywords_field(): void {
		$value = get_option( 'peptide_news_filter_title_keywords', '' );
		$placeholder = class_exists( 'Peptide_News_Content_Filter' )
			? Peptide_News_Content_Filter::get_default_title_keywords_text()
			: '';
		printf(
			'<textarea name="peptide_news_filter_title_keywords" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'One keyword/phrase per line. Articles with these in the title are blocked. Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
	}

	public static function render_filter_body_keywords_field(): void {
		$value = get_option( 'peptide_news_filter_body_keywords', '' );
		$placeholder = class_exists( 'Peptide_News_Content_Filter' )
			? Peptide_News_Content_Filter::get_default_body_keywords_text()
			: '';
		printf(
			'<textarea name="peptide_news_filter_body_keywords" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'One keyword/phrase per line. Multiple matches in article body trigger filtering (threshold depends on sensitivity). Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
	}

	public static function render_filter_blocked_domains_field(): void {
		$value = get_option( 'peptide_news_filter_blocked_domains', '' );
		$placeholder = class_exists( 'Peptide_News_Content_Filter' )
			? Peptide_News_Content_Filter::get_default_blocked_domains_text()
			: '';
		printf(
			'<textarea name="peptide_news_filter_blocked_domains" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'One domain per line. Articles from these sources are always blocked. Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
	}
}
