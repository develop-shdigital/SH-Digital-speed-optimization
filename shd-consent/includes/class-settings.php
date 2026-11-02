<?php
/**
 * Settings: texts, categories and services, colours, behaviour.
 *
 * @package SHD\Consent
 */

namespace SHD\Consent;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'shd_consent_settings';

	/** Optional categories, as named in the cookie (the names CookieFirst uses). */
	const CATEGORIES = array( 'functional', 'performance', 'advertising' );

	/** @var array|null */
	private static $cache = null;

	/**
	 * @return array Saved settings merged over the defaults.
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * @param array $values Sanitized values to store (merged over the saved ones).
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, self::merge( is_array( $saved ) ? $saved : array(), $values ), true );
		self::$cache = null;
	}

	/**
	 * @param array $base  Base.
	 * @param array $over  Values that win.
	 * @return array
	 */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! wp_is_numeric_array( $value ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/**
	 * Defaults in the language of the site (German with Swiss spelling on de_CH).
	 *
	 * @return array
	 */
	public static function defaults() {
		$locale = get_locale();
		$de     = 0 === strpos( $locale, 'de' );
		$ss     = 0 === strpos( $locale, 'de_CH' ) ? 'ss' : 'ß';
		$google = 'https://policies.google.com/privacy';

		return array(
			'mode'               => 'auto',
			'texts'              => $de ? array(
				'title'    => 'Cookies und Datenschutz',
				'message'  => 'Wir verwenden Cookies und ähnliche Technologien: notwendige für den Betrieb der Website und, mit Ihrer Zustimmung, weitere für Statistik und Marketing. Sie können Ihre Auswahl jederzeit unter «Cookie-Einstellungen» ändern.',
				'accept'   => 'Alle akzeptieren',
				'reject'   => 'Nur notwendige',
				'settings' => 'Einstellungen',
				'save'     => 'Auswahl speichern',
				'privacy'  => 'Datenschutzerklärung',
				'imprint'  => 'Impressum',
				'reopen'   => 'Cookie-Einstellungen',
				'services' => 'Dienste',
				'always'   => 'Immer aktiv',
			) : array(
				'title'    => 'Cookies and privacy',
				'message'  => 'We use cookies and similar technologies: necessary ones to run the website and, with your consent, others for statistics and marketing. You can change your choice at any time under “Cookie settings”.',
				'accept'   => 'Accept all',
				'reject'   => 'Necessary only',
				'settings' => 'Settings',
				'save'     => 'Save choice',
				'privacy'  => 'Privacy policy',
				'imprint'  => 'Imprint',
				'reopen'   => 'Cookie settings',
				'services' => 'Services',
				'always'   => 'Always active',
			),
			'necessary'          => array(
				'label'       => $de ? 'Notwendig' : 'Necessary',
				'description' => $de ? 'Für den Betrieb der Website nötig, zum Beispiel für Formulare, den Spamschutz und das Speichern Ihrer Auswahl.' : 'Needed to run the website, for example for forms, spam protection and storing your choice.',
				'services'    => $de
					? "SHD Consent | Diese Website | Speichert Ihre Auswahl (Cookie shd_consent, 12 Monate) |\nGoogle reCAPTCHA | Google Ireland Ltd. | Spamschutz für Formulare, erst beim Ausfüllen | $google"
					: "SHD Consent | This website | Stores your choice (cookie shd_consent, 12 months) |\nGoogle reCAPTCHA | Google Ireland Ltd. | Spam protection for forms, only when you fill one in | $google",
			),
			'categories'         => array(
				'functional'  => array(
					'enabled'     => true,
					'label'       => $de ? 'Funktional' : 'Functional',
					'description' => $de ? 'Zusätzliche Funktionen und eingebettete Inhalte.' : 'Additional features and embedded content.',
					'services'    => $de ? "YouTube | Google Ireland Ltd. | Videos, erst wenn Sie eines abspielen | $google" : "YouTube | Google Ireland Ltd. | Videos, only when you play one | $google",
				),
				'performance' => array(
					'enabled'     => true,
					'label'       => $de ? 'Statistik' : 'Statistics',
					'description' => $de ? "Hilft uns zu verstehen, wie die Website genutzt wird, damit wir sie verbessern können." : 'Helps us understand how the website is used, so we can improve it.',
					'services'    => "Google Analytics 4 | Google Ireland Ltd. | " . ( $de ? 'Besuchsstatistik' : 'Visitor statistics' ) . " | $google\nHotjar | Hotjar Ltd. | " . ( $de ? 'Nutzungsanalyse' : 'Usage analysis' ) . " | https://www.hotjar.com/legal/policies/privacy/\nSHD Analytics | " . ( $de ? 'Diese Website' : 'This website' ) . ' | ' . ( $de ? 'Wiederkehrende Besuche erkennen' : 'Recognising returning visits' ) . ' |',
				),
				'advertising' => array(
					'enabled'     => true,
					'label'       => 'Marketing',
					'description' => $de ? 'Misst den Erfolg unserer Werbung und ermöglicht passende Anzeigen auf anderen Websites.' : 'Measures the success of our advertising and allows relevant ads on other websites.',
					'services'    => "Google Ads | Google Ireland Ltd. | " . ( $de ? 'Conversion-Messung' : 'Conversion measurement' ) . " | $google\nLinkedIn Insight Tag | LinkedIn Ireland | " . ( $de ? 'Conversion-Messung, Zielgruppen' : 'Conversion measurement, audiences' ) . " | https://www.linkedin.com/legal/privacy-policy\nTikTok Pixel | TikTok Technology Ltd. | " . ( $de ? 'Conversion-Messung' : 'Conversion measurement' ) . " | https://www.tiktok.com/legal/privacy-policy\nOpenAI Ads | OpenAI | " . ( $de ? 'Conversion-Messung' : 'Conversion measurement' ) . " | https://openai.com/policies/privacy-policy\nLeadinfo | Leadinfo B.V. | " . ( $de ? 'Firmenbesuche erkennen' : 'Recognising company visits' ) . ' | https://www.leadinfo.com/privacy',
				),
			),
			'privacy_url'        => (string) get_privacy_policy_url(),
			'imprint_url'        => self::find_page( array( 'impressum', 'imprint', 'legal-notice' ) ),
			'colors'             => array(
				'background'  => '#ffffff',
				'text'        => '#1d2327',
				'button'      => '#001e5a',
				'button_text' => '#ffffff',
				'link'        => '#001e5a',
			),
			'layout'             => 'box',
			'days'               => 365,
			'version'            => '1',
			'reopen_button'      => true,
			'compat_cookiefirst' => true,
			'consent_mode'       => true,
			'block_scripts'      => true,
			'block_rules'        => array(),
			'log'                => true,
			'log_months'         => 24,
		);
	}

	/**
	 * @param string[] $slugs Page slugs to try.
	 * @return string URL of the first published page found, or ''.
	 */
	private static function find_page( array $slugs ) {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return (string) get_permalink( $page );
			}
		}
		return '';
	}

	/**
	 * Services of a category, one per line: Name | Provider | Purpose | Privacy policy URL.
	 *
	 * @param string $text Stored text.
	 * @return array<int,array{name:string,provider:string,purpose:string,url:string}>
	 */
	public static function services( $text ) {
		$list = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$list[] = array(
				'name'     => $parts[0],
				'provider' => isset( $parts[1] ) ? $parts[1] : '',
				'purpose'  => isset( $parts[2] ) ? $parts[2] : '',
				'url'      => isset( $parts[3] ) ? esc_url_raw( $parts[3] ) : '',
			);
		}
		return $list;
	}

	/**
	 * WCAG contrast ratio of two colours.
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 * @return float 1–21.
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/**
	 * @param string $hex Hex colour.
	 * @return float Relative luminance.
	 */
	private static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return 0.0;
		}
		$channels = array();
		foreach ( str_split( $hex, 2 ) as $part ) {
			$c          = hexdec( $part ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Another consent banner plugin that is active (then this one waits), or ''.
	 *
	 * @return string
	 */
	public static function other_banner() {
		$names = array(
			'cookiefirst'             => 'CookieFirst',
			'complianz'               => 'Complianz',
			'cookie-law-info'         => 'CookieYes',
			'cookieyes'               => 'CookieYes',
			'borlabs-cookie'          => 'Borlabs Cookie',
			'real-cookie-banner'      => 'Real Cookie Banner',
			'cookiebot'               => 'Cookiebot',
			'cookie-notice'           => 'Cookie Notice',
			'gdpr-cookie-compliance'  => 'GDPR Cookie Compliance',
			'iubenda'                 => 'iubenda',
			'usercentrics'            => 'Usercentrics',
		);
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$folder = strtolower( strtok( (string) $plugin, '/' ) );
			foreach ( $names as $prefix => $name ) {
				if ( 0 === strpos( $folder, $prefix ) ) {
					return $name;
				}
			}
		}
		return '';
	}

	/**
	 * Whether the banner is shown to visitors: on, or "auto" while no other banner plugin is active.
	 *
	 * @return bool
	 */
	public static function active() {
		$mode = self::get()['mode'];
		return 'on' === $mode || ( 'auto' === $mode && '' === self::other_banner() );
	}
}
