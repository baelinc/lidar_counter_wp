<?php
defined( 'ABSPATH' ) || exit;

/** Plugin options (single array option) and small shared helpers. */
class LSCC_Settings {

	const OPTION = 'lscc_settings';

	public static function defaults() {
		return array(
			'secret'              => '',
			'season_start'        => '',   // optional YYYY-MM-DD: ignore nights before this
			'season_end'          => '',   // optional YYYY-MM-DD
			'start_offset'        => 0,    // cars counted before this plugin existed
			'rollover_hour'       => 6,    // keep equal to the FPP plugin's "new night starts at"
			'accent'              => '#c62828',
			'public_api'          => 1,    // allow [car_counter live="true"] to poll
			'stale_hours'         => 36,   // warn when nothing has arrived for this long
			'delete_on_uninstall' => 0,
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Sanitise and store the settings form. The secret is never changed here. */
	public static function save( array $in ) {
		$cur = self::all();
		$new = $cur;
		foreach ( array( 'season_start', 'season_end' ) as $k ) {
			$v = isset( $in[ $k ] ) ? trim( (string) $in[ $k ] ) : '';
			$new[ $k ] = self::valid_date( $v ) ? $v : '';
		}
		$new['start_offset']  = isset( $in['start_offset'] ) ? max( 0, min( 10000000, (int) $in['start_offset'] ) ) : 0;
		$new['rollover_hour'] = isset( $in['rollover_hour'] ) ? max( 0, min( 12, (int) $in['rollover_hour'] ) ) : 6;
		$accent               = isset( $in['accent'] ) ? sanitize_hex_color( trim( (string) $in['accent'] ) ) : '';
		$new['accent']        = $accent ? $accent : '#c62828';
		$new['public_api']    = empty( $in['public_api'] ) ? 0 : 1;
		$new['stale_hours']   = isset( $in['stale_hours'] ) ? max( 1, min( 720, (int) $in['stale_hours'] ) ) : 36;
		$new['delete_on_uninstall'] = empty( $in['delete_on_uninstall'] ) ? 0 : 1;
		update_option( self::OPTION, $new, false );
		LSCC_DB::bump_cache();
	}

	public static function generate_secret() {
		return bin2hex( random_bytes( 24 ) );
	}

	public static function ensure_secret() {
		$all = self::all();
		if ( '' === $all['secret'] ) {
			$all['secret'] = self::generate_secret();
			update_option( self::OPTION, $all, false );
		}
	}

	public static function regenerate_secret() {
		$all           = self::all();
		$all['secret'] = self::generate_secret();
		update_option( self::OPTION, $all, false );
	}

	/** The show night we are in right now, using the site timezone and the rollover hour. */
	public static function tonight_date() {
		$now = new DateTime( 'now', wp_timezone() );
		$now->modify( '-' . (int) self::get( 'rollover_hour' ) . ' hours' );
		return $now->format( 'Y-m-d' );
	}

	public static function format_date( $ymd, $format = '' ) {
		if ( '' === $format ) {
			$format = get_option( 'date_format' );
		}
		$ts = strtotime( $ymd . ' 12:00:00 UTC' );
		return $ts ? wp_date( $format, $ts, new DateTimeZone( 'UTC' ) ) : $ymd;
	}

	public static function format_hour( $h ) {
		$h = (int) $h;
		if ( false !== strpos( (string) get_option( 'time_format' ), 'H' ) ) {
			return sprintf( '%02d:00', $h );
		}
		return gmdate( 'g A', $h * 3600 );
	}
}
