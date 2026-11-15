<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API
 *   POST /wp-json/lscc/v1/ingest   nightly / live upload from the FPP plugin (HMAC signed)
 *   POST /wp-json/lscc/v1/ping     "test connection" from the FPP plugin (HMAC signed)
 *   GET  /wp-json/lscc/v1/public   aggregate numbers for [car_counter live="true"]
 *
 * Signature = hex( HMAC-SHA256( secret, "<timestamp>." . <raw request body> ) )
 * sent in X-LSCC-Signature, with the timestamp in X-LSCC-Timestamp.
 */
class LSCC_REST {

	const MAX_DAYS = 400;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'lscc/v1', '/ingest', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ingest' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( 'lscc/v1', '/ping', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ping' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( 'lscc/v1', '/public', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'public_stats' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Verify the HMAC signature and that the request is fresh. */
	public static function authorize( WP_REST_Request $request ) {
		$secret = (string) LSCC_Settings::get( 'secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'lscc_not_configured', 'No shared secret has been generated yet.', array( 'status' => 503 ) );
		}
		$ts  = (string) $request->get_header( 'x_lscc_timestamp' );
		$sig = strtolower( (string) $request->get_header( 'x_lscc_signature' ) );
		if ( '' === $ts || '' === $sig || ! ctype_digit( $ts ) ) {
			return new WP_Error( 'lscc_missing_auth', 'Missing or malformed authentication headers.', array( 'status' => 401 ) );
		}
		$max_skew = (int) apply_filters( 'lscc_max_clock_skew', 900 );
		if ( abs( time() - (int) $ts ) > $max_skew ) {
			return new WP_Error(
				'lscc_stale_timestamp',
				sprintf( 'Server time is %s UTC, request time was %s UTC.', gmdate( 'H:i:s', time() ), gmdate( 'H:i:s', (int) $ts ) ),
				array( 'status' => 401 )
			);
		}
		$expected = hash_hmac( 'sha256', $ts . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'lscc_bad_signature', 'Signature does not match.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function ping( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'ok'             => true,
			'site'           => get_bloginfo( 'name' ),
			'plugin_version' => LSCC_VERSION,
			'server_time'    => time(),
		) );
	}

	private static function clean_day( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['date'] ) || ! LSCC_Settings::valid_date( (string) $raw['date'] ) ) {
			return null;
		}
		// A Pi with a wrong clock must not be able to create nights in the far future (or distant past).
		if ( (string) $raw['date'] < '2000-01-01' || (string) $raw['date'] > gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) {
			return null;
		}
		$hours = array();
		if ( isset( $raw['hours'] ) && is_array( $raw['hours'] ) ) {
			$hours = array_values( $raw['hours'] );
		}
		return array(
			'date'     => (string) $raw['date'],
			'total'    => isset( $raw['total'] ) ? max( 0, (int) $raw['total'] ) : 0,
			'offhours' => isset( $raw['offhours'] ) ? max( 0, (int) $raw['offhours'] ) : 0,
			'hours'    => $hours,
			'first_ts' => isset( $raw['first_ts'] ) ? (int) $raw['first_ts'] : 0,
			'last_ts'  => isset( $raw['last_ts'] ) ? (int) $raw['last_ts'] : 0,
		);
	}

	public static function ingest( WP_REST_Request $request ) {
		$data = json_decode( $request->get_body(), true );
		if ( ! is_array( $data ) || ! isset( $data['days'] ) || ! is_array( $data['days'] ) ) {
			return new WP_Error( 'lscc_bad_payload', 'Expected a JSON body with a "days" list.', array( 'status' => 400 ) );
		}
		if ( count( $data['days'] ) > self::MAX_DAYS ) {
			return new WP_Error( 'lscc_too_many_days', 'Too many days in one upload.', array( 'status' => 413 ) );
		}
		$received = 0;
		$changed  = 0;
		$locked   = 0;
		$ignored  = 0;
		$stored   = array();
		foreach ( $data['days'] as $raw ) {
			$day = self::clean_day( $raw );
			if ( null === $day ) {
				continue;
			}
			$received++;
			if ( LSCC_DB::is_ignored( $day['date'] ) ) {   // deleted by the site owner, or older than a reset
				$ignored++;
				continue;
			}
			$result = LSCC_DB::upsert_day( $day, 'fpp' );
			if ( 'locked' === $result ) {
				$locked++;
			} else {
				$changed++;
				$stored[] = $day['date'];
			}
		}
		LSCC_DB::bump_cache();

		$info = array(
			'ts'       => time(),
			'live'     => ! empty( $data['live'] ),
			'received' => $received,
			'changed'  => $changed,
			'locked'   => $locked,
			'ignored'  => $ignored,
			'dates'    => array_slice( $stored, 0, 60 ),
			'version'  => isset( $data['plugin_version'] ) ? sanitize_text_field( (string) $data['plugin_version'] ) : '',
			'show'     => isset( $data['show_name'] ) ? sanitize_text_field( (string) $data['show_name'] ) : '',
			'ip'       => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);
		update_option( 'lscc_last_sync', $info, false );
		if ( empty( $data['live'] ) ) {
			$log = get_option( 'lscc_sync_log', array() );
			if ( ! is_array( $log ) ) {
				$log = array();
			}
			array_unshift( $log, $info );
			update_option( 'lscc_sync_log', array_slice( $log, 0, 20 ), false );
		}
		do_action( 'lscc_after_ingest', $info );

		$summary = LSCC_DB::summary();
		return rest_ensure_response( array(
			'ok'             => true,
			'received'       => $received,
			'updated'        => $changed,
			'skipped_locked' => $locked,
			'ignored'        => $ignored,
			'season_total'   => $summary['total'],
			'site'           => get_bloginfo( 'name' ),
			'plugin_version' => LSCC_VERSION,
			'server_time'    => time(),
		) );
	}

	public static function public_stats( WP_REST_Request $request ) {
		if ( ! LSCC_Settings::get( 'public_api' ) ) {
			return new WP_Error( 'lscc_disabled', 'Live statistics are disabled.', array( 'status' => 403 ) );
		}
		$s        = LSCC_DB::summary();
		$response = rest_ensure_response( array(
			'total'      => $s['total'],
			'tonight'    => $s['tonight'],
			'last_night' => $s['last_night'] ? $s['last_night']['total'] : 0,
			'nights'     => $s['nights'],
			'updated'    => $s['updated'],
		) );
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}
}
