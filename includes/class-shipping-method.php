<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * The "USPS (Beacon)" shipping method: one instance per shipping zone, live USPS rates per enabled service.
 */
class Method extends \WC_Shipping_Method {

	public static function domestic_services() {
		return array(
			'USPS_GROUND_ADVANTAGE' => __( 'Ground Advantage', 'beacon-shipping-rates-for-usps' ),
			'PRIORITY_MAIL'         => __( 'Priority Mail', 'beacon-shipping-rates-for-usps' ),
			'PRIORITY_MAIL_EXPRESS' => __( 'Priority Mail Express', 'beacon-shipping-rates-for-usps' ),
			'MEDIA_MAIL'            => __( 'Media Mail (books, media only)', 'beacon-shipping-rates-for-usps' ),
		);
	}

	public static function international_services() {
		return array(
			'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE' => __( 'First-Class Package International', 'beacon-shipping-rates-for-usps' ),
			'PRIORITY_MAIL_INTERNATIONAL'               => __( 'Priority Mail International', 'beacon-shipping-rates-for-usps' ),
			'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'       => __( 'Priority Mail Express International', 'beacon-shipping-rates-for-usps' ),
		);
	}

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'beacon_usps';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'USPS (Beacon)', 'beacon-shipping-rates-for-usps' );
		$this->method_description = __( 'Live USPS rates from the USPS APIs, cached to respect the USPS hourly quota, with a fallback so checkout never breaks. Set your USPS API keys under WooCommerce → USPS Rates.', 'beacon-shipping-rates-for-usps' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();
		$this->title      = $this->get_option( 'title', __( 'USPS', 'beacon-shipping-rates-for-usps' ) );
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );
		$this->enabled    = 'yes';
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$store_zip = get_option( 'woocommerce_store_postcode', '' );
		$wu        = get_option( 'woocommerce_weight_unit', 'lbs' );
		$du        = get_option( 'woocommerce_dimension_unit', 'in' );
		$this->instance_form_fields = array(
			'title'             => array(
				'title'       => __( 'Method title', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers as a prefix for each USPS service.', 'beacon-shipping-rates-for-usps' ),
				'default'     => __( 'USPS', 'beacon-shipping-rates-for-usps' ),
				'desc_tip'    => true,
			),
			'origin_zip'        => array(
				'title'       => __( 'Ship-from ZIP code', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'text',
				'description' => __( 'Where packages ship from. Defaults to the store address postcode.', 'beacon-shipping-rates-for-usps' ),
				'default'     => $store_zip,
				'placeholder' => '90210',
				'desc_tip'    => true,
			),
			'domestic_services' => array(
				'title'   => __( 'Domestic services', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => self::domestic_services(),
				'default' => array( 'USPS_GROUND_ADVANTAGE', 'PRIORITY_MAIL', 'PRIORITY_MAIL_EXPRESS' ),
				'description' => __( 'Media Mail is only legal for books, printed music and recorded media — enable it only if that is all you sell.', 'beacon-shipping-rates-for-usps' ),
			),
			'intl_services'     => array(
				'title'   => __( 'International services', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => self::international_services(),
				'default' => array( 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE', 'PRIORITY_MAIL_INTERNATIONAL' ),
			),
			'tax_status'        => array(
				'title'   => __( 'Tax status', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable', 'beacon-shipping-rates-for-usps' ),
					'none'    => _x( 'None', 'Tax status', 'beacon-shipping-rates-for-usps' ),
				),
			),
			'media_class'       => array(
				'title'       => __( 'Media Mail only for shipping class', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'options'     => self::shipping_class_options(),
				'default'     => '',
				'description' => __( 'Media Mail is only legal for books, printed music and recorded media. Pick the shipping class you give those products; Media Mail is offered only when every item in the cart has it. Leave as "any item" only if that is all you sell.', 'beacon-shipping-rates-for-usps' ),
			),
			'handling_days'     => array(
				'title'       => __( 'Handling time (business days)', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'number',
				'default'     => '1',
				'description' => __( 'Days between an order and the package leaving you. Used for delivery-date estimates (Pro).', 'beacon-shipping-rates-for-usps' ),
				'desc_tip'    => true,
			),
			'cutoff_hour'       => array(
				'title'       => __( 'Same-day cutoff (hour, 0–23)', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'number',
				'default'     => '14',
				'description' => __( 'Orders after this hour (store time) count as placed the next business day for delivery-date estimates.', 'beacon-shipping-rates-for-usps' ),
				'desc_tip'    => true,
			),
			'packing'           => array(
				'title'       => __( 'Packing', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'select',
				'options'     => array(
					'combined' => __( 'One box: total weight, footprint of the largest item (typical small store)', 'beacon-shipping-rates-for-usps' ),
					'per_line' => __( 'One box per product line (quantities stacked, split at 70 lb)', 'beacon-shipping-rates-for-usps' ),
					'per_item' => __( 'One box per item', 'beacon-shipping-rates-for-usps' ),
				),
				'default'     => 'combined',
				'description' => __( 'How cart items become USPS packages. Rates for several boxes are added together.', 'beacon-shipping-rates-for-usps' ),
			),
			'default_weight'    => array(
				'title'       => sprintf( /* translators: %s weight unit */ __( 'Default item weight (%s)', 'beacon-shipping-rates-for-usps' ), 'lb' ),
				'type'        => 'decimal',
				'default'     => '1',
				'description' => sprintf( /* translators: %s store weight unit */ __( 'Used when a product has no weight. Products are converted from your store unit (%s) automatically.', 'beacon-shipping-rates-for-usps' ), $wu ),
				'desc_tip'    => true,
			),
			'default_length'    => array(
				'title'   => __( 'Default length (in)', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'decimal',
				'default' => '12',
				'description' => sprintf( /* translators: %s store dimension unit */ __( 'Used when a product has no dimensions (store unit: %s).', 'beacon-shipping-rates-for-usps' ), $du ),
				'desc_tip' => true,
			),
			'default_width'     => array(
				'title'   => __( 'Default width (in)', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'decimal',
				'default' => '9',
			),
			'default_height'    => array(
				'title'   => __( 'Default height (in)', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'decimal',
				'default' => '4',
			),
			'markup_type'       => array(
				'title'   => __( 'Handling markup', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'select',
				'options' => array(
					'none'    => __( 'None — charge the USPS rate', 'beacon-shipping-rates-for-usps' ),
					'fixed'   => __( 'Add a fixed amount per order', 'beacon-shipping-rates-for-usps' ),
					'percent' => __( 'Add a percentage', 'beacon-shipping-rates-for-usps' ),
				),
				'default' => 'none',
			),
			'markup_amount'     => array(
				'title'   => __( 'Markup amount', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'decimal',
				'default' => '0',
				'description' => __( 'Dollars for a fixed markup, or percent (e.g. 10 for 10%).', 'beacon-shipping-rates-for-usps' ),
				'desc_tip' => true,
			),
			'fallback_enabled'  => array(
				'title'       => __( 'Fallback rate', 'beacon-shipping-rates-for-usps' ),
				'type'        => 'checkbox',
				'label'       => __( 'Offer a flat fallback rate when USPS cannot be reached and nothing is cached', 'beacon-shipping-rates-for-usps' ),
				'default'     => 'yes',
				'description' => __( 'Without this, an outage at USPS means customers see no shipping option at all.', 'beacon-shipping-rates-for-usps' ),
			),
			'fallback_label'    => array(
				'title'   => __( 'Fallback label', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'text',
				'default' => __( 'USPS shipping', 'beacon-shipping-rates-for-usps' ),
			),
			'fallback_cost'     => array(
				'title'   => __( 'Fallback cost', 'beacon-shipping-rates-for-usps' ),
				'type'    => 'decimal',
				'default' => '9.95',
			),
		);
	}

	public static function shipping_class_options() {
		$out = array( '' => __( 'Any item (no restriction)', 'beacon-shipping-rates-for-usps' ) );
		foreach ( (array) WC()->shipping()->get_shipping_classes() as $c ) {
			$out[ $c->slug ] = $c->name;
		}
		return $out;
	}

	/** True when every shippable item in the package has the configured "media" shipping class. */
	private function media_ok( array $package ) {
		$class = (string) $this->get_option( 'media_class', '' );
		if ( '' === $class ) {
			return true;
		}
		foreach ( (array) ( $package['contents'] ?? array() ) as $line ) {
			$p = $line['data'] ?? null;
			if ( is_object( $p ) && $p->needs_shipping() && $p->get_shipping_class() !== $class ) {
				return false;
			}
		}
		return true;
	}

	/** Ship date after handling time + cutoff, as Y-m-d (store timezone). Used by delivery-date estimates. */
	public function ship_date() {
		$now  = current_datetime();
		$days = max( 0, (int) $this->get_option( 'handling_days', 1 ) );
		if ( (int) $now->format( 'G' ) >= (int) $this->get_option( 'cutoff_hour', 14 ) ) {
			++$days;
		}
		$d = $now;
		while ( $days > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) < 6 ) { // business days only
				--$days;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	private function opts() {
		return array(
			'origin_zip'     => $this->get_option( 'origin_zip' ) ? $this->get_option( 'origin_zip' ) : get_option( 'woocommerce_store_postcode', '' ),
			'strategy'       => $this->get_option( 'packing', 'combined' ),
			'default_weight' => (float) $this->get_option( 'default_weight', 1 ),
			'default_length' => (float) $this->get_option( 'default_length', 12 ),
			'default_width'  => (float) $this->get_option( 'default_width', 9 ),
			'default_height' => (float) $this->get_option( 'default_height', 4 ),
			'price_type'     => Settings::get( 'price_type' ),
		);
	}

	public function calculate_shipping( $package = array() ) {
		$dest = isset( $package['destination'] ) ? $package['destination'] : array();
		if ( empty( $dest['postcode'] ) && ( empty( $dest['country'] ) || 'US' === $dest['country'] ) ) {
			return; // no ZIP yet (address form still being filled in)
		}
		$opts = $this->opts();
		if ( '' === Packer::zip5( $opts['origin_zip'] ) ) {
			API::log( 'no ship-from ZIP: set the store address or the method\'s origin ZIP' );
			$this->maybe_fallback();
			return;
		}
		/**
		 * Pro hook: replace the packages (e.g. real box packing) before rating. Return ['intl'=>bool,'payloads'=>array].
		 */
		$built = apply_filters( 'busr_packages', Packer::build( $package, $opts ), $package, $opts, $this );
		if ( empty( $built['payloads'] ) ) {
			return;
		}
		$enabled = $built['intl'] ? (array) $this->get_option( 'intl_services', array() ) : (array) $this->get_option( 'domestic_services', array() );
		if ( ! $built['intl'] && ! $this->media_ok( $package ) ) {
			$enabled = array_values( array_diff( $enabled, array( 'MEDIA_MAIL', 'LIBRARY_MAIL' ) ) );
		}
		$labels  = $built['intl'] ? self::international_services() : self::domestic_services();
		if ( ! $enabled ) {
			$this->maybe_fallback();
			return;
		}
		// Rates per box, then summed per service; a service must be available for every box.
		$per_class = array();
		$boxes     = 0;
		foreach ( $built['payloads'] as $payload ) {
			/** Pro hook: add itemValue / extraServices etc. to the USPS request. */
			$payload = apply_filters( 'busr_rate_payload', $payload, $package, $built['intl'], $this );
			$rates   = API::rates( $payload, $built['intl'] );
			if ( is_wp_error( $rates ) ) {
				API::log( 'checkout rate lookup failed: ' . $rates->get_error_message() );
				$this->maybe_fallback();
				return;
			}
			++$boxes;
			foreach ( $rates as $r ) {
				if ( ! in_array( $r['mailClass'], $enabled, true ) ) {
					continue;
				}
				$c = $r['mailClass'];
				if ( ! isset( $per_class[ $c ] ) ) {
					$per_class[ $c ] = array(
						'total' => 0.0,
						'boxes' => 0,
						'name'  => $r['productName'],
						'sku'   => $r['sku'],
					);
				}
				$per_class[ $c ]['total'] += $r['price'];
				++$per_class[ $c ]['boxes'];
			}
		}
		$to_add = array();
		foreach ( $enabled as $class ) {
			if ( empty( $per_class[ $class ] ) || $per_class[ $class ]['boxes'] < $boxes ) {
				continue;
			}
			$cost     = $this->markup( $per_class[ $class ]['total'] );
			$label    = trim( $this->title . ' ' . ( isset( $labels[ $class ] ) ? $labels[ $class ] : $per_class[ $class ]['name'] ) );
			$to_add[] = array(
				'id'        => $this->get_rate_id( $class ),
				'label'     => $label,
				'cost'      => round( $cost, 2 ),
				'package'   => $package,
				'class'     => $class,
				'raw_cost'  => $per_class[ $class ]['total'],
				'meta_data' => array(
					'usps_service' => $class,
					'usps_sku'     => $per_class[ $class ]['sku'],
					'boxes'        => $boxes,
				),
			);
		}
		/**
		 * Pro hook: rate rules (per-service markup, hide, free over, min/max), delivery-date labels. Each item is an
		 * add_rate() array plus 'class' and 'raw_cost'; drop items to hide them, return [] to fall back.
		 */
		$to_add = apply_filters( 'busr_checkout_rates', $to_add, $package, $built, $this );
		foreach ( (array) $to_add as $rate ) {
			unset( $rate['class'], $rate['raw_cost'] );
			$this->add_rate( $rate );
		}
		if ( ! $to_add ) {
			$this->maybe_fallback();
		}
	}

	private function markup( $cost ) {
		$type   = $this->get_option( 'markup_type', 'none' );
		$amount = (float) $this->get_option( 'markup_amount', 0 );
		if ( 'fixed' === $type ) {
			return $cost + $amount;
		}
		if ( 'percent' === $type ) {
			return $cost * ( 1 + $amount / 100 );
		}
		return $cost;
	}

	private function maybe_fallback() {
		if ( 'yes' !== $this->get_option( 'fallback_enabled', 'yes' ) ) {
			return;
		}
		$cost = (float) $this->get_option( 'fallback_cost', 0 );
		if ( $cost <= 0 ) {
			return;
		}
		$this->add_rate(
			array(
				'id'        => $this->get_rate_id( 'fallback' ),
				'label'     => $this->get_option( 'fallback_label', __( 'USPS shipping', 'beacon-shipping-rates-for-usps' ) ),
				'cost'      => $cost,
				'meta_data' => array( 'usps_service' => 'fallback' ),
			)
		);
	}
}
