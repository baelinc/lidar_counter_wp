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

	/** Build one designed card for a stat (icon, trend, progress, sparkline where it makes sense). */
	public static function stat_card( $name, array $s, array $opts = array() ) {
		$d    = self::stat_data( $name, $s );
		$o    = array();
		$icons = array( 'total' => 'car', 'tonight' => 'moon', 'last_night' => 'calendar', 'best_night' => 'star', 'average' => 'bars', 'nights' => 'calendar', 'busiest_hour' => 'clock', 'updated' => 'sync' );
		if ( isset( $icons[ $name ] ) ) {
			$o['icon'] = $icons[ $name ];
		}
		$sub = $d[3];
		if ( null !== $d[2] && in_array( $name, array( 'total', 'tonight', 'last_night', 'nights', 'average', 'best_night' ), true ) ) {
			$o['count'] = (int) $d[2];
		}
		if ( 'total' === $name ) {
			if ( ! empty( $opts['hero'] ) ) {
				$o['hero']  = true;
				$o['spark'] = isset( $opts['spark'] ) ? $opts['spark'] : array();
			}
			if ( $s['nights'] ) {
				/* translators: %s: number of nights */
				$sub = sprintf( _n( 'across %s night', 'across %s nights', $s['nights'], 'light-show-car-counter' ), number_format_i18n( $s['nights'] ) );
			}
		} elseif ( 'tonight' === $name ) {
			if ( $s['best'] && $s['best']['total'] > 0 ) {
				$o['progress'] = $s['tonight'] / $s['best']['total'];
				/* translators: %d: percent */
				$sub = $s['tonight'] >= $s['best']['total'] && $s['tonight'] > 0 ? __( 'A new best night!', 'light-show-car-counter' ) : sprintf( __( '%d%% of the best night', 'light-show-car-counter' ), min( 100, round( 100 * $s['tonight'] / $s['best']['total'] ) ) );
			}
			if ( $s['updated'] && ( time() - $s['updated'] ) < 15 * MINUTE_IN_SECONDS ) {
				$o['live'] = true;
			}
		} elseif ( 'last_night' === $name && $s['last_night'] && $s['average'] > 0 ) {
			$pct = (int) round( 100 * ( $s['last_night']['total'] - $s['average'] ) / $s['average'] );
			if ( 0 !== $pct ) {
				/* translators: %d: percent */
				$o['trend'] = array( $pct > 0 ? 'up' : 'down', ( $pct > 0 ? "\xE2\x96\xB2 " : "\xE2\x96\xBC " ) . sprintf( __( '%d%% vs average', 'light-show-car-counter' ), abs( $pct ) ) );
			}
		}
		return LSCC_Render::card( $d[0], $d[1], $sub, $o );
	}

	/** Cars per night for the last $n nights with data (oldest first), for sparklines. */
	public static function recent_totals( $since = '', $until = '', $n = 14 ) {
		$days = LSCC_DB::get_days( array( 'since' => $since, 'until' => $until, 'order' => 'DESC', 'limit' => $n ) );
		return array_reverse( array_column( $days, 'total' ) );
	}

	/** Shown to admins only: saved nights that the season dates leave out of the numbers. */
	public static function season_note() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$o = LSCC_DB::outside_season();
		if ( ! $o['nights'] ) {
			return '';
		}
		return '<p class="lscc-admin-note">' . esc_html( sprintf( /* translators: 1: nights 2: cars */ _n( 'Admin note: %1$d saved night (%2$s cars) is outside your Season dates, so it is not counted here.', 'Admin note: %1$d saved nights (%2$s cars) are outside your Season dates, so they are not counted here.', $o['nights'], 'light-show-car-counter' ), $o['nights'], number_format_i18n( $o['cars'] ) ) )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=lscc-settings' ) ) . '">' . esc_html__( 'Change season dates', 'light-show-car-counter' ) . '</a></p>';
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

	private static function enqueue_cards() {
		wp_enqueue_script( 'lscc-cards', LSCC_URL . 'assets/lscc-cards.js', array(), LSCC_VERSION, true );
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
			'cards' => 'total,tonight,last_night,best_night,average,busiest_hour', 'title' => '', 'since' => '', 'until' => '', 'hero' => 'true',
		), $atts, 'car_counter_stats' );
		self::assets();
		self::enqueue_cards();
		list( $since, $until ) = self::range( $atts );
		$s     = LSCC_DB::summary( $since, $until );
		$hero  = self::truthy( $atts['hero'] );
		$opts  = array( 'hero' => $hero, 'spark' => $hero ? self::recent_totals( null === $since ? (string) LSCC_Settings::get( 'season_start' ) : $since, null === $until ? (string) LSCC_Settings::get( 'season_end' ) : $until ) : array() );
		$cards = '';
		foreach ( array_slice( array_map( 'sanitize_key', explode( ',', $atts['cards'] ) ), 0, 12 ) as $name ) {
			if ( ! in_array( $name, self::stat_names(), true ) || 'date' === $name ) {
				continue;
			}
			$cards .= self::stat_card( $name, $s, $opts );
		}
		$title = '' !== $atts['title'] ? '<h3 class="lscc-title">' . esc_html( $atts['title'] ) . '</h3>' : '';
		return LSCC_Render::wrap( $title . '<div class="lscc-cards">' . $cards . '</div>' . ( null === $since && null === $until ? self::season_note() : '' ), 'lscc-stats' );
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
