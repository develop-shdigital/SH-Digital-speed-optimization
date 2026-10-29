<?php
/**
 * Minimal TrueType/OpenType reader for fallback-font metrics.
 *
 * Reads unitsPerEm, the hhea vertical metrics and the advance widths of common
 * letters, then derives `size-adjust` and ascent/descent/line-gap overrides that
 * make a local Arial take up the same space as the web font. Only uncompressed
 * sfnt files (.ttf/.otf) are read; WOFF/WOFF2 are compressed and not parsed.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Font_Metrics {

	/**
	 * Relative frequency of characters in German running text (spaces included),
	 * used to weight the average glyph width.
	 */
	const FREQUENCY = array(
		' ' => 15.0,
		'e' => 14.8,
		'n' => 8.3,
		'i' => 6.4,
		's' => 6.2,
		'r' => 6.0,
		'a' => 5.5,
		't' => 5.3,
		'd' => 4.3,
		'h' => 4.1,
		'u' => 3.7,
		'l' => 2.9,
		'c' => 2.6,
		'g' => 2.6,
		'm' => 2.1,
		'o' => 2.1,
		'b' => 1.6,
		'w' => 1.6,
		'f' => 1.4,
		'k' => 1.0,
		'z' => 1.0,
		'p' => 0.7,
		'v' => 0.6,
		'j' => 0.2,
		'y' => 0.1,
		'x' => 0.1,
		'q' => 0.05,
	);

	/**
	 * Arial advance widths (units per em 2048) for the characters above.
	 */
	const ARIAL_WIDTHS = array(
		' ' => 569,
		'a' => 1139,
		'b' => 1139,
		'c' => 1024,
		'd' => 1139,
		'e' => 1139,
		'f' => 569,
		'g' => 1139,
		'h' => 1139,
		'i' => 455,
		'j' => 455,
		'k' => 1024,
		'l' => 455,
		'm' => 1706,
		'n' => 1139,
		'o' => 1139,
		'p' => 1139,
		'q' => 1139,
		'r' => 682,
		's' => 1024,
		't' => 569,
		'u' => 1139,
		'v' => 1024,
		'w' => 1479,
		'x' => 1024,
		'y' => 1024,
		'z' => 1024,
	);

	const ARIAL_UPM = 2048;

	/**
	 * Read a font file and compute fallback overrides.
	 *
	 * @param string $path Absolute path to a .ttf/.otf file.
	 * @return array|null { size_adjust, ascent, descent, line_gap } as percentages, or null.
	 */
	public static function fallback_overrides( $path ) {
		$metrics = self::read( $path );
		if ( null === $metrics ) {
			return null;
		}

		$font_avg  = self::weighted_average( $metrics['widths'] ) / $metrics['units_per_em'];
		$arial_avg = self::weighted_average( self::ARIAL_WIDTHS ) / self::ARIAL_UPM;
		if ( $font_avg <= 0 || $arial_avg <= 0 ) {
			return null;
		}

		$size_adjust = $font_avg / $arial_avg;
		$upm         = $metrics['units_per_em'];

		return array(
			'size_adjust' => round( $size_adjust * 100, 2 ),
			'ascent'      => round( $metrics['ascender'] / $upm / $size_adjust * 100, 2 ),
			'descent'     => round( abs( $metrics['descender'] ) / $upm / $size_adjust * 100, 2 ),
			'line_gap'    => round( max( 0, $metrics['line_gap'] ) / $upm / $size_adjust * 100, 2 ),
		);
	}

	/**
	 * @param array<string,int> $widths Advance widths keyed by character.
	 * @return float
	 */
	private static function weighted_average( array $widths ) {
		$sum    = 0.0;
		$weight = 0.0;
		foreach ( self::FREQUENCY as $char => $frequency ) {
			if ( isset( $widths[ $char ] ) ) {
				$sum    += $widths[ $char ] * $frequency;
				$weight += $frequency;
			}
		}
		return $weight > 0 ? $sum / $weight : 0.0;
	}

	/**
	 * Parse the tables we need.
	 *
	 * @param string $path Font file.
	 * @return array|null
	 */
	public static function read( $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) > 20 * MB_IN_BYTES ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local font file.
		$data = file_get_contents( $path );
		if ( false === $data || strlen( $data ) < 12 ) {
			return null;
		}

		$tag = substr( $data, 0, 4 );
		if ( "\x00\x01\x00\x00" !== $tag && 'OTTO' !== $tag && 'true' !== $tag ) {
			return null;
		}

		$tables = array();
		$count  = self::u16( $data, 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$record = 12 + 16 * $i;
			if ( $record + 16 > strlen( $data ) ) {
				return null;
			}
			$tables[ substr( $data, $record, 4 ) ] = self::u32( $data, $record + 8 );
		}

		foreach ( array( 'head', 'hhea', 'hmtx', 'cmap' ) as $required ) {
			if ( ! isset( $tables[ $required ] ) ) {
				return null;
			}
		}

		$units_per_em = self::u16( $data, $tables['head'] + 18 );
		$hhea         = $tables['hhea'];
		$metrics      = array(
			'units_per_em' => $units_per_em > 0 ? $units_per_em : 1000,
			'ascender'     => self::i16( $data, $hhea + 4 ),
			'descender'    => self::i16( $data, $hhea + 6 ),
			'line_gap'     => self::i16( $data, $hhea + 8 ),
			'widths'       => array(),
		);
		$h_metrics    = self::u16( $data, $hhea + 34 );

		$cmap = self::cmap_lookup( $data, $tables['cmap'] );
		if ( null === $cmap ) {
			return null;
		}

		foreach ( array_keys( self::FREQUENCY ) as $char ) {
			$glyph = $cmap( ord( $char ) );
			if ( $glyph > 0 && $h_metrics > 0 ) {
				$index                       = min( $glyph, $h_metrics - 1 );
				$metrics['widths'][ $char ] = self::u16( $data, $tables['hmtx'] + 4 * $index );
			}
		}

		return $metrics;
	}

	/**
	 * Characters the font has glyphs for, as a CSS unicode-range value.
	 *
	 * @param string $path TrueType/OpenType file.
	 * @return string '' when the file cannot be read.
	 */
	public static function unicode_ranges( $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) > 20 * MB_IN_BYTES ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local font file.
		$data = (string) file_get_contents( $path );
		$tag  = substr( $data, 0, 4 );
		if ( strlen( $data ) < 12 || ( "\x00\x01\x00\x00" !== $tag && 'OTTO' !== $tag && 'true' !== $tag ) ) {
			return '';
		}
		$cmap  = null;
		$count = self::u16( $data, 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$record = 12 + 16 * $i;
			if ( 'cmap' === substr( $data, $record, 4 ) ) {
				$cmap = self::u32( $data, $record + 8 );
			}
		}
		if ( null === $cmap ) {
			return '';
		}
		$lookup = self::cmap_lookup( $data, $cmap );
		if ( null === $lookup ) {
			return '';
		}

		// Walk the Basic Multilingual Plane (plus the supplementary planes a format 12 table may map).
		$ranges = array();
		$start  = null;
		$prev   = null;
		$limit  = 0x2FFFF;
		for ( $code = 0x20; $code <= $limit; $code++ ) {
			if ( 0x10000 === $code && $lookup( 0x1F600 ) === 0 && $lookup( 0x10000 ) === 0 && ! self::has_supplementary( $data, $cmap ) ) {
				break;
			}
			if ( $lookup( $code ) > 0 ) {
				if ( null === $start ) {
					$start = $code;
				}
				$prev = $code;
			} elseif ( null !== $start ) {
				$ranges[] = $start === $prev ? sprintf( 'U+%04X', $start ) : sprintf( 'U+%04X-%04X', $start, $prev );
				$start    = null;
			}
		}
		if ( null !== $start ) {
			$ranges[] = $start === $prev ? sprintf( 'U+%04X', $start ) : sprintf( 'U+%04X-%04X', $start, $prev );
		}
		return implode( ',', $ranges );
	}

	/**
	 * Whether the font has a format 12 subtable (the only kind that can map beyond U+FFFF).
	 *
	 * @param string $data Font data.
	 * @param int    $cmap Offset of the cmap table.
	 * @return bool
	 */
	private static function has_supplementary( $data, $cmap ) {
		$count = self::u16( $data, $cmap + 2 );
		for ( $i = 0; $i < $count; $i++ ) {
			$offset = $cmap + self::u32( $data, $cmap + 4 + 8 * $i + 4 );
			if ( 12 === self::u16( $data, $offset ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a char-code => glyph-id resolver from a format 4 or 12 Unicode subtable.
	 *
	 * @param string $data  Font data.
	 * @param int    $cmap  Offset of the cmap table.
	 * @return callable|null
	 */
	private static function cmap_lookup( $data, $cmap ) {
		$count   = self::u16( $data, $cmap + 2 );
		$best    = null;
		$best_rk = -1;
		for ( $i = 0; $i < $count; $i++ ) {
			$record   = $cmap + 4 + 8 * $i;
			$platform = self::u16( $data, $record );
			$encoding = self::u16( $data, $record + 2 );
			$offset   = $cmap + self::u32( $data, $record + 4 );
			$format   = self::u16( $data, $offset );
			$rank     = -1;
			if ( 3 === $platform && 10 === $encoding && 12 === $format ) {
				$rank = 3;
			} elseif ( 3 === $platform && 1 === $encoding && 4 === $format ) {
				$rank = 2;
			} elseif ( 0 === $platform && in_array( $format, array( 4, 12 ), true ) ) {
				$rank = 1;
			}
			if ( $rank > $best_rk ) {
				$best_rk = $rank;
				$best    = array( $offset, $format );
			}
		}
		if ( null === $best ) {
			return null;
		}

		list( $offset, $format ) = $best;

		if ( 12 === $format ) {
			$groups = self::u32( $data, $offset + 12 );
			return static function ( $code ) use ( $data, $offset, $groups ) {
				for ( $g = 0; $g < $groups; $g++ ) {
					$group = $offset + 16 + 12 * $g;
					$start = self::u32( $data, $group );
					$end   = self::u32( $data, $group + 4 );
					if ( $code >= $start && $code <= $end ) {
						return self::u32( $data, $group + 8 ) + ( $code - $start );
					}
				}
				return 0;
			};
		}

		$segments    = self::u16( $data, $offset + 6 ) / 2;
		$end_codes   = $offset + 14;
		$start_codes = $end_codes + 2 * $segments + 2;
		$id_deltas   = $start_codes + 2 * $segments;
		$id_ranges   = $id_deltas + 2 * $segments;

		return static function ( $code ) use ( $data, $segments, $end_codes, $start_codes, $id_deltas, $id_ranges ) {
			for ( $s = 0; $s < $segments; $s++ ) {
				$end = self::u16( $data, $end_codes + 2 * $s );
				if ( $code > $end ) {
					continue;
				}
				$start = self::u16( $data, $start_codes + 2 * $s );
				if ( $code < $start ) {
					return 0;
				}
				$delta        = self::u16( $data, $id_deltas + 2 * $s );
				$range_offset = self::u16( $data, $id_ranges + 2 * $s );
				if ( 0 === $range_offset ) {
					return ( $code + $delta ) & 0xFFFF;
				}
				$glyph = self::u16( $data, $id_ranges + 2 * $s + $range_offset + 2 * ( $code - $start ) );
				return 0 === $glyph ? 0 : ( $glyph + $delta ) & 0xFFFF;
			}
			return 0;
		};
	}

	private static function u16( $data, $offset ) {
		$value = unpack( 'n', substr( $data, $offset, 2 ) );
		return false === $value ? 0 : $value[1];
	}

	private static function i16( $data, $offset ) {
		$value = self::u16( $data, $offset );
		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	private static function u32( $data, $offset ) {
		$value = unpack( 'N', substr( $data, $offset, 4 ) );
		return false === $value ? 0 : $value[1];
	}
}
