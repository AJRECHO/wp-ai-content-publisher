<?php
/**
 * 卸载清理：删除插件写入的所有数据与计划任务。
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$schedule = 'aicp_publish_cron';
wp_clear_scheduled_hook( $schedule );

$options = array(
    'aicp_settings',
    'aicp_publish_log',
    'aicp_used_urls',
    'aicp_rotation',
    'aicp_pub_time',
);
if ( is_multisite() ) {
    $sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        foreach ( $options as $opt ) { delete_option( $opt ); }
        restore_current_blog();
    }
} else {
    foreach ( $options as $opt ) { delete_option( $opt ); }
}
