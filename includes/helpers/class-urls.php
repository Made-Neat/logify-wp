<?php
/**
 * Contains the Urls class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logify_WP\Urls
 *
 * This is a utility class containing methods relating to URLs.
 */
class Urls {

	/**
	 * Check if a page exists at the given URL.
	 *
	 * @param mixed $url The URL to check.
	 * @return bool True if the page exists, false otherwise.
	 */
	public static function url_exists( $url ): bool {
		// Only ever fetch ordinary web URLs. Without this, a caller passing user input could
		// make the server request internal services (metadata endpoints, localhost, private
		// ranges) - see https://owasp.org/www-community/attacks/Server_Side_Request_Forgery.
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( $url === '' ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		// A literal private or reserved IP address is never a legitimate target here.
		if ( filter_var( $parts['host'], FILTER_VALIDATE_IP ) &&
			! filter_var( $parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// wp_safe_remote_head() applies WordPress's own HTTP request validation, which blocks
		// requests that resolve to the local network unless the site explicitly allows them.
		// HEAD is used because only the status code is needed.
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 3,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Check if the response code is 200.
		return wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Generates a link to lookup an IP address.
	 *
	 * @param string $ip_address An IP address.
	 * @return string The HTML link to the IP address lookup page.
	 */
	public static function get_ip_link( string $ip_address ): string {
		$esc_ip = esc_html( $ip_address );
		return "<a href='https://whatismyipaddress.com/ip/$esc_ip' target='_blank'>$esc_ip</a>";
	}

	/**
	 * If a given URL doesn't have a scheme, add one.
	 *
	 * @param string $url The URL to fix.
	 * @return string The fixed URL.
	 */
	public static function fix_scheme( string $url ): string {
		// Check if the URL has a scheme already.
		if ( preg_match( '/^https?:\/\//', $url ) ) {
			return $url;
		}

		// Try https first, as that's preferred.
		$https_url = 'https://' . $url;

		// Check if it exists.
		if ( self::url_exists( $https_url ) ) {
			return $https_url;
		}

		// If https doesn't work, fall back to http.
		return 'http://' . $url;
	}
}
