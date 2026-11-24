<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * 核心发布引擎：抓取 RSS → 筛选打分 → AI 改写 → 配图/封面 → 入库发布。
 * 所有可变参数均来自 AICP_Settings。
 */
class AICP_Publisher {

    const CRON_HOOK = 'aicp_publish_cron';

    /** @var AICP_Settings */
    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
        add_action( self::CRON_HOOK, array( $this, 'cron_tick' ) );
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
    }

    /* ------------------------------------------------------------------ *
     * 调度
     * ------------------------------------------------------------------ */

    public function add_schedules( $schedules ) {
        $schedules['aicp_five_min'] = array(
            'interval' => 300,
            'display'  => __( '每 5 分钟（WP AI Content Publisher）', 'wp-ai-content-publisher' ),
        );
        return $schedules;
    }

    public function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'aicp_five_min', self::CRON_HOOK );
        }
    }

    public function unschedule_cron() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /** init 时兜底：若计划丢失则重建（低流量站点也能跑） */
    public function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $this->schedule_cron();
        }
    }

    public function cron_tick() {
        $this->maybe_publish( false );
    }

    private function tz() {
        $tz = (string) $this->settings->get( 'timezone', 'Asia/Shanghai' );
        if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) { $tz = 'Asia/Shanghai'; }
        try { return new DateTimeZone( $tz ); }
        catch ( Exception $e ) { return new DateTimeZone( 'Asia/Shanghai' ); }
    }

    /** 当前时间是否落在"高峰期"（高峰期不发布） */
    private function is_peak_at( $dt ) {
        $ranges = (string) $this->settings->get( 'peak_ranges', '' );
        $dow = (int) $dt->format( 'N' ); // 1=周一 7=周日
        if ( $dow >= 6 ) { return false; } // 周末不设高峰
        $hm  = (int) $dt->format( 'G' ) * 60 + (int) $dt->format( 'i' );
        foreach ( explode( "\n", $ranges ) as $line ) {
            $line = trim( $line );
            if ( ! preg_match( '/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $line, $m ) ) { continue; }
            $a = (int) $m[1] * 60 + (int) $m[2];
            $b = (int) $m[3] * 60 + (int) $m[4];
            if ( $hm >= $a && $hm < $b ) { return true; }
        }
        return false;
    }

    /** 生成/读取"今日目标发布时间戳" */
    private function today_target_ts() {
        $tz  = $this->tz();
        $now = new DateTime( 'now', $tz );
        $day = $now->format( 'Y-m-d' );
        $rec = (array) get_option( AICP_Settings::OPT_PUBTIME, array() );
        if ( isset( $rec['day'], $rec['ts'] ) && $rec['day'] === $day ) {
            return (int) $rec['ts'];
        }

        $mode = $this->settings->get( 'publish_mode', 'random' );
        if ( $mode === 'fixed' ) {
            $hm = $this->parse_hm( $this->settings->get( 'fixed_time', '20:00' ) );
        } else {
            $start = $this->parse_hm( $this->settings->get( 'random_start', '06:00' ) );
            $end   = $this->parse_hm( $this->settings->get( 'random_end', '23:55' ) );
            if ( $end <= $start ) { $end = $start + 1; }
            $hm = $start + mt_rand( 0, $end - $start );
            // 随机点若落在高峰期，顺延到高峰结束
            if ( $this->settings->get( 'offpeak' ) ) {
                $cand = clone $now;
                $cand->setTime( (int) ( $hm / 60 ), $hm % 60, 0 );
                if ( $this->is_peak_at( $cand ) ) {
                    $hm = $this->next_after_peak( $cand, $end );
                }
            }
        }
        $ts = ( new DateTime( $day . ' 00:00:00', $tz ) )->getTimestamp() + $hm * 60;
        update_option( AICP_Settings::OPT_PUBTIME, array( 'day' => $day, 'ts' => $ts, 'at' => gmdate( 'H:i', $hm * 60 ) ) );
        return $ts;
    }

    private function parse_hm( $s ) {
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $s, $m ) ) { return 20 * 60; }
        return ( (int) $m[1] ) * 60 + (int) $m[2];
    }

    /** 高峰期顺延：返回当天 end 之前最近的非高峰分钟 */
    private function next_after_peak( $dt, $end_hm ) {
        $hm = (int) $dt->format( 'G' ) * 60 + (int) $dt->format( 'i' );
        for ( $i = $hm + 5; $i <= $end_hm; $i += 5 ) {
            $probe = clone $dt;
            $probe->setTime( (int) ( $i / 60 ), $i % 60, 0 );
            if ( ! $this->is_peak_at( $probe ) ) { return $i; }
        }
        return $hm; // 兜底
    }

    /** 今日已发数量（按配置时区自然日，删稿自动释放配额） */
    public function daily_count() {
        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        $cut = ( new DateTime( 'today', $this->tz() ) )->getTimestamp();
        $n = 0;
        foreach ( $log as $item ) {
            if ( ! isset( $item['time'] ) || (int) $item['time'] < $cut ) { continue; }
            if ( isset( $item['post_id'] ) && get_post_status( $item['post_id'] ) !== 'publish'
                 && $this->settings->get( 'post_status' ) === 'publish' ) { continue; }
            $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------ *
     * 发布决策
     * ------------------------------------------------------------------ */

    /**
     * @param bool   $force 强制发布（忽略模式/时间/限额）
     * @param string $hint  标题关键词，精准锁定某篇（调试/手动用）
     */
    public function maybe_publish( $force = false, $hint = '' ) {
        if ( ! $force ) {
            if ( ! $this->settings->has_api_key() ) {
                return $this->skip( 'no_api_key', '尚未配置 AI 接口密钥' );
            }
            $mode = $this->settings->get( 'publish_mode', 'random' );
            if ( $mode === 'manual' ) {
                return $this->skip( 'manual', '手动模式，自动发布已停用' );
            }
            $target = $this->today_target_ts();
            if ( time() < $target ) {
                return $this->skip( 'not_time', '未到今日发布时间 ' . $this->fmt( $target ) );
            }
            if ( $this->settings->get( 'offpeak' ) && $this->is_peak_at( new DateTime( 'now', $this->tz() ) ) ) {
                return $this->skip( 'peak', '当前为高峰时段，已暂停发布' );
            }
            $limit = (int) $this->settings->get( 'daily_limit', 1 );
            if ( $limit > 0 && $this->daily_count() >= $limit ) {
                return $this->skip( 'quota', '今日已发满 ' . $limit . ' 篇' );
            }
        }
        return $this->publish_one( $force, $hint );
    }

    private function skip( $reason, $msg ) {
        $this->log( $msg );
        return array( 'ok' => false, 'reason' => $reason, 'message' => $msg );
    }

    private function fmt( $ts ) {
        $d = new DateTime( '@' . $ts );
        $d->setTimezone( $this->tz() );
        return $d->format( 'Y-m-d H:i' );
    }

    /** 确保分类存在，返回 分类名=>term_id */
    public function ensure_categories() {
        $ids = array();
        foreach ( (array) $this->settings->get( 'categories', array() ) as $name ) {
            if ( ! is_string( $name ) || trim( $name ) === '' ) { continue; }
            $term = get_term_by( 'name', $name, 'category' );
            if ( $term ) {
                $ids[ $name ] = (int) $term->term_id;
            } else {
                $r = wp_insert_term( $name, 'category' );
                $ids[ $name ] = is_wp_error( $r ) ? 0 : (int) $r['term_id'];
            }
        }
        return $ids;
    }

    /** 分类轮换：返回本次要发布的分类名 */
    private function pick_category() {
        $cats = array_values( array_filter( (array) $this->settings->get( 'categories', array() ), function ( $c ) {
            return is_string( $c ) && $c !== '';
        } ) );
        if ( empty( $cats ) ) { return ''; }
        $feeds_map = (array) $this->settings->get( 'feeds', array() );
        $rot = (int) get_option( AICP_Settings::OPT_ROT, 0 );
        // 最多轮一圈，挑一个配了 RSS 源的分类
        for ( $i = 0; $i < count( $cats ); $i++ ) {
            $name = $cats[ ( $rot + $i ) % count( $cats ) ];
            if ( ! empty( $feeds_map[ $name ] ) ) {
                update_option( AICP_Settings::OPT_ROT, ( $rot + $i + 1 ) % 1000000 );
                return $name;
            }
        }
        update_option( AICP_Settings::OPT_ROT, $rot + 1 );
        return $cats[ $rot % count( $cats ) ];
    }

    /* ------------------------------------------------------------------ *
     * 筛选打分
     * ------------------------------------------------------------------ */

    /** 黑名单命中（标题） */
    private function hit_blacklist( $title ) {
        $t = mb_strtolower( $title );
        $list = explode( "\n", (string) $this->settings->get( 'blacklist', '' ) );
        foreach ( $list as $kw ) {
            $kw = trim( mb_strtolower( $kw ) );
            if ( $kw !== '' && mb_strpos( $t, $kw ) !== false ) { return true; }
        }
        // 多主题拼接（新闻快讯特征）
        if ( mb_strpos( $title, '；' ) !== false || mb_strpos( $title, '; ' ) !== false ) { return true; }
        return false;
    }

    /** 内容相关度打分：命中深度词加分，取分高者 */
    private function title_score( $title, $cat = '' ) {
        $t = mb_strtolower( $title );

        // 新闻快讯 / 单条资讯直接淘汰（无论分类）
        $news = array( '快讯', '简讯', '通报', '刚刚', '突发', '记者从', '获悉', '据消息', '网曝', '被曝', '曝光', '官宣', '开售', '发售', '正式发布', '据外媒', '外媒报道', '消息称', '据悉', '最新消息', 'breaking', ' just in', 'reportedly' );
        foreach ( $news as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { return -1000; } }
        if ( mb_strpos( $title, '；' ) !== false || mb_strpos( $title, '; ' ) !== false ) { return -1000; }

        // 低价值稿淘汰：早报/周报、发版公告、活动问卷、交易帖、求助提问
        $junk = array( '早报', '周报', '日报', '本周看', '新玩意', '正式发版', '发版', '版本更新', '更新公告', '发布了新版本', '大会将于', '问卷', '有奖', '报名', '统计', '调查',
            '收鸡', '出鸡', '代开', '蹲', '求推荐', '求助', '请问', '怎么解决', '如何解决', '有没有大佬', '佬们', '佬友', '来一个',
            'release notes', 'changelog', 'version ', 'survey', 'giveaway' );
        foreach ( $junk as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { return -1000; } }

        // 深度内容（评测/上手/选购/教程/对比/折腾/解析）
        $depth = array( '评测', '测评', '上手', '体验', '试用', '选购', '怎么选', '如何选', '指南', '教程', '盘点', '推荐', '对比', '横评', '拆解', '实测', '深度', '值得买', '深入', '详解', '解析', '实践', '折腾', '搭建', '部署', '配置', '优化', '踩坑', '记录',
            'review', 'hands-on', 'handson', 'guide', 'comparison', 'compared', 'buying', 'tested', 'benchmark', 'deep dive', 'explained', 'how to', 'tutorial', 'setup', 'build' );
        $depth_hit = false;
        foreach ( $depth as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { $depth_hit = true; break; } }

        // 游戏类专项：不要攻略/密码/获取方法，只要新游戏/评分/测评/奖项/深度
        if ( $cat === '游戏' ) {
            $game_junk = array( '攻略', '获取方法', '获得方法', '怎么拿', '怎么获得', '如何获得', '密码', '保险箱', '获取', '位置', '流程', '通关', '收集', '解锁', '合成', '配方', '在哪', '哪里找', 'how to', 'guide', 'walkthrough', 'location', 'password', 'safe code', 'unlock', 'farm', 'obtain', 'where to find', 'tips and tricks' );
            foreach ( $game_junk as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { return -1000; } }
            $game_ctx = array( '游戏', 'gta', 'grand theft', 'nioh', '仁王', 'persona', '女神异闻', 'skyrim', '上古卷轴', 'elder scrolls', 'deltarune', 'steam', 'ubisoft', 'rockstar', 'bethesda', 'atlus', 'player', '玩家', 'console', 'ps5', 'xbox', 'nintendo', 'switch', 'dungeon', 'roguelite', 'rpg', 'fps', 'game ', 'games ', 'gaming', 'metascore', 'metacritic', 'opencritic' );
            $has_ctx = false;
            foreach ( $game_ctx as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { $has_ctx = true; break; } }
            if ( ! $has_ctx ) { return -1000; }
            $game_good = array( '评测', '测评', '评分', '评价', '新作', '新游', '新游戏', '获奖', '奖项', '年度游戏', '提名', '榜单', '排行', '排名', '销量', 'metascore', 'metacritic', 'opencritic', 'ign', 'tga', 'the game awards', 'review', 'rating', 'score', 'award', 'nominated', 'biggest', 'record', 'best-selling', 'deep dive', 'preview', 'first look', 'hands-on' );
            foreach ( $game_good as $kw ) { if ( mb_strpos( $t, $kw ) !== false ) { return 100; } }
            return $depth_hit ? 90 : 55;
        }

        return $depth_hit ? 90 : 55;
    }

    /* ------------------------------------------------------------------ *
     * 发布主流程
     * ------------------------------------------------------------------ */

    public function publish_one( $force = false, $hint = '' ) {
        if ( ! $this->settings->has_api_key() ) {
            return $this->skip( 'no_api_key', '尚未配置 AI 接口密钥，无法发布' );
        }

        $cat_name = $this->pick_category();
        if ( $cat_name === '' ) {
            return $this->skip( 'no_category', '未配置参与轮换的分类' );
        }
        $feeds_map = (array) $this->settings->get( 'feeds', array() );
        $sources = $this->parse_urls( isset( $feeds_map[ $cat_name ] ) ? $feeds_map[ $cat_name ] : '' );
        if ( empty( $sources ) ) {
            return $this->skip( 'no_feed', '分类「' . $cat_name . '」未配置 RSS 源' );
        }
        shuffle( $sources );

        $used   = (array) get_option( AICP_Settings::OPT_USED, array() );
        $max_age = (int) $this->settings->get( 'max_age', 7 );
        $now_ts = time();
        $candidates = array();

        foreach ( $sources as $url ) {
            $feed = fetch_feed( $url );
            if ( is_wp_error( $feed ) ) {
                $this->log( '抓取失败 ' . $url . '：' . $feed->get_error_message() );
                continue;
            }
            $max = min( $feed->get_item_quantity( 20 ), 20 );
            $items = $feed->get_items( 0, $max );
            foreach ( $items as $it ) {
                $link = $it->get_permalink();
                if ( ! $link || in_array( $link, $used, true ) ) { continue; }
                $title = $it->get_title();
                if ( $title === '' || $this->hit_blacklist( $title ) ) { continue; }

                $pub_ts = (int) $it->get_date( 'U' );
                if ( $max_age > 0 ) {
                    if ( $pub_ts <= 0 || ( $now_ts - $pub_ts ) > $max_age * DAY_IN_SECONDS ) { continue; }
                }
                $candidates[] = array(
                    'score' => $this->title_score( $title, $cat_name ),
                    'item'  => $it,
                    'link'  => $link,
                    'title' => $title,
                    'pub_ts'=> $pub_ts,
                );
            }
        }

        // 精准关键词（后台"立即发布"可填）
        if ( $hint !== '' ) {
            $hint = trim( $hint );
            $candidates = array_values( array_filter( $candidates, function ( $c ) use ( $hint ) {
                return mb_stripos( $c['title'], $hint ) !== false;
            } ) );
        }

        if ( empty( $candidates ) ) {
            return $this->skip( 'no_item', '未找到符合条件的文章（' . $cat_name . '）' );
        }

        usort( $candidates, function ( $a, $b ) {
            if ( $a['score'] === $b['score'] ) {
                return $a['pub_ts'] > $b['pub_ts'] ? -1 : ( $a['pub_ts'] < $b['pub_ts'] ? 1 : 0 );
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        } );
        $top  = array_slice( $candidates, 0, min( 5, count( $candidates ) ) );
        $pick = $top[ mt_rand( 0, count( $top ) - 1 ) ];
        $item = $pick['item'];
        $feed_url = $pick['link'];
        $feed_title = $pick['title'];
        $pub_ts = $pick['pub_ts'];

        // 原文正文 + 配图
        $raw_html = (string) $item->get_content();
        if ( ! $raw_html ) { $raw_html = (string) $item->get_description(); }
        $image_urls = $this->extract_image_urls( $item, $raw_html );
        $raw = wp_strip_all_tags( $raw_html );
        $raw = preg_replace( '/\s+/u', ' ', $raw );
        $raw = mb_substr( trim( $raw ), 0, 9000 );

        $min_len = (int) $this->settings->get( 'min_body_len', 200 );
        if ( mb_strlen( $raw ) < $min_len ) {
            $used[] = $feed_url;
            update_option( AICP_Settings::OPT_USED, array_slice( $used, -300 ) );
            return $this->skip( 'too_short', '原文正文过短（' . mb_strlen( $raw ) . ' 字），已跳过' );
        }

        // 封面
        $cover_att_id = 0;
        $cover_mode = $this->settings->get( 'cover_mode', 'source' );
        if ( $cover_mode === 'custom' ) {
            $pool = $this->parse_urls( $this->settings->get( 'cover_urls', '' ) );
            $src = $this->pick_cover_url( $pool );
            if ( $src !== '' ) {
                $cover_att_id = $this->store_cover( $src, $feed_url );
            }
        } elseif ( $cover_mode === 'source' && ! empty( $image_urls ) ) {
            $cover_att_id = $this->store_cover( $image_urls[0], $feed_url );
        }
        if ( $this->settings->get( 'require_image' ) && ! $cover_att_id ) {
            $used[] = $feed_url;
            update_option( AICP_Settings::OPT_USED, array_slice( $used, -300 ) );
            return $this->skip( 'no_cover', '无法获取封面图，已跳过（可关闭"必须封面"或改用自定义封面）' );
        }

        // AI 改写
        $rewritten = $this->ai_rewrite( $feed_title, $raw );
        if ( is_wp_error( $rewritten ) ) {
            if ( $rewritten->get_error_code() === 'skip' ) {
                $used[] = $feed_url;
                update_option( AICP_Settings::OPT_USED, array_slice( $used, -300 ) );
                return $this->skip( 'skipped_by_ai', 'AI 判定不符合主题，已跳过：' . $feed_title );
            }
            return $this->skip( 'ai_error', 'AI 改写失败：' . $rewritten->get_error_message() );
        }

        // 正文配图（按 image_mode 存到媒体库或自定义目录）
        $body_images = array();
        foreach ( $image_urls as $src ) {
            if ( count( $body_images ) >= 8 ) { break; }
            $u = $this->store_body_image( $src, $feed_url );
            if ( $u ) { $body_images[] = $u; }
        }
        $content = $this->insert_images( $rewritten['content'], $body_images );

        // 文末版权
        $src_host = parse_url( $feed_url, PHP_URL_HOST );
        $src_host = $src_host ? preg_replace( '/^www\./', '', $src_host ) : $cat_name;
        $footer_tpl = (string) $this->settings->get( 'footer', '' );
        $footer = str_replace(
            array( '{url}', '{title}', '{host}', '{cat}' ),
            array( esc_url( $feed_url ), esc_html( $feed_title ), esc_html( $src_host ), esc_html( $cat_name ) ),
            $footer_tpl
        );
        $content .= $footer;

        $cat_ids = $this->ensure_categories();
        $cat_id  = isset( $cat_ids[ $cat_name ] ) ? (int) $cat_ids[ $cat_name ] : 0;
        $author  = (int) $this->settings->get( 'author_id', 0 );
        if ( ! $author ) { $author = (int) get_current_user_id(); }
        if ( ! $author ) { $author = 1; }

        $post_id = wp_insert_post( array(
            'post_title'    => wp_strip_all_tags( $rewritten['title'] ),
            'post_content'  => $content,
            'post_status'   => in_array( $this->settings->get( 'post_status' ), array( 'publish','draft' ), true )
                ? $this->settings->get( 'post_status' ) : 'publish',
            'post_author'   => $author,
            'post_category' => $cat_id ? array( $cat_id ) : array(),
            'tags_input'    => isset( $rewritten['tags'] ) ? $rewritten['tags'] : array(),
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $this->skip( 'insert_error', '入库失败：' . $post_id->get_error_message() );
        }

        if ( $cover_att_id ) {
            set_post_thumbnail( $post_id, $cover_att_id );
        }

        $used[] = $feed_url;
        update_option( AICP_Settings::OPT_USED, array_slice( $used, -300 ) );

        $log = (array) get_option( AICP_Settings::OPT_LOG, array() );
        $log[] = array(
            'time'        => time(),
            'title'       => get_the_title( $post_id ),
            'cat'         => $cat_name,
            'source'      => $feed_title,
            'source_url'  => $feed_url,
            'source_date' => $pub_ts > 0 ? gmdate( 'Y-m-d H:i', $pub_ts + ( $this->tz()->getOffset( $now = new DateTime( 'now', $this->tz() ) ) ) ) : '',
            'post_id'     => $post_id,
            'cover_att'   => $cover_att_id,
            'img_found'   => count( $image_urls ),
            'body_images' => count( $body_images ),
        );
        update_option( AICP_Settings::OPT_LOG, array_slice( $log, -100 ) );

        $this->flush_cache();
        $this->log( '已发布 #' . $post_id . ' [' . $cat_name . '] ' . get_the_title( $post_id ) );

        return array(
            'ok'          => true,
            'post_id'     => $post_id,
            'title'       => get_the_title( $post_id ),
            'cat'         => $cat_name,
            'source_url'  => $feed_url,
            'url'         => get_permalink( $post_id ),
            'cover_att'   => $cover_att_id,
            'img_found'   => count( $image_urls ),
            'body_images' => count( $body_images ),
        );
    }

    private function parse_urls( $text ) {
        $out = array();
        foreach ( explode( "\n", (string) $text ) as $line ) {
            $u = trim( $line );
            if ( $u !== '' && ( strpos( $u, 'http' ) === 0 ) ) { $out[] = $u; }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * 封面洗牌袋：把图池打乱成队列依次取出，取完一轮才重新洗牌。
     * 保证同一轮内不重复；图池内容变化（增删图）会自动重建队列。
     */
    private function pick_cover_url( $pool ) {
        if ( empty( $pool ) ) { return ''; }
        $sorted = $pool;
        sort( $sorted, SORT_STRING );
        $hash = md5( implode( "\n", $sorted ) );
        $state = get_option( AICP_Settings::OPT_COVER_BAG, array() );
        if ( ! is_array( $state ) ) { $state = array(); }
        $bag = ( isset( $state['bag'] ) && is_array( $state['bag'] ) ) ? $state['bag'] : array();

        // 图池变化或队列用尽 → 重新洗牌
        if ( ! isset( $state['hash'] ) || $state['hash'] !== $hash || empty( $bag ) ) {
            $bag = $pool;
            shuffle( $bag );
        }

        $src = array_pop( $bag );
        // 防御：若取出的已不在当前图池，重建后重取
        if ( ! in_array( $src, $pool, true ) ) {
            $bag = $pool;
            shuffle( $bag );
            $src = array_pop( $bag );
        }

        update_option( AICP_Settings::OPT_COVER_BAG, array( 'hash' => $hash, 'bag' => array_values( $bag ) ), false );
        return $src;
    }

    private function flush_cache() {
        if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
        if ( function_exists( 'wpsc_delete_all_cache' ) ) { wpsc_delete_all_cache(); }
        if ( function_exists( 'prune_super_cache' ) && function_exists( 'get_supercache_dir' ) ) {
            prune_super_cache( get_supercache_dir(), true );
        }
    }

    /* ------------------------------------------------------------------ *
     * 图片：下载 → 校验 → 存储
     * ------------------------------------------------------------------ */

    /** 下载图片，返回 array(bytes, mime) 或 WP_Error */
    private function download_image( $src, $referer ) {
        $resp = wp_remote_get( $src, array(
            'timeout' => 25,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer'    => $referer ? $referer : home_url( '/' ),
            ),
        ) );
        if ( is_wp_error( $resp ) ) { return $resp; }
        if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return new WP_Error( 'http', 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
        }
        $body = wp_remote_retrieve_body( $resp );
        if ( strlen( $body ) < 1024 || strlen( $body ) > 20 * 1024 * 1024 ) {
            return new WP_Error( 'size', '图片大小异常' );
        }
        $tmp = tempnam( sys_get_temp_dir(), 'aicp' );
        if ( ! $tmp ) { return new WP_Error( 'tmp', '无法创建临时文件' ); }
        file_put_contents( $tmp, $body );
        $info = @getimagesize( $tmp );
        @unlink( $tmp );
        if ( ! $info || empty( $info['mime'] ) ) { return new WP_Error( 'type', '不是有效图片' ); }
        return array( 'bytes' => $body, 'mime' => $info['mime'] );
    }

    private function ext_by_mime( $mime ) {
        $map = array(
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/avif' => 'avif',
        );
        return isset( $map[ $mime ] ) ? $map[ $mime ] : 'img';
    }

    /** 封面：始终存到 WP 媒体库并生成附件 ID（零依赖，主题通用） */
    private function store_cover( $src, $referer ) {
        $img = $this->download_image( $src, $referer );
        if ( is_wp_error( $img ) ) { return 0; }
        return $this->insert_attachment( $img['bytes'], $img['mime'], 'aicp-cover-' . substr( md5( $src ), 0, 8 ) );
    }

    /** 正文配图：按 image_mode 存媒体库或自定义目录，返回公开 URL */
    private function store_body_image( $src, $referer ) {
        $img = $this->download_image( $src, $referer );
        if ( is_wp_error( $img ) ) { return ''; }
        $mode = $this->settings->get( 'image_mode', 'media' );
        if ( $mode === 'custom' ) {
            $dir = rtrim( (string) $this->settings->get( 'image_dir', '' ), '/' );
            $base = rtrim( (string) $this->settings->get( 'image_url_base', '' ), '/' );
            if ( $dir === '' || $base === '' ) { return ''; }
            $sub = gmdate( 'Ym' );
            if ( ! is_dir( $dir . '/' . $sub ) ) { @mkdir( $dir . '/' . $sub, 0755, true ); }
            $fname = gmdate( 'Ymd_His' ) . '_' . substr( md5( $src ), 0, 8 ) . '.' . $this->ext_by_mime( $img['mime'] );
            if ( @file_put_contents( $dir . '/' . $sub . '/' . $fname, $img['bytes'] ) === false ) { return ''; }
            @chmod( $dir . '/' . $sub . '/' . $fname, 0644 );
            return $base . '/' . $sub . '/' . $fname;
        }
        // 默认：媒体库
        $att_id = $this->insert_attachment( $img['bytes'], $img['mime'], 'aicp-img-' . substr( md5( $src ), 0, 8 ) );
        if ( ! $att_id ) { return ''; }
        return wp_get_attachment_url( $att_id );
    }

    /** 把二进制写入 WP 媒体库，返回附件 ID */
    private function insert_attachment( $bytes, $mime, $name_hint ) {
        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $fname = $name_hint . '.' . $this->ext_by_mime( $mime );
        $upload = wp_upload_bits( $fname, null, $bytes );
        if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) { return 0; }
        $file = $upload['file'];
        $att_id = wp_insert_attachment( array(
            'guid'           => $upload['url'],
            'post_mime_type' => $mime,
            'post_title'     => $name_hint,
            'post_status'    => 'inherit',
        ), $file );
        if ( is_wp_error( $att_id ) || ! $att_id ) { @unlink( $file ); return 0; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $att_id, $file );
        if ( $meta ) { wp_update_attachment_metadata( $att_id, $meta ); }
        return (int) $att_id;
    }

    /** 从 RSS item 提取候选图 URL */
    private function extract_image_urls( $item, $html ) {
        $urls = array();
        if ( method_exists( $item, 'get_enclosures' ) ) {
            foreach ( (array) $item->get_enclosures() as $enc ) {
                $link = method_exists( $enc, 'get_link' ) ? (string) $enc->get_link() : '';
                $thumb = method_exists( $enc, 'get_thumbnail' ) ? (string) $enc->get_thumbnail() : '';
                $type = method_exists( $enc, 'get_type' ) ? (string) $enc->get_type() : '';
                if ( $type && stripos( $type, 'image' ) === false ) { continue; }
                if ( $link ) { $urls[] = $link; }
                if ( $thumb ) { $urls[] = $thumb; }
            }
        }
        if ( method_exists( $item, 'get_thumbnail' ) ) {
            $th = $item->get_thumbnail();
            if ( is_array( $th ) ) {
                foreach ( $th as $t ) {
                    if ( is_string( $t ) ) { $urls[] = $t; }
                    elseif ( is_object( $t ) && method_exists( $t, 'get_link' ) ) { $urls[] = (string) $t->get_link(); }
                }
            } elseif ( is_object( $th ) && method_exists( $th, 'get_link' ) ) {
                $urls[] = (string) $th->get_link();
            } elseif ( is_string( $th ) ) {
                $urls[] = $th;
            }
        }
        if ( $html ) {
            if ( preg_match_all( '/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
                foreach ( $m[1] as $u ) { $urls[] = html_entity_decode( $u, ENT_QUOTES, 'UTF-8' ); }
            }
            if ( preg_match_all( '/<media:(?:content|thumbnail)[^>]+url\s*=\s*["\']([^"\']+)["\']/i', $html, $m2 ) ) {
                foreach ( $m2[1] as $u ) { $urls[] = html_entity_decode( $u, ENT_QUOTES, 'UTF-8' ); }
            }
        }
        $out = array();
        foreach ( $urls as $u ) {
            $u = trim( $u );
            if ( $u === '' ) { continue; }
            if ( strpos( $u, '//' ) === 0 ) { $u = 'https:' . $u; }
            if ( strpos( $u, 'http' ) !== 0 ) { continue; }
            if ( preg_match( '/(avatar|gravatar|emoji|icon|logo|pixel|placeholder|1x1|spacer|blank)/i', $u ) ) { continue; }
            if ( in_array( $u, $out, true ) ) { continue; }
            $out[] = $u;
            if ( count( $out ) >= 8 ) { break; }
        }
        return $out;
    }

    /** 均匀把配图插入正文段落 */
    private function insert_images( $content, $imgs ) {
        if ( empty( $imgs ) ) { return $content; }
        $html = array();
        foreach ( $imgs as $u ) {
            $html[] = '<p><img src="' . esc_url( $u ) . '" alt="" style="max-width:100%;height:auto;display:block;margin:1.2em auto;"></p>';
        }
        if ( ! preg_match_all( '/<\/p>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
            return implode( '', $html ) . $content;
        }
        $points = array();
        foreach ( $m[0] as $one ) { $points[] = $one[1] + 4; }
        $n = count( $html ); $total = count( $points );
        $inserts = array();
        for ( $i = 0; $i < $n; $i++ ) {
            $idx = (int) round( ( $i + 1 ) * $total / ( $n + 1 ) ) - 1;
            $idx = max( 0, min( $total - 1, $idx ) );
            $inserts[ $idx ] = isset( $inserts[ $idx ] ) ? $inserts[ $idx ] . $html[ $i ] : $html[ $i ];
        }
        $result = ''; $prev = 0;
        for ( $k = 0; $k < $total; $k++ ) {
            $result .= substr( $content, $prev, $points[ $k ] - $prev );
            if ( isset( $inserts[ $k ] ) ) { $result .= $inserts[ $k ]; }
            $prev = $points[ $k ];
        }
        return $result . substr( $content, $prev );
    }

    /* ------------------------------------------------------------------ *
     * AI 改写（OpenAI 兼容接口）
     * ------------------------------------------------------------------ */

    public function ai_rewrite( $title, $content, $override = array() ) {
        $api_key = isset( $override['api_key'] ) ? $override['api_key'] : $this->settings->get( 'api_key', '' );
        $api_base = isset( $override['api_base'] ) ? $override['api_base'] : $this->settings->get( 'api_base', '' );
        $model = isset( $override['model'] ) ? $override['model'] : $this->settings->get( 'model', '' );
        if ( trim( (string) $api_key ) === '' ) { return new WP_Error( 'no_key', '未配置 API Key' ); }

        $tpl = (string) $this->settings->get( 'prompt', '' );
        if ( trim( $tpl ) === '' ) { $tpl = AICP_Settings::default_prompt(); }
        $prompt = str_replace( array( '{title}', '{content}' ), array( $title, $content ), $tpl );

        $resp = wp_remote_post( $api_base, array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => $model,
                'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
                'temperature' => (float) $this->settings->get( 'temperature', 0.8 ),
                'max_tokens'  => (int) $this->settings->get( 'max_tokens', 3000 ),
                'response_format' => array( 'type' => 'json_object' ),
            ) ),
        ) );
        if ( is_wp_error( $resp ) ) { return $resp; }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body_raw = wp_remote_retrieve_body( $resp );
        $body = json_decode( $body_raw, true );
        if ( $code !== 200 || empty( $body['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'api', 'HTTP ' . $code . '：' . mb_substr( $body_raw, 0, 300 ) );
        }
        $txt = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $body['choices'][0]['message']['content'] ) );
        $data = json_decode( $txt, true );
        if ( ! is_array( $data ) ) { return new WP_Error( 'parse', 'AI 返回非 JSON：' . mb_substr( $txt, 0, 200 ) ); }
        if ( ! empty( $data['skip'] ) ) { return new WP_Error( 'skip', 'AI 判定跳过' ); }

        $new_title = isset( $data['title'] ) ? (string) $data['title'] : $title;
        $new_content = isset( $data['content'] ) ? (string) $data['content'] : '';
        if ( trim( $new_content ) === '' ) { return new WP_Error( 'parse', 'AI 返回正文为空' ); }

        $allowed = array(
            'p' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
            'strong' => array(), 'em' => array(), 'ul' => array(), 'ol' => array(),
            'li' => array(), 'br' => array(), 'blockquote' => array(),
        );
        $tags = array();
        if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
            $broad = array( '科技','游戏','网络','资讯','新闻','数码','技术','分享','推荐','测评','电脑','手机' );
            foreach ( $data['tags'] as $tg ) {
                if ( ! is_scalar( $tg ) ) { continue; }
                $tg = trim( sanitize_text_field( (string) $tg ), " \t\n\r\0\x0B#「」《》" );
                if ( $tg === '' || mb_strlen( $tg ) > 12 || in_array( $tg, $broad, true ) || in_array( $tg, $tags, true ) ) { continue; }
                $tags[] = $tg;
                if ( count( $tags ) >= 5 ) { break; }
            }
        }
        return array(
            'title'   => sanitize_text_field( $new_title ),
            'content' => wp_kses( $new_content, $allowed ),
            'tags'    => $tags,
        );
    }

    /** 供后台"测试连接"用：发一条极简请求验证密钥/接口 */
    public function test_api( $override = array() ) {
        $api_key = isset( $override['api_key'] ) ? $override['api_key'] : $this->settings->get( 'api_key', '' );
        $api_base = isset( $override['api_base'] ) ? $override['api_base'] : $this->settings->get( 'api_base', '' );
        $model = isset( $override['model'] ) ? $override['model'] : $this->settings->get( 'model', '' );
        $resp = wp_remote_post( $api_base, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model' => $model,
                'messages' => array( array( 'role' => 'user', 'content' => '只回复 OK 两个字母。' ) ),
                'max_tokens' => 10,
            ) ),
        ) );
        if ( is_wp_error( $resp ) ) { return new WP_Error( 'net', $resp->get_error_message() ); }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            return new WP_Error( 'http', 'HTTP ' . $code . '：' . mb_substr( wp_remote_retrieve_body( $resp ), 0, 200 ) );
        }
        return true;
    }

    private function log( $msg ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[AICP] ' . $msg );
        }
    }
}
