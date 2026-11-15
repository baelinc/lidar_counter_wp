<?php
/**
 * Self-updater: checks the plugin's GitHub repository for a newer version and plugs into
 * WordPress's normal update system (Plugins page "Update now" + our own banner button).
 */
defined( 'ABSPATH' ) || exit;

class LSCC_Updater {
	const REPO      = 'baelinc/lidar_counter_wp';
	const BRANCH    = 'main';
	const FILE      = 'light-show-car-counter.php';
	const TRANSIENT = 'lscc_update_info';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	public static function basename() {
		return plugin_basename( LSCC_FILE );
	}

	public static function raw_url() {
		return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . self::FILE;
	}

	public static function zip_url() {
		return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
	}

	/** Latest version info from GitHub, cached. Returns array( version, checked, error ) . */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$info = array( 'version' => '', 'checked' => time(), 'error' => '' );
		$res  = wp_remote_get( add_query_arg( 't', time(), self::raw_url() ), array( 'timeout' => 10, 'headers' => array( 'Accept' => 'text/plain' ) ) );
		if ( is_wp_error( $res ) ) {
			$info['error'] = $res->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$info['error'] = sprintf( /* translators: %d: HTTP status */ __( 'GitHub answered HTTP %d.', 'light-show-car-counter' ), (int) wp_remote_retrieve_response_code( $res ) );
		} elseif ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-+]*)/mi', wp_remote_retrieve_body( $res ), $m ) ) {
			$info['version'] = $m[1];
		} else {
			$info['error'] = __( 'Could not read the version from GitHub.', 'light-show-car-counter' );
		}
		// Remember failures briefly so a broken connection doesn't slow every admin page.
		set_site_transient( self::TRANSIENT, $info, $info['error'] ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $info;
	}

	public static function update_available( $force = false ) {
		$i = self::latest( $force );
		return ( '' !== $i['version'] && version_compare( $i['version'], LSCC_VERSION, '>' ) ) ? $i['version'] : false;
	}

	/** Tell WordPress about the update so it shows in Plugins / Dashboard → Updates. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$new = self::update_available();
		$obj = (object) array(
			'slug'        => dirname( self::basename() ),
			'plugin'      => self::basename(),
			'new_version' => $new ? $new : LSCC_VERSION,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => self::zip_url(),
		);
		if ( $new ) {
			$transient->response[ self::basename() ] = $obj;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$transient->no_update[ self::basename() ] = $obj;
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}
		$i = self::latest();
		return (object) array(
			'name'          => 'Light Show Car Counter',
			'slug'          => $args->slug,
			'version'       => $i['version'] ? $i['version'] : LSCC_VERSION,
			'author'        => '<a href="https://github.com/baelinc">baelinc</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => self::zip_url(),
			'sections'      => array( 'description' => esc_html__( 'Receives nightly car counts from the FPP Car Counter plugin and shows them with shortcodes and charts. Updates come from GitHub.', 'light-show-car-counter' ) ),
		);
	}

	/** GitHub zips unpack to "<repo>-<branch>/": rename to the folder the plugin is installed in. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return new WP_Error( 'lscc_rename', __( 'Could not prepare the downloaded update.', 'light-show-car-counter' ) );
		}
		return $wanted;
	}

	public static function after_upgrade( $upgrader, $extra ) {
		if ( isset( $extra['type'], $extra['plugins'] ) && 'plugin' === $extra['type'] && in_array( self::basename(), (array) $extra['plugins'], true ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/** Link that runs WordPress's own plugin upgrader (needs update_plugins capability). */
	public static function update_url() {
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( self::basename() ) ), 'upgrade-plugin_' . self::basename() );
	}

	/** Banner shown on the plugin's admin pages. */
	public static function banner() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$new = self::update_available();
		if ( ! $new ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Light Show Car Counter update available', 'light-show-car-counter' ) . '</strong> – '
			. esc_html( sprintf( /* translators: 1: new version 2: installed version */ __( 'version %1$s is ready (you have %2$s).', 'light-show-car-counter' ), $new, LSCC_VERSION ) )
			. ' <a class="button button-primary" href="' . esc_url( self::update_url() ) . '">' . esc_html__( 'Update now', 'light-show-car-counter' ) . '</a> '
			. '<a href="' . esc_url( 'https://github.com/' . self::REPO . '/commits/' . self::BRANCH ) . '" target="_blank" rel="noopener">' . esc_html__( 'What changed?', 'light-show-car-counter' ) . '</a></p></div>';
	}
}
