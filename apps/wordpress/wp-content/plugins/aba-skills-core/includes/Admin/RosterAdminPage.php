<?php
/**
 * PA44 — Staff roster administration screen.
 *
 * AGENTS.md §2 permits WordPress administration screens (never the student /
 * parent application UI). This closes the gap the whole parent-link flow leaned
 * on: PA42/PA43 let a parent request a learner by code, but only the seeder ever
 * minted codes. Here a school staff member (or platform administrator) creates
 * real learners and issues their student codes.
 *
 * It is a thin shell over the permission-checked REST surface (GET
 * /aba/v1/school/context to read, POST /aba/v1/school/students to create), so the
 * screen cannot do anything the API would refuse: the caller's scope (which
 * school/class a learner lands in) is decided SERVER-SIDE from their own
 * assignment, never from a field here (§8). wp.apiFetch attaches the admin
 * session's X-WP-Nonce automatically, so the reviewer is authenticated by the
 * same gate any REST client faces.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class RosterAdminPage {
    private const SLUG = 'aba-roster';
    // The roster cap, not manage_options: a class teacher may manage their own
    // learners without being able to approve parent links.
    private const CAPABILITY = 'aba_manage_students';
    private const MENU_TITLE = 'ABA Quản lý học sinh';

    private static bool $registered = false;

    public static function init(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        \add_action('admin_menu', [self::class, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function registerMenu(): void {
        \add_menu_page(
            __('ABA Skills — Quản lý học sinh', 'aba-skills-core'),
            __('Quản lý học sinh', 'aba-skills-core'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'renderPage'],
            'dashicons-admin-users',
            57
        );
    }

    public static function enqueueAssets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::SLUG || !current_user_can(self::CAPABILITY)) {
            return;
        }
        \wp_enqueue_script('wp-api-fetch');
        \wp_add_inline_script('wp-api-fetch', self::inlineScript(), 'after');
    }

    public static function renderPage(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Bạn không có quyền truy cập màn hình này.', 'aba-skills-core'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p class="description">
                <?php esc_html_e('Tạo học sinh và cấp mã học sinh. Mã này là thứ phụ huynh nhập để xin liên kết (được duyệt ở màn hình “Duyệt liên kết”).', 'aba-skills-core'); ?>
            </p>

            <div id="aba-roster-notice" class="notice" style="display:none;"><p></p></div>

            <div id="aba-st-cred-box" class="notice notice-warning" style="display:none;">
                <p>
                    <strong><?php esc_html_e('Thông tin đăng nhập (chỉ hiện một lần):', 'aba-skills-core'); ?></strong>
                    <span id="aba-st-cred-text" style="font-family:Consolas,monospace;"></span>
                    <button type="button" class="button-link" id="aba-st-cred-close" style="margin-left:10px;"><?php esc_html_e('Đóng', 'aba-skills-core'); ?></button>
                </p>
            </div>

            <?php /* PA49 — append-only credential audit trail for one learner. */ ?>
            <div id="aba-st-audit-box" class="notice" style="display:none;">
                <p>
                    <strong id="aba-st-audit-title"></strong>
                    <button type="button" class="button-link" id="aba-st-audit-close" style="margin-left:10px;"><?php esc_html_e('Đóng', 'aba-skills-core'); ?></button>
                </p>
                <ul id="aba-st-audit-list" style="margin:0;"></ul>
            </div>

            <h2 id="aba-roster-scope" class="title" style="font-size:1.1em;"></h2>

            <h2 class="title" style="font-size:1.2em;margin-top:1.5em;"><?php esc_html_e('Thêm học sinh', 'aba-skills-core'); ?></h2>
            <table id="aba-roster-form" class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="aba-st-last"><?php esc_html_e('Họ', 'aba-skills-core'); ?></label></th>
                    <td><input type="text" class="regular-text" id="aba-st-last" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aba-st-first"><?php esc_html_e('Tên', 'aba-skills-core'); ?></label></th>
                    <td><input type="text" class="regular-text" id="aba-st-first" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aba-st-grade"><?php esc_html_e('Khối lớp (1–12)', 'aba-skills-core'); ?></label></th>
                    <td><input type="number" id="aba-st-grade" min="1" max="12" value="4" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aba-st-code"><?php esc_html_e('Mã học sinh (tuỳ chọn)', 'aba-skills-core'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="aba-st-code" placeholder="<?php esc_attr_e('Để trống sẽ tự sinh', 'aba-skills-core'); ?>">
                        <button type="button" class="button-link" id="aba-st-gen"><?php esc_html_e('Gợi ý mã', 'aba-skills-core'); ?></button>
                    </td>
                </tr>
                <tr id="aba-st-school-row" style="display:none;">
                    <th scope="row"><label for="aba-st-school"><?php esc_html_e('Trường', 'aba-skills-core'); ?></label></th>
                    <td><select id="aba-st-school"></select></td>
                </tr>
                <tr id="aba-st-class-row" style="display:none;">
                    <th scope="row"><label for="aba-st-class"><?php esc_html_e('Lớp', 'aba-skills-core'); ?></label></th>
                    <td><select id="aba-st-class"></select></td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td><button type="button" class="button button-primary" id="aba-st-create"><?php esc_html_e('Tạo học sinh', 'aba-skills-core'); ?></button></td>
                </tr>
            </table>

            <h2 class="title" style="font-size:1.2em;margin-top:1.5em;"><?php esc_html_e('Danh sách hiện có', 'aba-skills-core'); ?></h2>

            <div id="aba-st-edit-wrap" style="display:none;background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin-bottom:14px;">
                <h2 class="title" style="font-size:1.1em;margin-top:0;"><?php esc_html_e('Chỉnh sửa học sinh', 'aba-skills-core'); ?> <span id="aba-st-edit-code" style="font-weight:600;"></span></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aba-st-edit-last"><?php esc_html_e('Họ', 'aba-skills-core'); ?></label></th>
                        <td><input type="text" class="regular-text" id="aba-st-edit-last"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aba-st-edit-first"><?php esc_html_e('Tên', 'aba-skills-core'); ?></label></th>
                        <td><input type="text" class="regular-text" id="aba-st-edit-first"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aba-st-edit-grade"><?php esc_html_e('Khối lớp (1–12)', 'aba-skills-core'); ?></label></th>
                        <td><input type="number" id="aba-st-edit-grade" min="1" max="12"></td>
                    </tr>
                    <tr id="aba-st-edit-class-row" style="display:none;">
                        <th scope="row"><label for="aba-st-edit-class"><?php esc_html_e('Lớp', 'aba-skills-core'); ?></label></th>
                        <td><select id="aba-st-edit-class"></select></td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" class="button button-primary" id="aba-st-edit-save"><?php esc_html_e('Lưu', 'aba-skills-core'); ?></button>
                            <button type="button" class="button" id="aba-st-edit-cancel"><?php esc_html_e('Hủy', 'aba-skills-core'); ?></button>
                        </td>
                    </tr>
                </table>
            </div>

            <p>
                <input type="search" id="aba-st-search" placeholder="<?php esc_attr_e('Tìm theo mã hoặc tên…', 'aba-skills-core'); ?>" style="width:240px;">
                <select id="aba-st-filter"></select>
            </p>
            <table class="wp-list-table widefat fixed striped" id="aba-roster-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Mã học sinh', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Họ tên', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Khối', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Lớp', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('XP', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Trạng thái', 'aba-skills-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Thao tác', 'aba-skills-core'); ?></th>
                    </tr>
                </thead>
                <tbody id="aba-roster-body">
                    <tr><td colspan="7"><?php esc_html_e('Đang tải…', 'aba-skills-core'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Roster front-end. Names are written via textContent (never innerHTML with
     * data) so a crafted learner name cannot inject markup. The create call sends
     * only names/grade (+ destination when platform); scope is enforced server-side.
     */
    private static function inlineScript(): string {
        $strings = [
            'loading' => __('Đang tải…', 'aba-skills-core'),
            'empty' => __('Chưa có học sinh nào.', 'aba-skills-core'),
            'loadError' => __('Không tải được danh sách.', 'aba-skills-core'),
            'created' => __('Đã tạo học sinh %1$s (mã %2$s).', 'aba-skills-core'),
            'saved' => __('Đã lưu thay đổi cho %s.', 'aba-skills-core'),
            'failed' => __('Thao tác thất bại: %s', 'aba-skills-core'),
            'needName' => __('Vui lòng nhập họ và tên.', 'aba-skills-core'),
            'busy' => __('Đang tạo…', 'aba-skills-core'),
            'saving' => __('Đang lưu…', 'aba-skills-core'),
            'scopeClass' => __('Phạm vi của bạn: Lớp %1$s — %2$s', 'aba-skills-core'),
            'scopeSchool' => __('Phạm vi của bạn: Trường %s', 'aba-skills-core'),
            'scopePlatform' => __('Quản trị nền tảng: chọn trường để xem danh sách và tạo học sinh.', 'aba-skills-core'),
            'anyClass' => __('— Không gán lớp —', 'aba-skills-core'),
            'statusActive' => __('Đang học', 'aba-skills-core'),
            'statusInactive' => __('Đã ngưng', 'aba-skills-core'),
            'btnEdit' => __('Sửa', 'aba-skills-core'),
            'btnArchive' => __('Ngưng hoạt động', 'aba-skills-core'),
            'btnReactivate' => __('Kích hoạt lại', 'aba-skills-core'),
            'allClasses' => __('Tất cả lớp', 'aba-skills-core'),
            'noClass' => __('— Chưa có lớp —', 'aba-skills-core'),
            'btnCred' => __('Cấp tài khoản', 'aba-skills-core'),
            // PA47 — an already-issued login switches to an explicit, confirmed
            // reset so the primary button never silently rotates a live password.
            'btnCredReset' => __('Đặt lại mật khẩu', 'aba-skills-core'),
            'resetConfirm' => __('Đặt lại sẽ VÔ HIỆU mật khẩu cũ ngay lập tức và đăng xuất mọi thiết bị đang dùng tài khoản này — gia đình phải dùng mật khẩu mới để đăng nhập. Tiếp tục?', 'aba-skills-core'),
            'credRotated' => __('(Đã đặt lại — mật khẩu cũ không còn dùng được)', 'aba-skills-core'),
            // PA48 — a generated handout password is force-replaced at first login.
            'credMustChange' => __('Học sinh sẽ buộc đổi mật khẩu ở lần đăng nhập đầu.', 'aba-skills-core'),
            // PA51 — the rotation also ends live logins, and staff are told how many.
            'credSessions' => __('Đã đăng xuất %s phiên đang đăng nhập của tài khoản này.', 'aba-skills-core'),
            'credLine' => __('Tài khoản: %1$s — Mật khẩu: %2$s (đưa trực tiếp cho phụ huynh; mật khẩu không xem lại được).', 'aba-skills-core'),
            // PA49 — append-only credential audit trail (who issued/reset/changed, when).
            'btnAudit' => __('Lịch sử đăng nhập', 'aba-skills-core'),
            'auditTitle' => __('Lịch sử tài khoản của %s:', 'aba-skills-core'),
            'auditEmpty' => __('Chưa có sự kiện nào được ghi nhận.', 'aba-skills-core'),
            'auditLoading' => __('Đang tải…', 'aba-skills-core'),
            'auditError' => __('Không tải được lịch sử: %s', 'aba-skills-core'),
            'auditLine' => __('%1$s — %2$s (%3$s)', 'aba-skills-core'),
            'auditGenerated' => __('mật khẩu tự sinh, buộc đổi lần đầu', 'aba-skills-core'),
            'auditStaff' => __('mật khẩu do staff đặt', 'aba-skills-core'),
            'auditSelf' => __('tự đổi', 'aba-skills-core'),
            // PA50 — a rotation-policy lapse (no secret of the learner's own).
            'auditExpired' => __('quá hạn sử dụng, buộc đổi', 'aba-skills-core'),
        ];
        $config = [
            'contextPath' => '/aba/v1/school/context',
            'createPath' => '/aba/v1/school/students',
            'studentPath' => '/aba/v1/students',
            'strings' => $strings,
        ];
        $json = wp_json_encode($config);

        $js = <<<'JS'
(function () {
  var cfg = __ABA_CONFIG__;
  var s = cfg.strings;
  var apiFetch = window.wp && window.wp.apiFetch;
  if (!apiFetch) { return; }

  var body = document.getElementById('aba-roster-body');
  var scopeH = document.getElementById('aba-roster-scope');
  var notice = document.getElementById('aba-roster-notice');
  var lastI = document.getElementById('aba-st-last');
  var firstI = document.getElementById('aba-st-first');
  var gradeI = document.getElementById('aba-st-grade');
  var codeI = document.getElementById('aba-st-code');
  var genBtn = document.getElementById('aba-st-gen');
  var createBtn = document.getElementById('aba-st-create');
  var schoolRow = document.getElementById('aba-st-school-row');
  var classRow = document.getElementById('aba-st-class-row');
  var schoolSel = document.getElementById('aba-st-school');
  var classSel = document.getElementById('aba-st-class');
  var editWrap = document.getElementById('aba-st-edit-wrap');
  var editCode = document.getElementById('aba-st-edit-code');
  var editLast = document.getElementById('aba-st-edit-last');
  var editFirst = document.getElementById('aba-st-edit-first');
  var editGrade = document.getElementById('aba-st-edit-grade');
  var editClassRow = document.getElementById('aba-st-edit-class-row');
  var editClassSel = document.getElementById('aba-st-edit-class');
  var editSaveBtn = document.getElementById('aba-st-edit-save');
  var editCancelBtn = document.getElementById('aba-st-edit-cancel');
  var searchI = document.getElementById('aba-st-search');
  var filterSel = document.getElementById('aba-st-filter');
  var credBox = document.getElementById('aba-st-cred-box');
  var credText = document.getElementById('aba-st-cred-text');
  var credClose = document.getElementById('aba-st-cred-close');
  var auditBox = document.getElementById('aba-st-audit-box');
  var auditTitle = document.getElementById('aba-st-audit-title');
  var auditList = document.getElementById('aba-st-audit-list');
  var auditClose = document.getElementById('aba-st-audit-close');

  var ctx = null;
  var editingId = 0;
  var platformRosterLoaded = false; // guards the one auto-reload after first paint

  function fmt(str, map) {
    return str.replace(/%(\d)\$s|%s/g, function (m, n) {
      return map && map[n ? (n - 1) : 0] !== undefined ? map[n ? (n - 1) : 0] : '';
    });
  }
  function fmtTime(iso) {
    if (!iso) { return ''; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return iso; }
    return d.toLocaleString('vi-VN', { hour12: false });
  }
  function showNotice(text, isError) {
    notice.querySelector('p').textContent = text;
    notice.className = 'notice ' + (isError ? 'notice-error' : 'notice-success');
    notice.style.display = 'block';
    window.setTimeout(function () { notice.style.display = 'none'; }, 6000);
  }
  function cell(t) { var td = document.createElement('td'); td.textContent = (t == null) ? '' : String(t); return td; }
  function failText(err) { return fmt(s.failed, [(err && err.message) ? err.message : '?']); }

  // Class options the CURRENT actor may legitimately assign; null means the
  // actor is class-pinned and the selector is hidden — the server would ignore
  // a forged classId anyway (§8), the UI simply does not tempt one.
  function classOptionsForActor() {
    if (!ctx) { return null; }
    if (ctx.scope === 'platform') {
      var sid = parseInt(schoolSel.value, 10) || 0;
      var sch = (ctx.schools || []).filter(function (x) { return x.id === sid; })[0];
      return (sch && sch.classes) || [];
    }
    if (ctx.scope === 'school') {
      return (ctx.schools && ctx.schools[0] && ctx.schools[0].classes) || [];
    }
    return null;
  }

  function fillSelect(sel, list) {
    sel.innerHTML = '';
    var any = document.createElement('option'); any.value = '0'; any.textContent = s.anyClass; sel.appendChild(any);
    (list || []).forEach(function (c) {
      var o = document.createElement('option'); o.value = String(c.id); o.textContent = c.name; sel.appendChild(o);
    });
  }

  function matches(st) {
    var cls = filterSel.value;
    if (cls) {
      var key = st.classId ? String(st.classId) : '__none__';
      if (key !== cls) { return false; }
    }
    var q = searchI.value.trim().toLowerCase();
    if (!q) { return true; }
    return (((st.studentCode || '') + ' ' + (st.fullName || '')).toLowerCase().indexOf(q) !== -1);
  }

  function fillFilterOptions() {
    var prev = filterSel.value;
    filterSel.innerHTML = '';
    function mk(value, label) { var o = document.createElement('option'); o.value = value; o.textContent = label; filterSel.appendChild(o); }
    mk('', s.allClasses);
    var seen = {};
    ((ctx && ctx.students) || []).forEach(function (st) {
      var key = st.classId ? String(st.classId) : '__none__';
      if (seen[key]) { return; }
      seen[key] = 1;
      mk(key, st.classId ? (st.className || ('#' + st.classId)) : s.noClass);
    });
    filterSel.value = (prev === '' || seen[prev]) ? prev : '';
  }

  function renderStudent(st) {
    var tr = document.createElement('tr');
    tr.appendChild(cell(st.studentCode));
    tr.appendChild(cell(st.fullName));
    tr.appendChild(cell(st.gradeLevel));
    tr.appendChild(cell(st.className));
    tr.appendChild(cell(st.totalXp));
    tr.appendChild(cell(st.status === 'active' ? s.statusActive : s.statusInactive));
    var td = document.createElement('td');
    var be = document.createElement('button');
    be.type = 'button'; be.className = 'button button-small'; be.textContent = s.btnEdit;
    be.addEventListener('click', function () { openEdit(st); });
    var bt = document.createElement('button');
    bt.type = 'button'; bt.className = 'button button-small';
    bt.textContent = st.status === 'active' ? s.btnArchive : s.btnReactivate;
    bt.addEventListener('click', function () { toggleStatus(st, bt); });
    var bc = document.createElement('button');
    bc.type = 'button'; bc.className = 'button button-small';
    // PA47 — label reflects state: first-time issue vs explicit reset.
    bc.textContent = st.credentialIssuedAt ? s.btnCredReset : s.btnCred;
    bc.addEventListener('click', function () { provision(st, bc); });
    var ba = document.createElement('button');
    ba.type = 'button'; ba.className = 'button button-small';
    ba.textContent = s.btnAudit;
    ba.addEventListener('click', function () { showAudit(st); });
    td.appendChild(be); td.appendChild(document.createTextNode(' ')); td.appendChild(bt);
    td.appendChild(document.createTextNode(' ')); td.appendChild(bc);
    td.appendChild(document.createTextNode(' ')); td.appendChild(ba);
    tr.appendChild(td);
    return tr;
  }

  function renderRoster() {
    body.innerHTML = '';
    var list = ((ctx && ctx.students) || []).filter(matches);
    if (!list.length) {
      var e = document.createElement('tr'); var td = cell(s.empty); td.colSpan = 7; e.appendChild(td); body.appendChild(e); return;
    }
    list.forEach(function (st) { body.appendChild(renderStudent(st)); });
  }

  function buildSchoolSelect(schools) {
    var prev = schoolSel.value; // reload must not snap the picker back to school #1
    schoolSel.innerHTML = '';
    schools.forEach(function (sch) { var o = document.createElement('option'); o.value = String(sch.id); o.textContent = sch.name; schoolSel.appendChild(o); });
    var keep = prev && schools.filter(function (x) { return String(x.id) === prev; }).length ? prev : (schools[0] ? String(schools[0].id) : '');
    schoolSel.value = keep;
    var sc = schools.filter(function (x) { return String(x.id) === keep; })[0];
    fillSelect(classSel, sc && sc.classes);
  }

  function applyScope() {
    if (!ctx) { return; }
    if (ctx.scope === 'platform') {
      scopeH.textContent = s.scopePlatform;
      schoolRow.style.display = ''; classRow.style.display = '';
      buildSchoolSelect(ctx.schools || []);
    } else if (ctx.scope === 'class') {
      scopeH.textContent = fmt(s.scopeClass, [ctx.className || ('#' + ctx.classId), ctx.schoolName || ('#' + ctx.schoolId)]);
      schoolRow.style.display = 'none'; classRow.style.display = 'none';
    } else {
      scopeH.textContent = fmt(s.scopeSchool, [ctx.schoolName || ('#' + ctx.schoolId)]);
      schoolRow.style.display = 'none'; classRow.style.display = 'none';
    }
  }

  // PA45: a platform admin reads one school's roster; the destination picker
  // doubles as the view selector, so the chosen school rides along as a query
  // string (GET with a body is illegal — the PA43 lesson).
  function contextPath() {
    var sid = parseInt(schoolSel.value, 10) || 0;
    return sid > 0 ? cfg.contextPath + '?schoolId=' + encodeURIComponent(sid) : cfg.contextPath;
  }

  function load() {
    return apiFetch({ path: contextPath() })
      .then(function (data) {
        ctx = data;
        applyScope();
        fillFilterOptions();
        renderRoster();
        // First paint for a platform admin showed no roster (no school picked
        // yet); now that the selector defaults to one, fetch it exactly once.
        if (ctx.scope === 'platform' && !platformRosterLoaded && (ctx.schools || []).length) {
          platformRosterLoaded = true;
          load();
        }
      })
      .catch(function (err) {
        if (window.console && console.error) { console.error('[ABA roster] load failed', err); }
        body.innerHTML = ''; var e = document.createElement('tr'); var td = cell(s.loadError); td.colSpan = 7; e.appendChild(td); body.appendChild(e);
      });
  }

  function currentSchoolForCreate() {
    if (ctx && ctx.scope === 'platform') {
      var schoolId = parseInt(schoolSel.value, 10) || 0;
      var classId = parseInt(classSel.value, 10) || 0;
      return { schoolId: schoolId, classId: classId };
    }
    return {}; // staff: server derives scope from their own assignment (§8).
  }

  function create() {
    var last = lastI.value.trim(), first = firstI.value.trim();
    if (!last || !first) { showNotice(s.needName, true); return; }
    var payload = { lastName: last, firstName: first, gradeLevel: parseInt(gradeI.value, 10) || 0 };
    var code = codeI.value.trim(); if (code) { payload.studentCode = code; }
    var dest = currentSchoolForCreate();
    if (dest.schoolId) { payload.schoolId = dest.schoolId; }
    if (dest.classId) { payload.classId = dest.classId; }

    createBtn.disabled = true; var prev = createBtn.textContent; createBtn.textContent = s.busy;
    apiFetch({ path: cfg.createPath, method: 'POST', data: payload })
      .then(function (st) {
        showNotice(fmt(s.created, [st.fullName, st.studentCode]), false);
        lastI.value = ''; firstI.value = ''; codeI.value = '';
        load();
      })
      .catch(function (err) { showNotice(failText(err), true); })
      .finally(function () { createBtn.disabled = false; createBtn.textContent = prev; });
  }

  function openEdit(st) {
    editingId = st.id;
    editCode.textContent = '(' + st.studentCode + ')';
    editLast.value = st.lastName || '';
    editFirst.value = st.firstName || '';
    editGrade.value = st.gradeLevel || 1;
    var opts = classOptionsForActor();
    if (opts) {
      editClassRow.style.display = '';
      fillSelect(editClassSel, opts);
      editClassSel.value = String(st.classId || 0);
    } else {
      editClassRow.style.display = 'none';
    }
    editWrap.style.display = '';
  }
  function closeEdit() { editWrap.style.display = 'none'; editingId = 0; }

  function saveEdit() {
    if (!editingId) { return; }
    var payload = { lastName: editLast.value.trim(), firstName: editFirst.value.trim(), gradeLevel: parseInt(editGrade.value, 10) || 0 };
    if (!payload.lastName || !payload.firstName) { showNotice(s.needName, true); return; }
    if (editClassRow.style.display !== 'none') { payload.classId = parseInt(editClassSel.value, 10) || 0; }
    editSaveBtn.disabled = true; var prev = editSaveBtn.textContent; editSaveBtn.textContent = s.saving;
    apiFetch({ path: cfg.createPath + '/' + editingId, method: 'PUT', data: payload })
      .then(function (st) { showNotice(fmt(s.saved, [st.fullName]), false); closeEdit(); load(); })
      .catch(function (err) { showNotice(failText(err), true); })
      .finally(function () { editSaveBtn.disabled = false; editSaveBtn.textContent = prev; });
  }

  function toggleStatus(st, btn) {
    var next = st.status === 'active' ? 'inactive' : 'active';
    btn.disabled = true;
    apiFetch({ path: cfg.createPath + '/' + st.id + '/status', method: 'POST', data: { status: next } })
      .then(function () { load(); })
      .catch(function (err) { showNotice(failText(err), true); btn.disabled = false; });
  }

  // PA46 — issue the learner login. The plaintext password arrives only
  // in this response, so it is shown in a DISMISSIBLE box (never the 6s
  // auto-hiding notice) for staff to copy before it is gone.
  // PA47 — the same button is state-aware. A not-yet-issued learner gets a
  // quiet one-time issue; an already-issued one requires an EXPLICIT confirm
  // and sends rotate=true, so a stray click can never invalidate live creds.
  function provision(st, btn) {
    var isReset = !!st.credentialIssuedAt;
    if (isReset && !window.confirm(s.resetConfirm)) { return; }
    btn.disabled = true;
    apiFetch({ path: cfg.createPath + '/' + st.id + '/credentials', method: 'POST', data: isReset ? { rotate: true } : {} })
      .then(function (res) {
        var line = fmt(s.credLine, [res.username, res.password]);
        if (res.rotated) { line += ' ' + s.credRotated; }
        // PA48 — the learner must swap a generated password for a private one.
        if (res.mustChangePassword) { line += ' ' + s.credMustChange; }
        // PA51 — say out loud that devices holding the old secret were cut off.
        if (res.sessionsRevoked > 0) { line += ' ' + fmt(s.credSessions, [String(res.sessionsRevoked)]); }
        credText.textContent = line;
        credBox.style.display = 'block';
        load();
      })
      .catch(function (err) { showNotice(failText(err), true); })
      .finally(function () { btn.disabled = false; });
  }

  // PA49 — the learner's append-only credential audit trail. Same permission
  // gate as everywhere else on this screen (the server decides who may read
  // it, never the id in the URL, §8). Rendered via textContent, never
  // innerHTML with data, so a crafted display name cannot inject markup.
  function showAudit(st) {
    auditTitle.textContent = fmt(s.auditTitle, [st.fullName]);
    auditList.innerHTML = '';
    var loading = document.createElement('li'); loading.textContent = s.auditLoading;
    auditList.appendChild(loading);
    auditBox.style.display = 'block';
    apiFetch({ path: cfg.studentPath + '/' + st.id + '/credential-events' })
      .then(function (res) {
        auditList.innerHTML = '';
        var events = (res && res.events) || [];
        if (!events.length) {
          var empty = document.createElement('li'); empty.textContent = s.auditEmpty;
          auditList.appendChild(empty); return;
        }
        events.forEach(function (ev) {
          var li = document.createElement('li');
          var method = ev.action === 'credential_changed' ? s.auditSelf
            : ev.action === 'credential_expired' ? s.auditExpired
            : (ev.method === 'generated' ? s.auditGenerated : s.auditStaff);
          li.textContent = fmt(s.auditLine, [fmtTime(ev.createdAt), ev.actionLabel + ' — ' + ev.actorLabel, method]);
          auditList.appendChild(li);
        });
      })
      .catch(function (err) {
        auditList.innerHTML = '';
        var e = document.createElement('li');
        e.textContent = fmt(s.auditError, [(err && err.message) ? err.message : '?']);
        auditList.appendChild(e);
      });
  }

  schoolSel.addEventListener('change', function () {
    var sc = (ctx && ctx.schools || []).filter(function (x) { return String(x.id) === schoolSel.value; })[0];
    fillSelect(classSel, sc && sc.classes);
    load(); // platform: the same selector drives which roster is read.
  });
  genBtn.addEventListener('click', function () {
    if (!codeI.value.trim()) { codeI.value = 'ABA-' + (new Date().getFullYear()) + '-' + String(Math.floor(100 + Math.random() * 900)); }
  });
  createBtn.addEventListener('click', create);
  credClose.addEventListener('click', function () { credBox.style.display = 'none'; credText.textContent = ''; });
  auditClose.addEventListener('click', function () { auditBox.style.display = 'none'; auditTitle.textContent = ''; auditList.innerHTML = ''; });
  editSaveBtn.addEventListener('click', saveEdit);
  editCancelBtn.addEventListener('click', closeEdit);
  searchI.addEventListener('input', renderRoster);
  filterSel.addEventListener('change', renderRoster);
  document.addEventListener('DOMContentLoaded', load);
})();
JS;

        return str_replace('__ABA_CONFIG__', $json, $js);
    }
}
