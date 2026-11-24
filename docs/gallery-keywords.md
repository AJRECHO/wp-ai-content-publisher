# 封面补货：pixiv 搜索标签调优记录

> 适用版本：1.1.0+（封面图库自动补货）
> 关联文件：`includes/class-gallery.php`（`default_options()` / `search_candidates()` / `refine()`）

这份文档记录一次真实的补货失败排查，以及以后**怎么换标签、为什么这样换**。改标签前先看这里，别再重复踩坑。

---

## 一、当时出了什么问题

后台补货日志频繁出现：

```
精筛后无合格图；粗筛 99 张，淘汰原因：width_or_orientation=71 ...
```

看起来像"闸门太严"，但把日志拆开看，71 张倒在了 `width_or_orientation` 这道闸。

## 二、根因（别再误判成接口 bug）

当时的两个怀疑都被实测排除：

1. **不是搜索接口和详情接口数据打架**
   抽样 39 张候选，逐张对比两个接口返回的 `width/height`：**39 张全部一致**。

2. **不是竖屏图太多**
   抽样中竖屏 0 张——粗筛的"宽 > 高"判断是好的。

**真正原因**：这些是 pixiv 早年作品，分辨率太低。抽样尺寸集中在 `250×200`、`640×480`、`800×600`、`1024×768`，其中 **31/39 张宽度 <900px**，被精筛的 `min_width` 挡掉。

进一步对比标签，发现**标签本身就决定了出图质量**：

| 标签 | 纯手绘 | 横屏 | 宽度≥900 可用 |
|---|---:|---:|---:|
| `風景1000users入り` | 58 | 37 | **27** |
| `風景500users入り` | 59 | 41 | **29** |
| `背景1000users入り` | 7 | 3 | 3 |
| `風景`（旧标签） | 59 | 30 | **4** ← 主因 |

同样是搜"风景"，普通标签搜出来 60 张只有 4 张能用，高收藏标签有 27~29 张——**差 7 倍**。所以问题不在闸门参数，在图源。

## 三、当前生效的标签

写在 `includes/class-gallery.php` 的 `default_options()`：

```
風景1000users入り
風景500users入り
背景1000users入り
風景
背景
```

前三个是主力（高收藏 = 高清），后两个是低优先级兜底。搜索时插件会 `shuffle` 打乱顺序轮流抓，所以每批候选来源不固定。

> 注意：`背景1000users入り` 总量少（每页只有 7 张），但里面出过 `1920×1080` 的图，质量很高，保留作补充。

## 四、以后怎么改（重要：两处都要改）

### 1. 后台改（立即生效，推荐）

后台 →「WP AI 内容发布」→「图片与封面」→「封面图库自动补货」→ **搜索标签**，一行一个，保存。

这是最容易踩的坑：**站点已经保存过配置时，改代码里的默认值完全没用**——`get_option()` 拿到的是存下来的旧值。所以：

- 已部署的站点调参 → 改后台表单（写入 `aicp_gallery_options.keywords`）
- 改代码默认值 → 只影响**全新安装**的站点；已有站点还是要去后台改一次

### 2. 代码改默认值（影响新安装）

编辑 `includes/class-gallery.php` 的 `default_options()`：

```php
'keywords' => "風景1000users入り\n風景500users入り\n背景1000users入り\n風景\n背景",
```

改完新装的站点就用新标签。

## 五、怎么挑新标签（实测方法）

不要凭感觉加标签。用下面这套判断：

- **优先 `XXXNusers入り` 形式**：pixiv 的"被 N 人收藏"标签，天然过滤掉冷门低清图。常用 `1000users入り`、`500users入り`。
- **先看产出率再加**：搜一次，数一下"纯手绘 + 横屏 + 宽度≥900"有几张。60 张里低于 10 张可用就别加。
- **热门榜不能用**：`order=popular` 的结果几乎全是 AI 生成图，插件固定用 `order=date`。
- **实测无数据的标签**：`青空1000users入り`、`星空1000users入り`、`雪山1000users入り`、`桜1000users入り`（要么无结果，要么无横屏），别加。
- **别加太多标签**：`search_candidates()` 会 `shuffle` 后逐个搜，直到凑够 `batch * 12` 张粗筛候选就停。塞一堆低产标签会拖慢补货。

### 快速产出率探测（在站点环境跑）

临时 PHP 放到 WordPress 根目录，浏览器访问或 CLI 执行，跑完删掉：

```php
<?php
require_once __DIR__ . '/wp-load.php';   // 按需改成你的 WordPress 根目录
$tags = array('風景1000users入り', '風景500users入り', '風景');
foreach ($tags as $tag) {
    $q = rawurlencode($tag);
    $r = wp_remote_get(
        'https://www.pixiv.net/ajax/search/illustrations/' . $q . '?word=' . $q . '&mode=safe&order=date&p=1',
        array('timeout' => 20, 'headers' => array(
            'User-Agent' => 'Mozilla/5.0',
            'Referer' => 'https://www.pixiv.net/',
            'Accept' => 'application/json',
        ))
    );
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) continue;
    $j = json_decode(wp_remote_retrieve_body($r), true);
    $ok = 0;
    foreach ((array) ($j['body']['illust']['data'] ?? array()) as $it) {
        if ((int) ($it['aiType'] ?? -1) !== 0) continue;          // 纯手绘
        if ((int) ($it['illustType'] ?? -1) !== 0) continue;      // 插画
        if ((int) ($it['pageCount'] ?? 0) !== 1) continue;        // 单页
        $w = (int) ($it['width'] ?? 0); $h = (int) ($it['height'] ?? 0);
        if ($w <= $h || $w < 900) continue;                       // 横屏 + 宽度
        $ok++;
    }
    echo $tag . ' => 可用 ' . $ok . " / 60\n";
}
```

> 路径按需改；这段只做统计，不下载图、不写数据库。

## 六、改动后的验证

换完标签后，点后台「立即抓一批候选」，看结果：

- **成功**：`补货完成：转存 8 张，待审核 8 张`，日志 `粗筛 N，合格 8，转存成功 8`
- **失败**：日志会明确列出每道闸门淘汰了多少张（`width_or_orientation=` / `bookmarks=` / `bad_word=` 等），按最大那项调对应参数或换标签

一次换标签后的实测：粗筛 127 张 → 合格 8 张 → 转存 8 张（之前经常 0 张）。
