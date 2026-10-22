<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Global (site-wide) options: USPS credentials and behaviour that is not per shipping zone.
 */
class Settings {
	const OPTION = 'busr_settings';

	public static function defaults() {
		return array(
			'consumer_key'    => '',
			'consumer_secret' => '',
			'sandbox'         => 'no',
			'price_type'      => 'RETAIL',
			'cache_hours'     => 24,
			'quota_ceiling'   => 55,
			'debug'           => 'no',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$clean = self::all();
		foreach ( $values as $k => $v ) {
			switch ( $k ) {
				case 'consumer_key':
				case 'consumer_secret':
					$clean[ $k ] = trim( sanitize_text_field( $v ) );
					break;
				case 'sandbox':
				case 'debug':
					$clean[ $k ] = 'yes' === $v ? 'yes' : 'no';
					break;
				case 'price_type':
					$clean[ $k ] = 'COMMERCIAL' === $v ? 'COMMERCIAL' : 'RETAIL';
					break;
				case 'cache_hours':
					$clean[ $k ] = max( 1, min( 168, (int) $v ) );
					break;
				case 'quota_ceiling':
					$clean[ $k ] = max( 5, min( 100000, (int) $v ) );
					break;
			}
		}
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	public static function has_credentials() {
		$s = self::all();
		return '' !== $s['consumer_key'] && '' !== $s['consumer_secret'];
	}

	/** Base URL for the USPS APIs. Filterable so tests can point at a mock server. */
	public static function api_base() {
		$base = 'yes' === self::get( 'sandbox' ) ? 'https://apis-tem.usps.com' : 'https://apis.usps.com';
		return untrailingslashit( apply_filters( 'busr_api_base', $base ) );
	}
}
