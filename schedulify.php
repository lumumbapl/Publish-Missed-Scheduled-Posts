<?php
/*
Plugin Name: Schedulify
Description: WordPress plugin that automatically publishes all the scheduled posts missed by WordPress cron. Sends email notifications to administrators.
Author: WP Corner
Contributors: wpcorner, lumiblog
Author URI: https://wpcorner.co
Version: 1.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: schedulify
 */

//bail if not WordPress path
if ( false === defined( 'ABSPATH' ) ) {
	return;
}

//plugin basename for further reference
$nv_wpms_base_name = plugin_basename( __FILE__ );

// Hook into WordPress
add_action( 'init', 'nv_wpmsp_init', 0 );

// Plugin Actions
add_filter( 'plugin_action_links_' . $nv_wpms_base_name, 'nv_wpmsp_plugin_activation_link', 10, 1 );
add_filter( 'plugin_row_meta', 'nv_wpmsp_plugin_row_meta', 10, 2 );

// Add activation link under the Posts menu
add_action( 'admin_menu', 'nv_wpmsp_add_activation_link_to_menu' );

// Add settings link
add_action( 'admin_menu', 'nv_wpmsp_add_settings_link_to_menu' );

// Register settings
add_action( 'admin_init', 'nv_wpmsp_register_settings' );

/**
 * Check timestamp from transient and publish all missed posts
 */
function nv_wpmsp_init() {
	$last_scheduled_missed_time = get_transient( 'wp_scheduled_missed_time' );
	$time                       = current_time( 'timestamp', 0 );

	if ( false !== $last_scheduled_missed_time && absint( $last_scheduled_missed_time ) > ( $time - nv_wpmsp_get_interval() ) ) {
		return;
	}

	set_transient( 'wp_scheduled_missed_time', $time, nv_wpmsp_get_interval() );

	global $wpdb;

	$sql_query           = "SELECT ID FROM {$wpdb->posts} WHERE ( ( post_date > 0 && post_date <= %s ) ) AND post_status = 'future' LIMIT 0,%d";
	$sql                 = $wpdb->prepare( $sql_query, current_time( 'mysql', 0 ), nv_wpmsp_get_post_limit() );
	$scheduled_post_ids = $wpdb->get_col( $sql );

	if ( ! count( $scheduled_post_ids ) ) {
		return;
	}

	foreach ( $scheduled_post_ids as $scheduled_post_id ) {
		if ( ! $scheduled_post_id ) {
			continue;
		}

		wp_publish_post( $scheduled_post_id );

		// Send Email Notification
		if ( nv_wpmsp_get_email_notifications() ) {
			nv_wpmsp_send_email_notification( $scheduled_post_id );
		}
	}
}

/**
 * Send email notification to administrators
 *
 * @param int $post_id
 */
function nv_wpmsp_send_email_notification( $post_id ) {
	$admin_email = get_option( 'admin_email' );

	$subject = sprintf( esc_html__( 'Scheduled Post Published: #%d', 'schedulify' ), $post_id );
	$message = sprintf( esc_html__( 'The scheduled post #%d has been published.', 'schedulify' ), $post_id );

	wp_mail( $admin_email, $subject, $message );
}

/**
 * Add plugin activation link
 *
 * @param $links
 *
 * @return array
 */
function nv_wpmsp_plugin_activation_link( $links ) {
	$links[] = '<a href="edit.php?post_status=future&post_type=post">' . esc_html__( 'Scheduled Posts', 'schedulify' ) . '</a>';

	return $links;
}

/**
 * Add link in plugin row meta
 *
 * @param $links
 *
 * @return array
 */
function nv_wpmsp_plugin_row_meta( $links, $file ) {
	if ( false === is_admin() ) {
		return;
	}

	if ( false === current_user_can( 'administrator' ) ) {
		return;
	}

	if ( $file == plugin_basename( __FILE__ ) ) {
		$links[] = '<a href="https://wpcorner.co/docs/schedulify/">' . esc_html__( 'Documentation', 'schedulify' ) . '</a>';
	}

	return $links;
}

/**
 * Add settings link under the Schedulify menu
 */
function nv_wpmsp_add_settings_link_to_menu() {
	add_menu_page(
		esc_html__( 'Schedulify', 'schedulify' ),
		esc_html__( 'Schedulify', 'schedulify' ),
		'manage_options',
		'nv_wpmsp_settings_page',
		'nv_wpmsp_render_settings_page',
		'dashicons-calendar'
	);

	// Move Scheduled Posts submenu
	remove_submenu_page( 'edit.php?post_status=future&post_type=post', 'edit.php?post_status=future&post_type=post' );
	add_submenu_page(
		'nv_wpmsp_settings_page',
		esc_html__( 'Scheduled Posts', 'schedulify' ),
		esc_html__( 'Scheduled Posts', 'schedulify' ),
		'read',
		'edit.php?post_status=future&post_type=post'
	);
}

/**
 * Register plugin settings
 */
function nv_wpmsp_register_settings() {
	register_setting( 'nv_wpmsp_settings_group', 'nv_wpmsp_email_notifications', 'intval' );
	register_setting( 'nv_wpmsp_settings_group', 'nv_wpmsp_admin_email', 'sanitize_email' );
	register_setting( 'nv_wpmsp_settings_group', 'nv_wpmsp_custom_interval', 'intval' );
	register_setting( 'nv_wpmsp_settings_group', 'nv_wpmsp_allowed_roles', 'nv_wpmsp_sanitize_roles' );
}

/**
 * Sanitize roles input
 *
 * @param mixed $input
 *
 * @return array
 */
function nv_wpmsp_sanitize_roles( $input ) {
	return is_array( $input ) ? $input : array();
}

/**
 * Render settings page
 */
function nv_wpmsp_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Schedulify Settings', 'schedulify' ); ?></h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'nv_wpmsp_settings_group' ); ?>
			<?php do_settings_sections( 'nv_wpmsp_settings_group' ); ?>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Email Notifications', 'schedulify' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="nv_wpmsp_email_notifications" value="1" <?php checked( get_option( 'nv_wpmsp_email_notifications', 1 ), 1 ); ?> />
							<?php esc_html_e( 'Receive email notifications', 'schedulify' ); ?>
						</label>
						<?php if ( get_option( 'nv_wpmsp_email_notifications', 1 ) ) : ?>
							<br>
							<label for="nv_wpmsp_admin_email">
								<?php esc_html_e( 'Email Address:', 'schedulify' ); ?>
							</label>
							<input type="email" name="nv_wpmsp_admin_email" value="<?php echo esc_attr( get_option( 'nv_wpmsp_admin_email' ) ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Setting Custom Interval', 'schedulify' ); ?></th>
					<td>
						<label for="nv_wpmsp_custom_interval">
							<?php esc_html_e( 'Choose Interval:', 'schedulify' ); ?>
						</label>
						<select name="nv_wpmsp_custom_interval" id="nv_wpmsp_custom_interval">
							<?php
							$selected_interval = get_option( 'nv_wpmsp_custom_interval', 15 );
							$intervals         = array( 5, 10, 15, 30, 60 );

							foreach ( $intervals as $interval ) {
								echo '<option value="' . esc_attr( $interval ) . '" ' . selected( $selected_interval, $interval, false ) . '>' . esc_html( $interval ) . ' ' . esc_html__( 'minutes', 'schedulify' ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'User Roles Access', 'schedulify' ); ?></th>
					<td>
						<?php
						$allowed_roles = get_option( 'nv_wpmsp_allowed_roles', array() );
						$all_roles     = wp_roles()->get_names();

						foreach ( $all_roles as $role => $label ) :
							?>
							<label>
								<input type="checkbox" name="nv_wpmsp_allowed_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $allowed_roles ), true ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
							<br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save Settings', 'schedulify' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Get the configured post limit
 *
 * @return int
 */
function nv_wpmsp_get_post_limit() {
	return apply_filters( 'nv_wpmsp_post_limit', get_option( 'nv_wpmsp_post_limit', 20 ) );
}

/**
 * Get the configured interval
 *
 * @return int
 */
function nv_wpmsp_get_interval() {
	return apply_filters( 'nv_wpmsp_interval', get_option( 'nv_wpmsp_custom_interval', 15 ) * MINUTE_IN_SECONDS );
}

/**
 * Get the configured email notifications status
 *
 * @return bool
 */
function nv_wpmsp_get_email_notifications() {
	return apply_filters( 'nv_wpmsp_email_notifications', get_option( 'nv_wpmsp_email_notifications', true ) );
}
