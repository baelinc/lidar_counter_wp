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
		add_action( 'admin_post_lscc_delete_selected', array( __CLASS__, 'handle_delete_selected' ) );
		add_action( 'admin_post_lscc_delete_range', array( __CLASS__, 'handle_delete_range' ) );
		add_action( 'admin_post_lscc_delete_upload', array( __CLASS__, 'handle_delete_upload' ) );
		add_action( 'admin_post_lscc_remove_log', array( __CLASS__, 'handle_remove_log' ) );
		add_action( 'admin_post_lscc_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_action( 'admin_post_lscc_unblock', array( __CLASS__, 'handle_unblock' ) );
		add_action( 'admin_post_lscc_clear_floor', array( __CLASS__, 'handle_clear_floor' ) );
		add_action( 'admin_post_lscc_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_action( 'admin_post_lscc_reset_all', array( __CLASS__, 'handle_reset_all' ) );
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
			wp_enqueue_script( 'lscc-cards', LSCC_URL . 'assets/lscc-cards.js', array(), LSCC_VERSION, true );
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
			/* translators: %d: number of nights */
			'deleted'      => array( 'success', __( '%d night(s) deleted. They will be ignored if the Pi uploads them again – restore them under "Ignored nights" if you change your mind.', 'light-show-car-counter' ) ),
			'nothing'      => array( 'warning', __( 'Nothing was selected, or no nights matched.', 'light-show-car-counter' ) ),
			'bad_range'    => array( 'error', __( 'Enter a valid start and end date (start must not be after end).', 'light-show-car-counter' ) ),
			'log_removed'  => array( 'success', __( 'Upload log updated.', 'light-show-car-counter' ) ),
			'restored'     => array( 'success', __( 'Restored. The next upload from the Pi can bring that night back.', 'light-show-car-counter' ) ),
			'floor_clear'  => array( 'success', __( 'Now accepting all nights again. The next upload re-sends the Pi\'s recent history.', 'light-show-car-counter' ) ),
			'reset_done'   => array( 'success', __( 'All data was deleted.', 'light-show-car-counter' ) ),
			'day_outside'  => array( 'warning', __( 'Night saved – but its date is outside your Season dates, so it is not counted in the cards. Change the season dates in Settings if you want it counted.', 'light-show-car-counter' ) ),
			'upd_none'     => array( 'success', __( 'You are running the latest version.', 'light-show-car-counter' ) ),
			'upd_found'    => array( 'warning', __( 'A new version is available – use the Update now button.', 'light-show-car-counter' ) ),
			'upd_error'    => array( 'error', __( 'Could not check GitHub for updates. Try again in a few minutes.', 'light-show-car-counter' ) ),
			'reset_bad'    => array( 'error', __( 'Nothing was deleted: you must type RESET exactly to confirm.', 'light-show-car-counter' ) ),
		);
		$key = isset( $_GET['lscc_msg'] ) ? sanitize_key( wp_unslash( $_GET['lscc_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			$n    = isset( $_GET['lscc_n'] ) ? absint( $_GET['lscc_n'] ) : 0;
			$text = false !== strpos( $messages[ $key ][1], '%d' ) ? sprintf( $messages[ $key ][1], $n ) : $messages[ $key ][1];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $text ) );
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
		LSCC_Updater::banner();
		echo self::health_box();

		$cards = '';
		$opts  = array( 'hero' => true, 'spark' => LSCC_Shortcodes::recent_totals( (string) LSCC_Settings::get( 'season_start' ), (string) LSCC_Settings::get( 'season_end' ) ) );
		$names = array( 'total', 'tonight', 'last_night', 'nights', 'average', 'best_night', 'busiest_hour' );
		if ( $s['people'] > 0 ) {
			$names = array_merge( $names, array( 'people_tonight', 'people', 'people_peak', 'people_per_car' ) );
		}
		foreach ( $names as $name ) {
			$cards .= LSCC_Shortcodes::stat_card( $name, $s, $opts );
		}
		echo LSCC_Render::wrap( '<div class="lscc-cards">' . $cards . '</div>' );
		$out = LSCC_DB::outside_season();
		if ( $out['nights'] ) {
			echo '<div class="lscc-box lscc-health-stale"><strong>' . esc_html__( 'Some nights are not in the cards above.', 'light-show-car-counter' ) . '</strong> '
				. esc_html( sprintf( /* translators: 1: nights 2: cars 3: start 4: end */ __( '%1$d saved night(s) with %2$s cars fall outside your Season dates (%3$s to %4$s), so they are left out of the totals. The chart below still shows them.', 'light-show-car-counter' ), $out['nights'], number_format_i18n( $out['cars'] ), '' !== LSCC_Settings::get( 'season_start' ) ? LSCC_Settings::get( 'season_start' ) : __( 'any', 'light-show-car-counter' ), '' !== LSCC_Settings::get( 'season_end' ) ? LSCC_Settings::get( 'season_end' ) : __( 'any', 'light-show-car-counter' ) ) )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=lscc-settings' ) ) . '">' . esc_html__( 'Change season dates', 'light-show-car-counter' ) . '</a></div>';
		}

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
		echo '<tr><th><label for="lscc-people">' . esc_html__( 'People (est.)', 'light-show-car-counter' ) . '</label></th><td><input type="number" min="0" id="lscc-people" name="people" value="' . esc_attr( $editing && $editing['people'] ? $editing['people'] : '' ) . '"> <span class="description">' . esc_html__( 'Optional – estimated visitors from a BaldrickSignals board. Leave empty to keep what is stored.', 'light-show-car-counter' ) . '</span></td></tr>';
		echo '<tr><th><label for="lscc-peak">' . esc_html__( 'Biggest crowd', 'light-show-car-counter' ) . '</label></th><td><input type="number" min="0" id="lscc-peak" name="people_peak" value="' . esc_attr( $editing && $editing['people_peak'] ? $editing['people_peak'] : '' ) . '"> <span class="description">' . esc_html__( 'Optional – most people at once.', 'light-show-car-counter' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Protect', 'light-show-car-counter' ) . '</th><td><label><input type="checkbox" name="locked" value="1" checked> ' . esc_html__( 'Lock this night so uploads from the Pi cannot overwrite it', 'light-show-car-counter' ) . '</label></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Save night', 'light-show-car-counter' ) . '</button>';
		if ( $editing ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=lscc' ) ) . '">' . esc_html__( 'Cancel', 'light-show-car-counter' ) . '</a>';
		}
		echo '</p></form></div>';

		// Reset cut-off banner
		$floor = (string) LSCC_Settings::get( 'data_floor' );
		if ( '' !== $floor ) {
			echo '<div class="lscc-box lscc-health-stale"><strong>' . esc_html__( 'Ignoring older uploads:', 'light-show-car-counter' ) . '</strong> '
				. esc_html( sprintf( /* translators: %s: date */ __( 'nights before %s that the Pi sends are skipped (set by "Reset all data").', 'light-show-car-counter' ), $floor ) ) . ' ';
			self::row_form( 'lscc_clear_floor', '', __( 'Accept all nights again', 'light-show-car-counter' ) );
			echo '</div>';
		}

		// All nights (with bulk select)
		echo '<div class="lscc-box"><h2>' . esc_html__( 'All nights', 'light-show-car-counter' ) . '</h2>';
		if ( ! $days ) {
			echo '<p>' . esc_html__( 'No nights recorded yet.', 'light-show-car-counter' ) . '</p>';
		} else {
			echo '<form id="lscc-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Delete the selected nights?', 'light-show-car-counter' ) ) ) . ');">';
			wp_nonce_field( 'lscc_delete_selected' );
			echo '<input type="hidden" name="action" value="lscc_delete_selected"><button class="button">' . esc_html__( 'Delete selected nights', 'light-show-car-counter' ) . '</button> '
				. '<span class="description">' . esc_html__( 'Deleted nights are ignored if the Pi uploads them again.', 'light-show-car-counter' ) . '</span></form>';
			echo '<table class="widefat striped" style="margin-top:8px"><thead><tr><td class="check-column"><input type="checkbox" id="lscc-all" aria-label="' . esc_attr__( 'Select all', 'light-show-car-counter' ) . '"></td><th>' . esc_html__( 'Night', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Cars', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Off-hours', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'People (est.)', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Source', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Updated (UTC)', 'light-show-car-counter' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $days as $d ) {
				echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="dates[]" value="' . esc_attr( $d['date'] ) . '" form="lscc-bulk"></th>';
				echo '<td>' . esc_html( LSCC_Settings::format_date( $d['date'] ) . ' (' . $d['date'] . ')' ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $d['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $d['offhours'] ) ) . '</td><td>' . ( $d['people'] > 0 ? esc_html( number_format_i18n( $d['people'] ) . ' (peak ' . number_format_i18n( $d['people_peak'] ) . ')' ) : '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( $d['source'] ) . ( $d['locked'] ? ' &#128274;' : '' ) . '</td><td>' . esc_html( $d['updated_at'] ) . '</td><td>';
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( array( 'page' => 'lscc', 'edit' => $d['date'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit', 'light-show-car-counter' ) . '</a> ';
				self::row_form( 'lscc_lock_day', $d['date'], $d['locked'] ? __( 'Unlock', 'light-show-car-counter' ) : __( 'Lock', 'light-show-car-counter' ), array( 'lock' => $d['locked'] ? '0' : '1' ) );
				self::row_form( 'lscc_delete_day', $d['date'], __( 'Delete', 'light-show-car-counter' ), array(), __( 'Delete this night? It will be ignored if the Pi uploads it again.', 'light-show-car-counter' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<script>document.getElementById("lscc-all").addEventListener("change",function(e){document.querySelectorAll("input[name=\'dates[]\']").forEach(function(c){c.checked=e.target.checked;});});</script>';
		}
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=lscc_export' ), 'lscc_export' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Download CSV', 'light-show-car-counter' ) . '</a></p></div>';

		// Delete a range
		echo '<div class="lscc-box"><h2>' . esc_html__( 'Delete a range of nights', 'light-show-car-counter' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Delete every night in this range?', 'light-show-car-counter' ) ) ) . ');">';
		wp_nonce_field( 'lscc_delete_range' );
		echo '<input type="hidden" name="action" value="lscc_delete_range">'
			. '<label>' . esc_html__( 'From', 'light-show-car-counter' ) . ' <input type="date" name="from" required></label> '
			. '<label>' . esc_html__( 'to', 'light-show-car-counter' ) . ' <input type="date" name="to" required></label> '
			. '<button class="button">' . esc_html__( 'Delete range', 'light-show-car-counter' ) . '</button></form></div>';

		// Ignored nights
		$blocked = LSCC_DB::blocked();
		if ( $blocked ) {
			echo '<div class="lscc-box"><h2>' . esc_html__( 'Ignored nights', 'light-show-car-counter' ) . '</h2><p class="description">'
				. esc_html__( 'You deleted these, so uploads for them are skipped. Restore one to let the Pi bring it back.', 'light-show-car-counter' ) . '</p>';
			foreach ( array_reverse( $blocked ) as $date ) {
				echo '<span style="display:inline-block;margin:0 12px 6px 0">' . esc_html( $date ) . ' ';
				self::row_form( 'lscc_unblock', $date, __( 'Restore', 'light-show-car-counter' ) );
				echo '</span>';
			}
			echo '</div>';
		}

		// Danger zone
		echo '<div class="lscc-box" style="border-left:4px solid #c62828"><h2>' . esc_html__( 'Reset all data', 'light-show-car-counter' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes every night, the upload log and the ignored-nights list. Your settings and shared secret are kept. This cannot be undone.', 'light-show-car-counter' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lscc_reset_all' );
		echo '<input type="hidden" name="action" value="lscc_reset_all">';
		echo '<p><strong>' . esc_html__( 'Your Pi keeps its own history and re-sends recent nights every upload. After the reset:', 'light-show-car-counter' ) . '</strong></p>';
		echo '<p><label><input type="radio" name="restart" value="tonight" checked> ' . esc_html__( 'Accept tonight\'s upload, ignore anything older (recommended)', 'light-show-car-counter' ) . '</label><br>'
			. '<label><input type="radio" name="restart" value="tomorrow"> ' . esc_html__( 'Ignore tonight too – start counting from tomorrow night', 'light-show-car-counter' ) . '</label><br>'
			. '<label><input type="radio" name="restart" value="all"> ' . esc_html__( 'Accept everything the Pi sends again (its recent history will come back)', 'light-show-car-counter' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Type RESET to confirm:', 'light-show-car-counter' ) . ' <input type="text" name="confirm_text" autocomplete="off" size="10"></label> '
			. '<button class="button" style="border-color:#c62828;color:#c62828">' . esc_html__( 'Delete all data', 'light-show-car-counter' ) . '</button></p></form></div></div>';
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
		LSCC_Updater::banner();
		echo self::health_box();

		// Plugin updates
		$info = LSCC_Updater::latest();
		echo '<div class="lscc-box"><h2>' . esc_html__( 'Plugin updates', 'light-show-car-counter' ) . '</h2><p>'
			. esc_html( sprintf( /* translators: %s: version */ __( 'Installed version: %s', 'light-show-car-counter' ), LSCC_VERSION ) );
		if ( $info['version'] ) {
			echo ' · ' . esc_html( sprintf( /* translators: %s: version */ __( 'Latest on GitHub: %s', 'light-show-car-counter' ), $info['version'] ) );
		}
		if ( $info['checked'] ) {
			echo ' · ' . esc_html( sprintf( /* translators: %s: time ago */ __( 'checked %s ago', 'light-show-car-counter' ), human_time_diff( $info['checked'] ) ) );
		}
		echo '</p>';
		self::row_form( 'lscc_check_update', '', __( 'Check for updates now', 'light-show-car-counter' ) );
		echo '<p class="description">' . esc_html__( 'WordPress also checks automatically a few times a day and shows "Update now" on the Plugins page.', 'light-show-car-counter' ) . '</p></div>';

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
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Nights received', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'Skipped (locked / ignored)', 'light-show-car-counter' ) . '</th><th>' . esc_html__( 'FPP plugin', 'light-show-car-counter' ) . '</th><th>IP</th><th></th></tr></thead><tbody>';
			foreach ( $log as $row ) {
				$ts    = (int) $row['ts'];
				$dates = isset( $row['dates'] ) && is_array( $row['dates'] ) ? $row['dates'] : array();
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) . '</td><td>' . esc_html( (int) $row['received'] ) . '</td><td>' . esc_html( (int) $row['locked'] . ' / ' . ( isset( $row['ignored'] ) ? (int) $row['ignored'] : 0 ) ) . '</td><td>' . esc_html( $row['version'] ) . '</td><td>' . esc_html( $row['ip'] ) . '</td><td>';
				if ( $dates ) {
					self::row_form( 'lscc_delete_upload', '', __( 'Delete its nights', 'light-show-car-counter' ), array( 'ts' => $ts ), sprintf( /* translators: %d: number of nights */ __( 'Delete the %d night(s) this upload brought in? They will be ignored if uploaded again.', 'light-show-car-counter' ), count( $dates ) ) );
				}
				self::row_form( 'lscc_remove_log', '', __( 'Remove entry', 'light-show-car-counter' ), array( 'ts' => $ts ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p>';
			self::row_form( 'lscc_clear_log', '', __( 'Clear upload log', 'light-show-car-counter' ), array(), __( 'Clear the whole upload log? Stored nights are not affected.', 'light-show-car-counter' ) );
			echo '</p>';
		}
		echo '</div>';

		// Shortcode help
		echo '<div class="lscc-box"><h2>' . esc_html__( '4. Shortcodes', 'light-show-car-counter' ) . '</h2>'
			. '<p class="description">' . esc_html__( 'Paste any of these into a page, post or widget. Press Copy to copy one.', 'light-show-car-counter' ) . '</p>';
		$groups = array(
			__( 'Single numbers (use inside a sentence or a heading)', 'light-show-car-counter' ) => array(
				'[car_counter]'                          => __( 'Cars so far this season', 'light-show-car-counter' ),
				'[car_counter stat="tonight"]'           => __( 'Cars tonight', 'light-show-car-counter' ),
				'[car_counter stat="last_night"]'        => __( 'Cars last night', 'light-show-car-counter' ),
				'[car_counter stat="best_night"]'        => __( 'Cars on the best night', 'light-show-car-counter' ),
				'[car_counter stat="best_night_date"]'   => __( 'Date of the best night', 'light-show-car-counter' ),
				'[car_counter stat="average"]'           => __( 'Average cars per night', 'light-show-car-counter' ),
				'[car_counter stat="nights"]'            => __( 'Number of nights open', 'light-show-car-counter' ),
				'[car_counter stat="busiest_hour"]'      => __( 'Busiest hour of the evening', 'light-show-car-counter' ),
				'[car_counter stat="updated"]'           => __( 'When the last upload arrived', 'light-show-car-counter' ),
				'[car_counter stat="people"]'            => __( 'People so far (estimated, from a BaldrickSignals board)', 'light-show-car-counter' ),
				'[car_counter stat="people_tonight"]'    => __( 'People tonight (estimated)', 'light-show-car-counter' ),
				'[car_counter stat="people_last_night"]' => __( 'People last night (estimated)', 'light-show-car-counter' ),
				'[car_counter stat="people_peak"]'       => __( 'Biggest crowd at one time', 'light-show-car-counter' ),
				'[car_counter stat="people_per_car"]'    => __( 'Estimated people per car', 'light-show-car-counter' ),
				'[car_counter stat="date" date="2026-12-24"]' => __( 'Cars on one specific night', 'light-show-car-counter' ),
			),
			__( 'Single number options', 'light-show-car-counter' ) => array(
				'[car_counter stat="tonight" live="true"]' => __( 'Refreshes itself every minute (works for total, tonight, last_night, nights). Needs "Live numbers" turned on above.', 'light-show-car-counter' ),
				'[car_counter label="Cars tonight:" stat="tonight"]' => __( 'Add a label. Also: prefix="…" and suffix="…".', 'light-show-car-counter' ),
				'[car_counter stat="tonight" style="card"]' => __( 'Show a single number as a designed card instead of plain text. Works with any stat; put several in a row and they sit side by side.', 'light-show-car-counter' ),
				'[car_counter format="raw"]'             => __( 'No thousands separator (1234 instead of 1,234)', 'light-show-car-counter' ),
			),
			__( 'Cards', 'light-show-car-counter' ) => array(
				'[car_counter_stats]'                    => __( 'The full set: big "Cars so far" card with trend line, tonight, last night, best night, average, busiest hour', 'light-show-car-counter' ),
				'[car_counter_stats cards="tonight,last_night,busiest_hour"]' => __( 'Choose your own cards, in order. Available: total, tonight, last_night, best_night, average, nights, busiest_hour, updated.', 'light-show-car-counter' ),
				'[car_counter_stats hero="true" cards="total,nights,average,best_night"]' => __( 'The big "Cars so far" card with trend line is only used for the full set. If you choose your own cards you get an even grid; add hero="true" to get the big card anyway.', 'light-show-car-counter' ),
				'[car_counter_stats title="By the numbers"]' => __( 'Add a heading above the cards', 'light-show-car-counter' ),
			),
			__( 'Charts', 'light-show-car-counter' ) => array(
				'[car_counter_chart]'                    => __( 'Bar chart of the last 14 nights', 'light-show-car-counter' ),
				'[car_counter_chart days="30" type="line"]' => __( 'Line chart, any number of nights (up to 120). Also: height="300", title="…".', 'light-show-car-counter' ),
				'[car_counter_chart metric="people"]'    => __( 'Bar chart of estimated people per night instead of cars', 'light-show-car-counter' ),
				'[car_counter_chart view="hourly"]'      => __( 'Which hours of the evening are busiest across the season', 'light-show-car-counter' ),
			),
			__( 'Table', 'light-show-car-counter' ) => array(
				'[car_counter_table]'                    => __( 'Table of nightly totals (newest first)', 'light-show-car-counter' ),
				'[car_counter_table limit="30" order="asc"]' => __( 'Choose how many nights and the order (asc = oldest first)', 'light-show-car-counter' ),
			),
			__( 'Any shortcode', 'light-show-car-counter' ) => array(
				'since="2026-11-26" until="2026-12-31"'  => __( 'Add to any shortcode above to use these dates instead of your Season dates, e.g. [car_counter_stats since="2026-12-01"]', 'light-show-car-counter' ),
			),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $groups as $title => $rows ) {
			echo '<tr><th colspan="3" style="background:#f0f0f1">' . esc_html( $title ) . '</th></tr>';
			foreach ( $rows as $code => $desc ) {
				echo '<tr><td style="white-space:nowrap"><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $desc ) . '</td><td>'
					. ( 0 === strpos( $code, '[' ) ? '<button type="button" class="button button-small lscc-copy" data-code="' . esc_attr( $code ) . '">' . esc_html__( 'Copy', 'light-show-car-counter' ) . '</button>' : '' )
					. '</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<script>document.querySelectorAll(".lscc-copy").forEach(function(b){b.addEventListener("click",function(){var t=b.getAttribute("data-code"),d=function(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy"},1500)};if(navigator.clipboard){navigator.clipboard.writeText(t).then(d)}else{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");document.body.removeChild(a);d()}})})</script>';
		echo '</div></div>';
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
			$cards .= LSCC_Shortcodes::stat_card( $name, $s );
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
			'people'   => ( isset( $_POST['people'] ) && '' !== trim( (string) $_POST['people'] ) ) ? max( 0, (int) $_POST['people'] ) : null,
			'people_peak' => ( isset( $_POST['people_peak'] ) && '' !== trim( (string) $_POST['people_peak'] ) ) ? max( 0, (int) $_POST['people_peak'] ) : null,
			'hours'    => $existing ? $existing['hours'] : array(),
			'first_ts' => $existing ? $existing['first_ts'] : 0,
			'last_ts'  => $existing ? $existing['last_ts'] : 0,
		), 'manual', true, ! empty( $_POST['locked'] ) );
		LSCC_DB::unblock( $date );   // adding a night by hand means you want it
		LSCC_DB::bump_cache();
		$ss = (string) LSCC_Settings::get( 'season_start' );
		$se = (string) LSCC_Settings::get( 'season_end' );
		self::back( 'lscc', ( ( '' !== $ss && $date < $ss ) || ( '' !== $se && $date > $se ) ) ? 'day_outside' : 'day_saved' );
	}

	public static function handle_delete_day() {
		self::guard( 'lscc_delete_day' );
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$n    = LSCC_Settings::valid_date( $date ) ? LSCC_DB::delete_days( array( $date ) ) : 0;
		self::back( 'lscc', 'deleted', array( 'lscc_n' => $n ) );
	}

	public static function handle_delete_selected() {
		self::guard( 'lscc_delete_selected' );
		$dates = isset( $_POST['dates'] ) && is_array( $_POST['dates'] ) ? array_slice( array_map( 'sanitize_text_field', wp_unslash( $_POST['dates'] ) ), 0, 500 ) : array();
		$dates = array_filter( $dates, array( 'LSCC_Settings', 'valid_date' ) );
		if ( ! $dates ) {
			self::back( 'lscc', 'nothing' );
		}
		self::back( 'lscc', 'deleted', array( 'lscc_n' => LSCC_DB::delete_days( $dates ) ) );
	}

	public static function handle_delete_range() {
		self::guard( 'lscc_delete_range' );
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! LSCC_Settings::valid_date( $from ) || ! LSCC_Settings::valid_date( $to ) || $from > $to ) {
			self::back( 'lscc', 'bad_range' );
		}
		$dates = LSCC_DB::dates_in_range( $from, $to );
		if ( ! $dates ) {
			self::back( 'lscc', 'nothing' );
		}
		self::back( 'lscc', 'deleted', array( 'lscc_n' => LSCC_DB::delete_days( $dates ) ) );
	}

	private static function log_without( $ts ) {
		$log = get_option( 'lscc_sync_log', array() );
		$log = is_array( $log ) ? $log : array();
		foreach ( $log as $i => $row ) {
			if ( isset( $row['ts'] ) && (int) $row['ts'] === (int) $ts ) {
				unset( $log[ $i ] );
				break;
			}
		}
		update_option( 'lscc_sync_log', array_values( $log ), false );
	}

	/** Delete the nights a particular upload brought in, then drop its log row. */
	public static function handle_delete_upload() {
		self::guard( 'lscc_delete_upload' );
		$ts    = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		$log   = get_option( 'lscc_sync_log', array() );
		$dates = array();
		foreach ( is_array( $log ) ? $log : array() as $row ) {
			if ( isset( $row['ts'] ) && (int) $row['ts'] === $ts && isset( $row['dates'] ) && is_array( $row['dates'] ) ) {
				$dates = $row['dates'];
				break;
			}
		}
		$n = $dates ? LSCC_DB::delete_days( $dates ) : 0;
		self::log_without( $ts );
		self::back( 'lscc-settings', $dates ? 'deleted' : 'nothing', array( 'lscc_n' => $n ) );
	}

	public static function handle_remove_log() {
		self::guard( 'lscc_remove_log' );
		self::log_without( isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0 );
		self::back( 'lscc-settings', 'log_removed' );
	}

	public static function handle_clear_log() {
		self::guard( 'lscc_clear_log' );
		update_option( 'lscc_sync_log', array(), false );
		self::back( 'lscc-settings', 'log_removed' );
	}

	public static function handle_unblock() {
		self::guard( 'lscc_unblock' );
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( LSCC_Settings::valid_date( $date ) ) {
			LSCC_DB::unblock( $date );
		}
		self::back( 'lscc', 'restored' );
	}

	public static function handle_clear_floor() {
		self::guard( 'lscc_clear_floor' );
		LSCC_Settings::set_floor( '' );
		self::back( 'lscc', 'floor_clear' );
	}

	public static function handle_check_update() {
		self::guard( 'lscc_check_update' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'light-show-car-counter' ), 403 );
		}
		delete_site_transient( 'update_plugins' );   // make WordPress re-read too
		$info = LSCC_Updater::latest( true );
		$msg  = $info['error'] ? 'upd_error' : ( LSCC_Updater::update_available() ? 'upd_found' : 'upd_none' );
		self::back( 'lscc-settings', $msg );
	}

	public static function handle_reset_all() {
		self::guard( 'lscc_reset_all' );
		$confirm = isset( $_POST['confirm_text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm_text'] ) ) ) : '';
		if ( 'RESET' !== $confirm ) {
			self::back( 'lscc', 'reset_bad' );
		}
		$restart = isset( $_POST['restart'] ) ? sanitize_key( wp_unslash( $_POST['restart'] ) ) : 'tonight';
		LSCC_DB::reset_all( in_array( $restart, array( 'tonight', 'tomorrow', 'all' ), true ) ? $restart : 'tonight' );
		self::back( 'lscc', 'reset_done' );
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
		$head = array( 'date', 'cars', 'offhours_detections', 'people_est', 'people_peak', 'source', 'locked' );
		for ( $h = 0; $h < 24; $h++ ) {
			$head[] = sprintf( 'h%02d', $h );
		}
		fputcsv( $out, $head, ',', '"', '\\' );
		foreach ( LSCC_DB::get_days() as $d ) {
			fputcsv( $out, array_merge( array( $d['date'], $d['total'], $d['offhours'], $d['people'], $d['people_peak'], $d['source'], (int) $d['locked'] ), $d['hours'] ), ',', '"', '\\' );
		}
		fclose( $out );
		exit;
	}
}
