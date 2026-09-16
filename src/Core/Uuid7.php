<?php
/**
 * UUIDv7 (RFC 9562). Object IDs / tracing / invalidation / logging only.
 *
 * Layout: unix_ts_ms(48b) | ver(4)=0111 | rand_a(12b) | var(2)=10 | rand_b(62b)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

final class Uuid7 {

	/**
	 * @param int|null $ms Unix epoch milliseconds (tests may pin).
	 * @return string canonical lowercase, 36 chars.
	 */
	public static function generate( $ms = null ) {
		if ( null === $ms ) {
			$ms = (int) floor( microtime( true ) * 1000 );
		}
		$hex = str_pad( dechex( (int) $ms ), 12, '0', STR_PAD_LEFT );
		if ( strlen( $hex ) > 12 ) {
			$hex = substr( $hex, -12 );
		}

		$b  = random_bytes( 10 );
		$b6 = ( ord( $b[0] ) & 0x0F ) | 0x70; // version nibble
		$b8 = ( ord( $b[2] ) & 0x3F ) | 0x80; // variant bits

		return sprintf(
			'%s-%s-7%s-%s-%s',
			substr( $hex, 0, 8 ),                       // ts[47:16]
			substr( $hex, 8, 4 ),                       // ts[15:0]
			substr( '0' . dechex( $b6 ), -1 ) . bin2hex( $b[1] ),
			sprintf( '%02x%02x', $b8, ord( $b[3] ) ),
			bin2hex( substr( $b, 4, 6 ) )
		);
	}
}
