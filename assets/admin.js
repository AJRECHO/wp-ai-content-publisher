/* WP AI Content Publisher — 后台交互 */
(function ($) {
    'use strict';

    $(function () {
        // 测试 AI 连接
        $('#aicp-test-btn').on('click', function () {
            var $btn = $(this), $res = $('#aicp-test-result');
            $btn.prop('disabled', true);
            $res.removeClass('ok fail').text(AICP.i18n.testing);
            $.post(AICP.ajaxUrl, {
                action: 'aicp_test_api',
                _wpnonce: AICP.nonce
            }).done(function (r) {
                if (r && r.success) {
                    $res.addClass('ok').text(r.data.message || AICP.i18n.ok);
                } else {
                    $res.addClass('fail').text((r && r.data && r.data.message) || AICP.i18n.fail);
                }
            }).fail(function () {
                $res.addClass('fail').text(AICP.i18n.fail);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // 恢复默认提示词（二次确认）
        $('#aicp-restore-prompt').on('click', function () {
            if (!window.confirm('恢复默认提示词会覆盖你当前的修改，确定继续？')) { return; }
            $('<form>', {
                method: 'post',
                action: $('#aicp-restore-prompt').closest('form').attr('action')
            }).append($('<input>', { type: 'hidden', name: 'action', value: 'aicp_action' }))
              .append($('<input>', { type: 'hidden', name: 'aicp_do', value: 'restore_prompt' }))
              .append($('<input>', { type: 'hidden', name: 'aicp_tab', value: 'ai' }))
              .append($('<input>', { type: 'hidden', name: '_wpnonce', value: $('#aicp-restore-prompt').closest('form').find('input[name="_wpnonce"]').val() }))
              .appendTo('body').submit();
        });

        // 预览候选文章
        $('#aicp-preview-btn').on('click', function () {
            var $btn = $(this), $box = $('#aicp-preview-box'), $list = $('#aicp-preview-list');
            $btn.prop('disabled', true).text('抓取中…');
            $box.show();
            $list.html('<li>正在抓取 RSS，请稍候…</li>');
            $.post(AICP.ajaxUrl, {
                action: 'aicp_preview',
                _wpnonce: AICP.nonce
            }).done(function (r) {
                if (r && r.success && r.data.items) {
                    if (!r.data.items.length) {
                        $list.html('<li>没有可发布的候选（可能都发过了或无源）。</li>');
                        return;
                    }
                    var html = '';
                    $.each(r.data.items, function (_, it) {
                        html += '<li><a href="' + it.url + '" target="_blank">' + $('<div>').text(it.title).html() + '</a>' +
                            (it.date ? '<time>' + it.date + '</time>' : '') + '</li>';
                    });
                    $list.html(html);
                } else {
                    $list.html('<li>抓取失败：' + ((r && r.data && r.data.message) || '未知错误') + '</li>');
                }
            }).fail(function () {
                $list.html('<li>请求失败，请重试。</li>');
            }).always(function () {
                $btn.prop('disabled', false).text('预览候选文章');
            });
        });

        // 复制导出配置
        $('#aicp-copy-export').on('click', function () {
            var $ta = $('#aicp-export');
            $ta.select();
            try {
                document.execCommand('copy');
                $(this).text('已复制 ✓');
                var $b = $(this);
                setTimeout(function () { $b.text('复制配置 JSON'); }, 1800);
            } catch (e) {
                window.alert('复制失败，请手动选中复制。');
            }
        });

        // 图库候选：拒绝按钮同步当前勾选到拒绝表单
        $('#aicp-gal-reject-btn').on('click', function () {
            var $holder = $('#aicp-gal-reject-holder');
            $holder.empty();
            var n = 0;
            $('.aicp-gal-check:checked').each(function () {
                $holder.append($('<input>', { type: 'hidden', name: 'gal_ids[]', value: $(this).val() }));
                n++;
            });
            if (!n) { window.alert('请先勾选要拒绝的候选图。'); return; }
            if (!window.confirm('确定拒绝选中的 ' + n + ' 张候选？')) { return; }
            $('#aicp-gal-reject-form').submit();
        });

        // 删除文章（REST）
        $('.aicp-del').on('click', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!window.confirm('确定删除这篇文章及其配图？此操作不可恢复。')) { return; }
            var $link = $(this);
            $.ajax({
                url: AICP.restUrl + 'delete-post',
                method: 'POST',
                headers: { 'X-AICP-Key': AICP.restKey },
                data: { post_id: id }
            }).done(function () {
                $link.closest('tr').fadeOut(function () { $(this).remove(); });
            }).fail(function (xhr) {
                window.alert('删除失败：' + (xhr.responseJSON && xhr.responseJSON.message || '未知错误'));
            });
        });
    });
})(jQuery);
