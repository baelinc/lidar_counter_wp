<?php
defined( 'ABSPATH' ) || exit;

/**
 * [car_counter]        a single number         stat="total|tonight|last_night|best_night|best_night_date|average|nights|busiest_hour|updated|date"
 * [car_counter_stats]  a grid of number cards
 * [car_counter_chart]  SVG chart               view="daily|hourly" type="bar|line"
 * [car_counter_table]  nightly table
 */
class LSCC_Shortcodes {

	const LIVE_STATS = array( 'total', 'tonight', 'last_night', 'nights' );

	public static function init() {
		add_shortcode( 'car_counter', array( __CLASS__, 'stat' ) );
		add_shortcode( 'car_counter_stats', array( __CLASS__, 'stats' ) );
		add_shortcode( 'car_counter_chart', array( __CLASS__, 'chart' ) );
		add_shortcode( 'car_counter_table', array( __CLASS__, 'table' ) );
	}

	private static function assets() {
		wp_enqueue_style( 'lscc', LSCC_URL . 'assets/lscc.css', array(), LSCC_VERSION );
	}

	private static function range( $atts ) {
		$since = ( isset( $atts['since'] ) && LSCC_Settings::valid_date( $atts['since'] ) ) ? $atts['since'] : null;
		$until = ( isset( $atts['until'] ) && LSCC_Settings::valid_date( $atts['until'] ) ) ? $atts['until'] : null;
		return array( $since, $until );
	}

	private static function truthy( $v ) {
		return in_array( strtolower( (string) $v ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/** Returns array( label, display text, raw number|null, sub text ). */
	public static function stat_data( $stat, array $s, $atts = array() ) {
		$dash = "\xE2\x80\x94";
		switch ( $stat ) {
			case 'tonight':
				return array( __( 'Cars tonight', 'light-show-car-counter' ), number_format_i18n( $s['tonight'] ), $s['tonight'], '' );
			case 'last_night':
				$n = $s['last_night'] ? $s['last_night']['total'] : 0;
				return array( __( 'Cars last night', 'light-show-car-counter' ), $s['last_night'] ? number_format_i18n( $n ) : $dash, $n, $s['last_night'] ? LSCC_Settings::format_date( $s['last_night']['date'] ) : '' );
			case 'best_night':
				return array( __( 'Best night', 'light-show-car-counter' ), $s['best'] ? number_format_i18n( $s['best']['total'] ) : $dash, $s['best'] ? $s['best']['total'] : 0, $s['best'] ? LSCC_Settings::format_date( $s['best']['date'] ) : '' );
			case 'best_night_date':
				return array( __( 'Best night', 'light-show-car-counter' ), $s['best'] ? LSCC_Settings::format_date( $s['best']['date'] ) : $dash, null, '' );
			case 'average':
				return array( __( 'Average per night', 'light-show-car-counter' ), $s['nights'] ? number_format_i18n( $s['average'] ) : $dash, $s['average'], '' );
			case 'nights':
				return array( __( 'Nights open', 'light-show-car-counter' ), number_format_i18n( $s['nights'] ), $s['nights'], '' );
			case 'busiest_hour':
				return array( __( 'Busiest hour', 'light-show-car-counter' ), null === $s['busiest_hour'] ? $dash : LSCC_Settings::format_hour( $s['busiest_hour'] ), null, '' );
			case 'updated':
				return array( __( 'Last updated', 'light-show-car-counter' ), $s['updated'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['updated'] ) : $dash, null, '' );
			case 'date':
				$d = ( isset( $atts['date'] ) && LSCC_Settings::valid_date( $atts['date'] ) ) ? LSCC_DB::get_day( $atts['date'] ) : null;
				$n = $d ? $d['total'] : 0;
				return array( $d ? LSCC_Settings::format_date( $d['date'] ) : '', $d ? number_format_i18n( $n ) : $dash, $n, '' );
			case 'total':
			default:
				return array( __( 'Cars so far', 'light-show-car-counter' ), number_format_i18n( $s['total'] ), $s['total'], '' );
		}
	}

	private static function stat_names() {
		return array( 'total', 'tonight', 'last_night', 'best_night', 'best_night_date', 'average', 'nights', 'busiest_hour', 'updated', 'date' );
	}

	/* ---------------- [car_counter] ---------------- */
	public static function stat( $atts ) {
		$atts = shortcode_atts( array(
			'stat' => 'total', 'label' => '', 'prefix' => '', 'suffix' => '', 'format' => 'number',
			'live' => 'false', 'since' => '', 'until' => '', 'date' => '', 'class' => '',
		), $atts, 'car_counter' );
		self::assets();

		$stat = sanitize_key( $atts['stat'] );
		if ( ! in_array( $stat, self::stat_names(), true ) ) {
			$stat = 'total';
		}
		list( $since, $until ) = self::range( $atts );
		$s    = LSCC_DB::summary( $since, $until );
		$data = self::stat_data( $stat, $s, $atts );
		$text = ( 'raw' === $atts['format'] && null !== $data[2] ) ? (string) $data[2] : $data[1];

		$live_attr = '';
		if ( self::truthy( $atts['live'] ) && in_array( $stat, self::LIVE_STATS, true ) && null === $since && null === $until && LSCC_Settings::get( 'public_api' ) ) {
			$live_attr = ' data-lscc-live="' . esc_attr( $stat ) . '" data-lscc-format="' . esc_attr( $atts['format'] ) . '"';
			self::enqueue_live();
		}
		$value = '<span class="' . esc_attr( trim( 'lscc-stat lscc-stat-' . $stat . ' ' . $atts['class'] ) ) . '"' . $live_attr . '>' . esc_html( $text ) . '</span>';
		if ( '' === $atts['label'] && '' === $atts['prefix'] && '' === $atts['suffix'] ) {
			return $value;
		}
		return '<span class="lscc-stat-wrap">'
			. ( '' !== $atts['label'] ? '<span class="lscc-stat-label">' . esc_html( $atts['label'] ) . '</span> ' : '' )
			. esc_html( $atts['prefix'] ) . $value . esc_html( $atts['suffix'] ) . '</span>';
	}

	private static function enqueue_live() {
		if ( wp_script_is( 'lscc-live', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( 'lscc-live', LSCC_URL . 'assets/lscc-live.js', array(), LSCC_VERSION, true );
		wp_localize_script( 'lscc-live', 'LSCC_LIVE', array(
			'url'      => esc_url_raw( rest_url( 'lscc/v1/public' ) ),
			'interval' => 60,
		) );
	}

	/* ---------------- [car_counter_stats] ---------------- */
	public static function stats( $atts ) {
		$atts = shortcode_atts( array(
			'cards' => 'total,nights,average,best_night', 'title' => '', 'since' => '', 'until' => '',
		), $atts, 'car_counter_stats' );
		self::assets();
		list( $since, $until ) = self::range( $atts );
		$s     = LSCC_DB::summary( $since, $until );
		$cards = '';
		foreach ( array_slice( array_map( 'sanitize_key', explode( ',', $atts['cards'] ) ), 0, 12 ) as $name ) {
			if ( ! in_array( $name, self::stat_names(), true ) || 'date' === $name ) {
				continue;
			}
			$d      = self::stat_data( $name, $s );
			$cards .= LSCC_Render::card( $d[0], $d[1], $d[3] );
		}
		$title = '' !== $atts['title'] ? '<h3 class="lscc-title">' . esc_html( $atts['title'] ) . '</h3>' : '';
		return LSCC_Render::wrap( $title . '<div class="lscc-cards">' . $cards . '</div>', 'lscc-stats' );
	}

	/* ---------------- [car_counter_chart] ---------------- */
	public static function chart( $atts ) {
		$atts = shortcode_atts( array(
			'view' => 'daily', 'type' => 'bar', 'days' => 14, 'height' => 260, 'title' => '', 'since' => '', 'until' => '',
		), $atts, 'car_counter_chart' );
		self::assets();
		list( $since, $until ) = self::range( $atts );
		$type = ( 'line' === $atts['type'] ) ? 'line' : 'bar';

		if ( 'hourly' === $atts['view'] ) {
			$s = LSCC_DB::summary( $since, $until );
			$lo = 24; $hi = -1;
			foreach ( $s['hourly'] as $h => $v ) {
				if ( $v > 0 ) {
					$lo = min( $lo, $h );
					$hi = max( $hi, $h );
				}
			}
			if ( $hi < 0 ) {
				$lo = 17; $hi = 22;
			}
			$labels = array(); $values = array();
			for ( $h = $lo; $h <= $hi; $h++ ) {
				$labels[] = LSCC_Settings::format_hour( $h );
				$values[] = $s['hourly'][ $h ];
			}
			$title = '' !== $atts['title'] ? $atts['title'] : __( 'Cars by hour of the evening', 'light-show-car-counter' );
		} else {
			$limit = max( 2, min( 120, (int) $atts['days'] ) );
			$days  = array_reverse( LSCC_DB::get_days( array(
				'since' => null === $since ? (string) LSCC_Settings::get( 'season_start' ) : $since,
				'until' => null === $until ? (string) LSCC_Settings::get( 'season_end' ) : $until,
				'order' => 'DESC', 'limit' => $limit, 'only_nonzero' => true,
			) ) );
			$labels = array(); $values = array();
			foreach ( $days as $d ) {
				$labels[] = LSCC_Settings::format_date( $d['date'], 'M j' );
				$values[] = $d['total'];
			}
			$title = '' !== $atts['title'] ? $atts['title'] : __( 'Cars per night', 'light-show-car-counter' );
		}
		$out = '<h3 class="lscc-title">' . esc_html( $title ) . '</h3>' . LSCC_Render::chart( $labels, $values, array( 'type' => $type, 'height' => $atts['height'], 'title' => $title ) );
		return LSCC_Render::wrap( $out, 'lscc-chartbox' );
	}

	/* ---------------- [car_counter_table] ---------------- */
	public static function table( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 14, 'order' => 'desc', 'since' => '', 'until' => '', 'title' => '' ), $atts, 'car_counter_table' );
		self::assets();
		list( $since, $until ) = self::range( $atts );
		$days = LSCC_DB::get_days( array(
			'since' => null === $since ? (string) LSCC_Settings::get( 'season_start' ) : $since,
			'until' => null === $until ? (string) LSCC_Settings::get( 'season_end' ) : $until,
			'order' => 'asc' === strtolower( $atts['order'] ) ? 'ASC' : 'DESC',
			'limit' => max( 1, min( 400, (int) $atts['limit'] ) ), 'only_nonzero' => true,
		) );
		$title = '' !== $atts['title'] ? '<h3 class="lscc-title">' . esc_html( $atts['title'] ) . '</h3>' : '';
		return LSCC_Render::wrap( $title . LSCC_Render::table( $days ), 'lscc-tablebox' );
	}
}
