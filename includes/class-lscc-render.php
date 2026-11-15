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
		$people = false;
		foreach ( $days as $d ) {
			if ( ! empty( $d['people'] ) ) {
				$people = true;
				break;
			}
		}
		if ( isset( $o['people'] ) && false === $o['people'] ) {
			$people = false;
		}
		$html  = '<table class="lscc-table"><thead><tr><th>' . esc_html__( 'Night', 'light-show-car-counter' ) . '</th><th class="lscc-num">' . esc_html__( 'Cars', 'light-show-car-counter' ) . '</th>'
			. ( $people ? '<th class="lscc-num">' . esc_html__( 'People (est.)', 'light-show-car-counter' ) . '</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $days as $d ) {
			$html .= '<tr><td>' . esc_html( LSCC_Settings::format_date( $d['date'] ) ) . '</td><td class="lscc-num">' . esc_html( number_format_i18n( $d['total'] ) ) . '</td>'
				. ( $people ? '<td class="lscc-num">' . ( $d['people'] > 0 ? esc_html( number_format_i18n( $d['people'] ) ) : '&mdash;' ) . '</td>' : '' ) . '</tr>';
		}
		return $html . '</tbody></table>';
	}

	/** Small inline icons (24x24, stroke based) keyed by stat. */
	public static function icon( $key ) {
		$paths = array(
			'car'      => '<path d="M5 16l1.4-4.6A2 2 0 0 1 8.3 10h7.4a2 2 0 0 1 1.9 1.4L19 16"/><rect x="3" y="16" width="18" height="4" rx="1.5"/><circle cx="7.5" cy="18" r=".6"/><circle cx="16.5" cy="18" r=".6"/><path d="M9 5l3-2 3 2"/>',
			'moon'     => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
			'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
			'star'     => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8 6.8 19.6l1-5.8L3.5 9.7l5.9-.9z"/>',
			'bars'     => '<path d="M5 20V11M12 20V5M19 20v-7"/>',
			'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/>',
			'people'   => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.4-3.4 2.7-5.2 5.5-5.2s5.1 1.8 5.5 5.2"/><circle cx="17" cy="9" r="2.4"/><path d="M16 13.9c2.6.2 4.2 1.8 4.5 4.6"/>',
			'sync'     => '<path d="M20 11a8 8 0 0 0-14-4L4 9M4 13a8 8 0 0 0 14 4l2-2M4 4v5h5M20 20v-5h-5"/>',
		);
		if ( ! isset( $paths[ $key ] ) ) {
			return '';
		}
		return '<svg class="lscc-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $key ] . '</svg>';
	}

	/** Tiny area/line chart for the hero card. $values: list of numbers, oldest first. */
	public static function sparkline( array $values ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			return '';
		}
		$w = 240; $h = 56; $pad = 4;
		$max = max( $values );
		$max = $max > 0 ? $max : 1;
		$pts = array();
		foreach ( $values as $i => $v ) {
			$x     = round( $pad + ( $w - 2 * $pad ) * $i / ( $n - 1 ), 1 );
			$y     = round( $h - $pad - ( $h - 2 * $pad ) * ( $v / $max ), 1 );
			$pts[] = $x . ',' . $y;
		}
		$last = explode( ',', end( $pts ) );
		return '<svg class="lscc-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Cars per night, recent trend', 'light-show-car-counter' ) . '">'
			. '<polygon class="lscc-spark-area" points="' . $pad . ',' . ( $h - $pad ) . ' ' . implode( ' ', $pts ) . ' ' . ( $w - $pad ) . ',' . ( $h - $pad ) . '"/>'
			. '<polyline class="lscc-spark-line" fill="none" points="' . implode( ' ', $pts ) . '"/>'
			. '<circle class="lscc-spark-dot" cx="' . esc_attr( $last[0] ) . '" cy="' . esc_attr( $last[1] ) . '" r="3.2"/></svg>';
	}

	/**
	 * A stat card.
	 * $o: icon (key), hero (bool), spark (array), progress (0..1), trend (array(dir,text)), live (bool), count (int for count-up)
	 */
	public static function card( $label, $value, $sub = '', $o = array() ) {
		$o     = is_array( $o ) ? $o : array();
		$class = 'lscc-card' . ( ! empty( $o['hero'] ) ? ' lscc-card-hero' : '' ) . ( ! empty( $o['icon'] ) ? ' lscc-has-icon' : '' );
		$h     = '<div class="' . esc_attr( $class ) . '">';
		$h    .= '<div class="lscc-card-head">' . ( ! empty( $o['icon'] ) ? '<span class="lscc-badge-icon">' . self::icon( $o['icon'] ) . '</span>' : '' )
			. '<span class="lscc-card-label">' . esc_html( $label ) . '</span>'
			. '</div>';
		$cnt   = isset( $o['count'] ) && null !== $o['count'] ? ' data-lscc-count="' . (int) $o['count'] . '"' : '';
		$h    .= '<div class="lscc-card-value"' . $cnt . '>' . esc_html( $value ) . '</div>';
		if ( ! empty( $o['live'] ) ) {
			$h .= '<div class="lscc-live" title="' . esc_attr__( 'Receiving live data', 'light-show-car-counter' ) . '"><i></i>' . esc_html__( 'Live', 'light-show-car-counter' ) . '</div>';
		}
		if ( ! empty( $o['trend'] ) ) {
			$h .= '<div class="lscc-trend lscc-trend-' . esc_attr( $o['trend'][0] ) . '">' . esc_html( $o['trend'][1] ) . '</div>';
		}
		if ( '' !== $sub ) {
			$h .= '<div class="lscc-card-sub">' . esc_html( $sub ) . '</div>';
		}
		if ( isset( $o['progress'] ) && null !== $o['progress'] ) {
			$pct = max( 0, min( 100, round( $o['progress'] * 100 ) ) );
			$h  .= '<div class="lscc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . (int) $pct . '%"></span></div>';
		}
		if ( ! empty( $o['spark'] ) ) {
			$h .= self::sparkline( $o['spark'] );
		}
		return $h . '</div>';
	}

	/** Wrap output so the accent colour from settings is applied. */
	public static function wrap( $inner, $class = '' ) {
		return '<div class="lscc ' . esc_attr( $class ) . '" style="--lscc-accent:' . esc_attr( LSCC_Settings::get( 'accent' ) ) . '">' . $inner . '</div>';
	}
}
