<?php
defined( 'ABSPATH' ) || exit;

/** wp-admin screens: Nights (overview + editing) and Connection & Settings. */
class LSCC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_lscc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lscc_regen_secret', array( __CLASS__, 'handle_regen_secret' ) );
		add_action( 'admin_post_lscc_save_day', array( __CLASS__, 'handle_save_day' ) );
		add_action( 'admin_post_lscc_delete_day', array( __CLASS__, 'handle_delete_day' ) );
		add_action( 'admin_post_lscc_lock_day', array( __CLASS__, 'handle_lock_day' ) );
		add_action( 'admin_post_lscc_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Car Counter', 'light-show-car-counter' ), __( 'Car Counter', 'light-show-car-counter' ), 'manage_options', 'lscc', array( __CLASS__, 'page_nights' ), 'dashicons-chart-bar', 58 );
		add_submenu_page( 'lscc', __( 'Nights', 'light-show-car-counter' ), __( 'Nights', 'light-show-car-counter' ), 'manage_options', 'lscc', array( __CLASS__, 'page_nights' ) );
		add_submenu_page( 'lscc', __( 'Connection & Settings', 'light-show-car-counter' ), __( 'Connection & Settings', 'light-show-car-counter' ), 'manage_options', 'lscc-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 === strpos( $page, 'lscc' ) || 'index.php' === $hook ) {
			wp_enqueue_style( 'lscc', LSCC_URL . 'assets/lscc.css', array(), LSCC_VERSION );
		}
	}

	/* ------------------------------------------------------------------ helpers */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'light-show-car-counter' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $page, $msg, $extra = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'lscc_msg' => $msg ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function notice() {
		$messages = array(
			'saved'        => array( 'success', __( 'Settings saved.', 'light-show-car-counter' ) ),
			'secret'       => array( 'warning', __( 'A new shared secret was generated. Update it in the FPP plugin, or uploads will be rejected.', 'light-show-car-counter' ) ),
			'day_saved'    => array( 'success', __( 'Night saved.', 'light-show-car-counter' ) ),
			'day_deleted'  => array( 'success', __( 'Night deleted.', 'light-show-car-counter' ) ),
			'locked'       => array( 'success', __( 'Night locked. Uploads from the Pi will not overwrite it.', 'light-show-car-counter' ) ),
			'unlocked'     => array( 'success', __( 'Night unlocked. The next upload from the Pi may overwrite it.', 'light-show-car-counter' ) ),
			'bad_date'     => array( 'error', __( 'That date is not valid (use YYYY-MM-DD).', 'light-show-car-counter' ) ),
		);
		$key = isset( $_GET['lscc_msg'] ) ? sanitize_key( wp_unslash( $_GET['lscc_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	/** Returns array( state => never|ok|stale, ts, last ). */
	public static function sync_health() {
		$last = get_option( 'lscc_last_sync', array() );
		if ( empty( $last['ts'] ) ) {
			return array( 'state' => 'never', 'ts' => 0, 'last' => array() );
		}
		$stale = ( time() - (int) $last['ts'] ) > ( (int) LSCC_Settings::get( 'stale_hours' ) * HOUR_IN_SECONDS );
		return array( 'state' => $stale ? 'stale' : 'ok', 'ts' => (int) $last['ts'], 'last' => $last );
	}

	private static function health_box() {
		$h = self::sync_health();
		if ( 'never' === $h['state'] ) {
			$msg = esc_html__( 'Nothing has been received from your FPP yet. Open "Connection & Settings" to see what to enter in the FPP plugin.', 'light-show-car-counter' );
		} elseif ( 'stale' === $h['state'] ) {
			/* translators: %s: time difference such as "2 days" */
			$msg = esc_html( sprintf( __( 'Last upload from your FPP was %s ago. If the show is running, check the Pi (power, network, FPP plugin Website Sync tab). Ignore this in the off-season.', 'light-show-car-counter' ), human_time_diff( $h['ts'] ) ) );
		} else {
			/* translators: %s: time difference such as "3 hours" */
			$msg = esc_html( sprintf( __( 'Receiving data. Last upload %s ago.', 'light-show-car-counter' ), human_time_diff( $h['ts'] ) ) );
		}
		return '<div class="lscc-box lscc-health-' . esc_attr( $h['state'] ) . '"><strong>' . esc_html__( 'Connection:', 'light-show-car-counter' ) . '</strong> ' . $msg . '</div>';
	}

	/* ------------------------------------------------------------------ Nights page */
	public static function page_nights() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = LSCC_DB::summary();
		$days = LSCC_DB::get_days( array( 'order' => 'DESC', 'limit' => 400 ) );

		$edit_date = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$editing   = ( $edit_date && LSCC_Settings::valid_date( $edit_date ) ) ? LSCC_DB::get_day( $edit_date ) : null;

		echo '<div class="wrap lscc-admin"><h1>' . esc_html__( 'Car Counter', 'light-show-car-counter' ) . '</h1>';
		self::notice();
		echo self::health_box();

		$cards = '';
		foreach ( array( 'total', 'tonight', 'last_night', 'nights', 'average', 'best_night', 'busiest_hour' ) as $name ) {
			$d      = LSCC_Shortcodes::stat_data( $name, $s );
			$cards .= LSCC_Render::card( $d[0], $d[1], $d[3] );
		}
		echo LSCC_Render::wrap( '<div class="lscc-cards">' . $cards . '</div>' );

		$recent = array_reverse( LSCC_DB::get_days( array( 'order' => 'DESC', 'limit' => 30, 'only_nonzero' => true ) ) );
		$labels = array(); $values = array();
		foreach ( $recent as $d ) {
			$labels[] = LSCC_Settings::format_date( $d['date'], 'M j' );
			$values[] = $d['total'];
		}
		echo '<div class="lscc-box"><h2>' . esc_html__( 'Last 30 nights', 'light-show-car-counter' ) . '</h2>'
			. LSCC_Render::wrap( LSCC_Render::chart( $labels, $values, array( 'height' => 240 ) ) ) . '</div>';

		// Add / edit form
		echo '<div class="lscc-box"><h2>' . esc_html( $editing ? __( 'Edit night', 'light-show-car-counter' ) : __( 'Add or correct a night', 'light-show-car-counter' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lscc_save_day' );
		echo '<input type="hidden" name="action" value="lscc_save_day"><table class="form-table"><tbody>';
		echo '<tr><th><label for="lscc-date">' . esc_html__( 'Night', 'light-show-car-counter' ) . '</label></th><td><input type="date" id="lscc-date" name="date" required value="' . esc_attr( $editing ? $editing['date'] : '' ) . '"' . ( $editing ? ' readonly' : '' ) . '></td></tr>';
		echo '<tr><th><label for="lscc-total">' . esc_html__( 'Cars', 'light-show-car-counter' ) . '</label></th><td><input type="number" min="0" id="lscc-total" name="total" required value="' . esc_attr( $editing ? $editing['total'] : '' ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Protect', 'light-show-car-counter' ) . '</th><td><label><input type="checkbox" name="locked" value="1" checked> ' . esc_html__( 'Lock this night so uploads from the Pi cannot overwrite it', 'light-show-car-counter' ) . '</label></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Save night', 'light-show-car-counter' ) . '</button>';
		if ( $editing ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=lscc' ) ) . '">' . esc_html__( 'Cancel', 'light-show-car-counter' ) . '</a>';
		}
		echo '</p></form></div>';

		// Table
		echo '<div class="lscc-box"><h2>' . esc_html__( 'All nights', 'light-show-car-counter' ) . '</h2>';
		if ( ! $days ) {
			echo '<p>' . esc_html__( 'No nights recorded yet.', 'light-show-car-counter' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Night', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Cars', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Off-hours', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Source', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Updated (UTC)', 'light-show-car-counter' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $days as $d ) {
				echo '<tr><td>' . esc_html( LSCC_Settings::format_date( $d['date'] ) . ' (' . $d['date'] . ')' ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $d['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $d['offhours'] ) ) . '</td>';
				echo '<td>' . esc_html( $d['source'] ) . ( $d['locked'] ? ' &#128274;' : '' ) . '</td><td>' . esc_html( $d['updated_at'] ) . '</td><td>';
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( array( 'page' => 'lscc', 'edit' => $d['date'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit', 'light-show-car-counter' ) . '</a> ';
				self::row_form( 'lscc_lock_day', $d['date'], $d['locked'] ? __( 'Unlock', 'light-show-car-counter' ) : __( 'Lock', 'light-show-car-counter' ), array( 'lock' => $d['locked'] ? '0' : '1' ) );
				self::row_form( 'lscc_delete_day', $d['date'], __( 'Delete', 'light-show-car-counter' ), array(), __( 'Delete this night? An FPP upload may bring it back.', 'light-show-car-counter' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=lscc_export' ), 'lscc_export' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Download CSV', 'light-show-car-counter' ) . '</a></p></div></div>';
	}

	private static function row_form( $action, $date, $label, $extra = array(), $confirm = '' ) {
		echo '<form class="lscc-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="date" value="' . esc_attr( $date ) . '">';
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
	}

	/* ------------------------------------------------------------------ Settings page */
	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = LSCC_Settings::all();
		echo '<div class="wrap lscc-admin"><h1>' . esc_html__( 'Car Counter – Connection & Settings', 'light-show-car-counter' ) . '</h1>';
		self::notice();
		echo self::health_box();

		// Connection
		echo '<div class="lscc-box"><h2>' . esc_html__( '1. Connect your FPP', 'light-show-car-counter' ) . '</h2>';
		echo '<p>' . esc_html__( 'In FPP open Status/Control → Car Counter → Website Sync and enter:', 'light-show-car-counter' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Website address', 'light-show-car-counter' ) . ':</strong> <code class="lscc-secret">' . esc_html( untrailingslashit( home_url() ) ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Shared secret', 'light-show-car-counter' ) . ':</strong> <code class="lscc-secret" id="lscc-secret">' . esc_html( $o['secret'] ) . '</code> '
			. '<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById(\'lscc-secret\').textContent).then(function(){this.textContent=\'Copied\'}.bind(this))">' . esc_html__( 'Copy', 'light-show-car-counter' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Then press "Test connection" in FPP. Use an https:// address so the data is encrypted in transit.', 'light-show-car-counter' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Generate a new secret? The FPP plugin must be updated afterwards.', 'light-show-car-counter' ) ) ) . ');">';
		wp_nonce_field( 'lscc_regen_secret' );
		echo '<input type="hidden" name="action" value="lscc_regen_secret"><button class="button">' . esc_html__( 'Generate new secret', 'light-show-car-counter' ) . '</button></form>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: URL */ __( 'Upload endpoint: %s', 'light-show-car-counter' ), rest_url( 'lscc/v1/ingest' ) ) ) . '</p></div>';

		// Settings
		echo '<div class="lscc-box"><h2>' . esc_html__( '2. Settings', 'light-show-car-counter' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lscc_save_settings' );
		echo '<input type="hidden" name="action" value="lscc_save_settings"><table class="form-table"><tbody>';
		self::row_input( __( 'Season starts', 'light-show-car-counter' ), 'season_start', $o['season_start'], 'date', __( 'Optional. Nights before this date are ignored in totals – handy when you reuse the site each year.', 'light-show-car-counter' ) );
		self::row_input( __( 'Season ends', 'light-show-car-counter' ), 'season_end', $o['season_end'], 'date', __( 'Optional.', 'light-show-car-counter' ) );
		self::row_input( __( 'Cars before this plugin', 'light-show-car-counter' ), 'start_offset', $o['start_offset'], 'number', __( 'Added to the season total (e.g. cars you counted by hand).', 'light-show-car-counter' ) );
		self::row_input( __( 'New night starts at (hour)', 'light-show-car-counter' ), 'rollover_hour', $o['rollover_hour'], 'number', __( 'Used for "tonight" / "last night". Keep equal to the FPP plugin setting (default 6).', 'light-show-car-counter' ) );
		self::row_input( __( 'Chart colour', 'light-show-car-counter' ), 'accent', $o['accent'], 'text', __( 'Hex colour, e.g. #c62828.', 'light-show-car-counter' ) );
		self::row_input( __( 'Warn after (hours)', 'light-show-car-counter' ), 'stale_hours', $o['stale_hours'], 'number', __( 'Show a warning when nothing has been uploaded for this long.', 'light-show-car-counter' ) );
		self::row_check( __( 'Live numbers', 'light-show-car-counter' ), 'public_api', $o['public_api'], __( 'Allow [car_counter live="true"] to refresh on visitors\' screens (exposes only the totals).', 'light-show-car-counter' ) );
		self::row_check( __( 'On uninstall', 'light-show-car-counter' ), 'delete_on_uninstall', $o['delete_on_uninstall'], __( 'Delete all stored nights and settings when the plugin is deleted.', 'light-show-car-counter' ) );
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Save settings', 'light-show-car-counter' ) . '</button></p></form></div>';

		// Sync log
		$log = get_option( 'lscc_sync_log', array() );
		echo '<div class="lscc-box"><h2>' . esc_html__( '3. Recent nightly uploads', 'light-show-car-counter' ) . '</h2>';
		if ( ! is_array( $log ) || ! $log ) {
			echo '<p>' . esc_html__( 'None yet.', 'light-show-car-counter' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Nights received', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Skipped (locked)', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'FPP plugin', 'light-show-car-counter' ) . '</th><th>IP</th></tr></thead><tbody>';
			foreach ( $log as $row ) {
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['ts'] ) ) . '</td><td>' . esc_html( (int) $row['received'] ) . '</td><td>' . esc_html( (int) $row['locked'] ) . '</td><td>' . esc_html( $row['version'] ) . '</td><td>' . esc_html( $row['ip'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// Shortcode help
		echo '<div class="lscc-box"><h2>' . esc_html__( '4. Shortcodes', 'light-show-car-counter' ) . '</h2><table class="widefat striped"><tbody>';
		$help = array(
			'[car_counter]'                                   => __( 'Season total as a plain number you can use inside a sentence: "We have welcomed [car_counter] cars!"', 'light-show-car-counter' ),
			'[car_counter stat="tonight" live="true"]'        => __( 'Tonight\'s count, refreshing on the visitor\'s screen. Stats: total, tonight, last_night, best_night, best_night_date, average, nights, busiest_hour, updated, date (with date="2026-12-24").', 'light-show-car-counter' ),
			'[car_counter label="Cars:" suffix=" so far"]'    => __( 'Add a label, prefix or suffix. format="raw" removes thousands separators.', 'light-show-car-counter' ),
			'[car_counter_stats cards="total,nights,average,best_night"]' => __( 'A grid of number cards. Cards: any stat above except date.', 'light-show-car-counter' ),
			'[car_counter_chart days="14"]'                   => __( 'Bar chart of recent nights. Options: type="line", height="300", title="…".', 'light-show-car-counter' ),
			'[car_counter_chart view="hourly"]'               => __( 'Which hours of the evening are busiest across the season.', 'light-show-car-counter' ),
			'[car_counter_table limit="14" order="desc"]'     => __( 'Table of nightly totals.', 'light-show-car-counter' ),
			'since="2026-11-20" until="2026-12-31"'           => __( 'Any shortcode accepts since / until (YYYY-MM-DD) to override the season dates.', 'light-show-car-counter' ),
		);
		foreach ( $help as $code => $desc ) {
			echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function row_input( $label, $name, $value, $type, $desc ) {
		echo '<tr><th><label for="lscc-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="lscc-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"><p class="description">' . esc_html( $desc ) . '</p></td></tr>';
	}

	private static function row_check( $label, $name, $value, $desc ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (int) $value, 1, false ) . '> ' . esc_html( $desc ) . '</label></td></tr>';
	}

	/* ------------------------------------------------------------------ dashboard widget */
	public static function dashboard_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'lscc_widget', __( 'Light Show Cars', 'light-show-car-counter' ), array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget() {
		$s     = LSCC_DB::summary();
		$cards = '';
		foreach ( array( 'total', 'tonight', 'last_night' ) as $name ) {
			$d      = LSCC_Shortcodes::stat_data( $name, $s );
			$cards .= LSCC_Render::card( $d[0], $d[1] );
		}
		echo LSCC_Render::wrap( '<div class="lscc-cards">' . $cards . '</div>' );
		$h = self::sync_health();
		if ( 'ok' === $h['state'] ) {
			/* translators: %s: time difference */
			echo '<p>' . esc_html( sprintf( __( 'Last upload %s ago.', 'light-show-car-counter' ), human_time_diff( $h['ts'] ) ) ) . '</p>';
		} elseif ( 'stale' === $h['state'] ) {
			/* translators: %s: time difference */
			echo '<p><strong>' . esc_html( sprintf( __( 'No upload for %s – check your Pi.', 'light-show-car-counter' ), human_time_diff( $h['ts'] ) ) ) . '</strong></p>';
		} else {
			echo '<p>' . esc_html__( 'Nothing received from FPP yet.', 'light-show-car-counter' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lscc' ) ) . '">' . esc_html__( 'View all nights', 'light-show-car-counter' ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ form handlers */
	public static function handle_save_settings() {
		self::guard( 'lscc_save_settings' );
		LSCC_Settings::save( wp_unslash( $_POST ) );
		self::back( 'lscc-settings', 'saved' );
	}

	public static function handle_regen_secret() {
		self::guard( 'lscc_regen_secret' );
		LSCC_Settings::regenerate_secret();
		self::back( 'lscc-settings', 'secret' );
	}

	public static function handle_save_day() {
		self::guard( 'lscc_save_day' );
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( ! LSCC_Settings::valid_date( $date ) ) {
			self::back( 'lscc', 'bad_date' );
		}
		$existing = LSCC_DB::get_day( $date );
		LSCC_DB::upsert_day( array(
			'date'     => $date,
			'total'    => isset( $_POST['total'] ) ? max( 0, (int) $_POST['total'] ) : 0,
			'offhours' => $existing ? $existing['offhours'] : 0,
			'hours'    => $existing ? $existing['hours'] : array(),
			'first_ts' => $existing ? $existing['first_ts'] : 0,
			'last_ts'  => $existing ? $existing['last_ts'] : 0,
		), 'manual', true, ! empty( $_POST['locked'] ) );
		LSCC_DB::bump_cache();
		self::back( 'lscc', 'day_saved' );
	}

	public static function handle_delete_day() {
		self::guard( 'lscc_delete_day' );
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( LSCC_Settings::valid_date( $date ) ) {
			LSCC_DB::delete_day( $date );
		}
		self::back( 'lscc', 'day_deleted' );
	}

	public static function handle_lock_day() {
		self::guard( 'lscc_lock_day' );
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$lock = ! empty( $_POST['lock'] );
		if ( LSCC_Settings::valid_date( $date ) ) {
			LSCC_DB::set_lock( $date, $lock );
		}
		self::back( 'lscc', $lock ? 'locked' : 'unlocked' );
	}

	public static function handle_export() {
		self::guard( 'lscc_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="car-counts-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out  = fopen( 'php://output', 'w' );
		$head = array( 'date', 'cars', 'offhours_detections', 'source', 'locked' );
		for ( $h = 0; $h < 24; $h++ ) {
			$head[] = sprintf( 'h%02d', $h );
		}
		fputcsv( $out, $head, ',', '"', '\\' );
		foreach ( LSCC_DB::get_days() as $d ) {
			fputcsv( $out, array_merge( array( $d['date'], $d['total'], $d['offhours'], $d['source'], (int) $d['locked'] ), $d['hours'] ), ',', '"', '\\' );
		}
		fclose( $out );
		exit;
	}
}
