<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * USPS APIs v3 client: OAuth2 client-credentials token + Domestic/International total-rates search.
 * The merchant's own consumer key/secret (USPS Developer Portal → Add App) are used; nothing is proxied.
 */
class API {
	const TOKEN_TRANSIENT = 'busr_token';
	const DOMESTIC        = '/prices/v3/total-rates/search';
	const INTERNATIONAL   = '/international-prices/v3/total-rates/search';

	/** Rate indicators we treat as "a normal parcel": single-piece and dimensional. Flat-rate/cubic/presort need special packaging or contracts. */
	const PARCEL_INDICATORS = array( 'SP', 'DR', 'DN', 'PA' );

	/** @return string|\WP_Error bearer token */
	public static function token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		if ( ! Settings::has_credentials() ) {
			return new \WP_Error( 'busr_no_credentials', __( 'USPS consumer key and secret are not set yet. Open WooCommerce → USPS Rates to add them.', 'beacon-shipping-rates-for-usps' ) );
		}
		$s   = Settings::all();
		$res = wp_remote_post(
			Settings::api_base() . '/oauth2/v3/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'client_id'     => $s['consumer_key'],
						'client_secret' => $s['consumer_secret'],
						'grant_type'    => 'client_credentials',
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			self::log( 'token request failed: ' . $res->get_error_message() );
			return new \WP_Error( 'busr_network', __( 'Could not reach USPS. Check that your server allows outbound HTTPS.', 'beacon-shipping-rates-for-usps' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$detail = is_array( $body ) && ! empty( $body['error_description'] ) ? $body['error_description'] : ( is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : '' );
			self::log( "token request HTTP {$code}: {$detail}" );
			return new \WP_Error( 'busr_auth', self::explain( $code, $detail, true ) );
		}
		$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 28800;
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, $ttl - 120 ) );
		return $body['access_token'];
	}

	/**
	 * Fetch rates for one package, going through the cache and the hourly quota guard.
	 *
	 * @param array $payload  USPS request body (see Packer::payload()).
	 * @param bool  $intl     International endpoint?
	 * @return array|\WP_Error list of ['mailClass','productName','sku','price','indicator'] (cheapest per class), or error.
	 */
	public static function rates( array $payload, $intl = false ) {
		/** Pro hook: which USPS rate indicators count as offerable (adds flat-rate boxes etc.). */
		$indicators = apply_filters( 'busr_parcel_indicators', self::PARCEL_INDICATORS, $intl );
		$key        = Cache::key( $payload + array( '_intl' => $intl ? 1 : 0, '_ind' => implode( ',', $indicators ) ) );
		$hit = Cache::get( $key );
		if ( $hit ) {
			Cache::bump( 'cache_hit' );
			return $hit['rates'];
		}
		if ( ! Cache::quota_available() ) {
			Cache::bump( 'quota_skip' );
			$stale = Cache::get( $key, true );
			if ( $stale ) {
				return $stale['rates'];
			}
			return new \WP_Error( 'busr_quota', __( 'The USPS hourly call quota is used up and no cached rate exists for this package.', 'beacon-shipping-rates-for-usps' ) );
		}
		$live = self::request( $intl ? self::INTERNATIONAL : self::DOMESTIC, $payload );
		if ( is_wp_error( $live ) ) {
			$stale = Cache::get( $key, true );
			if ( $stale ) {
				Cache::bump( 'stale_served' );
				return $stale['rates'];
			}
			return $live;
		}
		$rates = self::parse( $live, $indicators );
		Cache::set( $key, $rates );
		return $rates;
	}

	/** Raw authenticated POST; retries once on a 401 with a fresh token. */
	public static function request( $endpoint, array $payload, $retry = true ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( empty( $payload['mailingDate'] ) ) {
			$payload['mailingDate'] = gmdate( 'Y-m-d' );
		}
		Cache::count_call();
		$res = wp_remote_post(
			Settings::api_base() . $endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Cache::bump( 'live_error' );
			self::log( 'rate request failed: ' . $res->get_error_message() );
			return new \WP_Error( 'busr_network', __( 'Could not reach USPS.', 'beacon-shipping-rates-for-usps' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code && $retry ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return self::request( $endpoint, $payload, false );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			Cache::bump( 'live_error' );
			$detail = self::detail( $body );
			self::log( "rate request HTTP {$code} " . wp_json_encode( $payload ) . ' -> ' . $detail );
			return new \WP_Error( 'busr_http_' . $code, self::explain( $code, $detail, false ) );
		}
		Cache::bump( 'live_ok' );
		self::log( 'rate request ok ' . wp_json_encode( $payload ), 'debug' );
		return $body;
	}

	/** Reduce a total-rates response to the cheapest normal-parcel price per mail class. */
	public static function parse( array $body, $indicators = null ) {
		$indicators = $indicators ? $indicators : self::PARCEL_INDICATORS;
		$best       = array();
		foreach ( (array) ( $body['rateOptions'] ?? array() ) as $opt ) {
			foreach ( (array) ( $opt['rates'] ?? array() ) as $r ) {
				if ( empty( $r['mailClass'] ) ) {
					continue;
				}
				$ind = isset( $r['rateIndicator'] ) ? $r['rateIndicator'] : 'SP';
				if ( ! in_array( $ind, $indicators, true ) ) {
					continue;
				}
				$price = isset( $r['price'] ) ? (float) $r['price'] : ( isset( $opt['totalBasePrice'] ) ? (float) $opt['totalBasePrice'] : 0 );
				if ( $price <= 0 ) {
					continue;
				}
				$c = $r['mailClass'];
				if ( ! isset( $best[ $c ] ) || $price < $best[ $c ]['price'] ) {
					$best[ $c ] = array(
						'mailClass'   => $c,
						// USPS sends an empty productName for some classes (Media Mail, Library Mail).
						'productName' => ! empty( $r['productName'] ) ? (string) $r['productName'] : ucwords( strtolower( str_replace( '_', ' ', $c ) ) ),
						'sku'         => isset( $r['SKU'] ) ? (string) $r['SKU'] : '',
						'price'       => $price,
						'indicator'   => $ind,
					);
				}
			}
		}
		return array_values( $best );
	}

	/** USPS error bodies look like {error:{code,message,errors:[{title,detail,source}]}}; the useful text is in errors[].detail. */
	private static function detail( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}
		$parts = array();
		if ( ! empty( $body['error']['errors'] ) && is_array( $body['error']['errors'] ) ) {
			foreach ( array_slice( $body['error']['errors'], 0, 3 ) as $e ) {
				$t = trim( ( $e['title'] ?? '' ) . ' ' . ( $e['detail'] ?? '' ) );
				if ( '' !== $t ) {
					$parts[] = $t;
				}
			}
		}
		if ( ! $parts ) {
			$m = $body['error']['message'] ?? ( $body['message'] ?? ( $body['error_description'] ?? '' ) );
			if ( is_string( $m ) && '' !== $m ) {
				$parts[] = $m;
			}
		}
		return implode( '; ', $parts );
	}

	/** Turn USPS HTTP codes into sentences a store owner can act on. */
	private static function explain( $code, $detail, $is_token ) {
		switch ( $code ) {
			case 400:
				return sprintf( /* translators: %s USPS error detail */ __( 'USPS rejected the request: %s', 'beacon-shipping-rates-for-usps' ), $detail ? $detail : __( 'check the package weight, dimensions and ZIP codes.', 'beacon-shipping-rates-for-usps' ) );
			case 401:
				return $is_token
					? __( 'USPS did not accept the consumer key and secret. Copy them again from your app in the USPS Developer Portal (they are long, and the secret is only shown once).', 'beacon-shipping-rates-for-usps' )
					: __( 'USPS rejected the access token; it was refreshed, please try again.', 'beacon-shipping-rates-for-usps' );
			case 403:
				return __( 'USPS says this app is not allowed to use the Prices API. In the USPS Developer Portal, open your app and make sure the Domestic Prices and International Prices products are added and approved.', 'beacon-shipping-rates-for-usps' );
			case 429:
				return __( 'USPS hourly quota exceeded (the default is 60 calls per hour per API). Cached rates are being used where possible. To raise the quota, submit a service request on the USPS Developer Portal "Email Us" page.', 'beacon-shipping-rates-for-usps' );
			case 500:
			case 502:
			case 503:
			case 504:
				return __( 'USPS returned a server error; their API is having trouble. Cached or fallback rates are being used.', 'beacon-shipping-rates-for-usps' );
		}
		return sprintf( /* translators: 1: HTTP code, 2: detail */ __( 'USPS returned HTTP %1$d. %2$s', 'beacon-shipping-rates-for-usps' ), $code, $detail );
	}

	public static function log( $msg, $level = 'error' ) {
		if ( 'debug' === $level && 'yes' !== Settings::get( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $msg, array( 'source' => 'beacon-shipping-rates-for-usps' ) );
		}
	}
}
