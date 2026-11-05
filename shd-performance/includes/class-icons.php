<?php
/**
 * SH Digital's own SVG drawings of the IcoFont icons used on the site.
 *
 * LA-Studio Kit loads IcoFont, a 92 KB stylesheet and a 525 KB font with 2,100 icons, for
 * six icons: the delivery truck on /seo-spezialisten/ and the boxed check marks on
 * /geo-agentur/. Each drawing uses the font's grid (1,000 units per em) and covers the
 * same area as the original glyph, so it sits in the same <i> box at the same size and
 * nothing on the page moves. Filled shapes only, in currentColor: Elementor's icon
 * colours (also on hover) apply as before.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Icons {

	/** IcoFont class => array( fill rule, path ). */
	const DRAWINGS = array(
		// Van with three speed lines; the window and the wheel hubs are holes (even-odd).
		'icofont-fast-delivery' => array(
			'evenodd',
			'M132 310H230A20 20 0 0 1 230 350H132A20 20 0 0 1 132 310ZM86 400H230A20 20 0 0 1 230 440H86A20 20 0 0 1 86 400ZM170 490H230A20 20 0 0 1 230 530H170A20 20 0 0 1 170 490ZM326 254H636A36 36 0 0 1 672 290V326H768Q784 326 795 337L927 469Q938 480 938 496V608A32 32 0 0 1 906 640H896.32A108 108 0 0 0 683.68 640H546.32A108 108 0 0 0 333.68 640H322A32 32 0 0 1 290 608V290A36 36 0 0 1 326 254ZM700 362H770L858 450H700ZM354 659A86 86 0 1 0 526 659A86 86 0 1 0 354 659ZM410 659A30 30 0 1 0 470 659A30 30 0 1 0 410 659ZM704 659A86 86 0 1 0 876 659A86 86 0 1 0 704 659ZM760 659A30 30 0 1 0 820 659A30 30 0 1 0 760 659Z',
		),
		// Rounded box, open at the top right where a bold check mark with round ends leaves it.
		'icofont-tick-boxed'    => array(
			'nonzero',
			'M680 143H210A147 147 0 0 0 63 290V766A147 147 0 0 0 210 913H680A147 147 0 0 0 827 766V460A37 37 0 0 0 753 460V766A73 73 0 0 1 680 839H210A73 73 0 0 1 137 766V290A73 73 0 0 1 210 217H680A37 37 0 0 0 680 143ZM303.84 476.16L425.61 597.93L827.19 111.53A62 62 0 0 1 922.81 190.47L477.81 729.47A62 62 0 0 1 386.16 733.84L216.16 563.84A62 62 0 0 1 303.84 476.16Z',
		),
	);

	/**
	 * @param string $name IcoFont class, for example "icofont-tick-boxed".
	 * @return string Inline SVG one em square, or '' when there is no drawing for it.
	 */
	public static function svg( $name ) {
		if ( ! isset( self::DRAWINGS[ $name ] ) ) {
			return '';
		}
		list( $rule, $path ) = self::DRAWINGS[ $name ];
		return '<svg viewBox="0 0 1000 1000" width="1em" height="1em" fill="currentColor"' . ( 'evenodd' === $rule ? ' fill-rule="evenodd"' : '' )
			. ' aria-hidden="true" focusable="false" style="display:block"><path d="' . $path . '"/></svg>';
	}

	/**
	 * @param string $name IcoFont class.
	 * @return string The class the drawn icon gets instead ("shd-icon-tick-boxed").
	 */
	public static function class_name( $name ) {
		return 'shd-icon-' . substr( $name, strlen( 'icofont-' ) );
	}
}
