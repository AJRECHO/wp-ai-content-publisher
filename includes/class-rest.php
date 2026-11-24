<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST API：把插件能力开放为接口，供后台 AJAX 与外部定时任务调用。
 * 鉴权二选一：登录管理员（nonce）或 X-AICP-Key 密钥。
 */
class AICP_REST {

    const NS = 'aicp/v1';

    /** @var AICP_Settings */
    private $settings;
    /** @var AICP_Publisher */
    private $publisher;

    public function __construct( $settings, $publisher ) {
        $this->settings  = $settings;
        $this->publisher = $publisher;
        add_action( 'rest_api_init', array( $this, 'register' ) );
    }

    public function register() {
        $routes = array(
            '/status'       => array( 'GET',  'status' ),
            '/publish'      => array( 'POST', 'publish' ),
            '/test-api'     => array( 'POST', 'test_api' ),
            '/preview'      => array( 'GET',  'preview' ),
            '/reset'        => array( 'POST', 'reset' ),
            '/log'          => array( 'GET',  'get_log' ),
            '/delete-post'  => array( 'POST', 'delete_post' ),
        );
        foreach ( $routes as $path => $def ) {
            register_rest_route( self::NS, $path, array(
                array(
                    'methods'             => $def[0],
                    'callback'            => array( $this, $def[1] ),
                    'permission_callback' => array( $this, 'permission' ),
                    'args'                => $this->args_for( $path ),
                ),
            ) );
        }
    }

    private function args_for( $path ) {
        switch ( $path ) {
            case '/publish':
                return array(
                    'force' => array( 'type' => 'boolean', 'default' => false ),
                    'hint'  => array( 'type' => 'string', 'default' => '' ),
                );
            case '/test-api':
                return array(
                    'api_key'  => array( 'type' => 'string', 'default' => '' ),
                    'api_base' => array( 'type' => 'string', 'default' => '' ),
                    'model'    => array( 'type' => 'string', 'default' => '' ),
                );
            case '/reset':
                return array( 'what' => array( 'type' => 'string', 'default' => 'log' ) );
            case '/delete-post':
                return array( 'post_id' => array( 'type' => 'integer', 'required' => true ) );
            default:
                return array();
        }
    }

    /** 鉴权：管理员，或请求头带正确密钥 */
    public function permission( $req ) {
        if ( current_user_can( 'manage_options' ) ) { return true; }
        $key = $req->get_header( 'X-AICP-Key' );
        $expected = (string) $this->settings->get( 'rest_key', '' );
        return ( $expected !== '' && hash_equals( $expected, (string) $key ) );
    }

    public function status( $req ) {
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        return array(
            'version'       => AICP_VERSION,
            'mode'          => $this->settings->get( 'publish_mode' ),
            'api_configured'=> $this->settings->has_api_key(),
            'daily_count'   => $this->publisher->daily_count(),
            'daily_limit'   => (int) $this->settings->get( 'daily_limit', 1 ),
            'rotation'      => (int) get_option( AICP_Settings::OPT_ROT, 0 ),
            'pub_time'      => get_option( AICP_Settings::OPT_PUBTIME, array() ),
            'categories'    => $this->settings->get( 'categories', array() ),
            'total_published'=> count( $log ),
        );
    }

    public function publish( $req ) {
        return $this->publisher->maybe_publish(
            (bool) $req->get_param( 'force' ),
            (string) $req->get_param( 'hint' )
        );
    }

    public function test_api( $req ) {
        $override = array();
        foreach ( array( 'api_key','api_base','model' ) as $k ) {
            $v = (string) $req->get_param( $k );
            if ( $v !== '' ) { $override[ $k ] = $v; }
        }
        $r = $this->publisher->test_api( $override );
        if ( is_wp_error( $r ) ) {
            return new WP_Error( 'test_failed', $r->get_error_message(), array( 'status' => 400 ) );
        }
        return array( 'ok' => true, 'message' => '连接成功，AI 接口可用' );
    }

    /** 预览：列出当前分类下将进入候选池的文章标题（不改写不发布） */
    public function preview( $req ) {
        $cat = (string) $req->get_param( 'cat' );
        $feeds_map = (array) $this->settings->get( 'feeds', array() );
        if ( $cat === '' ) {
            $cats = array_values( (array) $this->settings->get( 'categories', array() ) );
            $cat = isset( $cats[0] ) ? $cats[0] : '';
        }
        $urls = array();
        foreach ( explode( "\n", (string) ( isset( $feeds_map[ $cat ] ) ? $feeds_map[ $cat ] : '' ) ) as $line ) {
            $u = trim( $line );
            if ( strpos( $u, 'http' ) === 0 ) { $urls[] = $u; }
        }
        if ( empty( $urls ) ) { return array( 'cat' => $cat, 'items' => array() ); }

        $used = (array) get_option( AICP_Settings::OPT_USED, array() );
        $items = array();
        foreach ( $urls as $url ) {
            $feed = fetch_feed( $url );
            if ( is_wp_error( $feed ) ) { continue; }
            foreach ( $feed->get_items( 0, min( $feed->get_item_quantity( 10 ), 10 ) ) as $it ) {
                $link = $it->get_permalink();
                if ( ! $link || in_array( $link, $used, true ) ) { continue; }
                $items[] = array(
                    'title' => $it->get_title(),
                    'url'   => $link,
                    'date'  => $it->get_date( 'Y-m-d H:i' ),
                );
            }
        }
        return array( 'cat' => $cat, 'count' => count( $items ), 'items' => array_slice( $items, 0, 30 ) );
    }

    public function get_log( $req ) {
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        return array( 'total' => count( $log ), 'items' => array_values( array_reverse( $log ) ) );
    }

    public function reset( $req ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', '仅管理员可重置', array( 'status' => 403 ) );
        }
        $what = (string) $req->get_param( 'what' );
        if ( $what === 'all' ) {
            update_option( AICP_Settings::OPT_LOG, array() );
            update_option( AICP_Settings::OPT_ROT, 0 );
            update_option( AICP_Settings::OPT_USED, array() );
            delete_option( AICP_Settings::OPT_PUBTIME ); delete_option( AICP_Settings::OPT_COVER_BAG );
            return array( 'ok' => true, 'reset' => 'log+rotation+used+pubtime' );
        }
        update_option( AICP_Settings::OPT_LOG, array() );
        update_option( AICP_Settings::OPT_ROT, 0 );
        return array( 'ok' => true, 'reset' => 'log+rotation' );
    }

    public function delete_post( $req ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', '仅管理员可删除', array( 'status' => 403 ) );
        }
        $id = (int) $req->get_param( 'post_id' );
        if ( ! $id || get_post_type( $id ) !== 'post' ) {
            return new WP_Error( 'invalid', '无效文章 ID', array( 'status' => 400 ) );
        }
        $thumb = get_post_thumbnail_id( $id );
        wp_delete_post( $id, true );
        if ( $thumb ) { wp_delete_post( $thumb, true ); }

        // 从日志中移除，释放当日配额
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        $log = array_values( array_filter( $log, function ( $item ) use ( $id ) {
            return ! isset( $item['post_id'] ) || (int) $item['post_id'] !== $id;
        } ) );
        update_option( AICP_Settings::OPT_LOG, $log );

        return array( 'ok' => true, 'deleted' => $id );
    }
}
