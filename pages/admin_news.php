<?php
/**
 * Admin News — create, edit, publish/unpublish, delete news posts.
 *
 *   /admin_news              → list (also accessible via admin_dashboard "News" tab)
 *   /admin_news?new=1        → blank create form
 *   /admin_news?id={id}      → edit existing post
 *
 * Posts are Markdown-bodied. Slug is auto-derived from title on first save,
 * but admins can override it. Saving sets `published_at` only when the post
 * is marked published — drafts keep it null.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/news.php';

// ─── GM 9+ guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$_gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$_gm->execute(['id' => $_SESSION['user_id']]);
$_gm_level = (int)($_gm->fetchColumn() ?: 0);
if ($_gm_level < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $_gm_level;

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

$errors  = [];
$success = '';

// ─── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id      = (int)($_POST['id'] ?? 0);
            $title   = trim((string)($_POST['title']   ?? ''));
            $slug_in = trim((string)($_POST['slug']    ?? ''));
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $body    = (string)($_POST['body'] ?? '');
            $icon    = trim((string)($_POST['icon']    ?? 'bi-megaphone'));
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $pub_in  = trim((string)($_POST['published_at'] ?? ''));

            if ($title === '')          $errors[] = $TEXT['news_err_title']  ?? 'Title is required.';
            if (mb_strlen($title) > 200) $errors[] = $TEXT['news_err_title_long'] ?? 'Title too long (max 200).';
            if (trim($body) === '')     $errors[] = $TEXT['news_err_body']   ?? 'Body is required.';
            if (mb_strlen($body) > 100000) $errors[] = $TEXT['news_err_body_long'] ?? 'Body too long.';
            if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
                $errors[] = $TEXT['news_err_icon'] ?? 'Icon must be a Bootstrap-Icons class (e.g. bi-megaphone).';
            }

            // Resolve slug
            $base = $slug_in !== '' ? news_slugify($slug_in) : news_slugify($title);
            $slug = news_unique_slug($pdo_auth, $base, $id ?: null);

            // Resolve published_at: explicit datetime-local input, else NOW() on publish, else NULL on draft
            $published_at = null;
            if ($status === 'published') {
                if ($pub_in !== '') {
                    $ts = strtotime($pub_in);
                    if ($ts !== false && $ts > 0) {
                        $published_at = date('Y-m-d H:i:s', $ts);
                    }
                }
                if ($published_at === null) $published_at = date('Y-m-d H:i:s');
            }

            if (empty($errors)) {
                try {
                    if ($id > 0) {
                        $stmt = $pdo_auth->prepare(
                            "UPDATE news_posts
                             SET slug=:slug, title=:title, excerpt=:excerpt, body=:body, icon=:icon,
                                 status=:status, published_at=:pub
                             WHERE id=:id"
                        );
                        $stmt->execute([
                            'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt ?: null,
                            'body' => $body, 'icon' => $icon ?: 'bi-megaphone',
                            'status' => $status, 'pub' => $published_at, 'id' => $id,
                        ]);
                        log_admin_action($pdo_auth, $admin_id, $admin_name, 'news_update', "id:$id ($slug)", "Status: $status", null);
                        $success = $TEXT['news_saved'] ?? 'Post saved.';
                    } else {
                        $stmt = $pdo_auth->prepare(
                            "INSERT INTO news_posts (slug, title, excerpt, body, icon, author_id, author_name, status, published_at)
                             VALUES (:slug, :title, :excerpt, :body, :icon, :aid, :aname, :status, :pub)"
                        );
                        $stmt->execute([
                            'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt ?: null,
                            'body' => $body, 'icon' => $icon ?: 'bi-megaphone',
                            'aid' => $admin_id, 'aname' => $admin_name,
                            'status' => $status, 'pub' => $published_at,
                        ]);
                        $id = (int)$pdo_auth->lastInsertId();
                        log_admin_action($pdo_auth, $admin_id, $admin_name, 'news_create', "id:$id ($slug)", "Status: $status", null);
                        $success = $TEXT['news_created'] ?? 'Post created.';
                    }
                    // Redirect back to edit on success so refresh doesn't re-save
                    header('Location: /admin_news?id=' . $id . '&saved=1');
                    exit;
                } catch (PDOException $e) {
                    error_log('admin_news save failed: ' . $e->getMessage());
                    $errors[] = $TEXT['news_err_save'] ?? 'Could not save post.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $info = $pdo_auth->prepare("SELECT slug FROM news_posts WHERE id = :id");
                    $info->execute(['id' => $id]);
                    $del_slug = $info->fetchColumn() ?: ('id:' . $id);

                    $del = $pdo_auth->prepare("DELETE FROM news_posts WHERE id = :id");
                    $del->execute(['id' => $id]);
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'news_delete', "id:$id ($del_slug)", null, null);
                } catch (PDOException $e) {
                    error_log('admin_news delete failed: ' . $e->getMessage());
                }
            }
            header('Location: /admin_news?deleted=1');
            exit;
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $cur = $pdo_auth->prepare("SELECT status, slug FROM news_posts WHERE id = :id");
                    $cur->execute(['id' => $id]);
                    $row = $cur->fetch();
                    if ($row) {
                        $new_status = $row['status'] === 'published' ? 'draft' : 'published';
                        if ($new_status === 'published') {
                            $upd = $pdo_auth->prepare("UPDATE news_posts SET status='published', published_at = COALESCE(published_at, NOW()) WHERE id = :id");
                        } else {
                            $upd = $pdo_auth->prepare("UPDATE news_posts SET status='draft' WHERE id = :id");
                        }
                        $upd->execute(['id' => $id]);
                        log_admin_action($pdo_auth, $admin_id, $admin_name, 'news_toggle', "id:$id ({$row['slug']})", "→ $new_status", null);
                    }
                } catch (PDOException $e) {
                    error_log('admin_news toggle failed: ' . $e->getMessage());
                }
            }
            header('Location: /admin_news');
            exit;
        }
    }
}

// ─── GET: render list or form ───────────────────────────────────────────────
$mode = 'list';
$post = null;
$edit_id = (int)($_GET['id'] ?? 0);
$is_new  = !empty($_GET['new']);

if ($edit_id > 0) {
    $stmt = $pdo_auth->prepare("SELECT * FROM news_posts WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($post) $mode = 'edit';
} elseif ($is_new) {
    $mode = 'edit';
    $post = ['id'=>0,'slug'=>'','title'=>'','excerpt'=>'','body'=>'','icon'=>'bi-megaphone','status'=>'draft','published_at'=>null];
}

$page_title = ($TEXT['news_admin_title'] ?? 'Manage News') . ' — ' . ($config['site']['title'] ?? 'WoW');
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();
?>

<style>
.news-admin-wrap { padding-top:120px; padding-bottom:3rem; }
.news-admin-card { background:linear-gradient(145deg,#15151f,#0e0e17); border:1px solid rgba(139,69,19,.3); border-radius:8px; padding:1.5rem; margin-bottom:1.5rem; }
.news-admin-input,
.news-admin-textarea,
.news-admin-select {
    width:100%; padding:.6rem .8rem; background:#0a0a0f; border:1px solid rgba(139,69,19,.3);
    border-radius:4px; color:#fff; font-size:.95rem; font-family:inherit;
}
.news-admin-textarea { font-family: 'SFMono-Regular',Consolas,monospace; font-size:.9rem; line-height:1.5; }
.news-admin-input:focus, .news-admin-textarea:focus, .news-admin-select:focus { outline:none; border-color:#c8a96e; }
.news-admin-label { display:block; font-size:.78rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.3rem; }
.news-admin-btn { padding:.5rem 1.1rem; border-radius:4px; border:1px solid; cursor:pointer; font-size:.9rem; transition:all .15s ease; text-decoration:none; display:inline-block; }
.news-admin-btn-primary { background:#8B4513; color:#fff; border-color:#A0522D; }
.news-admin-btn-primary:hover { background:#A0522D; color:#fff; }
.news-admin-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(139,69,19,.3); }
.news-admin-btn-ghost:hover { color:#c8a96e; border-color:#c8a96e; }
.news-admin-btn-danger { background:#5a1f1f; color:#fff; border-color:#7a2a2a; }
.news-admin-btn-danger:hover { background:#7a2a2a; }
.news-tbl { width:100%; border-collapse:collapse; color:#dee2e6; font-size:.9rem; }
.news-tbl th { text-align:left; padding:.7rem .8rem; border-bottom:1px solid rgba(139,69,19,.3); color:#8899aa; font-weight:600; text-transform:uppercase; font-size:.72rem; letter-spacing:.5px; }
.news-tbl td { padding:.7rem .8rem; border-bottom:1px solid rgba(139,69,19,.1); vertical-align:middle; }
.news-tbl tr:hover td { background:rgba(139,69,19,.06); }
.news-status-pill { display:inline-block; padding:.15rem .55rem; border-radius:10px; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.news-status-pub { background:rgba(46,204,113,.15); color:#5dd87c; border:1px solid rgba(46,204,113,.3); }
.news-status-draft { background:rgba(139,139,139,.15); color:#8899aa; border:1px solid rgba(139,139,139,.3); }
.preview-pane { background:#0a0a0f; border:1px solid rgba(139,69,19,.3); border-radius:4px; padding:1rem; min-height:200px; color:rgba(255,255,255,.85); line-height:1.7; }
.preview-pane h1,.preview-pane h2,.preview-pane h3,.preview-pane h4 { color:#c8a96e; }
.preview-pane a { color:#69CCF0; }
.preview-pane code { background:rgba(255,255,255,.06); padding:.1rem .35rem; border-radius:3px; }
.preview-pane pre { background:rgba(0,0,0,.4); padding:.7rem; border-radius:4px; overflow-x:auto; }
.preview-pane blockquote { border-left:3px solid #c8a96e; padding-left:1rem; color:#8899aa; }
.preview-pane img { max-width:100%; height:auto; }
.alert-news-success { background:rgba(46,204,113,.1); border:1px solid rgba(46,204,113,.3); color:#5dd87c; padding:.8rem 1rem; border-radius:4px; margin-bottom:1rem; }
.alert-news-error { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.8rem 1rem; border-radius:4px; margin-bottom:1rem; }
</style>

<div class="container news-admin-wrap">

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert-news-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['news_saved'] ?? 'Post saved.') ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert-news-success"><i class="bi bi-trash me-2"></i><?= htmlspecialchars($TEXT['news_deleted'] ?? 'Post deleted.') ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert-news-error"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if ($mode === 'list'): ?>

        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <h1 style="color:#c8a96e;margin:0;font-weight:700"><i class="bi bi-newspaper me-2"></i><?= htmlspecialchars($TEXT['news_admin_title'] ?? 'Manage News') ?></h1>
            <div class="d-flex gap-2">
                <a href="/admin_dashboard" class="news-admin-btn news-admin-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
                <a href="/admin_news?new=1" class="news-admin-btn news-admin-btn-primary"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['news_admin_new'] ?? 'New Post') ?></a>
            </div>
        </div>

        <?php
        $all = $pdo_auth->query("SELECT id, slug, title, status, published_at, updated_at FROM news_posts ORDER BY COALESCE(published_at, updated_at) DESC")->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="news-admin-card">
            <?php if (empty($all)): ?>
                <p class="text-center my-4" style="color:#4a5568">
                    <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.4"></i>
                    <?= htmlspecialchars($TEXT['news_admin_none'] ?? 'No posts yet. Click "New Post" to write one.') ?>
                </p>
            <?php else: ?>
                <table class="news-tbl">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars($TEXT['news_admin_col_title'] ?? 'Title') ?></th>
                            <th><?= htmlspecialchars($TEXT['news_admin_col_slug'] ?? 'Slug') ?></th>
                            <th><?= htmlspecialchars($TEXT['news_admin_col_status'] ?? 'Status') ?></th>
                            <th><?= htmlspecialchars($TEXT['news_admin_col_published'] ?? 'Published') ?></th>
                            <th class="text-end"><?= htmlspecialchars($TEXT['news_admin_col_actions'] ?? 'Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all as $row): ?>
                            <tr>
                                <td><strong style="color:#c8a96e"><?= htmlspecialchars($row['title']) ?></strong></td>
                                <td style="color:#8899aa;font-family:monospace;font-size:.85rem"><?= htmlspecialchars($row['slug']) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'published'): ?>
                                        <span class="news-status-pill news-status-pub"><i class="bi bi-eye"></i> <?= htmlspecialchars($TEXT['news_status_published'] ?? 'Published') ?></span>
                                    <?php else: ?>
                                        <span class="news-status-pill news-status-draft"><i class="bi bi-pencil"></i> <?= htmlspecialchars($TEXT['news_status_draft'] ?? 'Draft') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#8899aa;font-size:.85rem"><?= $row['published_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime($row['published_at']))) : '—' ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <?php if ($row['status'] === 'published'): ?>
                                            <a href="/news/<?= htmlspecialchars(rawurlencode($row['slug'])) ?>" target="_blank" class="news-admin-btn news-admin-btn-ghost" style="padding:.25rem .55rem;font-size:.8rem" title="<?= htmlspecialchars($TEXT['news_admin_view'] ?? 'View') ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                                        <?php endif; ?>
                                        <a href="/admin_news?id=<?= (int)$row['id'] ?>" class="news-admin-btn news-admin-btn-ghost" style="padding:.25rem .55rem;font-size:.8rem"><i class="bi bi-pencil"></i> <?= htmlspecialchars($TEXT['news_admin_edit'] ?? 'Edit') ?></a>
                                        <form method="post" style="display:inline" onsubmit="return confirm('<?= htmlspecialchars($TEXT['news_admin_toggle_confirm'] ?? 'Toggle publish status?', ENT_QUOTES) ?>')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="news-admin-btn news-admin-btn-ghost" style="padding:.25rem .55rem;font-size:.8rem" title="<?= htmlspecialchars($TEXT['news_admin_toggle'] ?? 'Toggle publish') ?>"><i class="bi bi-arrow-left-right"></i></button>
                                        </form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('<?= htmlspecialchars($TEXT['news_admin_delete_confirm'] ?? 'Delete this post? This cannot be undone.', ENT_QUOTES) ?>')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="news-admin-btn news-admin-btn-danger" style="padding:.25rem .55rem;font-size:.8rem"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php else: /* edit/create form */ ?>

        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <h1 style="color:#c8a96e;margin:0;font-weight:700">
                <i class="bi bi-<?= $post['id'] ? 'pencil-square' : 'plus-square' ?> me-2"></i>
                <?= $post['id']
                    ? htmlspecialchars($TEXT['news_admin_editing'] ?? 'Editing Post')
                    : htmlspecialchars($TEXT['news_admin_creating'] ?? 'New Post') ?>
            </h1>
            <a href="/admin_news" class="news-admin-btn news-admin-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back_list'] ?? 'Back to List') ?></a>
        </div>

        <form method="post" action="/admin_news">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">

            <div class="news-admin-card">
                <div class="mb-3">
                    <label class="news-admin-label" for="title"><?= htmlspecialchars($TEXT['news_admin_field_title'] ?? 'Title *') ?></label>
                    <input id="title" name="title" type="text" class="news-admin-input" maxlength="200" required value="<?= htmlspecialchars($_POST['title'] ?? $post['title']) ?>">
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="news-admin-label" for="slug"><?= htmlspecialchars($TEXT['news_admin_field_slug'] ?? 'Slug') ?> <span style="text-transform:none;color:#4a5568">(<?= htmlspecialchars($TEXT['news_admin_field_slug_hint'] ?? 'auto from title if blank') ?>)</span></label>
                        <input id="slug" name="slug" type="text" class="news-admin-input" maxlength="160" value="<?= htmlspecialchars($_POST['slug'] ?? $post['slug']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="news-admin-label" for="icon"><?= htmlspecialchars($TEXT['news_admin_field_icon'] ?? 'Icon') ?></label>
                        <input id="icon" name="icon" type="text" class="news-admin-input" maxlength="60" value="<?= htmlspecialchars($_POST['icon'] ?? ($post['icon'] ?: 'bi-megaphone')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="news-admin-label" for="status"><?= htmlspecialchars($TEXT['news_admin_field_status'] ?? 'Status') ?></label>
                        <select id="status" name="status" class="news-admin-select">
                            <?php $cur_status = $_POST['status'] ?? $post['status']; ?>
                            <option value="draft" <?= $cur_status === 'draft' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['news_status_draft'] ?? 'Draft') ?></option>
                            <option value="published" <?= $cur_status === 'published' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['news_status_published'] ?? 'Published') ?></option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="news-admin-label" for="published_at"><?= htmlspecialchars($TEXT['news_admin_field_published_at'] ?? 'Publish At') ?> <span style="text-transform:none;color:#4a5568">(<?= htmlspecialchars($TEXT['news_admin_field_published_at_hint'] ?? 'now if blank') ?>)</span></label>
                        <?php
                        $pub_val = $_POST['published_at'] ?? ($post['published_at'] ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : '');
                        ?>
                        <input id="published_at" name="published_at" type="datetime-local" class="news-admin-input" value="<?= htmlspecialchars($pub_val) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="news-admin-label" for="excerpt"><?= htmlspecialchars($TEXT['news_admin_field_excerpt'] ?? 'Excerpt') ?> <span style="text-transform:none;color:#4a5568">(<?= htmlspecialchars($TEXT['news_admin_field_excerpt_hint'] ?? 'auto from body if blank') ?>)</span></label>
                        <input id="excerpt" name="excerpt" type="text" class="news-admin-input" maxlength="500" value="<?= htmlspecialchars($_POST['excerpt'] ?? ($post['excerpt'] ?? '')) ?>">
                    </div>
                </div>
            </div>

            <div class="news-admin-card">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <label class="news-admin-label mb-0" for="body"><?= htmlspecialchars($TEXT['news_admin_field_body'] ?? 'Body * (Markdown)') ?></label>
                    <button type="button" id="togglePreview" class="news-admin-btn news-admin-btn-ghost" style="padding:.3rem .8rem;font-size:.8rem"><i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['news_admin_preview'] ?? 'Preview') ?></button>
                </div>
                <textarea id="body" name="body" class="news-admin-textarea" rows="18" required><?= htmlspecialchars($_POST['body'] ?? $post['body']) ?></textarea>
                <div id="previewBox" class="preview-pane mt-3" style="display:none">
                    <em style="color:#4a5568"><?= htmlspecialchars($TEXT['news_admin_preview_empty'] ?? 'Preview appears here.') ?></em>
                </div>
            </div>

            <div class="d-flex gap-2 justify-content-end">
                <a href="/admin_news" class="news-admin-btn news-admin-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
                <button type="submit" class="news-admin-btn news-admin-btn-primary"><i class="bi bi-save me-1"></i> <?= htmlspecialchars($TEXT['news_admin_save'] ?? 'Save Post') ?></button>
            </div>
        </form>

    <?php endif; ?>

</div>

<?php if ($mode === 'edit'): ?>
<script>
// Live MD preview — toggle visibility, render server-side via fetch.
(function(){
    const btn  = document.getElementById('togglePreview');
    const body = document.getElementById('body');
    const box  = document.getElementById('previewBox');
    if (!btn || !body || !box) return;

    let visible = false;
    let timer   = null;
    const PREVIEW_URL = '/news_preview';

    async function refresh() {
        if (!visible) return;
        const t = body.value;
        if (!t.trim()) { box.innerHTML = '<em style="color:#4a5568"><?= htmlspecialchars($TEXT['news_admin_preview_empty'] ?? 'Preview appears here.', ENT_QUOTES) ?></em>'; return; }
        try {
            const fd = new FormData();
            fd.append('csrf_token', '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>');
            fd.append('body', t);
            const res = await fetch(PREVIEW_URL, { method:'POST', body: fd, credentials:'same-origin' });
            const json = await res.json();
            if (json && typeof json.html === 'string') box.innerHTML = json.html;
        } catch (e) { /* swallow */ }
    }

    btn.addEventListener('click', () => {
        visible = !visible;
        box.style.display = visible ? 'block' : 'none';
        btn.innerHTML = visible
            ? '<i class="bi bi-eye-slash me-1"></i><?= htmlspecialchars($TEXT['news_admin_hide_preview'] ?? 'Hide Preview', ENT_QUOTES) ?>'
            : '<i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['news_admin_preview'] ?? 'Preview', ENT_QUOTES) ?>';
        if (visible) refresh();
    });

    body.addEventListener('input', () => {
        if (!visible) return;
        clearTimeout(timer);
        timer = setTimeout(refresh, 300);
    });

    // Auto-slugify from title only when slug is untouched
    const slug = document.getElementById('slug');
    const title = document.getElementById('title');
    if (slug && title) {
        let touched = slug.value !== '';
        slug.addEventListener('input', () => { touched = true; });
        title.addEventListener('input', () => {
            if (touched) return;
            const v = title.value.toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            slug.value = v.slice(0, 160);
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
