<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * 封面图库自动补货：从图片来源按标签搜索抓取手绘风景类横屏插画，
 * 经质量闸门筛选后转存自有图床，作为候选待审核，通过后并入封面图池。
 *
 * 设计要点（见 README「封面图库自动补货」）：
 * - 只收 aiType=0 手绘作品；order=date（热门榜多为 AI 生成内容）
 * - 搜索结果零成本粗筛，详情接口精筛收藏数/水印标签
 * - 所有下载图片转存本地，源站失效不影响已发布文章
 * - pixiv ID 去重，抓过的永不重复抓
 */
class AICP_Gallery {

    const OPT_CANDIDATES = 'aicp_gallery_candidates'; // 候选池 option
    const OPT_FETCHED    = 'aicp_gallery_fetched';    // 已抓 ID 列表
    const OPT_LAST       = 'aicp_gallery_last';       // 上次补货时间戳
    const OPT_LOG        = 'aicp_gallery_log';        // 补货日志

    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** @var AICP_Settings */
    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    /** 默认补货配置 */
    public static function default_options() {
        return array(
            'enabled'        => 0,    // 自动补货开关
            'keywords'       => "風景\n背景\n青空\n桜\n雪山\n草原\n星空", // 搜索标签（每行一个）
            'min_bookmarks'  => 50,   // 详情接口确认的最低收藏数（最新图收藏普遍偏低，阈值不宜过高）
            'min_width'      => 900,  // 最小宽度（保证清晰度；过严会导致横图大量淘汰）
            'batch'          => 8,    // 每次补货抓取候选数上限
            'threshold'      => 5,    // 图池剩余低于此值时触发
            'cooldown_hours' => 24,   // 两次补货最小间隔（小时）
            'review'         => 1,    // 1=候选需人工审核；0=自动入池
            'store_dir'      => '',   // 图库存储绝对路径（空=用 image_dir 同款目录）
            'store_url'      => '',   // 对应公开 URL 前缀（空=用 image_url_base）
        );
    }

    /** 读取补货配置（与默认合并） */
    public function options() {
        $saved = get_option( 'aicp_gallery_options', array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        return array_merge( self::default_options(), $saved );
    }

    public function save_options( $post ) {
        $cur = $this->options();
        $next = $cur;
        foreach ( array( 'keywords' ) as $k ) {
            if ( isset( $post[ $k ] ) ) { $next[ $k ] = sanitize_textarea_field( wp_unslash( $post[ $k ] ) ); }
        }
        foreach ( array( 'store_dir', 'store_url' ) as $k ) {
            if ( isset( $post[ $k ] ) ) { $next[ $k ] = sanitize_text_field( wp_unslash( $post[ $k ] ) ); }
        }
        foreach ( array( 'min_bookmarks', 'min_width', 'batch', 'threshold', 'cooldown_hours' ) as $k ) {
            if ( isset( $post[ $k ] ) ) { $next[ $k ] = max( 0, min( 100000, (int) $post[ $k ] ) ); }
        }
        $next['enabled'] = isset( $post['gal_enabled'] ) ? 1 : 0;
        $next['review']  = isset( $post['gal_review'] ) ? 1 : 0;
        update_option( 'aicp_gallery_options', $next, false );
        return $next;
    }

    /* ------------------------- HTTP 请求 ------------------------- */

    /** GET JSON，返回 array 或 null */
    private function get_json( $url, $timeout = 20 ) {
        $resp = wp_remote_get( $url, array(
            'timeout' => $timeout,
            'headers' => array(
                'User-Agent' => self::UA,
                'Referer'    => 'https://www.pixiv.net/',
                'Accept'     => 'application/json',
            ),
        ) );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) { return null; }
        $j = json_decode( wp_remote_retrieve_body( $resp ), true );
        return is_array( $j ) ? $j : null;
    }

    /** 下载图片二进制并校验，返回 array(bytes,mime,ext) 或 WP_Error */
    private function download_image( $src ) {
        $resp = wp_remote_get( $src, array(
            'timeout' => 25,
            'headers' => array(
                'User-Agent' => self::UA,
                'Referer'    => 'https://www.pixiv.net/',
            ),
        ) );
        if ( is_wp_error( $resp ) ) { return $resp; }
        if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return new WP_Error( 'http', 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
        }
        $body = wp_remote_retrieve_body( $resp );
        if ( strlen( $body ) < 2048 || strlen( $body ) > 20 * 1024 * 1024 ) {
            return new WP_Error( 'size', '图片大小异常' );
        }
        $tmp = tempnam( sys_get_temp_dir(), 'aicpgal' );
        if ( ! $tmp ) { return new WP_Error( 'tmp', '无法创建临时文件' ); }
        file_put_contents( $tmp, $body );
        $info = @getimagesize( $tmp );
        @unlink( $tmp );
        if ( ! $info || empty( $info['mime'] ) ) { return new WP_Error( 'type', '不是有效图片' ); }
        $map = array(
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/avif' => 'avif',
        );
        $ext = isset( $map[ $info['mime'] ] ) ? $map[ $info['mime'] ] : 'img';
        return array( 'bytes' => $body, 'mime' => $info['mime'], 'ext' => $ext );
    }

    /* ------------------------- 元数据筛选 ------------------------- */

    /** 水印/练习/杂物特征词（命中即淘汰，标题与标签都查） */
    private function bad_words() {
        return array(
            '落書き', 'らくがき', '塗り練習', '練習', '练习', '描こう', '模写', '3D', 'blender', '下書き', '下描き', '速写',
            '日付', 'date', 'watermark', 'ウォーターマーク', 'sample', '見本', '模版',
            '人物', 'キャラ', '女の子', '男の子', '戦士', '战士', '少女', '少年', '女子', '男子', '骑士', '騎士', '魔女', '妹', '兄', '_portfolio', 'commission', '依頼',
            'アニメ', 'anime', 'fate/', 'stay night', '博麗', '東方', '原神', '崩壊', '_onepiece', 'ドラゴンボール',
        );
    }

    /** 风景类正向标签（命中越多越优先，非硬性） */
    private function good_words() {
        return array( '風景', '背景', '青空', '空', '雲', '桜', '雪', '山', '草原', '湖', '海', '夕焼け', '星空', '银河', '花', '自然', '風景画', 'scenery', 'landscape', 'sky' );
    }

    /** 搜索粗筛：返回候选 [{id,title,tags,width,height,url}] */
    private function search_candidates( $keywords, $want ) {
        $fetched = array_map( 'strval', (array) get_option( self::OPT_FETCHED, array() ) );
        $found = array();
        $seen  = array();

        // 关键词轮流抓取，直到够数；order=date 才有足量手绘作品
        $words = preg_split( '/\R/u', trim( $keywords ) );
        $words = array_values( array_filter( array_map( 'trim', $words ) ) );
        if ( empty( $words ) ) { $words = array( '風景' ); }
        shuffle( $words );

        foreach ( $words as $word ) {
            if ( count( $found ) >= $want * 12 ) { break; } // 多备十几倍供精筛淘汰，避免好图排在后面没进候选池
            $q = rawurlencode( $word );
            $url = 'https://www.pixiv.net/ajax/search/illustrations/' . $q
                 . '?word=' . $q . '&mode=safe&order=date&p=1';
            $j = $this->get_json( $url );
            if ( ! $j || empty( $j['body']['illust']['data'] ) || ! is_array( $j['body']['illust']['data'] ) ) { continue; }
            foreach ( $j['body']['illust']['data'] as $it ) {
                if ( ! is_array( $it ) ) { continue; }
                $id = (string) ( $it['id'] ?? '' );
                if ( $id === '' || isset( $seen[ $id ] ) || in_array( $id, $fetched, true ) ) { continue; }
                // 粗筛：手绘插画、横屏、单页、有一定宽度
                if ( (int) ( $it['aiType'] ?? -1 ) !== 0 ) { $seen[ $id ] = 1; continue; }
                if ( (int) ( $it['illustType'] ?? -1 ) !== 0 ) { $seen[ $id ] = 1; continue; }
                if ( (int) ( $it['pageCount'] ?? 0 ) !== 1 ) { $seen[ $id ] = 1; continue; }
                $w = (int) ( $it['width'] ?? 0 ); $h = (int) ( $it['height'] ?? 0 );
                if ( $w <= $h ) { $seen[ $id ] = 1; continue; }
                $seen[ $id ] = 1;
                $tags = array();
                foreach ( (array) ( $it['tags'] ?? array() ) as $t ) {
                    if ( is_string( $t ) ) { $tags[] = $t; }
                    elseif ( is_array( $t ) && isset( $t['tag'] ) ) { $tags[] = (string) $t['tag']; }
                }
                $found[] = array(
                    'id'    => $id,
                    'title' => (string) ( $it['title'] ?? '' ),
                    'tags'  => $tags,
                    'w'     => $w,
                    'h'     => $h,
                    'url'   => (string) ( $it['url'] ?? '' ), // 缩略图，仅展示用
                );
            }
        }
        return $found;
    }

    /** 精筛：调详情接口拿收藏数与完整标签，按闸门淘汰 */
    private function refine( $cand, $opt, &$stat ) {
        $j = $this->get_json( 'https://www.pixiv.net/ajax/illust/' . $cand['id'] );
        if ( ! $j || empty( $j['body'] ) ) { $stat['detail_http'] = ( $stat['detail_http'] ?? 0 ) + 1; return null; }
        $b = $j['body'];

        // 二次确认手绘 + 横屏 + 插画 + 宽度
        if ( (int) ( $b['aiType'] ?? -1 ) !== 0 ) { $stat['detail_ai'] = ( $stat['detail_ai'] ?? 0 ) + 1; return null; }
        if ( (int) ( $b['illustType'] ?? -1 ) !== 0 ) { $stat['detail_illust'] = ( $stat['detail_illust'] ?? 0 ) + 1; return null; }
        if ( (int) ( $b['pageCount'] ?? 0 ) !== 1 ) { $stat['detail_page'] = ( $stat['detail_page'] ?? 0 ) + 1; return null; }
        $w = (int) ( $b['width'] ?? 0 ); $h = (int) ( $b['height'] ?? 0 );
        if ( $w <= $h || $w < (int) $opt['min_width'] ) { $stat['width_or_orientation'] = ( $stat['width_or_orientation'] ?? 0 ) + 1; return null; }

        $bm = (int) ( $b['bookmarkCount'] ?? 0 );
        if ( $bm < (int) $opt['min_bookmarks'] ) { $stat['bookmarks'] = ( $stat['bookmarks'] ?? 0 ) + 1; return null; }

        // 标题 + 标签坏词过滤
        $title = (string) ( $b['illustTitle'] ?? $cand['title'] );
        $tags = array();
        $tags_obj = $b['tags'] ?? array();
        // pixiv 详情接口：body.tags.tags[] 才是标签数组（body.tags 本身是 {authorId, isLocked, tags:[...]}）
        $tag_list = is_array( $tags_obj ) && isset( $tags_obj['tags'] ) && is_array( $tags_obj['tags'] ) ? $tags_obj['tags'] : (array) $tags_obj;
        foreach ( $tag_list as $t ) {
            if ( is_string( $t ) ) { $tags[] = $t; }
            elseif ( is_array( $t ) && isset( $t['tag'] ) ) { $tags[] = (string) $t['tag']; }
            elseif ( is_object( $t ) && isset( $t->tag ) ) { $tags[] = (string) $t->tag; }
        }
        $text = $title . ' ' . implode( ' ', $tags );
        foreach ( $this->bad_words() as $bad ) {
            if ( stripos( $text, $bad ) !== false ) { $stat['bad_word'] = ( $stat['bad_word'] ?? 0 ) + 1; return null; }
        }

        // 原图 URL：优先 regular（无水印、尺寸适中），无则 original
        $urls = $b['urls'] ?? array();
        $src = (string) ( $urls['regular'] ?? '' );
        if ( $src === '' ) { $src = (string) ( $urls['original'] ?? '' ); }
        if ( $src === '' ) { $stat['no_url'] = ( $stat['no_url'] ?? 0 ) + 1; return null; }

        // 正向分：风景标签命中越多越优先
        $score = 0;
        foreach ( $this->good_words() as $g ) {
            if ( stripos( $text, $g ) !== false ) { $score++; }
        }
        return array(
            'id'    => $cand['id'],
            'title' => $title,
            'tags'  => array_slice( $tags, 0, 10 ),
            'w'     => $w,
            'h'     => $h,
            'bm'    => $bm,
            'src'   => $src,
            'thumb' => $cand['url'],
            'score' => $score,
            'user'  => (string) ( $b['userName'] ?? '' ),
        );
    }

    /* ------------------------- 存储 ------------------------- */

    /** 确定图库存储目录与 URL 前缀（优先补货配置，回退到 image_mode 的自定义目录） */
    private function store_targets( $opt ) {
        $dir  = trim( (string) $opt['store_dir'] );
        $base = trim( (string) $opt['store_url'] );
        if ( $dir === '' || $base === '' ) {
            $dir  = trim( (string) $this->settings->get( 'image_dir', '' ) );
            $base = trim( (string) $this->settings->get( 'image_url_base', '' ) );
        }
        return array( rtrim( $dir, '/' ), rtrim( $base, '/' ) );
    }

    /** 把一张候选图转存到自有图床，返回公开 URL 或空串 */
    private function store_image( $item, $dir, $base ) {
        if ( $dir === '' || $base === '' ) { return ''; }
        $img = $this->download_image( $item['src'] );
        if ( is_wp_error( $img ) ) { return ''; }
        $gallery_dir = $dir . '/gallery';
        if ( ! is_dir( $gallery_dir ) ) { @mkdir( $gallery_dir, 0777, true ); }
        $fname = 'pixiv_' . $item['id'] . '.' . $img['ext'];
        $dest  = $gallery_dir . '/' . $fname;
        if ( @file_put_contents( $dest, $img['bytes'] ) === false ) { return ''; }
        @chmod( $dest, 0644 );
        return $base . '/gallery/' . $fname;
    }

    /* ------------------------- 主流程 ------------------------- */

    /**
     * 补货主入口。
     * @param bool $force 忽略冷却与阈值，手动强制抓
     * @return array {ok, message, added, ...}
     */
    public function fetch( $force = false ) {
        $opt = $this->options();

        if ( ! $force ) {
            if ( empty( $opt['enabled'] ) ) { return $this->res( false, '自动补货未开启' ); }
            // 阈值判断：图池剩余未用数量
            $pool = $this->cover_pool();
            $bag  = get_option( AICP_Settings::OPT_COVER_BAG, array() );
            $remaining = ( is_array( $bag ) && ! empty( $bag['bag'] ) ) ? count( $bag['bag'] ) : count( $pool );
            if ( $remaining >= (int) $opt['threshold'] ) {
                return $this->res( false, '图池还剩 ' . $remaining . ' 张，未到补货阈值 ' . $opt['threshold'] );
            }
            $last = (int) get_option( self::OPT_LAST, 0 );
            if ( $last > 0 && ( time() - $last ) < (int) $opt['cooldown_hours'] * 3600 ) {
                return $this->res( false, '冷却中，距上次补货未满 ' . $opt['cooldown_hours'] . ' 小时' );
            }
        }

        $cands = $this->search_candidates( $opt['keywords'], (int) $opt['batch'] );
        if ( empty( $cands ) ) {
            $this->log( '搜索无候选（pixiv 接口或关键词异常）' );
            return $this->res( false, '搜索无候选，请检查关键词或稍后重试' );
        }
        update_option( self::OPT_LAST, time(), false );

        // 精筛，按正向分排序，最多取 batch 个
        $refined = array();
        $stat    = array();
        foreach ( $cands as $c ) {
            $r = $this->refine( $c, $opt, $stat );
            if ( $r ) { $refined[] = $r; }
            if ( count( $refined ) >= (int) $opt['batch'] ) { break; }
        }
        if ( empty( $refined ) ) {
            $detail = array();
            foreach ( $stat as $k => $v ) { $detail[] = $k . '=' . $v; }
            $this->log( '精筛后无合格图；粗筛 ' . count( $cands ) . ' 张，淘汰原因：' . implode( ' ', $detail ) );
            return $this->res( false, '没有通过质量闸门的图（粗筛 ' . count( $cands ) . ' 张）；可下调收藏数或放宽最小宽度' );
        }
        usort( $refined, function ( $a, $b ) {
            if ( $a['score'] === $b['score'] ) { return $b['bm'] - $a['bm']; }
            return $b['score'] - $a['score'];
        } );

        list( $dir, $base ) = $this->store_targets( $opt );
        $candidates = (array) get_option( self::OPT_CANDIDATES, array() );
        $fetched    = (array) get_option( self::OPT_FETCHED, array() );
        $added = 0; $stored = 0;

        foreach ( $refined as $item ) {
            $url = $this->store_image( $item, $dir, $base );
            if ( $url === '' ) {
                $item['local_url'] = '';
                $item['status'] = 'failed';
            } else {
                $stored++;
                $item['local_url'] = $url;
                $item['status'] = 'pending';
            }
            // 不管转存成败都记 fetched，避免反复抓同一张
            $fetched[] = $item['id'];
            // 去重候选
            if ( ! $this->in_candidates( $candidates, $item['id'] ) ) {
                $candidates[] = $item;
                $added++;
            }
        }

        update_option( self::OPT_FETCHED, array_slice( array_unique( $fetched ), -500 ), false );
        update_option( self::OPT_CANDIDATES, array_slice( $candidates, -100 ), false );
        $this->log( '补货：粗筛 ' . count( $cands ) . '，合格 ' . count( $refined ) . '，转存成功 ' . $stored . '，入候选 ' . $added );

        // 非审核模式：直接全部通过
        if ( empty( $opt['review'] ) && $stored > 0 ) {
            $r = $this->approve_all();
            return $this->res( true, '补货完成：转存 ' . $stored . ' 张，已自动入池 ' . ( $r['approved'] ?? 0 ) . ' 张', array( 'added' => $added, 'stored' => $stored ) );
        }
        return $this->res( true, '补货完成：转存 ' . $stored . ' 张，待审核 ' . $added . ' 张', array( 'added' => $added, 'stored' => $stored ) );
    }

    private function in_candidates( $candidates, $id ) {
        foreach ( $candidates as $c ) {
            if ( is_array( $c ) && (string) ( $c['id'] ?? '' ) === (string) $id ) { return true; }
        }
        return false;
    }

    /** 当前封面图池 URL 列表 */
    private function cover_pool() {
        $text = (string) $this->settings->get( 'cover_urls', '' );
        $out = array();
        foreach ( explode( "\n", $text ) as $line ) {
            $u = trim( $line );
            if ( $u !== '' && strpos( $u, 'http' ) === 0 ) { $out[] = $u; }
        }
        return array_values( array_unique( $out ) );
    }

    /** 审核通过：把候选中的 pending 且已转存的图并入 cover_urls */
    public function approve( $ids ) {
        $candidates = (array) get_option( self::OPT_CANDIDATES, array() );
        $ids = array_map( 'strval', (array) $ids );
        $pool = $this->cover_pool();
        $approved = 0;
        foreach ( $candidates as $k => $c ) {
            if ( ! is_array( $c ) || ( $c['status'] ?? '' ) !== 'pending' ) { continue; }
            if ( ! in_array( (string) ( $c['id'] ?? '' ), $ids, true ) ) { continue; }
            $url = (string) ( $c['local_url'] ?? '' );
            if ( $url === '' || in_array( $url, $pool, true ) ) {
                $candidates[ $k ]['status'] = 'skipped';
                continue;
            }
            $pool[] = $url;
            $candidates[ $k ]['status'] = 'approved';
            $approved++;
        }
        update_option( self::OPT_CANDIDATES, $candidates, false );
        // 写回 cover_urls
        $all = $this->settings->all();
        $all['cover_urls'] = implode( "\n", array_values( array_unique( $pool ) ) );
        update_option( AICP_Settings::OPT, $all, false );
        if ( $approved > 0 ) { $this->log( '审核通过 ' . $approved . ' 张，已并入封面图池' ); }
        return $approved;
    }

    /** 审核拒绝：从候选移除 */
    public function reject( $ids ) {
        $candidates = (array) get_option( self::OPT_CANDIDATES, array() );
        $ids = array_map( 'strval', (array) $ids );
        $kept = array();
        $n = 0;
        foreach ( $candidates as $c ) {
            if ( is_array( $c ) && in_array( (string) ( $c['id'] ?? '' ), $ids, true ) ) { $n++; continue; }
            $kept[] = $c;
        }
        update_option( self::OPT_CANDIDATES, $kept, false );
        return $n;
    }

    /** 全部通过 */
    public function approve_all() {
        $candidates = (array) get_option( self::OPT_CANDIDATES, array() );
        $ids = array();
        foreach ( $candidates as $c ) {
            if ( is_array( $c ) && ( $c['status'] ?? '' ) === 'pending' ) { $ids[] = (string) $c['id']; }
        }
        return array( 'approved' => $this->approve( $ids ) );
    }

    public function candidates() {
        return (array) get_option( self::OPT_CANDIDATES, array() );
    }

    public function log( $msg ) {
        $logs = (array) get_option( self::OPT_LOG, array() );
        $logs[] = array( 'time' => current_time( 'mysql' ), 'msg' => (string) $msg );
        update_option( self::OPT_LOG, array_slice( $logs, -50 ), false );
    }

    public function logs() {
        return (array) get_option( self::OPT_LOG, array() );
    }

    private function res( $ok, $message, $extra = array() ) {
        return array_merge( array( 'ok' => (bool) $ok, 'message' => $message ), $extra );
    }
}
