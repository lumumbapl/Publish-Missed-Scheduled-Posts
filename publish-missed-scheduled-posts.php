<?php
/*
Plugin Name: Publish Missed Scheduled Posts
Description: WordPress plugin that automatically publishes all the scheduled posts missed by WordPress cron. Sends email notifications to administrators.
Author: WP Corner
Contributors: wpcorner, lumiblog
Author URI: https://wpcorner.co
Version: 1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publish-missed-scheduled-posts
 */

//bail if not WordPress path
if ( false === defined( 'ABSPATH' ) ) {
	return;
}

//plugin basename for further reference
$nv_wpms_base_name = plugin_basename( __FILE__ );

// Set default check interval - every 10 min
if ( false === defined( 'WPMSP_INTERVAL' ) ) {
	define( 'WPMSP_INTERVAL', 15 * MINUTE_IN_SECONDS );
}

// Set post limit
if ( false === defined( 'WPMSP_POST_LIMIT' ) ) {
	define( 'WPMSP_POST_LIMIT', 20 );
}

// Email Notifications option
if ( ! defined( 'WPMSP_EMAIL_NOTIFICATIONS' ) ) {
	define( 'WPMSP_EMAIL_NOTIFICATIONS', true );
}

// Hook into WordPress
add_action( 'init', 'nv_wpmsp_init', 0 );

// Plugin Actions
add_filter( 'plugin_action_links_' . $nv_wpms_base_name, 'nv_wpmsp_plugin_activation_link', 10, 1 );
add_filter( 'plugin_row_meta', 'nv_wpmsp_plugin_row_meta', 10, 2 );

// Add activation link under the Posts menu
add_action( 'admin_menu', 'nv_wpmsp_add_activation_link_to_menu' );

/**
 * Check timestamp from transient and publish all missed posts
 */
function nv_wpmsp_init() {
	$last_scheduled_missed_time = get_transient( 'wp_scheduled_missed_time' );
	$time                       = current_time( 'timestamp', 0 );

	if ( false !== $last_scheduled_missed_time && absint( $last_scheduled_missed_time ) > ( $time - WPMSP_INTERVAL ) ) {
		return;
	}

	set_transient( 'wp_scheduled_missed_time', $time, WPMSP_INTERVAL );

	global $wpdb;

	$sql_query           = "SELECT ID FROM {$wpdb->posts} WHERE ( ( post_date > 0 && post_date <= %s ) ) AND post_status = 'future' LIMIT 0,%d";
	$sql                 = $wpdb->prepare( $sql_query, current_time( 'mysql', 0 ), WPMSP_POST_LIMIT );
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
		if ( WPMSP_EMAIL_NOTIFICATIONS ) {
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

	$subject = sprintf( esc_html__( 'Scheduled Post Published: #%d', 'publish-missed-scheduled-posts' ), $post_id );
	$message = sprintf( esc_html__( 'The scheduled post #%d has been published.', 'publish-missed-scheduled-posts' ), $post_id );

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
	$links[] = '<a href="edit.php?post_status=future&post_type=post">' . esc_html__( 'Scheduled Posts', 'publish-missed-scheduled-posts' ) . '</a>';

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
		$links[] = '<a href="https://wpcorner.co/docs/publish-missed-scheduled-posts/">' . esc_html__( 'Documentation', 'publish-missed-scheduled-posts' ) . '</a>';
	}

	return $links;
}

/**
 * Add activation link under the Posts menu
 */
function nv_wpmsp_add_activation_link_to_menu() {
	add_posts_page(
		esc_html__( 'Scheduled Posts', 'publish-missed-scheduled-posts' ),
		esc_html__( 'Scheduled Posts', 'publish-missed-scheduled-posts' ),
		'read',
		'edit.php?post_status=future&post_type=post'
	);
}
