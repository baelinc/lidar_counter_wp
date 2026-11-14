<?php
defined( 'ABSPATH' ) || exit;

/** Server-side SVG charts and HTML snippets. No JavaScript, cache friendly, accessible. */
class LSCC_Render {

	/** Round a maximum up to a tidy axis value and return array( max, step ). */
	public static function nice_scale( $max, $ticks = 4 ) {
		$max = max( 1, (int) $max );
		$raw = $max / $ticks;
		$pow = pow( 10, floor( log10( $raw ) ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			if ( $m * $pow >= $raw ) {
				$step = $m * $pow;
				break;
			}
		}
		$step = max( 1, (int) ceil( $step ) );
		return array( $step * $ticks, $step );
	}

	private static function svg_open( $w, $h, $title ) {
		$id = 'lscc-t-' . wp_unique_id();
		return '<svg class="lscc-chart" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" role="img" aria-labelledby="' . esc_attr( $id ) . '" xmlns="http://www.w3.org/2000/svg"><title id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</title>';
	}

	/**
	 * @param string[] $labels
	 * @param int[]    $values
	 * @param array    $o  type (bar|line), height, title
	 */
	public static function chart( array $labels, array $values, $o = array() ) {
		$o = wp_parse_args( $o, array( 'type' => 'bar', 'height' => 260, 'title' => '' ) );
		$n = count( $values );
		if ( 0 === $n ) {
			return '<p class="lscc-empty">' . esc_html__( 'No data yet.', 'light-show-car-counter' ) . '</p>';
		}
		$W = 640;
		$H = max( 140, min( 700, (int) $o['height'] ) );
		$L = 44; $R = 10; $T = 22; $B = 36;
		$pw = $W - $L - $R;
		$ph = $H - $T - $B;
		list( $max, $step ) = self::nice_scale( max( $values ) );

		$svg = self::svg_open( $W, $H, $o['title'] ? $o['title'] : __( 'Cars counted', 'light-show-car-counter' ) );
		for ( $v = 0; $v <= $max; $v += $step ) {
			$y    = $T + $ph - ( $v / $max ) * $ph;
			$svg .= '<line class="lscc-grid" x1="' . $L . '" x2="' . ( $W - $R ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '"/>';
			$svg .= '<text class="lscc-axis" x="' . ( $L - 6 ) . '" y="' . round( $y + 4, 1 ) . '" text-anchor="end">' . esc_html( number_format_i18n( $v ) ) . '</text>';
		}
		$slot       = $pw / $n;
		$label_skip = (int) ceil( $n / 14 );
		$show_vals  = $n <= 20;
		$points     = array();
		foreach ( $values as $i => $val ) {
			$cx = $L + $slot * ( $i + 0.5 );
			$y  = $T + $ph - ( $val / $max ) * $ph;
			if ( 'line' === $o['type'] ) {
				$points[] = round( $cx, 1 ) . ',' . round( $y, 1 );
			} else {
				$bw   = max( 2, $slot * 0.7 );
				$svg .= '<rect class="lscc-bar" x="' . round( $cx - $bw / 2, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( max( 0, $T + $ph - $y ), 1 ) . '" rx="2"><title>' . esc_html( $labels[ $i ] . ': ' . number_format_i18n( $val ) ) . '</title></rect>';
				if ( $show_vals && $val > 0 ) {
					$svg .= '<text class="lscc-val" x="' . round( $cx, 1 ) . '" y="' . round( $y - 4, 1 ) . '" text-anchor="middle">' . esc_html( number_format_i18n( $val ) ) . '</text>';
				}
			}
			if ( 0 === $i % $label_skip ) {
				$svg .= '<text class="lscc-axis" x="' . round( $cx, 1 ) . '" y="' . ( $H - 14 ) . '" text-anchor="middle">' . esc_html( $labels[ $i ] ) . '</text>';
			}
		}
		if ( 'line' === $o['type'] ) {
			$svg .= '<polyline class="lscc-line" points="' . esc_attr( implode( ' ', $points ) ) . '" fill="none"/>';
			foreach ( $values as $i => $val ) {
				$cx   = $L + $slot * ( $i + 0.5 );
				$y    = $T + $ph - ( $val / $max ) * $ph;
				$svg .= '<circle class="lscc-dot" cx="' . round( $cx, 1 ) . '" cy="' . round( $y, 1 ) . '" r="3.5"><title>' . esc_html( $labels[ $i ] . ': ' . number_format_i18n( $val ) ) . '</title></circle>';
			}
		}
		return $svg . '</svg>';
	}

	public static function table( array $days, $o = array() ) {
		if ( ! $days ) {
			return '<p class="lscc-empty">' . esc_html__( 'No data yet.', 'light-show-car-counter' ) . '</p>';
		}
		$html  = '<table class="lscc-table"><thead><tr><th>' . esc_html__( 'Night', 'light-show-car-counter' ) . '</th><th class="lscc-num">' . esc_html__( 'Cars', 'light-show-car-counter' ) . '</th></tr></thead><tbody>';
		foreach ( $days as $d ) {
			$html .= '<tr><td>' . esc_html( LSCC_Settings::format_date( $d['date'] ) ) . '</td><td class="lscc-num">' . esc_html( number_format_i18n( $d['total'] ) ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	public static function card( $label, $value, $sub = '' ) {
		return '<div class="lscc-card"><div class="lscc-card-label">' . esc_html( $label ) . '</div><div class="lscc-card-value">' . esc_html( $value ) . '</div>'
			. ( '' !== $sub ? '<div class="lscc-card-sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>';
	}

	/** Wrap output so the accent colour from settings is applied. */
	public static function wrap( $inner, $class = '' ) {
		return '<div class="lscc ' . esc_attr( $class ) . '" style="--lscc-accent:' . esc_attr( LSCC_Settings::get( 'accent' ) ) . '">' . $inner . '</div>';
	}
}
