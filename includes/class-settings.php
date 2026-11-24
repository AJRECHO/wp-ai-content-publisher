<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * 配置中心：所有可配置项集中定义、读取、校验、保存。
 * 统一存在 wp_options 的一条记录里（autoload=no），便于导出/导入。
 */
class AICP_Settings {

    const OPT = 'aicp_settings';
    const OPT_LOG  = 'aicp_publish_log';
    const OPT_USED = 'aicp_used_urls';
    const OPT_ROT  = 'aicp_rotation';
    const OPT_PUBTIME = 'aicp_pub_time';

    /** 默认改写提示词（用户可在后台恢复到此值） */
    public static function default_prompt() {
        return "你是一位面向博客读者、程序员和网络技术爱好者的中文科技作者。请把下面这篇素材整理成一篇通顺实用的中文文章。\n"
            . "要求：\n"
            . "1. 忠实搬运 + 润色：以原文内容为准，只做语句通顺化、段落重组和必要补充说明，让文章读起来自然流畅。严禁编造原文没有的事实、数据或情节；严禁添加任何个人评价、主观判断或\"我认为/值得一买/值得关注\"这类评述语句。\n"
            . "2. 不写成新闻通稿口吻，禁止「据外媒报道」「日前」「记者获悉」「最新消息」「小编」等新闻腔；用平实、中立、客观的陈述语气。\n"
            . "3. 如果是外文，先准确翻译成中文；最终输出必须是地道中文，不得保留外文整句。\n"
            . "4. 不使用第一人称，可适度加小标题。\n"
            . "5. 标题信息密度要高：包含具体产品名/型号、关键数字或核心结论；技术名、公司名用「」或《》标注并准确保留。\n"
            . "6. 正文详实：交代清楚背景、规格参数、数据、来龙去脉和看点，多用原文的具体信息。\n"
            . "7. 篇幅 800-1300 字。\n"
            . "8. 【红线】若原文涉及政治、时政、军事冲突、选举、政府官员，或色情、赌博、毒品等违法违规内容，只输出 {\"skip\":true}。\n"
            . "9. 输出 3-5 个精准标签（文章核心主题词，2-6 个字），不要用「科技」「资讯」这类宽泛词。\n"
            . "10. 只输出 JSON，格式：{\"title\":\"新标题\",\"content\":\"正文HTML，用 <p> 和 <h3> 标签\",\"tags\":[\"标签1\",\"标签2\"]}，不要输出其他文字。\n\n"
            . "原文标题：{title}\n\n原文内容：\n{content}";
    }

    /** 默认红线/低质词表（标题命中即淘汰，每行一个），用户可编辑 */
    public static function default_blacklist() {
        return implode( "\n", array(
            // 政治时政
            '政治', '总统', '大选', '选举', '政府', '国会', '制裁', '战争', '开战', '军事', '导弹',
            'election', 'president', 'congress', 'geopolit', 'sanction', 'war ',
            // 黄赌毒
            '色情', '成人', '裸', '赌博', '赌场', '博彩', '毒品', '吸毒', '大麻',
            'porn', ' xxx', 'gambl', 'casino', ' drug', 'cocaine', 'marijuana',
            // 新闻快讯
            '快讯', '简讯', '突发', '刚刚', '据外媒', '外媒报道', '消息称', '据悉', '最新消息',
            'breaking', ' just in', 'reportedly',
            // 低价值
            '早报', '周报', '发版', '更新公告', 'release notes', 'changelog',
            '求助', '请问', '怎么解决', '求推荐', '问卷', '有奖', 'giveaway',
        ) );
    }

    /** 默认 RSS 源（按分类，每行一个 URL） */
    public static function default_feeds() {
        return array(
            '科技' => "https://sspai.com/feed\nhttps://www.ifanr.com/feed\nhttps://blog.cloudflare.com/rss/\nhttps://news.ycombinator.com/rss",
            '数码' => "https://sspai.com/feed\nhttps://www.appinn.com/feed/",
            '开发' => "https://www.oschina.net/news/rss\nhttps://news.ycombinator.com/rss\nhttps://blog.cloudflare.com/rss/",
        );
    }

    /** 全部默认配置 */
    public static function defaults() {
        return array(
            // AI 接口
            'api_key'      => '',
            'api_base'     => 'https://api.deepseek.com/v1/chat/completions',
            'model'        => 'deepseek-chat',
            'temperature'  => 0.8,
            'max_tokens'   => 3000,
            'prompt'       => self::default_prompt(),

            // 发布规则
            'daily_limit'   => 1,
            'max_age'       => 7,           // 时效天数，0=不限
            'publish_mode'  => 'random',    // manual | random | fixed
            'random_start'  => '06:00',
            'random_end'    => '23:55',
            'fixed_time'    => '20:00',
            'offpeak'       => 1,           // 是否只在谷时发（DeepSeek 5 折时段）
            'peak_ranges'   => "09:00-12:00\n14:00-18:00", // 高峰期（禁用发布）
            'timezone'      => 'Asia/Shanghai',
            'post_status'   => 'publish',   // publish | draft
            'author_id'     => 0,           // 0=当前登录管理员

            // 内容源
            'categories'   => array( '科技', '数码', '开发' ), // 参与轮换的分类
            'feeds'        => self::default_feeds(),

            // 图片与封面
            'image_mode'    => 'media',     // media=WP媒体库 | custom=自定义目录
            'image_dir'     => '',          // 自定义目录绝对路径
            'image_url_base'=> '',          // 自定义目录公开 URL 前缀
            'cover_mode'    => 'source',    // source=原文图 | custom=自定义图库 | none=不要封面
            'cover_urls'    => '',          // 自定义封面图 URL（每行一个）

            // 过滤
            'blacklist'     => self::default_blacklist(),
            'min_body_len'  => 200,         // 正文最短字数，太短跳过
            'require_image' => 1,           // 必须有封面才发布

            // 文末版权
            'footer'        => '<hr><p style="font-size:0.9em;color:#888;">本文搬运自 <a href="{url}" target="_blank" rel="noopener">《{title}》</a>（来源：{host}），仅作信息分享，版权归原作者所有。</p>',

            // 系统
            'rest_key'      => '',          // 首次 bootstrap 自动生成
        );
    }

    /** 读取全部配置（与默认值合并，保证新增字段有值） */
    public function all() {
        $saved = get_option( self::OPT, array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        return array_merge( self::defaults(), $saved );
    }

    /** 读取单个配置项 */
    public function get( $key, $fallback = null ) {
        $all = $this->all();
        if ( array_key_exists( $key, $all ) ) { return $all[ $key ]; }
        return $fallback;
    }

    /** 首次启用时初始化（生成 REST 密钥） */
    public function maybe_bootstrap() {
        $saved = get_option( self::OPT, array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        if ( empty( $saved['rest_key'] ) ) {
            $saved['rest_key'] = $this->gen_key();
            update_option( self::OPT, $saved, false );
        }
    }

    /** 生成随机密钥 */
    public function gen_key() {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 32, false, false );
        }
        return bin2hex( random_bytes( 16 ) );
    }

    /** 保存配置：从 $_POST 清洗后合并写回。返回保存后的全部配置。 */
    public function save_from_post( $post ) {
        $cur  = $this->all();
        $next = $cur;

        // 文本类
        foreach ( array( 'api_base','model','random_start','random_end','fixed_time','peak_ranges','timezone','image_dir','image_url_base','cover_urls','blacklist','footer' ) as $k ) {
            if ( isset( $post[ $k ] ) ) {
                $next[ $k ] = is_string( $post[ $k ] ) ? sanitize_text_field( wp_unslash( $post[ $k ] ) ) : $cur[ $k ];
                // peak_ranges / blacklist / cover_urls 需保留换行
                if ( in_array( $k, array( 'peak_ranges','blacklist','cover_urls' ), true ) && isset( $post[ $k ] ) ) {
                    $next[ $k ] = sanitize_textarea_field( wp_unslash( $post[ $k ] ) );
                }
            }
        }

        // 密码/密钥类（不清洗特殊字符，但限制长度）
        if ( isset( $post['api_key'] ) ) {
            $v = trim( (string) wp_unslash( $post['api_key'] ) );
            $next['api_key'] = mb_substr( $v, 0, 300 );
        }
        if ( isset( $post['rest_key'] ) ) {
            $v = trim( (string) wp_unslash( $post['rest_key'] ) );
            if ( $v !== '' ) { $next['rest_key'] = mb_substr( $v, 0, 100 ); }
        }

        // prompt / feeds 用 textarea 保留换行
        if ( isset( $post['prompt'] ) ) {
            $next['prompt'] = sanitize_textarea_field( wp_unslash( $post['prompt'] ) );
        }
        if ( isset( $post['feeds'] ) && is_array( $post['feeds'] ) ) {
            $feeds = array();
            foreach ( $post['feeds'] as $cat => $body ) {
                $cat = sanitize_text_field( (string) $cat );
                $feeds[ $cat ] = sanitize_textarea_field( wp_unslash( $body ) );
            }
            $next['feeds'] = $feeds;
        }
        if ( isset( $post['categories'] ) && is_array( $post['categories'] ) ) {
            $cats = array();
            foreach ( $post['categories'] as $c ) {
                $c = sanitize_text_field( (string) $c );
                if ( $c !== '' ) { $cats[] = $c; }
            }
            $next['categories'] = array_values( array_unique( $cats ) );
        }

        // 数字类
        $nums = array(
            'temperature'  => array( 0, 2,   0.8  ),
            'max_tokens'   => array( 100, 32000, 3000 ),
            'daily_limit'  => array( 0, 50,  1    ),
            'max_age'      => array( 0, 365, 7    ),
            'min_body_len' => array( 0, 5000, 200 ),
            'author_id'    => array( 0, 999999, 0 ),
        );
        foreach ( $nums as $k => $rule ) {
            if ( isset( $post[ $k ] ) ) {
                $v = (float) $post[ $k ];
                $v = max( $rule[0], min( $rule[1], $v ) );
                $next[ $k ] = ( $k === 'temperature' ) ? round( $v, 2 ) : (int) $v;
            }
        }

        // 枚举类
        $enums = array(
            'publish_mode' => array( 'manual','random','fixed' ),
            'post_status'  => array( 'publish','draft' ),
            'image_mode'   => array( 'media','custom' ),
            'cover_mode'   => array( 'source','custom','none' ),
        );
        foreach ( $enums as $k => $allowed ) {
            if ( isset( $post[ $k ] ) && in_array( $post[ $k ], $allowed, true ) ) {
                $next[ $k ] = $post[ $k ];
            }
        }

        // 开关类：只处理本表单声明过的开关（避免跨标签保存时误清未展示的开关）
        $touched = array();
        if ( isset( $post['aicp_touched'] ) ) {
            $touched = array_map( 'sanitize_key', explode( ',', (string) $post['aicp_touched'] ) );
        }
        foreach ( array( 'offpeak','require_image' ) as $k ) {
            if ( in_array( $k, $touched, true ) ) {
                $next[ $k ] = isset( $post[ $k ] ) ? 1 : 0;
            }
        }

        // 时间格式校验 HH:MM
        foreach ( array( 'random_start','random_end','fixed_time' ) as $k ) {
            if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $next[ $k ] ) ) {
                $next[ $k ] = self::defaults()[ $k ];
            }
        }

        update_option( self::OPT, $next, false );
        return $next;
    }

    /** 导出配置为 JSON 字符串（不含 API Key，避免误分享泄露） */
    public function export_json() {
        $all = $this->all();
        $all['api_key']  = ''; // 安全：导出不含密钥
        $all['rest_key'] = '';
        $all['_exported_at'] = current_time( 'mysql' );
        $all['_version'] = AICP_VERSION;
        return wp_json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    /** 从 JSON 导入配置（不含密钥字段，避免覆盖现有密钥） */
    public function import_json( $json ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', '无效的 JSON 格式' );
        }
        $cur = $this->all();
        $merge = array();
        foreach ( $cur as $k => $v ) {
            if ( array_key_exists( $k, $data ) && ! in_array( $k, array( 'api_key','rest_key' ), true ) ) {
                $merge[ $k ] = $data[ $k ];
            } else {
                $merge[ $k ] = $v;
            }
        }
        update_option( self::OPT, $merge, false );
        return true;
    }

    /** API Key 是否已配置 */
    public function has_api_key() {
        $k = $this->get( 'api_key', '' );
        return is_string( $k ) && trim( $k ) !== '';
    }

    /** 打码显示密钥 */
    public static function mask_key( $key ) {
        $key = (string) $key;
        if ( $key === '' ) { return ''; }
        $len = strlen( $key );
        if ( $len <= 8 ) { return str_repeat( '*', $len ); }
        return substr( $key, 0, 4 ) . str_repeat( '*', min( 16, $len - 8 ) ) . substr( $key, -4 );
    }
}
