<?php
/**
 * Suggested privacy policy text (Settings → Privacy → Policy guide).
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

final class Privacy {

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add' ) );
	}

	public static function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'This site offers its pages in other languages with Lango7. The translations are made from the site\'s own text and stored in this site\'s database.', 'lango7' ) . '</p>'
			. '<p>' . esc_html__( 'Lango7 does not collect personal data about visitors, does not set cookies, and does not send anything about your visit to another service.', 'lango7' ) . '</p>'
			. '<p>' . esc_html__( 'Translations made with Lango7\'s free translator were made on the site administrator\'s own computer, with the translator built into Google Chrome. The page text was not sent to Lango7 or to a translation service. Chrome downloads its translation model from Google once.', 'lango7' ) . '</p>';
		wp_add_privacy_policy_content( 'Lango7', wp_kses_post( $text ) );
	}
}
