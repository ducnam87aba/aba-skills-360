<?php
/**
 * PA43 — Administrator review screen for parent link requests.
 *
 * AGENTS.md §2 allows WordPress to host *administration* screens (never the
 * student/parent application UI). This closes the PA42 loop: a parent can submit
 * an "add a child" request, but until now the only way to approve it was a raw
 * REST call. This gives a real administrator a clickable queue.
 *
 * The screen is a thin shell: it renders nothing sensitive on the server and
 * drives the SAME permission-checked endpoints the REST layer already guards
 * (GET /aba/v1/admin/parent-link-requests to list, POST .../approve|.../reject
 * to decide — all requireLinkAdministrator, §8). wp.apiFetch attaches the admin
 * session's X-WP-Nonce automatically, so the cookie-authed reviewer satisfies
 * the same server-side gate any other caller would — no privileged path is
 * invented for the back office.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class LinkRequestsAdminPage {
    private const SLUG = 'aba-link-requests';
    private const CAPABILITY = 'manage_options';
    private const SCRIPT_HANDLE = 'aba-link-requests-admin';

    private static bool $registered = false;

    /** Register the admin hooks once. Safe to call from the plugin bootstrap. */
    public static function init(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        \add_action('admin_menu', [self::class, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function registerMenu(): void {
        // Capability (not a role name) gates visibility, mirroring the REST
        // permission callback so the menu never advertises a screen that 403s.
        \add_menu_page(
            __('ABA Skills — Duyệt liên kết phụ huynh', 'aba-skills-core'),
            __('ABA Skills', 'aba-skills-core'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'renderPage'],
            'dashicons-groups',
            58
        );
    }

    public static function enqueueAssets(string $hook): void {
        // Only load on our page (and only for a user who could open it).
        if ($hook !== 'toplevel_page_' . self::SLUG || !current_user_can(self::CAPABILITY)) {
            return;
        }
        // wp-api-fetch ships the authenticated REST client + nonce handling.
        \wp_enqueue_script('wp-api-fetch');
        \wp_add_inline_script('wp-api-fetch', self::inlineScript(), 'after');
    }

    /**
     * The page shell. Data is loaded client-side through the REST queue so the
     * screen reflects live server-side authorization rather than a cached render.
     */
    public static function renderPage(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Bạn không có quyền truy cập màn hình này.', 'aba-skills-core'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p class="description">
                <?php esc_html_e('Phụ huynh gửi yêu cầu liên kết con; chỉ quản trị nền tảng mới duyệt được. Duyệt ở đây ghi quyền truy cập — kiểm tra kỹ quan hệ trước khi chấp thuận.', 'aba-skills-core'); ?>
            </p>

            <p>
                <label for="aba-lr-status"><?php esc_html_e('Hiển thị:', 'aba-skills-core'); ?></label>
                <select id="aba-lr-status">
                    <option value="pending" selected><?php esc_html_e('Chờ duyệt', 'aba-skills-core'); ?></option>
                    <option value="approved"><?php esc_html_e('Đã duyệt', 'aba-skills-core'); ?></option>
                    <option value="rejected"><?php esc_html_e('Đã từ chối', 'aba-skills-core'); ?></option>
                    <option value="all"><?php esc_html_e('Tất cả', 'aba-skills-core'); ?></option>
                </select>
                <button type="button" class="button" id="aba-lr-refresh"><?php esc_html_e('Tải lại', 'aba-skills-core'); ?></button>
            </p>

            <div id="aba-lr-notice" class="notice" style="display:none;"><p></p></div>

            <table class="wp-list-table widefat fixed striped" id="aba-lr-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Phụ huynh', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Mã học sinh', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Học sinh', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Quan hệ', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Trạng thái', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Gửi lúc', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Hành động', 'aba-skills-core'); ?></th>
                    </tr>
                </thead>
                <tbody id="aba-lr-body">
                    <tr><td colspan="7"><?php esc_html_e('Đang tải…', 'aba-skills-core'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Front-end logic for the queue. Values are written with textContent (never
     * interpolated into HTML) so a learner/parent name cannot inject markup.
     */
    private static function inlineScript(): string {
        $rel_labels = [
            'father' => __('Cha', 'aba-skills-core'),
            'mother' => __('Mẹ', 'aba-skills-core'),
            'guardian' => __('Người giám hộ', 'aba-skills-core'),
            'parent' => __('Phụ huynh', 'aba-skills-core'),
        ];
        $status_labels = [
            'pending' => __('Chờ duyệt', 'aba-skills-core'),
            'approved' => __('Đã duyệt', 'aba-skills-core'),
            'rejected' => __('Đã từ chối', 'aba-skills-core'),
        ];
        $strings = [
            'loading' => __('Đang tải…', 'aba-skills-core'),
            'empty' => __('Không có yêu cầu nào.', 'aba-skills-core'),
            'error' => __('Không tải được danh sách.', 'aba-skills-core'),
            'approve' => __('Duyệt', 'aba-skills-core'),
            'reject' => __('Từ chối', 'aba-skills-core'),
            'done' => __('Đã cập nhật yêu cầu #%d.', 'aba-skills-core'),
            'failed' => __('Thao tác thất bại: %s', 'aba-skills-core'),
            'busy' => __('Đang xử lý…', 'aba-skills-core'),
        ];
        $config = [
            'listPath' => '/aba/v1/admin/parent-link-requests',
            'decisionBase' => '/aba/v1/parent/link-requests/',
            'relLabels' => $rel_labels,
            'statusLabels' => $status_labels,
            'strings' => $strings,
        ];

        $json = wp_json_encode($config);
        // Heredoc keeps the JS readable; %s placeholders are substituted above,
        // no PHP variables leak into the script body.
        $js = <<<'JS'
(function () {
  var cfg = __ABA_CONFIG__;
  var apiFetch = window.wp && window.wp.apiFetch;
  var body = document.getElementById('aba-lr-body');
  var statusSel = document.getElementById('aba-lr-status');
  var refreshBtn = document.getElementById('aba-lr-refresh');
  var notice = document.getElementById('aba-lr-notice');

  function showNotice(text, isError) {
    var p = notice.querySelector('p');
    p.textContent = text;
    notice.className = 'notice ' + (isError ? 'notice-error' : 'notice-success');
    notice.style.display = 'block';
    window.setTimeout(function () { notice.style.display = 'none'; }, 4000);
  }

  function cell(text) {
    var td = document.createElement('td');
    td.textContent = (text === null || text === undefined) ? '' : String(text);
    return td;
  }

  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? String(iso) : d.toLocaleString();
  }

  function makeButton(label, kind, id, disabled) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button button-' + (kind === 'approve' ? 'primary' : 'secondary');
    btn.textContent = label;
    btn.disabled = !!disabled;
    btn.addEventListener('click', function () { decide(id, kind, btn); });
    return btn;
  }

  function decide(id, action, btn) {
    var siblings = Array.prototype.slice.call(btn.parentNode.querySelectorAll('button'));
    siblings.forEach(function (b) { b.disabled = true; });
    btn.textContent = cfg.strings.busy;
    apiFetch({ path: cfg.decisionBase + id + '/' + action, method: 'POST' })
      .then(function () {
        showNotice(cfg.strings.done.replace('%d', id), false);
        load();
      })
      .catch(function (err) {
        showNotice(cfg.strings.failed.replace('%s', (err && err.message) ? err.message : '?'), true);
        siblings.forEach(function (b) { b.disabled = false; });
        btn.textContent = action === 'approve' ? cfg.strings.approve : cfg.strings.reject;
      });
  }

  function renderRow(r) {
    var tr = document.createElement('tr');
    var parentLabel = r.parentName || r.parentLogin || ('#' + r.parentUserId);
    tr.appendChild(cell(parentLabel));
    tr.appendChild(cell(r.studentCode));
    tr.appendChild(cell(r.studentName));
    tr.appendChild(cell(cfg.relLabels[r.relationship] || r.relationship));
    tr.appendChild(cell(cfg.statusLabels[r.status] || r.status));
    tr.appendChild(cell(fmtDate(r.createdAt)));

    var actions = document.createElement('td');
    if (r.status === 'pending') {
      actions.appendChild(makeButton(cfg.strings.approve, 'approve', r.id, false));
      actions.appendChild(document.createTextNode(' '));
      actions.appendChild(makeButton(cfg.strings.reject, 'reject', r.id, false));
    } else {
      actions.textContent = '—';
    }
    tr.appendChild(actions);
    return tr;
  }

  function load() {
    body.innerHTML = '';
    var loading = document.createElement('tr');
    var td = cell(cfg.strings.loading);
    td.colSpan = 7;
    loading.appendChild(td);
    body.appendChild(loading);

    return apiFetch({ path: cfg.listPath + '?status=' + encodeURIComponent(statusSel.value) })
      .then(function (rows) {
        body.innerHTML = '';
        if (!rows || !rows.length) {
          var empty = document.createElement('tr');
          var etd = cell(cfg.strings.empty);
          etd.colSpan = 7;
          empty.appendChild(etd);
          body.appendChild(empty);
          return;
        }
        rows.forEach(function (r) { body.appendChild(renderRow(r)); });
      })
      .catch(function (loadErr) {
        // Surface the real cause in the console — a silent .catch() here once
        // hid a GET-with-body fetch failure that only showed as a blank table.
        if (window.console && window.console.error) { window.console.error('[ABA link requests] load failed', loadErr); }
        body.innerHTML = '';
        var errRow = document.createElement('tr');
        var etd2 = cell(cfg.strings.error);
        etd2.colSpan = 7;
        errRow.appendChild(etd2);
        body.appendChild(errRow);
      });
  }

  if (!apiFetch) { return; }
  statusSel.addEventListener('change', load);
  refreshBtn.addEventListener('click', load);
  document.addEventListener('DOMContentLoaded', load);
})();
JS;

        return str_replace('__ABA_CONFIG__', $json, $js);
    }
}
