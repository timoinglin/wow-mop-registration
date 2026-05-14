<?php
/**
 * Forum: edit a single post. Author may edit their own posts; GM 9+ may edit
 * any post. Editing never resets the approval status — a pending post stays
 * pending, a published post stays published.
 *
 * URL: /forum/edit/{post_id}  →  pages/forum_edit.php?id={post_id}
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }

$user_id   = (int)$_SESSION['user_id'];
$username  = (string)($_SESSION['username'] ?? '');
$gm_level  = (int)($_SESSION['gm_level'] ?? 0);

$post_id = (int)($_GET['id'] ?? 0);
if ($post_id <= 0) { header('Location: /forum'); exit; }

$post = forum_post_get($pdo_auth, $post_id);
if (!$post) {
    http_response_code(404);
    require_once __DIR__ . '/../templates/header.php';
    echo '<main class="container" style="padding-top:120px;text-align:center"><h2 style="color:#c8a96e">'
       . htmlspecialchars($TEXT['forum_post_not_found'] ?? 'Post not found') . '</h2>'
       . '<a href="/forum" class="btn btn-gold mt-2">' . htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') . '</a></main>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Permission check: GM 9+ can edit anything; otherwise must own the post
$is_owner = ((int)$post['author_id'] === $user_id);
if (!$is_owner && $gm_level < 9) {
    http_response_code(403);
    require_once __DIR__ . '/../templates/header.php';
    echo '<main class="container" style="padding-top:120px;text-align:center"><h2 style="color:#c8a96e">'
       . htmlspecialchars($TEXT['forum_edit_forbidden'] ?? "You can only edit your own posts.") . '</h2>'
       . '<a href="/forum" class="btn btn-gold mt-2">' . htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') . '</a></main>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Owners (non-GM) can't edit if banned
if (!$is_owner) { /* GM editing — proceed */ }
else if (forum_is_user_banned($pdo_auth, $user_id)) {
    require_once __DIR__ . '/../templates/header.php';
    echo '<main class="container" style="padding-top:120px;text-align:center"><h2 style="color:#c8a96e">'
       . htmlspecialchars($TEXT['forum_banned_hint'] ?? 'You are banned from posting in the forum.') . '</h2>'
       . '<a href="/forum" class="btn btn-gold mt-2">' . htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') . '</a></main>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$back = '/forum/' . rawurlencode($post['category_slug']) . '/' . rawurlencode($post['thread_slug']) . '#post-' . (int)$post['id'];

$errors = [];
$form_body = $_POST['body'] ?? $post['body'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    } else {
        $body = trim((string)$form_body);
        if ($body === '')             $errors[] = $TEXT['forum_err_body']      ?? 'Body is required.';
        if (mb_strlen($body) > 50000) $errors[] = $TEXT['forum_err_body_long'] ?? 'Body too long.';

        if (empty($errors)) {
            // Editor name: real username for self-edits, "{GM} (admin)" when an admin edits someone else's post
            $editor_name = $is_owner ? $username : ($username . ' (admin)');
            if (forum_post_edit($pdo_auth, (int)$post['id'], $body, $editor_name)) {
                log_admin_action(
                    $pdo_auth, $user_id, $username,
                    $is_owner ? 'forum_post_edit_own' : 'forum_post_edit_admin',
                    "post:{$post['id']} (thread:{$post['thread_id']})",
                    "by_owner:" . ($is_owner ? '1' : '0'), null
                );
                header('Location: ' . $back);
                exit;
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not save.';
        }
    }
}

$page_title = ($TEXT['forum_edit_title'] ?? 'Edit Post') . ' — ' . $post['thread_title'];
$extra_head = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js" defer></script>
HTML;
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();
?>

<style>
.fe-wrap { padding-top:120px; padding-bottom:3rem; }
.fe-card { background:linear-gradient(145deg,#15151f,#0e0e17); border:1px solid rgba(139,69,19,.3); border-radius:10px; padding:1.5rem; }
.fe-flash-err { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.7rem 1rem; border-radius:4px; margin-bottom:1rem; }
.fe-btn { padding:.55rem 1.1rem; border-radius:4px; border:1px solid; cursor:pointer; font-size:.92rem; text-decoration:none; display:inline-block; font-family:inherit; }
.fe-btn-primary { background:#8B4513; color:#fff; border-color:#A0522D; }
.fe-btn-primary:hover { background:#A0522D; color:#fff; }
.fe-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(139,69,19,.3); }
.fe-btn-ghost:hover { color:#c8a96e; border-color:#c8a96e; }

.EasyMDEContainer .editor-toolbar { background:#15151f; border:1px solid rgba(139,69,19,.3); border-bottom:none; }
.EasyMDEContainer .editor-toolbar button { color:#c8a96e !important; border-color:transparent !important; }
.EasyMDEContainer .editor-toolbar button:hover, .EasyMDEContainer .editor-toolbar button.active { background:#2a1f10 !important; border-color:rgba(139,69,19,.3) !important; color:#fff !important; }
.EasyMDEContainer .CodeMirror { background:#0a0a0f; color:#dee2e6; border:1px solid rgba(139,69,19,.3); border-top:none; font-family:'SFMono-Regular',Consolas,monospace; font-size:.92rem; line-height:1.55; min-height:280px; }
.EasyMDEContainer .editor-preview, .EasyMDEContainer .editor-preview-side { background:#0a0a0f; color:rgba(255,255,255,.85); border-color:rgba(139,69,19,.3); line-height:1.7; }
.EasyMDEContainer .editor-preview h1,.EasyMDEContainer .editor-preview h2,.EasyMDEContainer .editor-preview h3,.EasyMDEContainer .editor-preview-side h1,.EasyMDEContainer .editor-preview-side h2,.EasyMDEContainer .editor-preview-side h3 { color:#c8a96e; }
.EasyMDEContainer .editor-statusbar { color:#4a5568; border:1px solid rgba(139,69,19,.15); border-top:none; background:#12121f; padding:.35rem .8rem; font-size:.75rem; }
.EasyMDEContainer .editor-toolbar.fullscreen, .EasyMDEContainer .CodeMirror-fullscreen, .EasyMDEContainer .editor-preview-side { z-index:1050; }
body:has(.editor-toolbar.fullscreen) #mainNavbar, body:has(.editor-preview-side.fullscreen) #mainNavbar, body.easymde-fullscreen #mainNavbar { display:none; }
.EasyMDEContainer .editor-toolbar.fullscreen { display:flex; flex-wrap:wrap; align-items:center; height:auto; min-height:50px; padding:0 4px; }
.EasyMDEContainer .editor-toolbar.fullscreen > * { float:none !important; margin:0 !important; flex:0 0 auto; }
</style>

<div class="container fe-wrap" style="max-width: 880px">
    <div style="color:#8899aa;font-size:.85rem;margin-bottom:1rem">
        <a href="<?= htmlspecialchars($back) ?>" style="color:#c8a96e;text-decoration:none">
            <i class="bi bi-chevron-left"></i> <?= htmlspecialchars($post['thread_title']) ?>
        </a>
    </div>

    <h1 style="color:#c8a96e;font-weight:700;margin-bottom:1.2rem">
        <i class="bi bi-pencil-square me-2"></i><?= htmlspecialchars($TEXT['forum_edit_title'] ?? 'Edit Post') ?>
        <?php if (!$is_owner): ?>
            <span style="font-size:.7rem;background:rgba(231,76,60,.15);color:#f87e8a;border:1px solid rgba(231,76,60,.3);padding:.15rem .55rem;border-radius:10px;text-transform:uppercase;letter-spacing:.5px;margin-left:.5rem;vertical-align:middle"><?= htmlspecialchars($TEXT['forum_admin_edit_badge'] ?? 'Admin Edit') ?></span>
        <?php endif; ?>
    </h1>

    <?php foreach ($errors as $err): ?>
        <div class="fe-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/forum/edit/<?= (int)$post['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="fe-card mb-3">
            <textarea id="body" name="body" required><?= htmlspecialchars($form_body) ?></textarea>
            <div class="mt-2" style="color:#4a5568;font-size:.8rem">
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['forum_composer_hint'] ?? 'Drag images into the editor or click the image button (max 5 MB).') ?>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
            <a href="<?= htmlspecialchars($back) ?>" class="fe-btn fe-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
            <button type="submit" class="fe-btn fe-btn-primary"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['forum_save_edit'] ?? 'Save Changes') ?></button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ta = document.getElementById('body');
    if (!ta || typeof EasyMDE === 'undefined') return;
    const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>';

    const ed = new EasyMDE({
        element: ta,
        autoDownloadFontAwesome: true,
        spellChecker: false,
        status: ['lines','words'],
        minHeight: '260px',
        autosave: { enabled: false },
        forceSync: true,
        previewRender: function (t, el) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF); fd.append('body', t);
            fetch('/news_preview', { method:'POST', body:fd, credentials:'same-origin' })
                .then(r => r.json()).then(j => { el.innerHTML = j.html || ''; })
                .catch(() => { el.innerHTML = '<em>Preview unavailable.</em>'; });
            return el.innerHTML;
        },
        uploadImage: true,
        imageMaxSize: 5 * 1024 * 1024,
        imageAccept: 'image/png, image/jpeg, image/webp, image/gif',
        imageUploadFunction: function (file, ok, err) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF); fd.append('image', file);
            fetch('/forum_image', { method:'POST', body:fd, credentials:'same-origin' })
                .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                .then(({ ok: ok2, body }) => ok2 && body.url ? ok(body.url) : err(body.error || 'Upload failed.'))
                .catch(() => err('Upload failed.'));
        },
        toolbar: ['bold','italic','strikethrough','|','heading-2','heading-3','|','quote','unordered-list','ordered-list','|','link','image','code','|','preview','side-by-side','fullscreen'],
    });

    // Body class hook for navbar-hide in fullscreen
    const container = ed.element.parentNode;
    if (container) {
        const sync = () => {
            const tb = container.querySelector('.editor-toolbar');
            document.body.classList.toggle('easymde-fullscreen', !!(tb && tb.classList.contains('fullscreen')));
            const cmFs = container.querySelector('.CodeMirror-fullscreen');
            const pvFs = container.querySelector('.editor-preview-side.fullscreen');
            if (tb && tb.classList.contains('fullscreen')) {
                const h = tb.offsetHeight + 'px';
                if (cmFs) cmFs.style.top = h;
                if (pvFs) pvFs.style.top = h;
            } else {
                if (cmFs) cmFs.style.top = '';
                if (pvFs) pvFs.style.top = '';
            }
        };
        new MutationObserver(sync).observe(container, { subtree:true, attributes:true, attributeFilter:['class'] });
        window.addEventListener('resize', () => { if (document.body.classList.contains('easymde-fullscreen')) sync(); });
        sync();
    }
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
