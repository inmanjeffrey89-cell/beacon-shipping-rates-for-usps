<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Turns a WooCommerce shipping package (cart contents + destination) into USPS request payloads.
 * Units: USPS wants pounds and inches; WooCommerce stores whatever the store chose, so everything is converted.
 * USPS parcels max out at 70 lb, so heavy carts are split.
 */
class Packer {
	const MAX_LB = 70.0;

	/**
	 * @param array $package  WC shipping package.
	 * @param array $opts     origin_zip, strategy (combined|per_line|per_item), default_weight, default_length, default_width, default_height, price_type.
	 * @return array ['intl' => bool, 'payloads' => array of USPS request bodies]
	 */
	public static function build( array $package, array $opts ) {
		$dest    = $package['destination'];
		$country = isset( $dest['country'] ) ? strtoupper( $dest['country'] ) : 'US';
		$intl    = ! in_array( $country, array( 'US', 'PR', 'VI', 'GU', 'AS', 'MP', 'UM' ), true );
		$boxes   = self::boxes( $package['contents'], $opts );
		$out     = array();
		foreach ( $boxes as $b ) {
			$p = array(
				'originZIPCode' => self::zip5( $opts['origin_zip'] ),
				'weight'        => max( 0.01, round( $b['weight'], 2 ) ),
				'length'        => max( 0.1, round( $b['length'], 2 ) ),
				'width'         => max( 0.1, round( $b['width'], 2 ) ),
				'height'        => max( 0.1, round( $b['height'], 2 ) ),
				'mailClass'     => 'ALL',
				'priceType'     => isset( $opts['price_type'] ) ? $opts['price_type'] : 'RETAIL',
			);
			if ( $intl ) {
				$p['destinationCountryCode'] = $country;
				$p['foreignPostalCode']      = isset( $dest['postcode'] ) ? preg_replace( '/[^A-Za-z0-9 -]/', '', $dest['postcode'] ) : '';
			} else {
				$p['destinationZIPCode']           = self::zip5( isset( $dest['postcode'] ) ? $dest['postcode'] : '' );
				$p['processingCategory']           = 'MACHINABLE';
				$p['rateIndicator']                = 'SP';
				$p['destinationEntryFacilityType'] = 'NONE';
			}
			$out[] = $p;
		}
		return array(
			'intl'     => $intl,
			'payloads' => $out,
		);
	}

	/** Group cart lines into boxes according to the packing strategy. Each box: weight(lb), length/width/height(in). */
	public static function boxes( array $contents, array $opts ) {
		$strategy = isset( $opts['strategy'] ) ? $opts['strategy'] : 'combined';
		$items    = array();
		foreach ( $contents as $line ) {
			if ( empty( $line['data'] ) || ! is_object( $line['data'] ) ) {
				continue;
			}
			$product = $line['data'];
			if ( ! $product->needs_shipping() ) {
				continue;
			}
			$qty = max( 1, (int) $line['quantity'] );
			$w   = self::lb( $product->get_weight(), $opts['default_weight'] );
			$l   = self::inch( $product->get_length(), $opts['default_length'] );
			$wd  = self::inch( $product->get_width(), $opts['default_width'] );
			$h   = self::inch( $product->get_height(), $opts['default_height'] );
			$items[] = array(
				'qty'    => $qty,
				'weight' => $w,
				'dims'   => array( $l, $wd, $h ),
			);
		}
		if ( ! $items ) {
			return array();
		}
		$boxes = array();
		if ( 'per_item' === $strategy ) {
			foreach ( $items as $it ) {
				for ( $i = 0; $i < $it['qty']; $i++ ) {
					$boxes[] = self::box( $it['weight'], $it['dims'] );
				}
			}
		} elseif ( 'per_line' === $strategy ) {
			foreach ( $items as $it ) {
				$per_box   = $it['weight'] > 0 ? max( 1, (int) floor( self::MAX_LB / $it['weight'] ) ) : $it['qty'];
				$remaining = $it['qty'];
				while ( $remaining > 0 ) {
					$n = min( $per_box, $remaining );
					// Stack units along the shortest side so the box grows the way a real carton would.
					$dims = $it['dims'];
					sort( $dims );
					$dims[0] *= $n;
					$boxes[]    = self::box( $it['weight'] * $n, $dims );
					$remaining -= $n;
				}
			}
		} else { // combined: one carton holding everything. Volume adds up; the carton grows like a real box, not a tower.
			$weight = 0;
			$volume = 0;
			$big    = array( 0, 0, 0 );
			foreach ( $items as $it ) {
				$weight += $it['weight'] * $it['qty'];
				$d       = $it['dims'];
				rsort( $d );
				$volume += $d[0] * $d[1] * $d[2] * $it['qty'];
				if ( $d[0] * $d[1] * $d[2] > $big[0] * $big[1] * $big[2] ) {
					$big = $d;
				}
			}
			// Split by the 70 lb cap into identical cartons; each carton is the largest item scaled up by the cube root
			// of the volume ratio, so 8 identical items make a carton twice as long, wide and tall - not 8x tall.
			$n       = max( 1, (int) ceil( $weight / self::MAX_LB ) );
			$big_vol = max( 0.001, $big[0] * $big[1] * $big[2] );
			$k       = max( 1.0, pow( ( $volume / $n ) / $big_vol, 1 / 3 ) );
			for ( $i = 0; $i < $n; $i++ ) {
				$boxes[] = self::box( $weight / $n, array( $big[0] * $k, $big[1] * $k, $big[2] * $k ) );
			}
		}
		return $boxes;
	}

	private static function box( $weight, array $dims ) {
		rsort( $dims );
		return array(
			'weight' => min( self::MAX_LB, (float) $weight ),
			'length' => (float) $dims[0],
			'width'  => (float) $dims[1],
			'height' => (float) $dims[2],
		);
	}

	private static function lb( $value, $default ) {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return (float) $default;
		}
		return (float) wc_get_weight( $value, 'lbs' );
	}

	private static function inch( $value, $default ) {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return (float) $default;
		}
		return (float) wc_get_dimension( $value, 'in' );
	}

	public static function zip5( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		return substr( $digits, 0, 5 );
	}
}
