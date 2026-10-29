<?php
/**
 * Inline scripts and styles the optimizer injects.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/** @var array<string,string> */
	private static $scripts = array();

	/**
	 * Script source for inlining (minified build when present).
	 *
	 * @param string $name delay|youtube|recaptcha|rum|lazy-bg.
	 * @return string
	 */
	public static function script( $name ) {
		if ( isset( self::$scripts[ $name ] ) ) {
			return self::$scripts[ $name ];
		}
		$name = preg_replace( '/[^a-z0-9-]/', '', $name );
		$min  = SHD_PERF_DIR . 'assets/js/min/' . $name . '.min.js';
		$file = is_readable( $min ) ? $min : SHD_PERF_DIR . 'assets/js/' . $name . '.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled asset.
		$code = is_readable( $file ) ? (string) file_get_contents( $file ) : '';

		self::$scripts[ $name ] = str_ireplace( '</script', '<\/script', trim( $code ) );
		return self::$scripts[ $name ];
	}

	/**
	 * Styles for the YouTube facade.
	 *
	 * @return string
	 */
	public static function youtube_css() {
		return '.shd-yt-slot{position:relative;width:100%;height:100%}'
			. '.shd-yt{position:absolute;inset:0;display:block;width:100%;height:100%;margin:0;padding:0;border:0;border-radius:0;background:#000;cursor:pointer;overflow:hidden}'
			. '.shd-yt img{position:absolute;inset:0;width:100%;height:100%;max-width:none;object-fit:cover;border:0}'
			. '.shd-yt__play{position:absolute;left:50%;top:50%;width:68px;height:48px;transform:translate(-50%,-50%);opacity:.9;transition:opacity .2s}'
			. '.shd-yt:hover .shd-yt__play,.shd-yt:focus-visible .shd-yt__play{opacity:1}'
			. '.shd-yt:focus-visible{outline:3px solid #fff;outline-offset:-6px}'
			. '.shd-yt-player{display:block;width:100%;height:100%;border:0}';
	}

	/**
	 * Background images of marked elements stay off until lazy-bg.js adds .shd-bg-in.
	 *
	 * @return string
	 */
	public static function lazy_bg_css() {
		$off       = '[data-shd-lazy-bg]:not(.shd-bg-in)';
		$selectors = array( $off, $off . '::before', $off . '::after' );
		// Only the elements Elementor paints backgrounds on (cheaper to match than "*" on large pages).
		foreach ( array( '.elementor-element', '.elementor-element::before', '.elementor-element::after', '.elementor-widget-container', '.elementor-motion-effects-layer', '.elementor-motion-effects-layer::before', '.elementor-background-overlay', '.elementor-background-slideshow::before', '.elementor-background-slideshow__slide__image', '.elementor-background-video-container::before' ) as $target ) {
			$selectors[] = $off . ' ' . $target;
		}
		return implode( ',', $selectors ) . '{background-image:none!important}';
	}

	/**
	 * A table of contents set to "Minimize Box" (which the plugin does not fill in) is
	 * shown minimized on phones while it loads, as Elementor Pro collapses it there anyway.
	 * With $collapsed, filled-in lists marked data-shd-toc-min start collapsed on phones
	 * and their buttons are hidden on larger screens.
	 *
	 * @param int  $max_width Mobile breakpoint in px.
	 * @param bool $collapsed Whether a list starts collapsed on phones.
	 * @return string
	 */
	public static function toc_css( $max_width, $collapsed = false ) {
		$toc = '.elementor-widget-table-of-contents';
		$own = '';
		if ( $collapsed ) {
			$min  = '[data-shd-toc-min]';
			$own  = '@media (max-width:' . (int) $max_width . 'px){'
				. $min . ' .elementor-toc__toggle-button{cursor:pointer;display:inline-flex}'
				. $min . '.elementor-toc--collapsed .elementor-toc__body,'
				. $min . '.elementor-toc--collapsed .elementor-toc__toggle-button--collapse,'
				. $min . ':not(.elementor-toc--collapsed) .elementor-toc__toggle-button--expand{display:none}'
				. '}'
				. '@media (min-width:' . ( (int) $max_width + 1 ) . 'px){' . $min . ' .elementor-toc__toggle-button{display:none}}'
				. $min . ' .elementor-toc__toggle-button svg{width:1em;height:1em;fill:var(--toggle-button-color,currentColor)}';
		}
		return $own . '@media (max-width:' . (int) $max_width . 'px){'
			. $toc . ':has(.elementor-toc__toggle-button--expand) .elementor-toc__body:has(.elementor-toc__spinner-container){display:none!important}'
			. $toc . ':has(.elementor-toc__spinner-container) .elementor-toc__toggle-button--collapse{display:none!important}'
			. $toc . ':has(.elementor-toc__spinner-container) .elementor-toc__toggle-button--expand{display:inline-flex!important}'
			. '}';
	}

	/**
	 * Lighter version of the site's cursor-dot script: same easing on mouse
	 * devices, hidden on touch devices, and no redraw loop while the mouse rests.
	 *
	 * @param string $original Original inline script.
	 * @return string
	 */
	public static function cursor_script( $original ) {
		$factor = 0.15;
		if ( preg_match( '/\(\s*mouseX\s*-\s*dotX\s*\)\s*\*\s*([0-9]*\.?[0-9]+)/', $original, $m ) ) {
			$factor = (float) $m[1];
		}
		$factor = ( $factor > 0 && $factor <= 1 ) ? $factor : 0.15;

		return '/* shd-cursor */document.addEventListener("DOMContentLoaded",function(){'
			. 'var d=document.getElementById("cursor-dot");if(!d){return}'
			. 'if(!(window.matchMedia&&window.matchMedia("(hover: hover) and (pointer: fine)").matches)){d.style.display="none";return}'
			. 'var x=0,y=0,mx=0,my=0,raf=0,k=' . $factor . ';d.style.transform="translate(0px, 0px)";'
			. 'function step(){x+=(mx-x)*k;y+=(my-y)*k;d.style.transform="translate("+x+"px, "+y+"px)";'
			. 'raf=(Math.abs(mx-x)>0.1||Math.abs(my-y)>0.1)?requestAnimationFrame(step):0}'
			. 'document.addEventListener("mousemove",function(e){mx=e.clientX;my=e.clientY;if(!raf){raf=requestAnimationFrame(step)}},{passive:true})'
			. '});';
	}
}
