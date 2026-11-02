<?php
/**
 * Settings page: banner texts and colours, categories and services, scripts held
 * back, and the consent log.
 *
 * @package SHD\Consent
 */

namespace SHD\Consent;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'shd-consent';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_shd_consent_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_shd_consent_reask', array( __CLASS__, 'reask' ) );
		add_action( 'admin_post_shd_consent_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHD_CONSENT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( __( 'SHD Consent', 'shd-consent' ), __( 'SHD Consent', 'shd-consent' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-shield', 82 );
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'shd-consent' ) . '</a>' );
		return $links;
	}

	/**
	 * @param string $tab  Tab.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	private static function url( $tab = 'banner', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'shd-consent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shd_consent_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$in  = isset( $_POST['shdc'] ) && is_array( $_POST['shdc'] ) ? wp_unslash( $_POST['shdc'] ) : array();
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'banner';
		Settings::update( self::sanitize( $in, $tab ) );
		self::refresh_pages();
		self::back( $tab, __( 'Settings saved. Stored pages are refreshed so visitors see the change.', 'shd-consent' ) );
	}

	public static function reask() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'shd-consent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shd_consent_reask' );
		Settings::update( array( 'version' => (string) ( (int) Settings::get()['version'] + 1 ) ) );
		self::refresh_pages();
		self::back( 'banner', __( 'New banner version: every visitor is asked again on their next visit.', 'shd-consent' ) );
	}

	public static function export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'shd-consent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shd_consent_export' );
		Log::export_csv();
	}

	/**
	 * Pages stored by a page cache still contain the old banner.
	 */
	private static function refresh_pages() {
		if ( class_exists( '\SHD\Performance\Cache' ) ) {
			\SHD\Performance\Cache::purge_all();
		}
		do_action( 'shd_consent_saved' );
	}

	/**
	 * @param string $tab     Tab.
	 * @param string $message Notice.
	 */
	private static function back( $tab, $message ) {
		set_transient( 'shd_consent_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * @param array  $in  Submitted values.
	 * @param string $tab Tab the form belongs to.
	 * @return array
	 */
	public static function sanitize( array $in, $tab ) {
		$out      = array();
		$defaults = Settings::defaults();
		if ( 'banner' === $tab ) {
			$out['mode'] = isset( $in['mode'] ) && in_array( $in['mode'], array( 'auto', 'on', 'off' ), true ) ? $in['mode'] : 'auto';
			foreach ( array_keys( $defaults['texts'] ) as $key ) {
				if ( isset( $in['texts'][ $key ] ) ) {
					$value               = 'message' === $key ? sanitize_textarea_field( (string) $in['texts'][ $key ] ) : sanitize_text_field( (string) $in['texts'][ $key ] );
					$out['texts'][ $key ] = '' !== trim( $value ) ? $value : $defaults['texts'][ $key ];
				}
			}
			foreach ( array( 'privacy_url', 'imprint_url' ) as $key ) {
				$out[ $key ] = isset( $in[ $key ] ) ? esc_url_raw( trim( (string) $in[ $key ] ) ) : '';
			}
			foreach ( array_keys( $defaults['colors'] ) as $key ) {
				$color                 = isset( $in['colors'][ $key ] ) ? sanitize_hex_color( (string) $in['colors'][ $key ] ) : '';
				$out['colors'][ $key ] = $color ? $color : $defaults['colors'][ $key ];
			}
			$out['layout']        = isset( $in['layout'] ) && 'bar' === $in['layout'] ? 'bar' : 'box';
			$out['reopen_button'] = ! empty( $in['reopen_button'] );
			$out['days']          = isset( $in['days'] ) ? max( 30, min( 395, (int) $in['days'] ) ) : 365;
		} elseif ( 'services' === $tab ) {
			$out['necessary'] = array(
				'label'       => self::text( $in, array( 'necessary', 'label' ), $defaults['necessary']['label'] ),
				'description' => sanitize_textarea_field( isset( $in['necessary']['description'] ) ? (string) $in['necessary']['description'] : '' ),
				'services'    => sanitize_textarea_field( isset( $in['necessary']['services'] ) ? (string) $in['necessary']['services'] : '' ),
			);
			foreach ( Settings::CATEGORIES as $name ) {
				$cat                        = isset( $in['categories'][ $name ] ) && is_array( $in['categories'][ $name ] ) ? $in['categories'][ $name ] : array();
				$out['categories'][ $name ] = array(
					'enabled'     => ! empty( $cat['enabled'] ),
					'label'       => self::text( $in, array( 'categories', $name, 'label' ), $defaults['categories'][ $name ]['label'] ),
					'description' => sanitize_textarea_field( isset( $cat['description'] ) ? (string) $cat['description'] : '' ),
					'services'    => sanitize_textarea_field( isset( $cat['services'] ) ? (string) $cat['services'] : '' ),
				);
			}
			$out['block_scripts']      = ! empty( $in['block_scripts'] );
			$out['consent_mode']       = ! empty( $in['consent_mode'] );
			$out['compat_cookiefirst'] = ! empty( $in['compat_cookiefirst'] );
			$out['block_rules']        = self::rules( isset( $in['block_rules'] ) ? (string) $in['block_rules'] : '' );
		} elseif ( 'log' === $tab ) {
			$out['log']        = ! empty( $in['log'] );
			$out['log_months'] = isset( $in['log_months'] ) ? max( 1, min( 60, (int) $in['log_months'] ) ) : 24;
		}
		return $out;
	}

	/**
	 * @param array  $in       Input.
	 * @param array  $path     Keys.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function text( array $in, array $path, $fallback ) {
		$value = $in;
		foreach ( $path as $key ) {
			$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : '';
		}
		$value = sanitize_text_field( (string) $value );
		return '' !== trim( $value ) ? $value : $fallback;
	}

	/**
	 * "category | text" per line; the category may be given in German.
	 *
	 * @param string $text Textarea.
	 * @return array<int,array{category:string,text:string}>
	 */
	private static function rules( $text ) {
		$aliases = array(
			'functional'  => 'functional',
			'funktional'  => 'functional',
			'performance' => 'performance',
			'statistics'  => 'performance',
			'statistik'   => 'performance',
			'advertising' => 'advertising',
			'marketing'   => 'advertising',
		);
		$rules   = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', (string) $line, 2 ) );
			$key   = strtolower( $parts[0] );
			if ( 2 === count( $parts ) && isset( $aliases[ $key ] ) && '' !== $parts[1] && strlen( $parts[1] ) <= 200 ) {
				$rules[] = array(
					'category' => $aliases[ $key ],
					'text'     => sanitize_text_field( $parts[1] ),
				);
			}
		}
		return array_slice( $rules, 0, 50 );
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = array(
			'banner'   => __( 'Banner', 'shd-consent' ),
			'services' => __( 'Categories and services', 'shd-consent' ),
			'log'      => __( 'Consent log', 'shd-consent' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'banner';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'banner';

		echo '<div class="wrap"><h1>' . esc_html__( 'SHD Consent', 'shd-consent' ) . ' <span style="font-size:13px;font-weight:400;color:#50575e">' . esc_html( SHD_CONSENT_VERSION ) . '</span></h1>';
		$notice = get_transient( 'shd_consent_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'shd_consent_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		self::status();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			printf( '<a href="%1$s" class="nav-tab%2$s">%3$s</a>', esc_url( self::url( $id ) ), $id === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function status() {
		$s     = Settings::get();
		$other = Settings::other_banner();
		$on    = Settings::active();
		$class = $on ? 'notice-success' : 'notice-warning';
		if ( $on ) {
			$text = __( 'Active: visitors see this banner. Tracking scripts wait for consent.', 'shd-consent' );
		} elseif ( 'off' === $s['mode'] ) {
			$text = __( 'Switched off: no banner is shown and no script is held back.', 'shd-consent' );
		} else {
			/* translators: %1$s: plugin name */
			$text = sprintf( __( 'Waiting: %1$s is still active, so this banner stays hidden for visitors. Check it with the preview, then deactivate %1$s: this banner takes over at once.', 'shd-consent' ), $other );
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p><strong>' . esc_html( $text ) . '</strong> <a href="' . esc_url( add_query_arg( 'shd-consent-preview', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview on the site', 'shd-consent' ) . '</a></p></div>';
	}

	private static function tab_banner() {
		$s = Settings::get();
		$t = $s['texts'];
		self::form_open( 'banner' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Show the banner', 'shd-consent' ) . '</th><td><select name="shdc[mode]">';
		foreach ( array( 'auto' => __( 'Automatically (while no other banner plugin is active)', 'shd-consent' ), 'on' => __( 'Always', 'shd-consent' ), 'off' => __( 'Off', 'shd-consent' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		$labels = array(
			'title'    => __( 'Title', 'shd-consent' ),
			'message'  => __( 'Text', 'shd-consent' ),
			'accept'   => __( 'Button: accept all', 'shd-consent' ),
			'reject'   => __( 'Button: necessary only', 'shd-consent' ),
			'settings' => __( 'Link: settings', 'shd-consent' ),
			'save'     => __( 'Button: save choice', 'shd-consent' ),
			'privacy'  => __( 'Link: privacy policy', 'shd-consent' ),
			'imprint'  => __( 'Link: imprint', 'shd-consent' ),
			'reopen'   => __( 'Cookie settings (round button, shortcode)', 'shd-consent' ),
			'services' => __( 'Services', 'shd-consent' ),
			'always'   => __( 'Always active', 'shd-consent' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<tr><th scope="row"><label for="shdc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'message' === $key ) {
				echo '<textarea id="shdc-message" name="shdc[texts][message]" rows="4" class="large-text">' . esc_textarea( $t['message'] ) . '</textarea>';
				echo '<p class="description">' . esc_html__( 'Short is better: on phones the banner should cover as little as possible. “Accept all” and “Necessary only” look the same, as data-protection authorities expect.', 'shd-consent' ) . '</p>';
			} else {
				echo '<input type="text" id="shdc-' . esc_attr( $key ) . '" name="shdc[texts][' . esc_attr( $key ) . ']" value="' . esc_attr( $t[ $key ] ) . '" class="regular-text">';
			}
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="shdc-privacy-url">' . esc_html__( 'Privacy policy (address)', 'shd-consent' ) . '</label></th><td><input type="url" id="shdc-privacy-url" name="shdc[privacy_url]" value="' . esc_attr( $s['privacy_url'] ) . '" class="large-text"></td></tr>';
		echo '<tr><th scope="row"><label for="shdc-imprint-url">' . esc_html__( 'Imprint (address)', 'shd-consent' ) . '</label></th><td><input type="url" id="shdc-imprint-url" name="shdc[imprint_url]" value="' . esc_attr( $s['imprint_url'] ) . '" class="large-text"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Colours', 'shd-consent' ) . '</th><td>';
		foreach ( array( 'background' => __( 'Background', 'shd-consent' ), 'text' => __( 'Text', 'shd-consent' ), 'link' => __( 'Links', 'shd-consent' ), 'button' => __( 'Buttons', 'shd-consent' ), 'button_text' => __( 'Button text', 'shd-consent' ) ) as $key => $label ) {
			echo '<label style="display:inline-block;margin:0 16px 8px 0"><input type="color" name="shdc[colors][' . esc_attr( $key ) . ']" value="' . esc_attr( $s['colors'][ $key ] ) . '" data-shdc-color="' . esc_attr( $key ) . '"> ' . esc_html( $label ) . '</label>';
		}
		echo '<p id="shdc-contrast" class="description" aria-live="polite">' . esc_html( self::contrast_note( $s['colors'] ) ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Layout on computers', 'shd-consent' ) . '</th><td><label><input type="radio" name="shdc[layout]" value="box"' . checked( $s['layout'], 'box', false ) . '> ' . esc_html__( 'Box at the bottom left', 'shd-consent' ) . '</label><br><label><input type="radio" name="shdc[layout]" value="bar"' . checked( $s['layout'], 'bar', false ) . '> ' . esc_html__( 'Bar across the bottom', 'shd-consent' ) . '</label><p class="description">' . esc_html__( 'On phones it is always a compact card at the bottom (about a quarter of the screen).', 'shd-consent' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Change the choice later', 'shd-consent' ) . '</th><td><input type="hidden" name="shdc[reopen_button]" value="0"><label><input type="checkbox" name="shdc[reopen_button]" value="1"' . checked( ! empty( $s['reopen_button'] ), true, false ) . '> ' . esc_html__( 'Small round button at the bottom left after a choice', 'shd-consent' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Or a link in the footer: the shortcode [shd_cookie_settings], or any link to #cookie-einstellungen (for example a custom menu item).', 'shd-consent' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="shdc-days">' . esc_html__( 'Remember the choice for (days)', 'shd-consent' ) . '</label></th><td><input type="number" id="shdc-days" name="shdc[days]" value="' . esc_attr( (string) $s['days'] ) . '" min="30" max="395" class="small-text"> <span class="description">' . esc_html__( 'At most 13 months (395 days).', 'shd-consent' ) . '</span></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'shd-consent' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Ask everyone again', 'shd-consent' ) . '</h2><p>' . esc_html(
			sprintf(
				/* translators: %s: version */
				__( 'Banner version %s. After adding a service or changing the purpose of one, ask every visitor again: their previous choice no longer counts.', 'shd-consent' ),
				$s['version']
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="shd_consent_reask">';
		wp_nonce_field( 'shd_consent_reask' );
		submit_button( __( 'Ask every visitor again', 'shd-consent' ), 'secondary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Show the banner to every visitor again?', 'shd-consent' ) ) . "');" ) );
		echo '</form>';
		self::contrast_script();
	}

	private static function tab_services() {
		$s = Settings::get();
		self::form_open( 'services' );
		echo '<p>' . esc_html__( 'Services: one per line, “Name | Provider | Purpose | Privacy policy address”. They are listed in the banner’s settings, under their category. Keep this list in line with your privacy policy.', 'shd-consent' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::category_rows( 'necessary', $s['necessary'], true );
		foreach ( Settings::CATEGORIES as $name ) {
			self::category_rows( $name, $s['categories'][ $name ], false );
		}
		echo '<tr><th scope="row">' . esc_html__( 'Hold back scripts', 'shd-consent' ) . '</th><td><input type="hidden" name="shdc[block_scripts]" value="0"><label><input type="checkbox" name="shdc[block_scripts]" value="1"' . checked( ! empty( $s['block_scripts'] ), true, false ) . '> ' . esc_html__( 'Tracking scripts in the page run only after consent to their category', 'shd-consent' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Built in: Google Analytics (also from MonsterInsights), Hotjar, Microsoft Clarity (statistics); Google Ads, Meta, LinkedIn, TikTok, Bing, Pinterest, Leadinfo, OpenAI (marketing). Not held back: SHD Tracking Manager and SHD Analytics (they wait for consent themselves), and cookieless statistics such as Simple Analytics.', 'shd-consent' ) . '</p>';
		$rules = array();
		foreach ( (array) $s['block_rules'] as $rule ) {
			$rules[] = $rule['category'] . ' | ' . $rule['text'];
		}
		echo '<p><label for="shdc-rules">' . esc_html__( 'More scripts, one per line: “category | part of the script address or code”, for example “marketing | widget.example.com”. Categories: functional, statistics, marketing.', 'shd-consent' ) . '</label></p><textarea id="shdc-rules" name="shdc[block_rules]" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $rules ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Google Consent Mode v2', 'shd-consent' ) . '</th><td><input type="hidden" name="shdc[consent_mode]" value="0"><label><input type="checkbox" name="shdc[consent_mode]" value="1"' . checked( ! empty( $s['consent_mode'] ), true, false ) . '> ' . esc_html__( 'Tell Google tags the choice (default: everything denied; statistics → analytics_storage, marketing → ad_storage, ad_user_data, ad_personalization)', 'shd-consent' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Scripts written for CookieFirst', 'shd-consent' ) . '</th><td><input type="hidden" name="shdc[compat_cookiefirst]" value="0"><label><input type="checkbox" name="shdc[compat_cookiefirst]" value="1"' . checked( ! empty( $s['compat_cookiefirst'] ), true, false ) . '> ' . esc_html__( 'Also store the choice as cookiefirst-consent and send the cf_consent events', 'shd-consent' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'SHD Tracking Manager and SHD Analytics read the choice from there and keep working without changes. A choice visitors made in CookieFirst is taken over until you ask everyone again.', 'shd-consent' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'shd-consent' ) );
		echo '</form>';
	}

	/**
	 * @param string $name     Category.
	 * @param array  $category Settings.
	 * @param bool   $fixed    Necessary (always on).
	 */
	private static function category_rows( $name, array $category, $fixed ) {
		$base = $fixed ? 'shdc[necessary]' : 'shdc[categories][' . $name . ']';
		echo '<tr><th scope="row">' . esc_html( $category['label'] ) . '</th><td>';
		if ( ! $fixed ) {
			echo '<input type="hidden" name="' . esc_attr( $base ) . '[enabled]" value="0"><label><input type="checkbox" name="' . esc_attr( $base ) . '[enabled]" value="1"' . checked( ! empty( $category['enabled'] ), true, false ) . '> ' . esc_html__( 'Offer this category', 'shd-consent' ) . '</label><br>';
		}
		echo '<input type="text" name="' . esc_attr( $base ) . '[label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" aria-label="' . esc_attr__( 'Name', 'shd-consent' ) . '"><br>';
		echo '<textarea name="' . esc_attr( $base ) . '[description]" rows="2" class="large-text" aria-label="' . esc_attr__( 'Description', 'shd-consent' ) . '">' . esc_textarea( $category['description'] ) . '</textarea>';
		echo '<textarea name="' . esc_attr( $base ) . '[services]" rows="4" class="large-text code" aria-label="' . esc_attr__( 'Services', 'shd-consent' ) . '">' . esc_textarea( $category['services'] ) . '</textarea>';
		echo '</td></tr>';
	}

	private static function tab_log() {
		$s = Settings::get();
		self::form_open( 'log' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Log choices', 'shd-consent' ) . '</th><td><input type="hidden" name="shdc[log]" value="0"><label><input type="checkbox" name="shdc[log]" value="1"' . checked( ! empty( $s['log'] ), true, false ) . '> ' . esc_html__( 'Store each choice as proof: time, a random consent ID (also in the visitor’s cookie), the categories, the banner version and the page. No IP address, no browser details.', 'shd-consent' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="shdc-months">' . esc_html__( 'Keep for (months)', 'shd-consent' ) . '</label></th><td><input type="number" id="shdc-months" name="shdc[log_months]" value="' . esc_attr( (string) $s['log_months'] ) . '" min="1" max="60" class="small-text"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'shd-consent' ) );
		echo '</form>';

		$sum = Log::summary();
		if ( $sum['total'] > 0 ) {
			echo '<h2>' . esc_html__( 'Last 30 days', 'shd-consent' ) . '</h2><p>' . esc_html(
				sprintf(
					/* translators: 1: choices, 2: percent accept all, 3: percent necessary only, 4: percent own choice */
					__( '%1$d choices: %2$d%% accepted all, %3$d%% necessary only, %4$d%% made their own choice.', 'shd-consent' ),
					$sum['total'],
					round( 100 * $sum['all'] / $sum['total'] ),
					round( 100 * $sum['necessary'] / $sum['total'] ),
					round( 100 * $sum['custom'] / $sum['total'] )
				)
			) . '</p>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search = isset( $_GET['consent_id'] ) ? sanitize_text_field( wp_unslash( $_GET['consent_id'] ) ) : '';
		// phpcs:enable
		$data = Log::query( $page, 50, $search );
		echo '<h2>' . esc_html__( 'Choices', 'shd-consent' ) . '</h2>';
		echo '<form method="get" style="margin:0 0 8px"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log"><label>' . esc_html__( 'Consent ID', 'shd-consent' ) . ' <input type="search" name="consent_id" value="' . esc_attr( $search ) . '"></label> <button class="button">' . esc_html__( 'Find', 'shd-consent' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'A visitor finds their consent ID in the cookie shd_consent; with it you can show which choice they made and when.', 'shd-consent' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'shd-consent' ) . '</th><th>' . esc_html__( 'Consent ID', 'shd-consent' ) . '</th><th>' . esc_html__( 'Choice', 'shd-consent' ) . '</th><th>' . esc_html__( 'Allowed', 'shd-consent' ) . '</th><th>' . esc_html__( 'Version', 'shd-consent' ) . '</th><th>' . esc_html__( 'Page', 'shd-consent' ) . '</th></tr></thead><tbody>';
		$names = array(
			'all'       => __( 'Accept all', 'shd-consent' ),
			'necessary' => __( 'Necessary only', 'shd-consent' ),
			'custom'    => __( 'Own choice', 'shd-consent' ),
		);
		if ( empty( $data['rows'] ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No choices logged yet.', 'shd-consent' ) . '</td></tr>';
		}
		foreach ( $data['rows'] as $row ) {
			$allowed = array();
			foreach ( Settings::CATEGORIES as $cat ) {
				if ( ! empty( $row[ $cat ] ) ) {
					$allowed[] = $s['categories'][ $cat ]['label'];
				}
			}
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( $row['created_at'] ),
				esc_html( $row['consent_id'] ),
				esc_html( isset( $names[ $row['method'] ] ) ? $names[ $row['method'] ] : $row['method'] ),
				esc_html( empty( $allowed ) ? $s['necessary']['label'] : $s['necessary']['label'] . ', ' . implode( ', ', $allowed ) ),
				esc_html( $row['version'] ),
				esc_html( $row['path'] )
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $data['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= min( $pages, 20 ); $i++ ) {
				echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( self::url( 'log', array( 'paged' => $i, 'consent_id' => $search ) ) ) . '">' . (int) $i . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers.
			}
			echo '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px"><input type="hidden" name="action" value="shd_consent_export">';
		wp_nonce_field( 'shd_consent_export' );
		/* translators: %d: number of entries */
		submit_button( sprintf( __( 'Download all %d entries (CSV)', 'shd-consent' ), $data['total'] ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * @param string $tab Tab.
	 */
	private static function form_open( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="shd_consent_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'shd_consent_save' );
	}

	/**
	 * @param array $colors Colours.
	 * @return string Contrast of text, links and buttons (WCAG asks for at least 4.5:1).
	 */
	private static function contrast_note( array $colors ) {
		$pairs = array(
			__( 'Text', 'shd-consent' )    => Settings::contrast( $colors['text'], $colors['background'] ),
			__( 'Links', 'shd-consent' )   => Settings::contrast( $colors['link'], $colors['background'] ),
			__( 'Buttons', 'shd-consent' ) => Settings::contrast( $colors['button_text'], $colors['button'] ),
		);
		$parts = array();
		$low   = false;
		foreach ( $pairs as $label => $ratio ) {
			$parts[] = $label . ' ' . number_format( $ratio, 1 ) . ':1';
			$low     = $low || $ratio < 4.5;
		}
		/* translators: %s: list of contrast ratios */
		return sprintf( $low ? __( 'Contrast: %s. Too low: at least 4.5:1 is needed so everyone can read it.', 'shd-consent' ) : __( 'Contrast: %s (at least 4.5:1 needed: fine).', 'shd-consent' ), implode( ' · ', $parts ) );
	}

	/**
	 * Updates the contrast note while colours are picked.
	 */
	private static function contrast_script() {
		$texts = array(
			'text'    => __( 'Text', 'shd-consent' ),
			'links'   => __( 'Links', 'shd-consent' ),
			'buttons' => __( 'Buttons', 'shd-consent' ),
			'low'     => __( 'Too low: at least 4.5:1 is needed so everyone can read it.', 'shd-consent' ),
			'ok'      => __( '(at least 4.5:1 needed: fine)', 'shd-consent' ),
			'prefix'  => __( 'Contrast:', 'shd-consent' ),
		);
		?>
		<script>
		( function () {
			var t = <?php echo wp_json_encode( $texts ); ?>;
			function lum( hex ) {
				var c = hex.replace( '#', '' ).match( /../g ).map( function ( p ) { var v = parseInt( p, 16 ) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 ); } );
				return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
			}
			function ratio( a, b ) { var x = lum( a ), y = lum( b ); return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 ); }
			function val( k ) { return document.querySelector( '[data-shdc-color="' + k + '"]' ).value; }
			function update() {
				var r = [ [ t.text, ratio( val( 'text' ), val( 'background' ) ) ], [ t.links, ratio( val( 'link' ), val( 'background' ) ) ], [ t.buttons, ratio( val( 'button_text' ), val( 'button' ) ) ] ];
				var low = r.some( function ( p ) { return p[1] < 4.5; } );
				document.getElementById( 'shdc-contrast' ).textContent = t.prefix + ' ' + r.map( function ( p ) { return p[0] + ' ' + p[1].toFixed( 1 ) + ':1'; } ).join( ' · ' ) + ( low ? '. ' + t.low : ' ' + t.ok );
			}
			document.querySelectorAll( '[data-shdc-color]' ).forEach( function ( input ) { input.addEventListener( 'input', update ); } );
		}() );
		</script>
		<?php
	}
}
