<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Rate cache + hourly quota guard.
 *
 * USPS grants every app a default quota of 60 calls per hour per API. A busy checkout can burn that in minutes, and
 * once it is gone every customer sees "no shipping methods". So: identical packages are served from cache for
 * `cache_hours`, a stale copy is kept for 7 more days and served whenever USPS errors or the quota is spent, and the
 * number of live calls per clock hour is counted so the plugin stops calling USPS before USPS starts refusing.
 */
class Cache {
	const STALE_DAYS = 7;
	const STATS      = 'busr_stats';

	/** Build a cache key from the request payload, rounding weight/dims so near-identical carts share an entry. */
	public static function key( array $payload ) {
		$p = $payload;
		foreach ( array( 'weight' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$p[ $k ] = round( (float) $p[ $k ] * 10 ) / 10; }
		}
		foreach ( array( 'length', 'width', 'height' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$p[ $k ] = round( (float) $p[ $k ] * 2 ) / 2; }
		}
		unset( $p['mailingDate'] );
		ksort( $p );
		return 'busr_r_' . self::generation() . '_' . md5( wp_json_encode( $p ) );
	}

	/**
	 * @return array|null ['rates' => array, 'fresh' => bool] or null when nothing is cached.
	 */
	public static function get( $key, $allow_stale = false ) {
		$row = get_transient( $key );
		if ( ! is_array( $row ) || ! isset( $row['rates'], $row['fresh_until'] ) ) {
			return null;
		}
		$fresh = time() <= (int) $row['fresh_until'];
		if ( ! $fresh && ! $allow_stale ) {
			return null;
		}
		return array(
			'rates' => $row['rates'],
			'fresh' => $fresh,
		);
	}

	public static function set( $key, array $rates ) {
		$hours = (int) Settings::get( 'cache_hours' );
		set_transient(
			$key,
			array(
				'rates'       => $rates,
				'fresh_until' => time() + $hours * HOUR_IN_SECONDS,
				'stored'      => time(),
			),
			$hours * HOUR_IN_SECONDS + self::STALE_DAYS * DAY_IN_SECONDS
		);
	}

	/**
	 * Cache generation: part of every rate key. "Clear rate cache" bumps it, so all earlier entries stop matching at once
	 * and expire on their own. Works the same with or without a persistent object cache (where transients never touch
	 * the options table), and never flushes anything that isn't ours.
	 */
	public static function generation() {
		return (int) get_option( 'busr_cache_gen', 1 );
	}

	public static function flush() {
		update_option( 'busr_cache_gen', self::generation() + 1, false );
	}

	// ---- hourly quota -------------------------------------------------------------------------------------

	/** Each USPS API product has its own hourly quota, so calls are counted per bucket ('' = prices). */
	private static function hour_key( $bucket = '' ) {
		return 'busr_calls_' . ( $bucket ? $bucket . '_' : '' ) . gmdate( 'YmdH' );
	}

	public static function calls_this_hour( $bucket = '' ) {
		return (int) get_transient( self::hour_key( $bucket ) );
	}

	/** True when another live call is allowed under the configured ceiling. */
	public static function quota_available( $bucket = '' ) {
		return self::calls_this_hour( $bucket ) < (int) Settings::get( 'quota_ceiling' );
	}

	public static function count_call( $bucket = '' ) {
		$k = self::hour_key( $bucket );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, 2 * HOUR_IN_SECONDS );
	}

	// ---- lightweight stats for the setup page ----------------------------------------------------------------

	public static function bump( $what ) {
		$s   = get_option( self::STATS, array() );
		$day = gmdate( 'Y-m-d' );
		if ( ! isset( $s['day'] ) || $s['day'] !== $day ) {
			$s = array( 'day' => $day );
		}
		$s[ $what ] = isset( $s[ $what ] ) ? $s[ $what ] + 1 : 1;
		if ( 'live_ok' === $what ) {
			$s['last_ok'] = time();
		}
		if ( 'live_error' === $what ) {
			$s['last_error'] = time();
		}
		update_option( self::STATS, $s, false );
	}

	public static function stats() {
		$s = get_option( self::STATS, array() );
		return is_array( $s ) ? $s : array();
	}
}
