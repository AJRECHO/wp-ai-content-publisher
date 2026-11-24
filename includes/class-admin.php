<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * 后台管理界面：菜单、设置页、各功能标签页渲染、表单处理。
 */
class AICP_Admin {

    /** @var AICP_Settings */
    private $settings;
    /** @var AICP_Publisher */
    private $publisher;
    private $page_slug = 'wp-ai-content-publisher';

    public function __construct( $settings, $publisher ) {
        $this->settings  = $settings;
        $this->publisher = $publisher;

        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'admin_post_aicp_save', array( $this, 'handle_save' ) );
        add_action( 'admin_post_aicp_action', array( $this, 'handle_action' ) );
        add_action( 'wp_ajax_aicp_test_api', array( $this, 'ajax_test_api' ) );
        add_action( 'wp_ajax_aicp_preview', array( $this, 'ajax_preview' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'WP AI 内容发布', 'wp-ai-content-publisher' ),
            __( 'WP AI 内容发布', 'wp-ai-content-publisher' ),
            'manage_options',
            $this->page_slug,
            array( $this, 'render_page' ),
            'dashicons-controls-play',
            30
        );
        add_submenu_page(
            $this->page_slug,
            __( '设置', 'wp-ai-content-publisher' ),
            __( '设置', 'wp-ai-content-publisher' ),
            'manage_options',
            $this->page_slug,
            array( $this, 'render_page' )
        );
    }

    public function enqueue( $hook ) {
        if ( strpos( $hook, $this->page_slug ) === false ) { return; }
        wp_enqueue_style( 'aicp-admin', AICP_PLUGIN_URL . 'assets/admin.css', array(), AICP_VERSION );
        wp_enqueue_script( 'aicp-admin', AICP_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), AICP_VERSION, true );
        wp_localize_script( 'aicp-admin', 'AICP', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'aicp/v1/' ),
            'restKey' => (string) $this->settings->get( 'rest_key', '' ),
            'nonce'   => wp_create_nonce( 'aicp_ajax' ),
            'i18n'    => array(
                'testing' => __( '测试中…', 'wp-ai-content-publisher' ),
                'ok'      => __( '✓ 连接成功', 'wp-ai-content-publisher' ),
                'fail'    => __( '✗ 连接失败', 'wp-ai-content-publisher' ),
            ),
        ) );
    }

    /* ------------------------------ 表单处理 ------------------------------ */

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '无权限' ); }
        check_admin_referer( 'aicp_save' );
        $tab = isset( $_POST['aicp_tab'] ) ? sanitize_key( $_POST['aicp_tab'] ) : 'ai';
        $this->settings->save_from_post( $_POST );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $tab . '&saved=1' ) );
        exit;
    }

    public function handle_action() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '无权限' ); }
        check_admin_referer( 'aicp_action' );
        $action = isset( $_POST['aicp_do'] ) ? sanitize_key( $_POST['aicp_do'] ) : '';
        $msg = '';

        if ( $action === 'publish' ) {
            $hint = isset( $_POST['hint'] ) ? sanitize_text_field( wp_unslash( $_POST['hint'] ) ) : '';
            $r = $this->publisher->maybe_publish( true, $hint );
            $msg = ! empty( $r['ok'] ) ? '已发布：《' . $r['title'] . '》' : '未发布：' . ( isset( $r['message'] ) ? $r['message'] : $r['reason'] );
        } elseif ( $action === 'restore_prompt' ) {
            $cur = $this->settings->all();
            $cur['prompt'] = AICP_Settings::default_prompt();
            update_option( AICP_Settings::OPT, $cur, false );
            $msg = '已恢复默认提示词';
        } elseif ( $action === 'restore_blacklist' ) {
            $cur = $this->settings->all();
            $cur['blacklist'] = AICP_Settings::default_blacklist();
            update_option( AICP_Settings::OPT, $cur, false );
            $msg = '已恢复默认过滤词表';
        } elseif ( $action === 'gen_key' ) {
            $cur = $this->settings->all();
            $cur['rest_key'] = $this->settings->gen_key();
            update_option( AICP_Settings::OPT, $cur, false );
            $msg = '已生成新的 REST 密钥';
        } elseif ( $action === 'clear_log' ) {
            update_option( AICP_Settings::OPT_LOG, array() );
            update_option( AICP_Settings::OPT_ROT, 0 );
            $msg = '已清空发布日志';
        } elseif ( $action === 'clear_used' ) {
            update_option( AICP_Settings::OPT_USED, array() );
            $msg = '已清空去重记录';
        } elseif ( $action === 'reset_all' ) {
            update_option( AICP_Settings::OPT_LOG, array() );
            update_option( AICP_Settings::OPT_ROT, 0 );
            update_option( AICP_Settings::OPT_USED, array() );
            delete_option( AICP_Settings::OPT_PUBTIME );
            $msg = '已重置所有运行数据';
        } elseif ( $action === 'import' ) {
            $json = isset( $_POST['import_json'] ) ? wp_unslash( $_POST['import_json'] ) : '';
            $r = $this->settings->import_json( $json );
            $msg = is_wp_error( $r ) ? '导入失败：' . $r->get_error_message() : '配置导入成功';
        }

        $tab = isset( $_POST['aicp_tab'] ) ? sanitize_key( $_POST['aicp_tab'] ) : 'tools';
        wp_safe_redirect( add_query_arg(
            array( 'page' => $this->page_slug, 'tab' => $tab, 'notice' => rawurlencode( $msg ) ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function ajax_test_api() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '无权限' ) );
        }
        check_ajax_referer( 'aicp_ajax' );
        $r = $this->publisher->test_api();
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( '连接成功，AI 接口可用', 'wp-ai-content-publisher' ) ) );
    }

    public function ajax_preview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '无权限' ) );
        }
        check_ajax_referer( 'aicp_ajax' );
        $cats = array_values( (array) $this->settings->get( 'categories', array() ) );
        $cat = isset( $_POST['cat'] ) ? sanitize_text_field( wp_unslash( $_POST['cat'] ) ) : ( isset( $cats[0] ) ? $cats[0] : '' );
        $feeds_map = (array) $this->settings->get( 'feeds', array() );
        $urls = array();
        foreach ( explode( "\n", (string) ( isset( $feeds_map[ $cat ] ) ? $feeds_map[ $cat ] : '' ) ) as $line ) {
            $u = trim( $line );
            if ( strpos( $u, 'http' ) === 0 ) { $urls[] = $u; }
        }
        $used = (array) get_option( AICP_Settings::OPT_USED, array() );
        $items = array();
        foreach ( $urls as $url ) {
            $feed = fetch_feed( $url );
            if ( is_wp_error( $feed ) ) { continue; }
            foreach ( $feed->get_items( 0, min( $feed->get_item_quantity( 10 ), 10 ) ) as $it ) {
                $link = $it->get_permalink();
                if ( ! $link || in_array( $link, $used, true ) ) { continue; }
                $items[] = array( 'title' => $it->get_title(), 'date' => $it->get_date( 'Y-m-d H:i' ), 'url' => $link );
            }
        }
        wp_send_json_success( array( 'cat' => $cat, 'items' => array_slice( $items, 0, 20 ) ) );
    }

    /* ------------------------------ 页面渲染 ------------------------------ */

    public function render_page() {
        $tabs = array(
            'dashboard' => __( '概览', 'wp-ai-content-publisher' ),
            'ai'        => __( 'AI 设置', 'wp-ai-content-publisher' ),
            'rules'     => __( '发布规则', 'wp-ai-content-publisher' ),
            'feeds'     => __( '内容源', 'wp-ai-content-publisher' ),
            'images'    => __( '图片与封面', 'wp-ai-content-publisher' ),
            'filters'   => __( '过滤规则', 'wp-ai-content-publisher' ),
            'tools'     => __( '工具与日志', 'wp-ai-content-publisher' ),
        );
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'dashboard'; }

        echo '<div class="wrap aicp-wrap">';
        echo '<h1 class="aicp-title"><span class="dashicons dashicons-controls-play"></span> '
            . esc_html__( 'WP AI 内容发布', 'wp-ai-content-publisher' )
            . ' <small>v' . esc_html( AICP_VERSION ) . '</small></h1>';

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '设置已保存。', 'wp-ai-content-publisher' ) . '</p></div>';
        }
        if ( ! empty( $_GET['notice'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( $_GET['notice'] ) ) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper aicp-tabs">';
        foreach ( $tabs as $slug => $label ) {
            $url = admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $slug );
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab ' . ( $slug === $tab ? 'nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav><div class="aicp-panel">';

        switch ( $tab ) {
            case 'dashboard': $this->tab_dashboard(); break;
            case 'ai':        $this->tab_ai(); break;
            case 'rules':     $this->tab_rules(); break;
            case 'feeds':     $this->tab_feeds(); break;
            case 'images':    $this->tab_images(); break;
            case 'filters':   $this->tab_filters(); break;
            case 'tools':     $this->tab_tools(); break;
        }
        echo '</div></div>';
    }

    /** 打开一个设置表单 */
    private function form_open( $tab, $touched = array() ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="aicp_save">';
        echo '<input type="hidden" name="aicp_tab" value="' . esc_attr( $tab ) . '">';
        if ( ! empty( $touched ) ) {
            echo '<input type="hidden" name="aicp_touched" value="' . esc_attr( implode( ',', $touched ) ) . '">';
        }
        wp_nonce_field( 'aicp_save' );
    }

    private function form_close( $label = null ) {
        submit_button( $label ? $label : __( '保存设置', 'wp-ai-content-publisher' ), 'primary', 'submit', false );
        echo '</form>';
    }

    private function field_text( $label, $name, $value, $desc = '', $type = 'text', $extra = '' ) {
        echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" ' . $extra . '>';
        if ( $desc ) { echo '<p class="description">' . wp_kses_post( $desc ) . '</p>'; }
        echo '</td></tr>';
    }

    private function field_number( $label, $name, $value, $min, $max, $desc = '' ) {
        echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<input type="number" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="' . $min . '" max="' . $max . '" class="small-text">';
        if ( $desc ) { echo '<p class="description">' . wp_kses_post( $desc ) . '</p>'; }
        echo '</td></tr>';
    }

    private function field_select( $label, $name, $value, $options, $desc = '' ) {
        echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
        foreach ( $options as $k => $v ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select>';
        if ( $desc ) { echo '<p class="description">' . wp_kses_post( $desc ) . '</p>'; }
        echo '</td></tr>';
    }

    private function field_textarea( $label, $name, $value, $desc = '', $rows = 8 ) {
        echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="' . $rows . '" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
        if ( $desc ) { echo '<p class="description">' . wp_kses_post( $desc ) . '</p>'; }
        echo '</td></tr>';
    }

    private function field_checkbox( $label, $name, $checked, $desc = '' ) {
        echo '<tr><th>' . esc_html( $label ) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, 1, false ) . '> ';
        if ( $desc ) { echo esc_html( $desc ); } else { echo esc_html( $label ); }
        echo '</label></td></tr>';
    }

    /* ------------------------------ 各标签页 ------------------------------ */

    private function tab_dashboard() {
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        $cnt = $this->publisher->daily_count();
        $limit = (int) $this->settings->get( 'daily_limit', 1 );
        $mode = (string) $this->settings->get( 'publish_mode', 'random' );
        $mode_label = array( 'manual' => '手动', 'random' => '每日随机时间', 'fixed' => '每日固定时间' );
        $key_ok = $this->settings->has_api_key();

        echo '<div class="aicp-cards">';
        $cards = array(
            array( 'AI 接口', $key_ok ? '已配置 ✓' : '未配置 ✗', $key_ok ? 'good' : 'bad', 'dashicons-admin-network' ),
            array( '发布模式', isset( $mode_label[ $mode ] ) ? $mode_label[ $mode ] : $mode, '', 'dashicons-clock' ),
            array( '今日已发', $cnt . ' / ' . ( $limit > 0 ? $limit : '∞' ), $cnt >= $limit && $limit > 0 ? 'warn' : 'good', 'dashicons-admin-post' ),
            array( '累计发布', (string) count( $log ), '', 'dashicons-chart-bar' ),
        );
        foreach ( $cards as $c ) {
            echo '<div class="aicp-card"><div class="aicp-card-ico dashicons ' . esc_attr( $c[3] ) . '"></div>';
            echo '<div class="aicp-card-label">' . esc_html( $c[0] ) . '</div>';
            echo '<div class="aicp-card-val ' . esc_attr( $c[2] ) . '">' . esc_html( $c[1] ) . '</div></div>';
        }
        echo '</div>';

        if ( ! $key_ok ) {
            echo '<div class="notice notice-warning inline"><p><strong>先配置 AI 接口：</strong>前往 <a href="' . esc_url( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=ai' ) ) . '">AI 设置</a> 填入 API Key，否则不会自动发布。</p></div>';
        }

        echo '<div class="aicp-two-col">';
        // 快捷发布
        echo '<div class="aicp-box"><h2>快捷操作</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aicp-inline-form">';
        echo '<input type="hidden" name="action" value="aicp_action"><input type="hidden" name="aicp_do" value="publish"><input type="hidden" name="aicp_tab" value="dashboard">';
        wp_nonce_field( 'aicp_action' );
        echo '<p><input type="text" name="hint" class="regular-text" placeholder="可选：标题关键词，精准指定某篇"></p>';
        submit_button( '立即发布一篇', 'primary', 'submit', false );
        echo '</form>';
        echo '<p class="aicp-hint">「立即发布」会忽略时间/配额限制，强制发一篇用于测试。</p></div>';

        // 最近发布
        echo '<div class="aicp-box"><h2>最近发布</h2>';
        if ( empty( $log ) ) {
            echo '<p class="aicp-hint">暂无发布记录。</p>';
        } else {
            echo '<ul class="aicp-recent">';
            foreach ( array_slice( array_reverse( $log ), 0, 6 ) as $it ) {
                echo '<li><span class="aicp-recent-cat">' . esc_html( isset( $it['cat'] ) ? $it['cat'] : '-' ) . '</span>';
                if ( ! empty( $it['post_id'] ) ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $it['post_id'] ) ) . '">' . esc_html( $it['title'] ) . '</a>';
                } else {
                    echo esc_html( isset( $it['title'] ) ? $it['title'] : '' );
                }
                echo '<time>' . esc_html( date_i18n( 'm-d H:i', $it['time'] ) ) . '</time></li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
    }

    private function tab_ai() {
        $s = $this->settings->all();
        $this->form_open( 'ai' );
        echo '<h2>AI 接口</h2><p class="aicp-hint">支持 DeepSeek 及任意 OpenAI 兼容的 chat/completions 接口。</p>';
        echo '<table class="form-table">';
        $this->field_text( 'API Key', 'api_key', $s['api_key'], '密钥仅保存在本机数据库，不会外发。留空表示不修改。', 'password', 'autocomplete="off"' );
        $this->field_text( '接口地址', 'api_base', $s['api_base'], '例：DeepSeek 为 <code>https://api.deepseek.com/v1/chat/completions</code>' );
        $this->field_text( '模型名称', 'model', $s['model'], '例：<code>deepseek-chat</code>、<code>gpt-4o-mini</code>' );
        $this->field_number( '温度 temperature', 'temperature', $s['temperature'], 0, 2, '越低越稳定，越高越有创意，建议 0.7-0.9。' );
        $this->field_number( '最大 tokens', 'max_tokens', $s['max_tokens'], 100, 32000, '单次改写输出上限。' );
        echo '</table>';
        echo '<p><button type="button" class="button" id="aicp-test-btn">测试连接</button> <span id="aicp-test-result"></span></p>';
        $this->form_close();

        $this->form_open( 'ai' );
        echo '<h2>改写提示词</h2>';
        echo '<p class="aicp-hint">用 <code>{title}</code> 和 <code>{content}</code> 作为原文占位符。要求模型只输出 JSON。</p>';
        echo '<table class="form-table">';
        $this->field_textarea( 'Prompt 模板', 'prompt', $s['prompt'], '', 16 );
        echo '</table>';
        echo '<button type="submit" class="button">' . esc_html__( '保存提示词', 'wp-ai-content-publisher' ) . '</button> ';
        echo '<button type="button" class="button" id="aicp-restore-prompt">恢复默认提示词</button>';
        echo '</form>';
    }

    private function tab_rules() {
        $s = $this->settings->all();
        $this->form_open( 'rules', array( 'offpeak' ) );
        echo '<h2>发布节奏</h2><table class="form-table">';
        $this->field_select( '发布模式', 'publish_mode', $s['publish_mode'], array(
            'manual' => '手动（关闭自动发布）',
            'random' => '每天随机时间（推荐）',
            'fixed'  => '每天固定时间',
        ), '随机模式每天在指定区间内挑一个时间点，避免发布过于规律。' );
        $this->field_text( '随机起始时间', 'random_start', $s['random_start'], '格式 HH:MM', 'text', 'placeholder="06:00"' );
        $this->field_text( '随机结束时间', 'random_end', $s['random_end'], '格式 HH:MM', 'text', 'placeholder="23:55"' );
        $this->field_text( '固定发布时间', 'fixed_time', $s['fixed_time'], '仅"固定时间"模式生效，格式 HH:MM', 'text', 'placeholder="20:00"' );
        $this->field_checkbox( '谷时省钱', 'offpeak', $s['offpeak'], '只在高峰时段之外发布（DeepSeek 5 折），随机点若落在高峰期会自动顺延。' );
        $this->field_textarea( '高峰时段', 'peak_ranges', $s['peak_ranges'], '每行一段，格式 HH:MM-HH:MM；周末默认不限制。', 4 );
        $this->field_number( '每日上限', 'daily_limit', $s['daily_limit'], 0, 50, '0 表示不限制。手动删除文章会自动释放配额。' );
        $this->field_number( '时效天数', 'max_age', $s['max_age'], 0, 365, '只搬运最近 N 天发布的文章，0 = 不限制。' );
        $this->field_select( '发布后状态', 'post_status', $s['post_status'], array(
            'publish' => '直接发布',
            'draft'   => '存为草稿（先审核）',
        ) );
        $this->field_text( '时区', 'timezone', $s['timezone'], 'PHP 时区标识，默认 <code>Asia/Shanghai</code>。' );
        echo '</table>';
        $this->form_close();
    }

    private function tab_feeds() {
        $s = $this->settings->all();
        $cats = (array) $s['categories'];
        $feeds = (array) $s['feeds'];

        $this->form_open( 'feeds' );
        echo '<h2>参与轮换的分类</h2>';
        echo '<p class="aicp-hint">每篇文章按此顺序轮换分类。多个分类用逗号分隔，插件会自动创建不存在的分类。</p>';
        echo '<table class="form-table">';
        $this->field_text( '分类列表', 'categories', implode( ',', $cats ), '例：<code>科技,数码,开发</code>（用英文逗号分隔）' );
        echo '</table>';

        echo '<h2>RSS 源</h2>';
        echo '<p class="aicp-hint">每个分类一行一个 feed 地址，插件会随机打乱后抓取。留空的分类会被跳过。</p>';
        echo '<table class="form-table">';
        $all_cats = array_unique( array_merge( $cats, array_keys( $feeds ) ) );
        foreach ( $all_cats as $cat ) {
            if ( $cat === '' ) { continue; }
            $this->field_textarea( esc_html( $cat ), 'feeds[' . $cat . ']', isset( $feeds[ $cat ] ) ? $feeds[ $cat ] : '', '', 5 );
        }
        echo '</table>';
        $this->form_close();

        echo '<p><button type="button" class="button" id="aicp-preview-btn">预览候选文章</button></p>';
        echo '<div id="aicp-preview-box" style="display:none"><h3>候选列表</h3><div id="aicp-preview-list"></div></div>';
    }

    private function tab_images() {
        $s = $this->settings->all();
        $this->form_open( 'images', array( 'require_image' ) );
        echo '<h2>正文配图存储</h2><table class="form-table">';
        $this->field_select( '存储方式', 'image_mode', $s['image_mode'], array(
            'media'  => 'WordPress 媒体库（推荐，零依赖）',
            'custom' => '自定义目录（如挂载的对象存储 / 图床）',
        ) );
        $this->field_text( '自定义目录绝对路径', 'image_dir', $s['image_dir'], '仅"自定义目录"模式生效，如 <code>/var/www/cdn/images</code>' );
        $this->field_text( '自定义目录公开 URL', 'image_url_base', $s['image_url_base'], '与上面目录对应的外链前缀，如 <code>https://img.example.com/images</code>' );
        echo '</table>';

        echo '<h2>封面图（特色图片）</h2><table class="form-table">';
        $this->field_select( '封面来源', 'cover_mode', $s['cover_mode'], array(
            'source' => '用原文配图（自动存为特色图）',
            'custom' => '自定义图库（从下面 URL 随机选）',
            'none'   => '不设封面',
        ) );
        $this->field_textarea( '自定义封面 URL', 'cover_urls', $s['cover_urls'], '每行一个图片直链，发布时随机选一张。适合统一风格的插画封面。', 6 );
        $this->field_checkbox( '必须有封面', 'require_image', $s['require_image'], '勾选后，若取不到封面则跳过该文章（保证列表页美观）。' );
        $this->field_number( '正文最短字数', 'min_body_len', $s['min_body_len'], 0, 5000, '原文正文低于此字数跳过，避免搬运摘要式快讯。' );
        echo '</table>';
        $this->form_close();
    }

    private function tab_filters() {
        $s = $this->settings->all();
        $this->form_open( 'filters' );
        echo '<h2>标题过滤词表</h2>';
        echo '<p class="aicp-hint">标题命中任意关键词的文章会被直接跳过。每行一个词，支持中英文。可放政治、黄赌毒、新闻快讯、广告等你想排除的词。</p>';
        echo '<table class="form-table">';
        $this->field_textarea( '黑名单关键词', 'blacklist', $s['blacklist'], '', 18 );
        echo '</table>';
        echo '<button type="submit" class="button button-primary">' . esc_html__( '保存过滤词表', 'wp-ai-content-publisher' ) . '</button> ';
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="aicp_action"><input type="hidden" name="aicp_do" value="restore_blacklist"><input type="hidden" name="aicp_tab" value="filters">';
        wp_nonce_field( 'aicp_action' );
        echo '<button type="submit" class="button">恢复默认词表</button></form>';
    }

    private function tab_tools() {
        $s = $this->settings->all();
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );

        // 配置导出
        echo '<div class="aicp-box"><h2>配置导入 / 导出</h2>';
        echo '<p class="aicp-hint">导出的配置<strong>不包含 API Key 和 REST 密钥</strong>，可安全分享给他人。</p>';
        echo '<p><textarea id="aicp-export" class="large-text code" rows="10" readonly>' . esc_textarea( $this->settings->export_json() ) . '</textarea></p>';
        echo '<p><button type="button" class="button" id="aicp-copy-export">复制配置 JSON</button></p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="aicp_action"><input type="hidden" name="aicp_do" value="import"><input type="hidden" name="aicp_tab" value="tools">';
        wp_nonce_field( 'aicp_action' );
        echo '<p><textarea name="import_json" class="large-text code" rows="5" placeholder="粘贴配置 JSON 后导入"></textarea></p>';
        submit_button( '导入配置', 'secondary', 'submit', false );
        echo '</form></div>';

        // REST 密钥
        echo '<div class="aicp-box"><h2>REST API 密钥</h2>';
        echo '<p>外部定时任务可用密钥调用接口。状态：<code>' . esc_html( AICP_Settings::mask_key( $s['rest_key'] ) ) . '</code></p>';
        echo '<p class="aicp-hint">接口地址：<code>' . esc_html( rest_url( 'aicp/v1/' ) ) . '</code>，请求头 <code>X-AICP-Key: 你的密钥</code>。</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="aicp_action"><input type="hidden" name="aicp_do" value="gen_key"><input type="hidden" name="aicp_tab" value="tools">';
        wp_nonce_field( 'aicp_action' );
        submit_button( '重新生成密钥', 'secondary', 'submit', false );
        echo '</form></div>';

        // 数据清理
        echo '<div class="aicp-box"><h2>运行数据</h2>';
        echo '<p>发布记录 <strong>' . count( $log ) . '</strong> 条；去重记录 <strong>' . count( (array) get_option( AICP_Settings::OPT_USED, array() ) ) . '</strong> 条。</p>';
        $actions = array(
            'clear_log'  => '清空发布日志',
            'clear_used' => '清空去重记录（可重复发布旧文）',
            'reset_all'  => '重置全部运行数据',
        );
        foreach ( $actions as $do => $label ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-right:8px">';
            echo '<input type="hidden" name="action" value="aicp_action"><input type="hidden" name="aicp_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="aicp_tab" value="tools">';
            wp_nonce_field( 'aicp_action' );
            submit_button( $label, 'secondary', 'submit', false );
            echo '</form>';
        }
        echo '</div>';

        // 完整日志
        echo '<div class="aicp-box"><h2>发布日志</h2>';
        if ( empty( $log ) ) {
            echo '<p class="aicp-hint">暂无记录。</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>时间</th><th>分类</th><th>标题</th><th>来源</th><th>配图</th><th>操作</th></tr></thead><tbody>';
            foreach ( array_reverse( $log ) as $it ) {
                echo '<tr>';
                echo '<td>' . esc_html( date_i18n( 'Y-m-d H:i', isset( $it['time'] ) ? $it['time'] : time() ) ) . '</td>';
                echo '<td>' . esc_html( isset( $it['cat'] ) ? $it['cat'] : '-' ) . '</td>';
                echo '<td>' . esc_html( isset( $it['title'] ) ? $it['title'] : '-' ) . '</td>';
                echo '<td>' . ( ! empty( $it['source_url'] ) ? '<a href="' . esc_url( $it['source_url'] ) . '" target="_blank">原文</a>' : '-' ) . '</td>';
                echo '<td>' . ( ! empty( $it['body_images'] ) ? (int) $it['body_images'] . ' 张' : '-' ) . '</td>';
                echo '<td>';
                if ( ! empty( $it['post_id'] ) ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $it['post_id'] ) ) . '">编辑</a> ';
                    echo '<a href="' . esc_url( get_permalink( $it['post_id'] ) ) . '" target="_blank">查看</a> ';
                    echo '<a href="#" class="aicp-del" data-id="' . (int) $it['post_id'] . '" style="color:#b32d2e">删除</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
