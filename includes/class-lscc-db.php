<?php
defined( 'ABSPATH' ) || exit;

/** Storage: one row per show night. */
class LSCC_DB {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lscc_days';
	}

	public static function activate() {
		self::install();
		LSCC_Settings::ensure_secret();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
  show_date date NOT NULL,
  total int(10) unsigned NOT NULL DEFAULT 0,
  offhours int(10) unsigned NOT NULL DEFAULT 0,
  hours text NULL,
  first_ts int(10) unsigned NULL,
  last_ts int(10) unsigned NULL,
  locked tinyint(1) NOT NULL DEFAULT 0,
  source varchar(10) NOT NULL DEFAULT 'fpp',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (show_date)
) {$charset};" );
		update_option( 'lscc_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lscc_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
		LSCC_Settings::ensure_secret();
	}

	/* ---------------- cache version (invalidates computed summaries) ---------------- */
	public static function cache_version() {
		return (int) get_option( 'lscc_cache_ver', 1 );
	}

	public static function bump_cache() {
		update_option( 'lscc_cache_ver', self::cache_version() + 1, false );
	}

	/* ---------------- writes ---------------- */
	private static function clean_hours( $hours ) {
		$out = array_fill( 0, 24, 0 );
		if ( is_array( $hours ) ) {
			for ( $h = 0; $h < 24; $h++ ) {
				if ( isset( $hours[ $h ] ) ) {
					$out[ $h ] = max( 0, min( 1000000, (int) $hours[ $h ] ) );
				}
			}
		}
		return $out;
	}

	public static function get_day( $date ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE show_date = %s', $date ), ARRAY_A );
		return $row ? self::normalize( $row ) : null;
	}

	/**
	 * Insert or update one night.
	 * $day: date, total, offhours, hours[24], first_ts, last_ts
	 * Returns 'inserted' | 'updated' | 'locked'.
	 */
	public static function upsert_day( array $day, $source = 'fpp', $force = false, $lock = null ) {
		global $wpdb;
		$existing = self::get_day( $day['date'] );
		if ( $existing && $existing['locked'] && ! $force ) {
			return 'locked';
		}
		$row = array(
			'show_date'  => $day['date'],
			'total'      => max( 0, min( 10000000, (int) $day['total'] ) ),
			'offhours'   => max( 0, min( 10000000, (int) ( isset( $day['offhours'] ) ? $day['offhours'] : 0 ) ) ),
			'hours'      => wp_json_encode( self::clean_hours( isset( $day['hours'] ) ? $day['hours'] : array() ) ),
			'first_ts'   => ! empty( $day['first_ts'] ) ? (int) $day['first_ts'] : null,
			'last_ts'    => ! empty( $day['last_ts'] ) ? (int) $day['last_ts'] : null,
			'locked'     => null === $lock ? ( $existing ? (int) $existing['locked'] : 0 ) : ( $lock ? 1 : 0 ),
			'source'     => $source,
			'updated_at' => current_time( 'mysql', true ),
		);
		$wpdb->replace( self::table(), $row, array( '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ) );
		return $existing ? 'updated' : 'inserted';
	}

	public static function delete_day( $date ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'show_date' => $date ), array( '%s' ) );
		self::bump_cache();
	}

	/* ---------------- ignored nights ----------------
	 * The Pi re-sends its last few nights with every upload, so a deleted night would simply come
	 * back. Deleted nights are remembered here and skipped on ingest until restored.
	 */
	public static function blocked() {
		$b = get_option( 'lscc_blocked', array() );
		return is_array( $b ) ? array_values( $b ) : array();
	}

	public static function block( array $dates ) {
		$all = array_unique( array_merge( self::blocked(), array_filter( $dates, array( 'LSCC_Settings', 'valid_date' ) ) ) );
		sort( $all );
		update_option( 'lscc_blocked', array_slice( $all, -2000 ), false );
	}

	public static function unblock( $date ) {
		update_option( 'lscc_blocked', array_values( array_diff( self::blocked(), array( $date ) ) ), false );
	}

	/** True if an upload for this night must be ignored (deleted night, or older than the reset cut-off). */
	public static function is_ignored( $date ) {
		$floor = (string) LSCC_Settings::get( 'data_floor' );
		return ( '' !== $floor && $date < $floor ) || in_array( $date, self::blocked(), true );
	}

	/** Delete nights and keep ignoring them. Returns how many stored nights were removed. */
	public static function delete_days( array $dates, $block = true ) {
		$removed = 0;
		foreach ( array_unique( $dates ) as $date ) {
			if ( ! LSCC_Settings::valid_date( (string) $date ) ) {
				continue;
			}
			if ( self::get_day( $date ) ) {
				self::delete_day( $date );
				$removed++;
			}
		}
		if ( $block ) {
			self::block( $dates );
		}
		self::bump_cache();
		return $removed;
	}

	public static function dates_in_range( $from, $to ) {
		$rows = self::get_days( array( 'since' => $from, 'until' => $to ) );
		return array_column( $rows, 'date' );
	}

	/**
	 * Wipe every stored night, the upload log and the ignore list.
	 * $restart: 'tonight' (accept tonight's upload but nothing older), 'tomorrow' (ignore tonight too)
	 * or 'all' (accept whatever the Pi sends, including its old history).
	 * Settings, the shared secret and the starting offset are kept.
	 */
	public static function reset_all( $restart = 'tonight' ) {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() );
		delete_option( 'lscc_last_sync' );
		delete_option( 'lscc_sync_log' );
		delete_option( 'lscc_blocked' );
		$tonight = LSCC_Settings::tonight_date();
		if ( 'tomorrow' === $restart ) {
			$floor = gmdate( 'Y-m-d', strtotime( $tonight . ' 12:00:00 UTC' ) + DAY_IN_SECONDS );
		} elseif ( 'all' === $restart ) {
			$floor = '';
		} else {
			$floor = $tonight;
		}
		LSCC_Settings::set_floor( $floor );
		self::bump_cache();
		return $floor;
	}

	public static function set_lock( $date, $locked ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'locked' => $locked ? 1 : 0 ), array( 'show_date' => $date ), array( '%d' ), array( '%s' ) );
		self::bump_cache();
	}

	/* ---------------- reads ---------------- */
	private static function normalize( array $r ) {
		$hours = json_decode( (string) $r['hours'], true );
		return array(
			'date'       => $r['show_date'],
			'total'      => (int) $r['total'],
			'offhours'   => (int) $r['offhours'],
			'hours'      => self::clean_hours( $hours ),
			'first_ts'   => $r['first_ts'] ? (int) $r['first_ts'] : null,
			'last_ts'    => $r['last_ts'] ? (int) $r['last_ts'] : null,
			'locked'     => (bool) $r['locked'],
			'source'     => $r['source'],
			'updated_at' => $r['updated_at'],
		);
	}

	/** @param array $args since, until (YYYY-MM-DD), order (ASC|DESC), limit, only_nonzero */
	public static function get_days( $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args( $args, array( 'since' => '', 'until' => '', 'order' => 'ASC', 'limit' => 0, 'only_nonzero' => false ) );
		$where  = array( '1=1' );
		$params = array();
		if ( $args['since'] && LSCC_Settings::valid_date( $args['since'] ) ) {
			$where[]  = 'show_date >= %s';
			$params[] = $args['since'];
		}
		if ( $args['until'] && LSCC_Settings::valid_date( $args['until'] ) ) {
			$where[]  = 'show_date <= %s';
			$params[] = $args['until'];
		}
		if ( $args['only_nonzero'] ) {
			$where[] = 'total > 0';
		}
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY show_date ' . ( 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC' );
		if ( (int) $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $args['limit'];
		}
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Aggregate statistics for the season (or a custom since/until).
	 * Cached until the next write.
	 */
	public static function summary( $since = null, $until = null ) {
		$custom = ( null !== $since || null !== $until );
		if ( null === $since ) {
			$since = (string) LSCC_Settings::get( 'season_start' );
		}
		if ( null === $until ) {
			$until = (string) LSCC_Settings::get( 'season_end' );
		}
		$tonight_date = LSCC_Settings::tonight_date();
		$key          = 'lscc_sum_' . md5( implode( '|', array( $since, $until, $tonight_date, self::cache_version(), (int) $custom, LSCC_Settings::get( 'start_offset' ) ) ) );
		$cached       = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$days   = self::get_days( array( 'since' => $since, 'until' => $until ) );
		$total  = 0;
		$nights = 0;
		$best   = null;
		$hourly = array_fill( 0, 24, 0 );
		$latest = null;
		$tonight_total = 0;
		$last_night    = null;
		foreach ( $days as $d ) {
			$total += $d['total'];
			if ( $d['total'] > 0 ) {
				$nights++;
				if ( null === $best || $d['total'] > $best['total'] ) {
					$best = array( 'date' => $d['date'], 'total' => $d['total'] );
				}
			}
			for ( $h = 0; $h < 24; $h++ ) {
				$hourly[ $h ] += $d['hours'][ $h ];
			}
			$latest = $d;
			if ( $d['date'] === $tonight_date ) {
				$tonight_total = $d['total'];
			}
			if ( $d['date'] < $tonight_date && $d['total'] > 0 ) {
				$last_night = array( 'date' => $d['date'], 'total' => $d['total'] );
			}
		}
		$busiest = null;
		if ( array_sum( $hourly ) > 0 ) {
			$busiest = (int) array_search( max( $hourly ), $hourly, true );
		}
		if ( ! $custom ) {
			$total += (int) LSCC_Settings::get( 'start_offset' );
		}
		$sync    = get_option( 'lscc_last_sync', array() );
		$summary = array(
			'total'        => $total,
			'nights'       => $nights,
			'average'      => $nights ? (int) round( ( $total - ( $custom ? 0 : (int) LSCC_Settings::get( 'start_offset' ) ) ) / $nights ) : 0,
			'best'         => $best,
			'hourly'       => $hourly,
			'busiest_hour' => $busiest,
			'latest'       => $latest ? array( 'date' => $latest['date'], 'total' => $latest['total'] ) : null,
			'tonight_date' => $tonight_date,
			'tonight'      => $tonight_total,
			'last_night'   => $last_night,
			'updated'      => isset( $sync['ts'] ) ? (int) $sync['ts'] : 0,
		);
		set_transient( $key, $summary, 10 * MINUTE_IN_SECONDS );
		return $summary;
	}
}
