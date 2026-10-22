<?php
namespace Beacon\USPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * WooCommerce → USPS Rates: the guided setup page (keys, connection test, rate test, zone link) and status.
 */
class Admin {
	const PAGE = 'busr-setup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_busr_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_busr_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_busr_test_rate', array( __CLASS__, 'ajax_test_rate' ) );
		add_action( 'wp_ajax_busr_flush_cache', array( __CLASS__, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_busr_diagnostics', array( __CLASS__, 'ajax_diagnostics' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BUSR_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_post_busr_dismiss_setup', array( __CLASS__, 'dismiss_setup' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'USPS Rates', 'beacon-shipping-rates-for-usps' ), __( 'USPS Rates', 'beacon-shipping-rates-for-usps' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Setup', 'beacon-shipping-rates-for-usps' ) . '</a>' );
		return $links;
	}

	/**
	 * "Finish setup" reminder: only on the Plugins screen and WooCommerce → Settings → Shipping, only until the keys are
	 * saved, and gone for good once the user dismisses it.
	 */
	public static function setup_notice() {
		if ( Settings::has_credentials() || ! current_user_can( 'manage_woocommerce' ) || get_user_meta( get_current_user_id(), 'busr_setup_dismissed', true ) ) {
			return;
		}
		$screen   = get_current_screen();
		$shipping = $screen && 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['tab'] ) && 'shipping' === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( ! $screen || ( 'plugins' !== $screen->id && ! $shipping ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=busr_dismiss_setup' ), 'busr_dismiss_setup' );
		echo '<div class="notice notice-info"><p>' .
			wp_kses_post( sprintf( /* translators: 1: setup page link, 2: dismiss link */ __( 'Beacon Shipping Rates for USPS is installed. <a href="%1$s">Finish the USPS API setup</a> to start showing live rates. <a href="%2$s">Dismiss</a>', 'beacon-shipping-rates-for-usps' ), esc_url( self::url() ), esc_url( $dismiss ) ) ) .
			'</p></div>';
	}

	public static function dismiss_setup() {
		check_admin_referer( 'busr_dismiss_setup' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-shipping-rates-for-usps' ) );
		}
		update_user_meta( get_current_user_id(), 'busr_setup_dismissed', 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'busr-admin', BUSR_URL . 'assets/admin.css', array(), BUSR_VERSION );
		wp_enqueue_script( 'busr-admin', BUSR_URL . 'assets/admin.js', array( 'jquery' ), BUSR_VERSION, true );
		wp_localize_script(
			'busr-admin',
			'busr',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'busr_ajax' ),
				'i18n'  => array(
					'working' => __( 'Working…', 'beacon-shipping-rates-for-usps' ),
					'failed'  => __( 'Request failed.', 'beacon-shipping-rates-for-usps' ),
				),
			)
		);
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beacon-shipping-rates-for-usps' ) );
		}
		check_admin_referer( 'busr_save' );
		$in = array(
			'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
			'consumer_secret' => isset( $_POST['consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) : '',
			'sandbox'         => isset( $_POST['sandbox'] ) ? 'yes' : 'no',
			'price_type'      => isset( $_POST['price_type'] ) ? sanitize_text_field( wp_unslash( $_POST['price_type'] ) ) : 'RETAIL',
			'cache_hours'     => isset( $_POST['cache_hours'] ) ? absint( $_POST['cache_hours'] ) : 24,
			'quota_ceiling'   => isset( $_POST['quota_ceiling'] ) ? absint( $_POST['quota_ceiling'] ) : 55,
			'debug'           => isset( $_POST['debug'] ) ? 'yes' : 'no',
		);
		// Keep the stored secret when the field is left blank (it is never echoed back).
		if ( '' === $in['consumer_secret'] ) {
			unset( $in['consumer_secret'] );
		}
		Settings::update( $in );
		delete_transient( API::TOKEN_TRANSIENT );
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url() ) );
		exit;
	}

	private static function guard() {
		check_ajax_referer( 'busr_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beacon-shipping-rates-for-usps' ) ), 403 );
		}
	}

	public static function ajax_test_connection() {
		self::guard();
		$t = API::token( true );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( array( 'message' => $t->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Connected. USPS accepted your key and secret and issued an access token.', 'beacon-shipping-rates-for-usps' ) ) );
	}

	public static function ajax_test_rate() {
		check_ajax_referer( 'busr_ajax', 'nonce' );
		self::guard();
		$origin  = isset( $_POST['origin'] ) ? sanitize_text_field( wp_unslash( $_POST['origin'] ) ) : '';
		$dest    = isset( $_POST['dest'] ) ? sanitize_text_field( wp_unslash( $_POST['dest'] ) ) : '';
		$country = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : 'US';
		$weight  = isset( $_POST['weight'] ) ? (float) $_POST['weight'] : 1;
		$dims    = array();
		foreach ( array( 'length', 'width', 'height' ) as $k ) {
			$dims[ $k ] = isset( $_POST[ $k ] ) ? max( 0.1, (float) $_POST[ $k ] ) : 6;
		}
		$intl    = ! in_array( $country, array( 'US', '' ), true );
		$payload = array(
			'originZIPCode' => Packer::zip5( $origin ? $origin : get_option( 'woocommerce_store_postcode', '' ) ),
			'weight'        => max( 0.01, $weight ),
			'length'        => $dims['length'],
			'width'         => $dims['width'],
			'height'        => $dims['height'],
			'mailClass'     => 'ALL',
			'priceType'     => Settings::get( 'price_type' ),
		);
		if ( $intl ) {
			$payload['destinationCountryCode'] = $country;
			$payload['foreignPostalCode']      = $dest;
		} else {
			$payload['destinationZIPCode']           = Packer::zip5( $dest );
			$payload['processingCategory']           = 'MACHINABLE';
			$payload['rateIndicator']                = 'SP';
			$payload['destinationEntryFacilityType'] = 'NONE';
		}
		if ( '' === $payload['originZIPCode'] || ( ! $intl && '' === $payload['destinationZIPCode'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a 5-digit ship-from and ship-to ZIP code.', 'beacon-shipping-rates-for-usps' ) ) );
		}
		$raw = API::request( $intl ? API::INTERNATIONAL : API::DOMESTIC, $payload );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( array( 'message' => $raw->get_error_message() ) );
		}
		$rates = API::parse( $raw );
		if ( ! $rates ) {
			wp_send_json_error( array( 'message' => __( 'USPS answered but returned no parcel rates for that package. Try a different weight or size.', 'beacon-shipping-rates-for-usps' ) ) );
		}
		Cache::set( Cache::key( $payload + array( '_intl' => $intl ? 1 : 0 ) ), $rates );
		wp_send_json_success(
			array(
				'rates' => array_map(
					function ( $r ) {
						return array(
							'service' => $r['productName'],
							'class'   => $r['mailClass'],
							'price'   => wc_price( $r['price'] ),
						);
					},
					$rates
				),
			)
		);
	}

	public static function ajax_flush_cache() {
		self::guard();
		Cache::flush();
		wp_send_json_success( array( 'message' => __( 'Rate cache cleared.', 'beacon-shipping-rates-for-usps' ) ) );
	}

	/** Everything support needs, minus the secret, as plain text for the clipboard. */
	public static function ajax_diagnostics() {
		self::guard();
		global $wp_version;
		$s  = Settings::all();
		$st = Cache::stats();
		$lines = array(
			'Beacon Shipping Rates for USPS ' . BUSR_VERSION,
			'WordPress ' . $wp_version . ' / WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' / PHP ' . PHP_VERSION,
			'Store: ' . wp_json_encode( wc_get_base_location() ) . ' postcode ' . get_option( 'woocommerce_store_postcode', '' ) . ' units ' . get_option( 'woocommerce_weight_unit' ) . '/' . get_option( 'woocommerce_dimension_unit' ) . ' currency ' . get_woocommerce_currency(),
			'Keys: ' . ( Settings::has_credentials() ? 'set (key ends …' . substr( $s['consumer_key'], -4 ) . ')' : 'NOT SET' ) . ' · sandbox ' . $s['sandbox'] . ' · price type ' . $s['price_type'],
			'Cache ' . $s['cache_hours'] . 'h · ceiling ' . $s['quota_ceiling'] . '/h · calls this hour ' . Cache::calls_this_hour(),
			'Today: ' . wp_json_encode( $st ),
			'Pro: ' . ( class_exists( '\\BeaconUSPSPro\\Settings' ) ? 'active' : 'no' ),
		);
		foreach ( \WC_Shipping_Zones::get_zones() as $z ) {
			foreach ( $z['shipping_methods'] as $m ) {
				if ( 'beacon_usps' === $m->id ) {
					$lines[] = 'Zone "' . $z['zone_name'] . '": ' . wp_json_encode( array_intersect_key( $m->instance_settings, array_flip( array( 'origin_zip', 'domestic_services', 'intl_services', 'packing', 'markup_type', 'markup_amount', 'fallback_enabled', 'fallback_cost', 'media_class', 'handling_days', 'cutoff_hour', 'tax_status' ) ) ) );
				}
			}
		}
		if ( function_exists( 'wc_get_log_file_path' ) ) {
			$lines[] = 'Log: WooCommerce → Status → Logs → source beacon-shipping-rates-for-usps';
		}
		wp_send_json_success( array( 'text' => implode( "\n", $lines ) ) );
	}

	/** Zero-nag review ask: only replaces the footer line on our own pages. */
	public static function footer_text( $text ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE ) ) {
			return $text;
		}
		return wp_kses_post( sprintf( /* translators: %s review link */ __( 'Beacon Shipping Rates for USPS saving you time? A quick <a href="%s" target="_blank" rel="noopener">review on wordpress.org</a> helps a new plugin more than you would think. Thank you.', 'beacon-shipping-rates-for-usps' ), 'https://wordpress.org/support/plugin/beacon-shipping-rates-for-usps/reviews/#new-post' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = Settings::all();
		$st    = Cache::stats();
		$has   = Settings::has_credentials();
		$saved = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a nonce-checked redirect.
		$zones = admin_url( 'admin.php?page=wc-settings&tab=shipping' );
		?>
		<div class="wrap busr-wrap">
			<h1><?php esc_html_e( 'USPS Rates — setup', 'beacon-shipping-rates-for-usps' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'beacon-shipping-rates-for-usps' ); ?></p></div>
			<?php endif; ?>

			<div class="busr-grid">
			<div class="busr-main">

			<div class="busr-step <?php echo $has ? 'is-done' : ''; ?>">
				<h2><span class="busr-num">1</span> <?php esc_html_e( 'Get USPS API access (free, about 15 minutes)', 'beacon-shipping-rates-for-usps' ); ?></h2>
				<ol class="busr-list">
					<li><?php echo wp_kses_post( __( 'Sign in or create a business account at the <a href="https://developers.usps.com/" target="_blank" rel="noopener">USPS Developer Portal</a>. It uses the same login as usps.com; the Customer Onboarding Portal (CRID/MID) is created for you on first sign-in.', 'beacon-shipping-rates-for-usps' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Open <strong>Apps → Add App</strong>. Name it anything (e.g. your store name). Leave the callback URL blank. The default API products include <strong>Domestic Prices</strong> and <strong>International Prices</strong> — that is all this plugin needs.', 'beacon-shipping-rates-for-usps' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'On the app page, copy the <strong>Consumer Key</strong> and <strong>Consumer Secret</strong>. The secret is shown once — copy it now.', 'beacon-shipping-rates-for-usps' ) ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'You do not need a USPS shipping account, EPS account or labels for rates. Retail rates need nothing else; Commercial rates apply only if you have a USPS commercial pricing agreement.', 'beacon-shipping-rates-for-usps' ); ?></p>
			</div>

			<div class="busr-step <?php echo $has ? 'is-done' : ''; ?>">
				<h2><span class="busr-num">2</span> <?php esc_html_e( 'Paste your keys', 'beacon-shipping-rates-for-usps' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'busr_save' ); ?>
					<input type="hidden" name="action" value="busr_save">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="busr_key"><?php esc_html_e( 'Consumer Key', 'beacon-shipping-rates-for-usps' ); ?></label></th>
							<td><input id="busr_key" name="consumer_key" type="text" class="regular-text code" value="<?php echo esc_attr( $s['consumer_key'] ); ?>" autocomplete="off"></td></tr>
						<tr><th scope="row"><label for="busr_secret"><?php esc_html_e( 'Consumer Secret', 'beacon-shipping-rates-for-usps' ); ?></label></th>
							<td><input id="busr_secret" name="consumer_secret" type="password" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo $has ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'beacon-shipping-rates-for-usps' ) : ''; ?>"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Price type', 'beacon-shipping-rates-for-usps' ); ?></th>
							<td><select name="price_type">
								<option value="RETAIL" <?php selected( $s['price_type'], 'RETAIL' ); ?>><?php esc_html_e( 'Retail (post office counter prices — most stores)', 'beacon-shipping-rates-for-usps' ); ?></option>
								<option value="COMMERCIAL" <?php selected( $s['price_type'], 'COMMERCIAL' ); ?>><?php esc_html_e( 'Commercial (you have a USPS commercial pricing account)', 'beacon-shipping-rates-for-usps' ); ?></option>
							</select></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Rate cache', 'beacon-shipping-rates-for-usps' ); ?></th>
							<td><input name="cache_hours" type="number" min="1" max="168" value="<?php echo esc_attr( $s['cache_hours'] ); ?>" class="small-text"> <?php esc_html_e( 'hours', 'beacon-shipping-rates-for-usps' ); ?>
							<p class="description"><?php esc_html_e( 'Identical packages are served from cache for this long. USPS prices change a few times a year, so 24 hours is safe. A stale copy is kept for a week and used if USPS is down.', 'beacon-shipping-rates-for-usps' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Hourly call ceiling', 'beacon-shipping-rates-for-usps' ); ?></th>
							<td><input name="quota_ceiling" type="number" min="5" max="100000" value="<?php echo esc_attr( $s['quota_ceiling'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'USPS allows 60 calls per hour per API by default. The plugin stops calling USPS below this number and serves cached rates instead, so a busy hour never turns into "no shipping methods". If USPS raises your quota, raise this.', 'beacon-shipping-rates-for-usps' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Advanced', 'beacon-shipping-rates-for-usps' ); ?></th>
							<td><label><input type="checkbox" name="sandbox" <?php checked( $s['sandbox'], 'yes' ); ?>> <?php esc_html_e( 'Use the USPS test environment (apis-tem.usps.com) — same keys, test prices; turn off for real checkout rates', 'beacon-shipping-rates-for-usps' ); ?></label><br>
							<label><input type="checkbox" name="debug" <?php checked( $s['debug'], 'yes' ); ?>> <?php echo wp_kses_post( sprintf( /* translators: %s logs url */ __( 'Log every USPS request to <a href="%s">WooCommerce → Status → Logs</a> (source: beacon-shipping-rates-for-usps)', 'beacon-shipping-rates-for-usps' ), esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) ) ); ?></label></td></tr>
					</table>
					<?php submit_button( __( 'Save keys & settings', 'beacon-shipping-rates-for-usps' ) ); ?>
				</form>
			</div>

			<div class="busr-step">
				<h2><span class="busr-num">3</span> <?php esc_html_e( 'Test the connection', 'beacon-shipping-rates-for-usps' ); ?></h2>
				<p><button type="button" class="button button-secondary" id="busr-test-connection" <?php disabled( ! $has ); ?>><?php esc_html_e( 'Test connection', 'beacon-shipping-rates-for-usps' ); ?></button> <span class="busr-result" id="busr-connection-result"></span></p>
			</div>

			<div class="busr-step">
				<h2><span class="busr-num">4</span> <?php esc_html_e( 'Get a real rate', 'beacon-shipping-rates-for-usps' ); ?></h2>
				<div class="busr-inline">
					<label><?php esc_html_e( 'From ZIP', 'beacon-shipping-rates-for-usps' ); ?> <input type="text" id="busr-origin" class="small-text" value="<?php echo esc_attr( get_option( 'woocommerce_store_postcode', '' ) ); ?>"></label>
					<label><?php esc_html_e( 'To ZIP / postcode', 'beacon-shipping-rates-for-usps' ); ?> <input type="text" id="busr-dest" class="small-text" value="10001"></label>
					<label><?php esc_html_e( 'Country', 'beacon-shipping-rates-for-usps' ); ?> <input type="text" id="busr-country" class="small-text" value="US" maxlength="2"></label>
					<label><?php esc_html_e( 'Weight (lb)', 'beacon-shipping-rates-for-usps' ); ?> <input type="number" step="0.01" id="busr-weight" class="small-text" value="2"></label>
					<label><?php esc_html_e( 'L × W × H (in)', 'beacon-shipping-rates-for-usps' ); ?> <span class="busr-dims"><input type="number" step="0.1" id="busr-length" class="small-text" value="10"> × <input type="number" step="0.1" id="busr-width" class="small-text" value="8"> × <input type="number" step="0.1" id="busr-height" class="small-text" value="4"></span></label>
					<button type="button" class="button button-secondary" id="busr-test-rate" <?php disabled( ! $has ); ?>><?php esc_html_e( 'Get rates', 'beacon-shipping-rates-for-usps' ); ?></button>
				</div>
				<div id="busr-rate-result" class="busr-result"></div>
			</div>

			<div class="busr-step">
				<h2><span class="busr-num">5</span> <?php esc_html_e( 'Add USPS to a shipping zone', 'beacon-shipping-rates-for-usps' ); ?></h2>
				<p><?php echo wp_kses_post( sprintf( /* translators: %s shipping zones url */ __( 'Go to <a href="%s">WooCommerce → Settings → Shipping</a>, open a zone (e.g. United States), click <strong>Add shipping method</strong> and pick <strong>USPS (Beacon)</strong>. In the method settings choose the services to offer and how items are packed. Add it to an international zone as well to sell abroad.', 'beacon-shipping-rates-for-usps' ), esc_url( $zones ) ) ); ?></p>
			</div>

			</div>
			<aside class="busr-side">
				<h3><?php esc_html_e( 'Status', 'beacon-shipping-rates-for-usps' ); ?></h3>
				<ul>
					<li><?php echo $has ? '✅ ' . esc_html__( 'Keys saved', 'beacon-shipping-rates-for-usps' ) : '⬜ ' . esc_html__( 'Keys not set', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php echo 'yes' === $s['sandbox'] ? '🧪 ' . esc_html__( 'Test environment', 'beacon-shipping-rates-for-usps' ) : '🟢 ' . esc_html__( 'Production USPS', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: 1 calls, 2 ceiling */ __( 'Live calls this hour: %1$d of %2$d', 'beacon-shipping-rates-for-usps' ), Cache::calls_this_hour(), (int) $s['quota_ceiling'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: 1 hits, 2 live, 3 stale, 4 skipped */ __( 'Today: %1$d from cache · %2$d live · %3$d stale-served · %4$d quota-skipped', 'beacon-shipping-rates-for-usps' ), (int) ( $st['cache_hit'] ?? 0 ), (int) ( $st['live_ok'] ?? 0 ), (int) ( $st['stale_served'] ?? 0 ), (int) ( $st['quota_skip'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( ! empty( $st['last_ok'] ) ? sprintf( /* translators: %s time ago */ __( 'Last successful USPS call: %s ago', 'beacon-shipping-rates-for-usps' ), human_time_diff( (int) $st['last_ok'] ) ) : __( 'No successful USPS call yet', 'beacon-shipping-rates-for-usps' ) ); ?></li>
					<?php if ( ! empty( $st['last_error'] ) ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: %s time ago */ __( 'Last USPS error: %s ago (see the log)', 'beacon-shipping-rates-for-usps' ), human_time_diff( (int) $st['last_error'] ) ) ); ?></li>
					<?php endif; ?>
				</ul>
				<p><button type="button" class="button" id="busr-flush-cache"><?php esc_html_e( 'Clear rate cache', 'beacon-shipping-rates-for-usps' ); ?></button> <span class="busr-result" id="busr-flush-result"></span></p>
				<p><button type="button" class="button" id="busr-diagnostics"><?php esc_html_e( 'Copy diagnostics', 'beacon-shipping-rates-for-usps' ); ?></button> <span class="busr-result" id="busr-diagnostics-result"></span><br><span class="description"><?php esc_html_e( 'Paste this into a support thread. Never includes your secret.', 'beacon-shipping-rates-for-usps' ); ?></span></p>
				<?php if ( ! class_exists( '\\BeaconUSPSPro\\Settings' ) ) : ?>
				<h3><?php esc_html_e( 'Pro add-on', 'beacon-shipping-rates-for-usps' ); ?></h3>
				<ul class="busr-help">
					<li><?php esc_html_e( 'Pack orders into your real boxes', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( 'Per-service rules: markup, hide, free over an amount', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( '"Arrives Tue, Sep 30" delivery dates at checkout', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( 'USPS flat-rate boxes when the order fits one', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( 'Per-product service restrictions', 'beacon-shipping-rates-for-usps' ); ?></li>
				</ul>
				<p><a class="button button-primary" href="https://checkout.freemius.com/plugin/40095/plan/69020/" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro — from $49/yr', 'beacon-shipping-rates-for-usps' ); ?></a></p>
				<?php endif; ?>
				<h3><?php esc_html_e( 'If something is off', 'beacon-shipping-rates-for-usps' ); ?></h3>
				<ul class="busr-help">
					<li><?php esc_html_e( 'Rates differ from usps.com: the site shows Retail; switch Price type if you have Commercial pricing.', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( 'No rates at checkout: products need a weight (or set defaults in the method), and the customer must enter a ZIP.', 'beacon-shipping-rates-for-usps' ); ?></li>
					<li><?php esc_html_e( 'HTTP 403 from USPS: add the Domestic/International Prices products to your app in the Developer Portal.', 'beacon-shipping-rates-for-usps' ); ?></li>
				</ul>
			</aside>
			</div>
		</div>
		<?php
	}
}
