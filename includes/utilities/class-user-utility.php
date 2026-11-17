<?php
/**
 * Contains the User_Utility class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTime;
use UnexpectedValueException;
use WP_Query;
use WP_User;

/**
 * Class Logify_WP\User_Utility
 *
 * Provides methods for working with users.
 */
class User_Utility extends Object_Utility {

	// =============================================================================================
	// Implementations of base class methods.

	/**
	 * Check if a user exists.
	 *
	 * @param int|string $user_id The ID of the user.
	 * @return bool True if the user exists, false otherwise.
	 */
	public static function exists( int|string $user_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE ID = %d', $wpdb->users, $user_id )
		);
		return $count > 0;
	}

	/**
	 * Get a user by ID.
	 *
	 * @param int|string $user_id The ID of the user.
	 * @return ?WP_User The user object if found, null otherwise.
	 */
	public static function load( int|string $user_id ): ?WP_User {
		// Get the user by ID.
		$user = get_userdata( $user_id );

		// Return the user or null if it doesn't exist.
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Retrieves a name for a given user.
	 *
	 * First preference is the display_name, second preference is the user_login, third preference
	 * is the user_nicename.
	 *
	 * @param int|string $user_id The ID of the user.
	 * @return ?string The username if found, otherwise null.
	 */
	public static function get_name( int|string $user_id ): ?string {
		// Load the user.
		$user = self::load( $user_id );

		// Handle the case where the user no longer exists.
		if ( ! $user ) {
			return null;
		}

		// First preference is the display name, which is their full name.
		if ( ! empty( $user->display_name ) ) {
			return $user->display_name;
		}

		// If that fails, use their login name.
		if ( ! empty( $user->user_login ) ) {
			return $user->user_login;
		}

		// If that fails, use the nice name.
		if ( ! empty( $user->user_nicename ) ) {
			return $user->user_nicename;
		}

		return null;
	}

	/**
	 * Get the core properties of a user.
	 *
	 * @param int|string $user_id The ID of the user.
	 * @return ?Property[] The core properties of the user, or null if not found.
	 */
	public static function get_core_properties( int|string $user_id ): ?array {
		global $wpdb;

		// Load the user.
		$user = self::load( $user_id );

		// Handle the case where the user no longer exists.
		if ( ! $user ) {
			return null;
		}

		// Build the array of properties.
		$props = array();

		// Display name.
		Property::update_array( $props, 'display_name', $wpdb->users, Object_Reference::new_from_wp_object( $user ) );

		// ID.
		Property::update_array( $props, 'ID', $wpdb->users, (int) $user->ID );

		// User login.
		Property::update_array( $props, 'user_login', $wpdb->users, $user->user_login );

		// User email.
		Property::update_array( $props, 'user_email', $wpdb->users, self::get_email_link( $user ) );

		// User registered.
		$user_registered = DateTimes::create_datetime( $user->data->user_registered, 'UTC' );
		Property::update_array( $props, 'user_registered', $wpdb->users, $user_registered );

		return $props;
	}

	/**
	 * If the user hasn't been deleted, get a link to its edit page; otherwise, get a span with
	 * the old title as the link text.
	 *
	 * @param int|string $user_id  The ID of the user.
	 * @param ?string    $old_name The username at the time of the event.
	 * @return string The link or span HTML tag.
	 */
	public static function get_tag( int|string $user_id, ?string $old_name = null ): string {
		// Unknown user.
		if ( ! $user_id && ! $old_name ) {
			return "<span class='logify-wp-object'>Unknown</span>";
		}

		// Load the user.
		$user = $user_id ? self::load( $user_id ) : null;

		// If the user wasn't found, but we have a name, try to load the user by email or username.
		if ( ! $user && $old_name ) {
			if ( Strings::looks_like_email( $old_name ) ) {
				// Load by email.
				$user = self::load_user_by_email( $old_name );
			} else {
				// Load by username.
				$user = self::load_user_by_username( $old_name );
			}
		}

		// If the user exists, get a link.
		if ( $user ) {
			// Get the name.
			$name = self::get_name( $user->ID );

			// Get the user edit URL.
			$url = admin_url( "user-edit.php?user_id={$user->ID}" );

			// Return the link.
			return "<a href='" . esc_url( $url ) . "' class='logify-wp-object'>" . esc_html( $name ) . "</a>";
		}

		// Make a backup name.
		if ( ! $old_name ) {
			$old_name = "User $user_id";
		}

		// The user no longer exists. Construct the 'unknown' span element.
		return "<span class='logify-wp-deleted-object'>" . esc_html( $old_name ) . " (deleted)</span>";
	}

	// =============================================================================================
	// Additional methods.

	/**
	 * Load a user by username.
	 *
	 * @param string $username The username of the user.
	 * @return ?WP_User The user object if found, null otherwise.
	 */
	public static function load_user_by_username( string $username ): ?WP_User {
		$user = get_user_by( 'login', $username );
		return $user ? $user : null;
	}

	/**
	 * Load a user by email address.
	 *
	 * @param string $email The email address of the user.
	 * @return ?WP_User The user object if found, null otherwise.
	 */
	public static function load_user_by_email( string $email ) {
		$user = get_user_by( 'email', $email );
		return $user ? $user : null;
	}

	/**
	 * Get the properties of a user to show in the log.
	 *
	 * @param WP_User|int $user The user object or ID.
	 * @return ?Property[] The properties of the user, or null if not found.
	 */
	public static function get_properties( WP_User|int $user ): ?array {
		global $wpdb;

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = self::load( $user );

			// Handle user not found.
			if ( ! $user ) {
				return null;
			}
		}

		$props = array();

		// Add the base properties.
		foreach ( $user->data as $key => $value ) {
			if ( Property_Repository::is_sensitive_key( (string) $key ) ) {
				Property::update_array( $props, $key, $wpdb->users, Sensitive_Data::REDACTED );
				continue;
			}
			// Process meta values into correct types.
			$value = Types::process_database_value( $key, $value );

			// For whatever reason, the user_registered column in the users table is in UTC,
			// despite not having the same '_gmt' suffix as UTC datetimes in the posts table.
			if ( $key === 'user_registered' ) {
				DateTimes::set_timezone( $value, 'UTC' );
			}

			// Construct the new Property object and add it to the properties array.
			Property::update_array( $props, $key, $wpdb->users, $value );
		}

		// Add the meta properties.
		$usermeta = get_user_meta( $user->ID );
		foreach ( $usermeta as $key => $value ) {
			if ( Property_Repository::is_sensitive_key( (string) $key ) ) {
				Property::update_array( $props, $key, $wpdb->usermeta, Sensitive_Data::REDACTED );
				continue;
			}
			// Check for single.
			self::reduce_to_single( $value );

			// Process meta values into correct types.
			$value = Types::process_database_value( $key, $value );

			// Construct the new Property object and add it to the properties array.
			Property::update_array( $props, $key, $wpdb->usermeta, $value );
		}

		return $props;
	}

	/**
	 * Inspect the IP attribution of the CURRENT admin HTTP request, without
	 * trusting or acting on headers sent by unverified clients.
	 *
	 * This is an administrator diagnostic. It does not alter event attribution
	 * and must never be used to infer trust from a header's mere presence.
	 *
	 * @return array<string, string|bool> Safe, bounded values for admin display.
	 */
	public static function get_ip_diagnostics(): array {
		$read_ip = static function ( string $key ): string {
			$value = $_SERVER[ $key ] ?? '';
			if ( ! is_string( $value ) ) {
				return '';
			}
			$value = trim( $value );
			return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
		};

		$remote = $read_ip( 'REMOTE_ADDR' );
		$server = $read_ip( 'SERVER_ADDR' );
		$cf = $read_ip( 'HTTP_CF_CONNECTING_IP' );
		$cf_ipv6 = $read_ip( 'HTTP_CF_CONNECTING_IPV6' );
		$x_real_ip = $read_ip( 'HTTP_X_REAL_IP' );
		$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
		if ( ! is_string( $xff ) ) {
			$xff = '';
		}
		// XFF is untrusted text; show only a short, printable excerpt.
		$xff = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', substr( $xff, 0, 180 ) );

		$verified_cf = '' !== $remote && self::is_cloudflare_proxy_ip( $remote );
		$custom_trusted = '' !== $remote && self::trust_proxy_headers() && self::is_trusted_proxy_ip( $remote );
		if ( $verified_cf ) {
			$trust = 'cloudflare';
		} elseif ( $custom_trusted ) {
			$trust = 'configured_proxy';
		} else {
			$trust = 'unverified';
		}

		return array(
			'remote_addr'         => $remote,
			'server_addr'         => $server,
			'cf_connecting_ip'    => $cf,
			'cf_connecting_ipv6'  => $cf_ipv6,
			'x_real_ip'          => $x_real_ip,
			'x_forwarded_for'     => trim( $xff ),
			'logify_ip'           => self::get_ip() ?? '',
			'reported_ip'         => self::capture_ip_context()['reported'] ?? '',
			'trust'               => $trust,
			'remote_is_server'    => '' !== $remote && '' !== $server && $remote === $server,
		);
	}

	/**
	 * Retrieves the IP address of the user.
	 *
	 * @return ?string The IP address of the user or null if not found.
	 */
	public static function get_ip(): ?string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		// Zero-config Cloudflare support is safe only when the immediate peer
		// belongs to Cloudflare's published ingress CIDRs. The header itself is NOT
		// evidence of Cloudflare and is ignored on every other connection.
		if ( self::is_cloudflare_proxy_ip( $remote ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IPV6', 'HTTP_CF_CONNECTING_IP' ) as $header ) {
				$visitor = isset( $_SERVER[ $header ] ) ? trim( (string) $_SERVER[ $header ] ) : '';
				if ( filter_var( $visitor, FILTER_VALIDATE_IP ) ) {
					return $visitor;
				}
			}
		}

		// Custom proxies still require explicit trust. In particular, do NOT
		// implicitly trust localhost/server IPs or a request-supplied XFF header.
		if ( ! self::trust_proxy_headers() || ! self::is_trusted_proxy_ip( $remote ) ) {
			return $remote;
		}

		foreach ( self::get_trusted_proxy_headers() as $header ) {
			if ( ! is_string( $header ) || ! preg_match( '/^HTTP_[A-Z0-9_]+$/', $header ) || empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = (string) $_SERVER[ $header ];
			if ( 'HTTP_X_FORWARDED_FOR' === $header ) {
				// Read from the trusted peer backwards. A leftmost entry can be forged.
				$chain = array_map( 'trim', explode( ',', $value ) );
				$hop = $remote;
				foreach ( array_reverse( $chain ) as $candidate ) {
					if ( ! self::is_trusted_proxy_ip( $hop ) || ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
						break;
					}
					$hop = $candidate;
				}
				return $hop;
			}
			$candidate = trim( $value );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		return $remote;
	}

	/**
	 * Capture both the audited connection address and a possible forwarded visitor IP.
	 *
	 * An address in a request header is NOT proof of who connected. For unverified
	 * proxies we retain a separately labeled reported value, never use it as the
	 * authoritative user_ip column, and never use it for access-control decisions.
	 * Capturing here (before Action Scheduler) keeps the original request's evidence.
	 *
	 * @return array{ip:?string,peer:?string,reported:?string,source:string,trust:string}
	 */
	public static function capture_ip_context(): array {
		$remote = self::valid_ip_header( 'REMOTE_ADDR' );
		$server = self::valid_ip_header( 'SERVER_ADDR' );
		$selected = self::get_ip();
		$trust = 'connection';
		$source = 'REMOTE_ADDR';
		$reported = null;

		if ( null === $remote ) {
			return array( 'ip' => null, 'peer' => null, 'reported' => null, 'source' => 'system', 'trust' => 'unknown' );
		}

		if ( self::is_cloudflare_proxy_ip( $remote ) && $selected !== $remote ) {
			$source = self::valid_ip_header( 'HTTP_CF_CONNECTING_IPV6' ) === $selected
				? 'CF-Connecting-IPv6'
				: 'CF-Connecting-IP';
			$trust = 'verified';
		} elseif ( self::trust_proxy_headers() && self::is_trusted_proxy_ip( $remote ) && $selected !== $remote ) {
			$source = 'configured proxy';
			$trust = 'verified';
		} else {
			// No automatic trust for arbitrary public peers or forged forwarded headers.
			// A private peer or PHP's own SERVER_ADDR strongly suggests an intermediate
			// hosting proxy, but the header itself remains unverified.
			$likely_local_proxy = ( null !== $server && $remote === $server ) || ! self::is_public_ip( $remote );
			if ( $likely_local_proxy ) {
				foreach ( array( 'HTTP_CF_CONNECTING_IPV6', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $header ) {
					$candidate = self::valid_ip_header( $header );
					if ( null !== $candidate && self::is_public_ip( $candidate ) && $candidate !== $remote && $candidate !== $server ) {
						$reported = $candidate;
						$source = array( 'HTTP_CF_CONNECTING_IPV6' => 'CF-Connecting-IPv6', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP', 'HTTP_X_REAL_IP' => 'X-Real-IP' )[ $header ];
						break;
					}
				}
				if ( null === $reported ) {
					// XFF can be attacker-controlled: report the nearest public hop,
					// NOT the first/leftmost element in an arbitrary chain.
					$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
					if ( is_string( $xff ) && strlen( $xff ) <= 512 ) {
						$hops = array_map( 'trim', explode( ',', $xff ) );
						if ( count( $hops ) <= 10 ) {
							foreach ( array_reverse( $hops ) as $hop ) {
								if ( filter_var( $hop, FILTER_VALIDATE_IP ) && self::is_public_ip( $hop ) && $hop !== $remote && $hop !== $server ) {
									$reported = $hop;
									$source = 'X-Forwarded-For';
									break;
								}
							}
						}
					}
				}
			}
		}
		return array( 'ip' => $selected, 'peer' => $remote, 'reported' => $reported, 'source' => $source, 'trust' => $trust );
	}

	/** Read a single, valid IP; never use an arbitrary comma-list as a single IP. */
	private static function valid_ip_header( string $name ): ?string {
		$value = $_SERVER[ $name ] ?? null;
		if ( ! is_string( $value ) || strlen( $value ) > 45 ) {
			return null;
		}
		$value = trim( $value );
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}

	/**
	 * Pack bounded, validated attribution into the existing client-IP argument.
	 * This avoids changing every existing Action Scheduler handler's signature.
	 * The token is an internal queue format, never a user-supplied trust assertion.
	 */
	public static function get_ip_context_token(): ?string {
		$context = self::capture_ip_context();
		if ( null === $context['ip'] ) {
			return 'logify-system';
		}
		// The common direct-connection case needs no extra metadata. Preserve
		// the original scalar job format to keep queues small on busy sites.
		if ( 'REMOTE_ADDR' === $context['source'] && null === $context['reported'] ) {
			return $context['ip'];
		}
		$json = json_encode( $context, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? 'logify-ip-v1:' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) : $context['ip'];
	}

	/**
	 * Decode a legacy scalar IP or a captured context token from our own queue.
	 * Corrupt/unexpected input fails closed; no header is trusted during decode.
	 */
	public static function decode_ip_context( ?string $value ): array {
		$fallback = array( 'ip' => null, 'peer' => null, 'reported' => null, 'source' => 'legacy', 'trust' => 'unknown' );
		if ( 'logify-system' === $value ) {
			$fallback['source'] = 'system';
			return $fallback;
		}
		if ( null === $value ) {
			return $fallback;
		}
		if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
			$fallback['ip'] = $value;
			return $fallback;
		}
		$prefix = 'logify-ip-v1:';
		if ( ! str_starts_with( $value, $prefix ) || strlen( $value ) > 768 ) {
			return $fallback;
		}
		$payload = substr( $value, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{10,700}$/D', $payload ) ) {
			return $fallback;
		}
		$json = base64_decode( strtr( $payload, '-_', '+/' ), true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return $fallback;
		}
		foreach ( array( 'ip', 'peer', 'reported' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ( ! is_string( $data[ $key ] ) || ! filter_var( $data[ $key ], FILTER_VALIDATE_IP ) ) ) ) {
				return $fallback;
			}
		}
		if ( ! in_array( $data['trust'] ?? null, array( 'verified', 'connection', 'unknown' ), true ) ||
			! in_array( $data['source'] ?? null, array( 'REMOTE_ADDR', 'CF-Connecting-IP', 'configured proxy', 'CF-Connecting-IPv6', 'X-Real-IP', 'X-Forwarded-For', 'WordPress comment record', 'system' ), true ) ) {
			return $fallback;
		}
		return array_intersect_key( $data, $fallback ) + $fallback;
	}

	/**
	 * Preserve WordPress' own stored comment-author IP as an unverified reported
	 * address when it differs from the captured connection IP. On a proxy, the
	 * comment-author value can be a better lead, but it must never be promoted
	 * to a verified network identity based solely on its presence.
	 */
	public static function get_comment_ip_context_token(\WP_Comment $comment ): string {
		$context = self::capture_ip_context();
		$author_ip = isset( $comment->comment_author_IP ) ? trim( (string) $comment->comment_author_IP ) : '';
		if ( filter_var( $author_ip, FILTER_VALIDATE_IP ) && $author_ip !== $context['ip'] && null === $context['reported'] ) {
			$context['reported'] = $author_ip;
			$context['source'] = 'WordPress comment record';
			$json = json_encode( $context, JSON_UNESCAPED_SLASHES );
			if ( is_string( $json ) ) {
				return 'logify-ip-v1:' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
			}
		}
		return self::get_ip_context_token();
	}

	/** Retrieve compact IP provenance metadata, or an empty array for legacy events. */
	public static function get_event_ip_attribution( Event $event ): array {
		$value = $event->get_meta_val( 'logify_ip_attribution' );
		return is_array( $value ) ? $value : array();
	}

	/** True when event metadata contains a validated but unverified visitor IP. */
	public static function get_reported_event_ip( Event $event ): ?string {
		$value = self::get_event_ip_attribution( $event )['reported'] ?? null;
		return is_string( $value ) && filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}

	/** Present a likely visitor address without misrepresenting it as verified. */
	public static function format_event_ip( Event $event ): string {
		$reported = self::get_reported_event_ip( $event );
		if ( $reported ) {
			return Urls::get_ip_link( $reported ) . ' <span class="logify-wp-ip-unverified" title="' . esc_attr__( 'This IP was reported by an unverified proxy and may be spoofed.', 'logify-wp' ) . '">(' . esc_html__( 'reported, unverified', 'logify-wp' ) . ')</span>';
		}
		return $event->user_ip ? Urls::get_ip_link( $event->user_ip ) : esc_html__( 'Unknown', 'logify-wp' );
	}

	/**
	 * Is this request coming directly from a verified Cloudflare edge?
	 *
	 * CIDRs from Cloudflare's published IPv4/IPv6 ingress lists (Nov 2026):
	 * https://www.cloudflare.com/ips-v4/ and https://www.cloudflare.com/ips-v6/
	 * They can be updated as part of normal releases; no remote request is made
	 * while handling a visitor request.
	 */
	public static function is_cloudflare_proxy_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$networks = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
			'103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
			'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
			'198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
			'2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
			'2c0f:f248::/32',
		);
		return self::ip_in_networks( $ip, $networks );
	}

	/** Match one valid IPv4/IPv6 address against IP or CIDR strings. */
	private static function ip_in_networks( string $ip, array $networks ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		foreach ( $networks as $network ) {
			if ( ! is_string( $network ) ) {
				continue;
			}
			$parts = explode( '/', trim( $network ), 2 );
			$base = inet_pton( $parts[0] );
			if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
				continue;
			}
			$bits = isset( $parts[1] )
				? filter_var( $parts[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => strlen( $base ) * 8 ) ) )
				: strlen( $base ) * 8;
			if ( false === $bits ) {
				continue;
			}
			$whole = intdiv( $bits, 8 );
			$remaining = $bits % 8;
			if ( substr( $packed, 0, $whole ) !== substr( $base, 0, $whole ) ) {
				continue;
			}
			if ( $remaining === 0 ||
				( ( ord( $packed[ $whole ] ) ^ ord( $base[ $whole ] ) ) & ( 0xff << ( 8 - $remaining ) ) ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify the address of the connecting proxy against explicit IPs/CIDRs.
	 * Define LOGIFY_WP_TRUSTED_PROXY_IPS in wp-config.php, or use the filter.
	 * No proxies are trusted automatically, including localhost.
	 */
	public static function is_trusted_proxy_ip( string $ip ): bool {
		$default = defined( 'LOGIFY_WP_TRUSTED_PROXY_IPS' ) ? LOGIFY_WP_TRUSTED_PROXY_IPS : array();
		$trusted = apply_filters( 'logify_wp_trusted_proxy_ips', $default );
		return is_array( $trusted ) && self::ip_in_networks( $ip, $trusted );
	}

	/**
	 * Whether this site is behind a trusted proxy or CDN.
	 *
	 * Off by default. Turn it on with the "Trust proxy headers" setting, or in wp-config.php:
	 *
	 *     define( 'LOGIFY_WP_TRUST_PROXY', true );
	 *
	 * @return bool
	 */
	public static function trust_proxy_headers(): bool {
		if ( defined( 'LOGIFY_WP_TRUST_PROXY' ) ) {
			return (bool) LOGIFY_WP_TRUST_PROXY;
		}

		return Plugin_Settings::get_trust_proxy_headers();
	}

	/**
	 * The request headers to read the visitor's IP address from, in order of preference.
	 *
	 * Only consulted when trust_proxy_headers() is true. Define LOGIFY_WP_TRUST_PROXY as an
	 * array of $_SERVER keys, or use the filter, to narrow this to the single header your proxy
	 * actually sets - that is the safest configuration.
	 *
	 * @return string[]
	 */
	public static function get_trusted_proxy_headers(): array {
		// XFF is processed right-to-left from an authenticated proxy peer.
		// Single-address headers such as CF-Connecting-IP must be explicitly
		// selected by the site owner and overwritten by their trusted proxy.
		$default = array( 'HTTP_X_FORWARDED_FOR' );

		if ( defined( 'LOGIFY_WP_TRUST_PROXY' ) && is_array( LOGIFY_WP_TRUST_PROXY ) ) {
			$default = LOGIFY_WP_TRUST_PROXY;
		}

		/**
		 * Filters the request headers Logify WP will read the visitor IP address from.
		 *
		 * @param string[] $headers The $_SERVER keys to check, in order of preference.
		 */
		$headers = apply_filters( 'logify_wp_trusted_proxy_headers', $default );

		return is_array( $headers ) ? array_values( array_filter( $headers, 'is_string' ) ) : $default;
	}

	/**
	 * Whether the current request carries proxy IP headers.
	 *
	 * Used to warn an administrator that visitor IPs may be recorded as the proxy's address
	 * because header trust is switched off.
	 *
	 * @return bool
	 */
	public static function request_has_proxy_headers(): bool {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an IP is public (not in private/reserved ranges).
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags );
	}

	/**
	 * Describe a user agent string in terms of what made the request.
	 *
	 * Not every event comes from a browser. Anything the plugin records during a cron run, an
	 * Action Scheduler task or a loopback request carries WordPress's own user agent -
	 * "WordPress/6.8; https://example.com" - which reads as though the site itself were the
	 * visitor, and is confusing next to an event about a file. WP-CLI is the same. Those are
	 * now named for what they are, with the raw header kept alongside.
	 *
	 * @param ?string $user_agent The raw User-Agent header.
	 * @return string A human-readable description, or the raw header if it is an ordinary one.
	 */
	public static function describe_user_agent( ?string $user_agent ): string {
		$user_agent = trim( (string) $user_agent );

		if ( $user_agent === '' ) {
			return __( 'Unknown', 'logify-wp' );
		}

		// WordPress's internal HTTP client: cron, Action Scheduler, loopback requests.
		if ( preg_match( '#^WordPress/[\d.]+#i', $user_agent ) ) {
			return sprintf(
				/* translators: %s: the raw user agent string. */
				__( 'WordPress background request (%s)', 'logify-wp' ),
				$user_agent
			);
		}

		if ( stripos( $user_agent, 'WP CLI' ) === 0 || stripos( $user_agent, 'WP-CLI' ) === 0 ) {
			return sprintf(
				/* translators: %s: the raw user agent string. */
				__( 'WP-CLI command line (%s)', 'logify-wp' ),
				$user_agent
			);
		}

		return $user_agent;
	}

	/**
	 * No external IP location lookups are performed in 1.5.0.
	 *
	 * @param string $ip Source IP address (retained in the audit record).
	 * @return null
	 */
	public static function get_location( string $ip ): ?string {
		// Location lookup was retired in 1.5.0. We continue logging source IPs
		// but do not send them to any external geolocation service.
		return null;
	}

	/**
	 * Retrieves the user agent string from the server variables.
	 *
	 * @return ?string The user agent string or null if not found.
	 */
	public static function get_user_agent(): ?string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) )
			: null;
	}

	/**
	 * Retrieves the last login datetime of a user.
	 *
	 * @param WP_User|int $user The user object or ID.
	 * @return ?DateTime The last login datetime of the user or null if not found.
	 */
	public static function get_last_login_datetime( WP_User|int $user ): ?DateTime {
		global $wpdb;

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = self::load( $user );
		}

		// Get the last login datetime from the logify_wp_events table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT when_happened
                FROM %i
                WHERE user_id = %d AND event_type = 'User Login'
                ORDER BY when_happened DESC
                LIMIT 1",
				Event_Repository::get_table_name(),
				$user->ID
			),
			ARRAY_A
		);
		return $record === null ? null : DateTimes::create_datetime( $record['when_happened'] );
	}

	/**
	 * Retrieves the last active datetime of a user.
	 *
	 * @param  WP_User|int $user The user object or ID.
	 * @return ?DateTime The last active datetime of the user or null if not found.
	 */
	public static function get_last_active_datetime( WP_User|int $user ): ?DateTime {
		global $wpdb;

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = self::load( $user );
		}

		// Get the most recent activity_end datetime from the logify_wp_events table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
                FROM %i
                WHERE user_id = %d AND event_type = 'User Active'
                ORDER BY when_happened DESC
                LIMIT 1",
				Event_Repository::get_table_name(),
				$user->ID
			),
			ARRAY_A
		);

		// If we got a record.
		if ( $record !== null ) {
			// Create the Event.
			$event = Event_Repository::load( $record['event_id'] );

			// Return the activity_end datetime if set.
			if ( $event->has_meta( 'activity_end' ) ) {
				return $event->get_meta_val( 'activity_end' );
			}
		}

		return null;
	}

	/**
	 * Get the email address of a user as a mailto link.
	 *
	 * @param WP_User|int|string $user The user object or ID or email address.
	 * @return string The email link.
	 */
	public static function get_email_link( WP_User|int|string $user ) {
		// Get the email address.
		if ( is_int( $user ) ) {
			$user_email = self::load( $user )->user_email;
		} elseif ( $user instanceof WP_User ) {
			$user_email = $user->user_email;
		} else {
			$user_email = $user;
		}

		return '<a href="' . esc_url( 'mailto:' . $user_email ) . '">' . esc_html( $user_email ) . '</a>';
	}

	/**
	 * Get all posts authored by a given user as an array of Object_Reference objects.
	 *
	 * @param WP_User|int $user The user object or ID.
	 * @return Object_Reference[] Array of Object_Reference objects representing the posts.
	 */
	public static function get_posts_by_user( WP_User|int $user ): array {
		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = self::load( $user );
		}

		// Fetch all posts by the user.
		$args = array(
			'author'         => $user->ID,
			'post_type'      => 'any', // Fetch any type of post
			'post_status'    => 'any', // Fetch any posts
			'posts_per_page' => -1, // Fetch all posts
		);

		$query = new WP_Query( $args );
		$posts = $query->posts;

		// Convert the posts to Object_Reference objects.
		$object_references = array();
		foreach ( $posts as $post ) {
			$object_reference    = new Object_Reference( 'post', $post->ID, $post->post_title );
			$object_references[] = $object_reference;
		}

		return $object_references;
	}

	/**
	 * Given a user object, ID, or username, get the user data required to log an event.
	 *
	 * This doesn't necessarily have to be a valid user; it could be one that is deleted or unknown.
	 *
	 * If no user is specified, return null.
	 *
	 * @param null|int|string|Object_Reference|WP_User $user
	 * @return ?array Array with the user's id, name, and roles.
	 * @throws UnexpectedValueException If the object type is invalid.
	 */
	public static function get_user_data( null|int|string|Object_Reference|WP_User $user ): ?array {
		// Default values for the user data.
		$user_id    = 0;
		$user_name  = '';
		$user_roles = array();

		// Extract the user information from the provided value.
		if ( $user === null ) {
			// If no user is specified, default to the current user.
			$user = wp_get_current_user();
		} elseif ( is_int( $user ) || ( is_string( $user ) && Strings::looks_like_int( $user ) ) ) {
			// Given a user ID, load the user object.
			$user_id = (int) $user;
			if ( $user_id > 0 ) {
				$user = self::load( $user_id );
			}
		} elseif ( is_string( $user ) && $user !== '' ) {
			// If a string is provided (which is not an integer), it will be either the login name
			// or email address. Try to load the user.
			$user_name = $user;
			if ( Strings::looks_like_email( $user_name ) ) {
				$user = self::load_user_by_email( $user_name );
			} else {
				$user = self::load_user_by_username( $user_name );
			}
		} elseif ( $user instanceof Object_Reference ) {
			// If an Object_Reference is given, get the user ID and name, and try to load the user
			// object.

			// If the object type is not user, this is invalid.
			if ( $user->type !== 'user' ) {
				throw new UnexpectedValueException( esc_html( "Invalid object type: '{$user->type}'" ) );
			}

			$user_id   = (int) $user->key;
			$user_name = $user->name ?? '';
			if ( $user_id > 0 ) {
				$user = self::load( $user_id );
			}
		}

		// If we have a WP_User object, extract the desired info.
		if ( $user instanceof WP_User ) {
			$user_id    = (int) $user->ID;
			$user_name  = self::get_name( $user_id ) ?? '';
			$user_roles = $user->roles;
		}

		// Return the user data.
		return array(
			'id'     => $user_id,
			'name'   => $user_name,
			'roles'  => empty( $user_roles ) ? array( 'none' ) : $user_roles,
			'ref'    => new Object_Reference( 'user', $user_id, $user_name ),
			'object' => $user instanceof WP_User ? $user : null,
		);
	}
}
