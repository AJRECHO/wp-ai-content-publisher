<?php
/**
 * Plugin Name:       AI Content Publisher
 * Plugin URI:        https://github.com/ai-content-publisher/ai-content-publisher
 * Description:       从 RSS 自动抓取文章，用 AI（DeepSeek / 任意 OpenAI 兼容接口）忠实翻译润色成中文，自动配图、设封面、打标签后发布到 WordPress。支持定时随机发布、谷时省费、分类轮换、关键词过滤。所有配置均在后台可视化设置，开箱即用。
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            AI Content Publisher Contributors
 * Author URI:        https://github.com/ai-content-publisher/ai-content-publisher
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ai-content-publisher
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AICP_VERSION', '1.0.0' );
define( 'AICP_PLUGIN_FILE', __FILE__ );
define( 'AICP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AICP_PLUGIN_DIR . 'includes/class-settings.php';
require_once AICP_PLUGIN_DIR . 'includes/class-publisher.php';
require_once AICP_PLUGIN_DIR . 'includes/class-rest.php';
require_once AICP_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * 插件主类：加载各组件、注册生命周期钩子。
 */
final class AICP_Plugin {

    /** @var AICP_Plugin|null */
    private static $instance = null;

    /** @var AICP_Settings */
    public $settings;

    /** @var AICP_Publisher */
    public $publisher;

    /** @var AICP_REST */
    public $rest;

    /** @var AICP_Admin */
    public $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings  = new AICP_Settings();
        $this->publisher = new AICP_Publisher( $this->settings );
        $this->rest      = new AICP_REST( $this->settings, $this->publisher );
        $this->admin     = new AICP_Admin( $this->settings, $this->publisher );

        register_activation_hook( AICP_PLUGIN_FILE, array( $this, 'on_activate' ) );
        register_deactivation_hook( AICP_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

        add_action( 'init', array( $this->publisher, 'maybe_schedule' ) );
    }

    public function on_activate() {
        $this->settings->maybe_bootstrap();
        $this->publisher->ensure_categories();
        $this->publisher->schedule_cron();
    }

    public function on_deactivate() {
        $this->publisher->unschedule_cron();
    }
}

AICP_Plugin::instance();
