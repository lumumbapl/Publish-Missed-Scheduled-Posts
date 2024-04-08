<?php
/*
Plugin Name: Schedulify
Description: WordPress plugin that automatically publishes all the scheduled posts missed by WordPress cron. Sends email notifications to administrators.
Author: WP Corner
Contributors: wpcornerke, lumiblog
Author URI: https://wpcorner.co
Version: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: schedulify
Domain Path: /languages
*/

  if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Plugin basename for further reference
$schedulify_base_name = plugin_basename(__FILE__);

// Hook into WordPress
add_action('init', 'schedulify_init', 0);

// Plugin Actions
add_filter('plugin_action_links_' . $schedulify_base_name, 'schedulify_plugin_activation_link', 10, 1);
add_filter('plugin_row_meta', 'schedulify_plugin_row_meta', 10, 2);

// Add activation link under the Posts menu
add_action('admin_menu', 'schedulify_add_activation_link_to_menu');

// Add settings link
add_action('admin_menu', 'schedulify_add_settings_link_to_menu');

// Add Cron Event Stats link
add_action('admin_menu', 'schedulify_add_cron_event_stats_link_to_menu');

// Register settings
add_action('admin_init', 'schedulify_register_settings');

/**
 * Check timestamp from transient and publish all missed posts
 */
function schedulify_init()
{
    $last_scheduled_missed_time = get_transient('schedulify_scheduled_missed_time');
    $time = current_time('timestamp', 0);

    if (false !== $last_scheduled_missed_time && absint($last_scheduled_missed_time) > ($time - schedulify_get_interval())) {
        return;
    }

    set_transient('schedulify_scheduled_missed_time', $time, schedulify_get_interval());

    global $wpdb;

    $sql_query = "SELECT ID FROM {$wpdb->posts} WHERE ( ( post_date > 0 && post_date <= %s ) ) AND post_status = 'future' LIMIT 0,%d";
    $sql = $wpdb->prepare($sql_query, current_time('mysql', 0), schedulify_get_post_limit());
    $scheduled_post_ids = $wpdb->get_col($sql);

    if (!count($scheduled_post_ids)) {
        return;
    }

    foreach ($scheduled_post_ids as $scheduled_post_id) {
        if (!$scheduled_post_id) {
            continue;
        }

        wp_publish_post($scheduled_post_id);

        // Send Email Notification
        if (schedulify_get_email_notifications()) {
            schedulify_send_email_notification($scheduled_post_id);
        }
    }
}

/**
 * Send email notification to administrators
 *
 * @param int $post_id
 */
function schedulify_send_email_notification($post_id)
{
    $admin_email = get_option('admin_email');

    $subject = sprintf(esc_html__('Scheduled Post Published: #%d', 'schedulify'), $post_id);
    $message = sprintf(esc_html__('The scheduled post #%d has been published.', 'schedulify'), $post_id);

    wp_mail($admin_email, $subject, $message);
}

/**
 * Add plugin activation link
 *
 * @param $links
 * @return array
 */
function schedulify_plugin_activation_link($links)
{
    $links[] = '<a href="edit.php?post_status=future&post_type=post">' . esc_html__('Scheduled Posts', 'schedulify') . '</a>';

    return $links;
}

/**
 * Add link in plugin row meta
 *
 * @param $links
 * @param $file
 * @return array
 */
function schedulify_plugin_row_meta($links, $file)
{
    if (false === is_admin()) {
        return;
    }

    if (false === current_user_can('administrator')) {
        return;
    }

    if ($file == plugin_basename(__FILE__)) {
        $links[] = '<a href="https://wpcorner.co/docs/schedulify/">' . esc_html__('Documentation', 'schedulify') . '</a>';
    }

    return $links;
}

/**
 * Add settings link under the Schedulify menu
 */
function schedulify_add_settings_link_to_menu()
{
    add_menu_page(
        esc_html__('Schedulify', 'schedulify'),
        esc_html__('Schedulify', 'schedulify'),
        'manage_options',
        'schedulify_settings_page',
        'schedulify_render_settings_page',
        'dashicons-calendar'
    );

    // Move Scheduled Posts submenu
    remove_submenu_page('edit.php?post_status=future&post_type=post', 'edit.php?post_status=future&post_type=post');
    add_submenu_page(
        'schedulify_settings_page',
        esc_html__('Scheduled Posts', 'schedulify'),
        esc_html__('Scheduled Posts', 'schedulify'),
        'read',
        'edit.php?post_status=future&post_type=post'
    );
}

/**
 * Add Cron Event Stats link under the Schedulify menu
 */
function schedulify_add_cron_event_stats_link_to_menu()
{
    add_submenu_page(
        'schedulify_settings_page',
        esc_html__('Cron Event Stats', 'schedulify'),
        esc_html__('Cron Event Stats', 'schedulify'),
        'read',
        'schedulify_cron_event_stats_page',
        'schedulify_render_cron_event_stats_page'
    );
}

/**
 * Register plugin settings
 */
function schedulify_register_settings()
{
    register_setting('schedulify_settings_group', 'schedulify_email_notifications', 'intval');
    register_setting('schedulify_settings_group', 'schedulify_admin_email', 'sanitize_email');
    register_setting('schedulify_settings_group', 'schedulify_custom_interval', 'intval');
    register_setting('schedulify_settings_group', 'schedulify_allowed_roles', 'schedulify_sanitize_roles');
}

/**
 * Render settings page
 */
function schedulify_render_settings_page()
{
    // Check if the settings have been saved
    if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
?>
        <div id="message" class="updated notice is-dismissible">
            <p><strong><?php esc_html_e('Settings saved.', 'schedulify'); ?></strong></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'schedulify'); ?></span>
            </button>
        </div>
<?php
    }

?>
    <div class="wrap">
        <h1><?php esc_html_e('Schedulify Settings', 'schedulify'); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields('schedulify_settings_group'); ?>
            <?php do_settings_sections('schedulify_settings_group'); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Email Notifications', 'schedulify'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="schedulify_email_notifications" value="1" <?php checked(get_option('schedulify_email_notifications', 1), 1); ?> />
                            <?php esc_html_e('Receive email notifications', 'schedulify'); ?>
                        </label>
                        <?php if (get_option('schedulify_email_notifications', 1)) : ?>
                            <br>
                            <label for="schedulify_admin_email">
                                <?php esc_html_e('Email Address:', 'schedulify'); ?>
                            </label>
                            <input type="email" name="schedulify_admin_email" value="<?php echo esc_attr(get_option('schedulify_admin_email')); ?>" />
                        <?php endif; ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Setting Custom Interval', 'schedulify'); ?></th>
                    <td>
                        <label for="schedulify_custom_interval">
                            <?php esc_html_e('Choose Interval:', 'schedulify'); ?>
                        </label>
                        <select name="schedulify_custom_interval" id="schedulify_custom_interval">
                            <?php
                            $selected_interval = get_option('schedulify_custom_interval', 15);
                            $intervals = array(5, 10, 15, 30, 60);

                            foreach ($intervals as $interval) {
                                echo '<option value="' . esc_attr($interval) . '" ' . selected($selected_interval, $interval, false) . '>' . esc_html($interval) . ' ' . esc_html__('minutes', 'schedulify') . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('User Roles Access', 'schedulify'); ?></th>
                    <td>
                        <?php
                        $allowed_roles = get_option('schedulify_allowed_roles', array());
                        $all_roles = wp_roles()->get_names();

                        foreach ($all_roles as $role => $label) :
                        ?>
                            <label>
                                <input type="checkbox" name="schedulify_allowed_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $allowed_roles), true); ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                            <br>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(esc_html__('Save Settings', 'schedulify')); ?>
        </form>
    </div>
<?php
}

/**
 * Render Cron Event Stats page
 */
function schedulify_render_cron_event_stats_page()
{
?>
    <div class="wrap">
        <h1><?php esc_html_e('Cron Event Stats', 'schedulify'); ?></h1>

        <?php
        $last_scheduled_missed_time = get_transient('schedulify_scheduled_missed_time');
        $missed_posts_count = schedulify_get_missed_posts_count();
        $missed_posts = schedulify_get_missed_posts();

        if (false !== $last_scheduled_missed_time) :
        ?>
            <p><?php esc_html_e('Last Cron Run:', 'schedulify'); ?> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_scheduled_missed_time)); ?></p>
        <?php endif; ?>

        <p><?php esc_html_e('Missed Scheduled Posts:', 'schedulify'); ?> <?php echo esc_html($missed_posts_count); ?></p>

        <?php if ($missed_posts_count > 0) : ?>
            <h2><?php esc_html_e('List of Missed Scheduled Posts:', 'schedulify'); ?></h2>
            <ul>
                <?php foreach ($missed_posts as $missed_post) : ?>
                    <?php
                    $post_edit_link = get_edit_post_link($missed_post->ID);
                    $post_view_link = get_permalink($missed_post->ID);
                    ?>
                    <li>
                        <?php echo esc_html(get_the_title($missed_post->ID)); ?>
                        - <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($missed_post->post_date))); ?>
                        (<?php echo '<a href="' . esc_url($post_view_link) . '" target="_blank">' . esc_html__('View', 'schedulify') . '</a>'; ?> |
                            <?php echo '<a href="' . esc_url($post_edit_link) . '" target="_blank">' . esc_html__('Edit', 'schedulify') . '</a>'; ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php
}

/**
 * Get the configured post limit
 *
 * @return int
 */
function schedulify_get_post_limit()
{
    return apply_filters('schedulify_post_limit', get_option('schedulify_post_limit', 20));
}

/**
 * Get the configured interval
 *
 * @return int
 */
function schedulify_get_interval()
{
    return apply_filters('schedulify_interval', get_option('schedulify_custom_interval', 15) * MINUTE_IN_SECONDS);
}

/**
 * Get the configured email notifications status
 *
 * @return bool
 */
function schedulify_get_email_notifications()
{
    return apply_filters('schedulify_email_notifications', get_option('schedulify_email_notifications', true));
}

/**
 * Get the count of missed scheduled posts
 *
 * @return int
 */
function schedulify_get_missed_posts_count()
{
    global $wpdb;

    $sql_query = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE ( ( post_date > 0 ) ) AND post_status = 'publish' AND post_type = 'post'";
    $missed_posts_count = $wpdb->get_var($sql_query);

    return $missed_posts_count;
}

/**
 * Get the list of missed scheduled posts
 *
 * @return array
 */
function schedulify_get_missed_posts()
{
    global $wpdb;

    $sql_query = "SELECT ID, post_date FROM {$wpdb->posts} WHERE ( ( post_date > 0 ) ) AND post_status = 'publish' AND post_type = 'post'";
    $missed_posts = $wpdb->get_results($sql_query);

    return $missed_posts;
}
