<?php
/**
 * Forum: new thread form + POST handler.
 *
 * URL: /forum/new/{category-slug}  →  pages/forum_new_thread.php?cat={slug}
 *
 * Any logged-in, non-banned user may post here. GM 9+ may post even when the
 * forum is publicly disabled (admin preview). Approval status is decided by
 * forum_should_auto_approve(): GM 9+ always auto-approves, threshold 0 means
 * everyone, otherwise compare against the user's approved-post count.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';
require_once __DIR__ . '/../includes/avatar.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$user_id   = (int)$_SESSION['user_id'];
$username  = (string)($_SESSION['username'] ?? '');
$gm_level  = (int)($_SESSION['gm_level'] ?? 0);

$cat_slug = trim((string)($_GET['cat'] ?? ''));
$category = $cat_slug !== '' ? forum_category_get_by_slug($pdo_auth, $cat_slug) : null;
if (!$category) {
    http_response_code(404);
    require_once __DIR__ . '/../templates/header.php';
    echo '<main class="container" style="padding-top:120px;text-align:center"><h2 style="color:#c8a96e">'
       . htmlspecialchars($TEXT['forum_cat_not_found'] ?? 'Category not found') . '</h2>'
       . '<a href="/forum" class="btn btn-gold mt-2">' . htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') . '</a></main>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$settings = forum_settings_get($pdo_auth);
[$can_post, $reason] = forum_can_user_post($pdo_auth, $user_id, $gm_level, $settings, null, $category);

if (!$can_post) {
    require_once __DIR__ . '/../templates/header.php';
    $msg = match ($reason) {
        'forum_disabled' => $TEXT['forum_disabled_hint'] ?? 'The forum is not available right now.',
        'banned'         => $TEXT['forum_banned_hint']   ?? 'You are banned from posting in the forum.',
        'admin_only'     => $TEXT['forum_announce_only']  ?? 'Announcements — only GMs can post here.',
        default          => $TEXT['forum_cannot_post']   ?? 'You cannot post right now.',
    };
    echo '<main class="container" style="padding-top:120px;text-align:center;max-width:680px"><h2 style="color:#c8a96e">'
       . htmlspecialchars($TEXT['forum_cannot_post_title'] ?? 'Cannot post')
       . '</h2><p style="color:#8899aa">' . htmlspecialchars($msg) . '</p>'
       . '<a href="/forum" class="btn btn-gold mt-2">' . htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') . '</a></main>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$errors  = [];
$form_title = $_POST['title'] ?? '';
$form_body  = $_POST['body']  ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    } else {
        $title = trim((string)$form_title);
        $body  = trim((string)$form_body);

        if ($title === '')              $errors[] = $TEXT['forum_err_title']    ?? 'Title is required.';
        if (mb_strlen($title) > 200)    $errors[] = $TEXT['forum_err_title_long'] ?? 'Title too long (max 200).';
        if ($body === '')               $errors[] = $TEXT['forum_err_body']     ?? 'Body is required.';
        if (mb_strlen($body) > 50000)   $errors[] = $TEXT['forum_err_body_long']  ?? 'Body too long.';

        // Anti-spam cooldown (GM 9+ bypasses)
        if (empty($errors) && $gm_level < 9) {
            [$ok, $wait] = forum_user_can_post_now($pdo_auth, $user_id, 30);
            if (!$ok) {
                $errors[] = sprintf($TEXT['forum_err_cooldown'] ?? 'Please wait %d more second(s) before posting again.', $wait);
            }
        }

        if (empty($errors)) {
            $auto = forum_should_auto_approve($pdo_auth, $user_id, $gm_level, $settings);
            $result = forum_create_thread($pdo_auth, (int)$category['id'], $user_id, $username, $title, $body, $auto);
            if ($result) {
                log_admin_action(
                    $pdo_auth, $user_id, $username, 'forum_thread_create',
                    "thread:{$result['thread_id']} ({$result['thread_slug']})",
                    "category:{$category['slug']}, status:{$result['status']}", null
                );
                if ($result['status'] === 'published') {
                    header('Location: /forum/' . rawurlencode($category['slug']) . '/' . rawurlencode($result['thread_slug']));
                } else {
                    header('Location: /forum/' . rawurlencode($category['slug']) . '?pending=1');
                }
                exit;
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not save.';
        }
    }
}

$page_title = ($TEXT['forum_new_thread_title'] ?? 'New Thread') . ' — ' . $category['name'];
$extra_head = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js" defer></script>
HTML;
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();
?>

<style>
.fn-wrap { padding-top:120px; padding-bottom:3rem; }
.fn-card {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 10px;
    padding: 1.5rem;
}
.fn-label { display:block; font-size:.72rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.3rem; }
.fn-input {
    width:100%; padding:.6rem .8rem; background:#0a0a0f;
    border:1px solid rgba(139,69,19,.3); border-radius:4px; color:#fff;
    font-size:1rem;
}
.fn-input:focus { outline:none; border-color:#c8a96e; }
.fn-btn { padding:.55rem 1.1rem; border-radius:4px; border:1px solid; cursor:pointer; font-size:.92rem; text-decoration:none; display:inline-block; font-family:inherit; }
.fn-btn-primary { background:#8B4513; color:#fff; border-color:#A0522D; }
.fn-btn-primary:hover { background:#A0522D; color:#fff; }
.fn-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(139,69,19,.3); }
.fn-btn-ghost:hover { color:#c8a96e; border-color:#c8a96e; }
.fn-flash-err { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.7rem 1rem; border-radius:4px; margin-bottom:1rem; }
.fn-pending-note {
    background:rgba(240,192,64,.08);
    border:1px solid rgba(240,192,64,.25);
    color:#f0c040;
    padding:.5rem .85rem;
    border-radius:6px;
    font-size:.85rem;
    margin-bottom:1rem;
}

/* EasyMDE dark-theme override (matches admin_news) */
.EasyMDEContainer .editor-toolbar { background:#15151f; border:1px solid rgba(139,69,19,.3); border-bottom:none; }
.EasyMDEContainer .editor-toolbar button { color:#c8a96e !important; border-color:transparent !important; }
.EasyMDEContainer .editor-toolbar button:hover, .EasyMDEContainer .editor-toolbar button.active { background:#2a1f10 !important; border-color:rgba(139,69,19,.3) !important; color:#fff !important; }
.EasyMDEContainer .CodeMirror { background:#0a0a0f; color:#dee2e6; border:1px solid rgba(139,69,19,.3); border-top:none; font-family:'SFMono-Regular',Consolas,monospace; font-size:.92rem; line-height:1.55; min-height:280px; }
.EasyMDEContainer .CodeMirror-cursor { border-left: 2px solid #c8a96e !important; }
.EasyMDEContainer .CodeMirror-selected { background:rgba(200,169,110,.18); }
.EasyMDEContainer .editor-preview, .EasyMDEContainer .editor-preview-side { background:#0a0a0f; color:rgba(255,255,255,.85); border-color:rgba(139,69,19,.3); line-height:1.7; }
.EasyMDEContainer .editor-preview h1, .EasyMDEContainer .editor-preview h2, .EasyMDEContainer .editor-preview h3, .EasyMDEContainer .editor-preview-side h1, .EasyMDEContainer .editor-preview-side h2, .EasyMDEContainer .editor-preview-side h3 { color:#c8a96e; }
.EasyMDEContainer .editor-statusbar { color:#4a5568; border:1px solid rgba(139,69,19,.15); border-top:none; background:#12121f; padding:.35rem .8rem; font-size:.75rem; }
.EasyMDEContainer .editor-toolbar.fullscreen, .EasyMDEContainer .CodeMirror-fullscreen, .EasyMDEContainer .editor-preview-side { z-index:1050; }
body:has(.editor-toolbar.fullscreen) #mainNavbar, body:has(.editor-preview-side.fullscreen) #mainNavbar, body.easymde-fullscreen #mainNavbar { display:none; }
.EasyMDEContainer .editor-toolbar.fullscreen { display:flex; flex-wrap:wrap; align-items:center; height:auto; min-height:50px; padding:0 4px; }
.EasyMDEContainer .editor-toolbar.fullscreen > * { float:none !important; margin:0 !important; flex:0 0 auto; }
</style>

<div class="container fn-wrap" style="max-width: 880px">
    <div style="color:#8899aa;font-size:.85rem;margin-bottom:1rem">
        <a href="/forum" style="color:#c8a96e;text-decoration:none"><i class="bi bi-chevron-left"></i> <?= htmlspecialchars($TEXT['forum_nav'] ?? 'Forum') ?></a>
        &middot;
        <a href="/forum/<?= htmlspecialchars(rawurlencode($category['slug']), ENT_QUOTES) ?>" style="color:#c8a96e;text-decoration:none"><?= htmlspecialchars($category['name']) ?></a>
    </div>

    <h1 style="color:#c8a96e;font-weight:700;margin-bottom:1.2rem">
        <i class="bi bi-pencil-square me-2"></i><?= htmlspecialchars($TEXT['forum_new_thread_title'] ?? 'New Thread') ?>
        <small style="color:#8899aa;font-weight:400;font-size:.9rem">— <?= htmlspecialchars($category['name']) ?></small>
    </h1>

    <?php foreach ($errors as $err): ?>
        <div class="fn-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php
    $auto = forum_should_auto_approve($pdo_auth, $user_id, $gm_level, $settings);
    if (!$auto):
        $need = max(0, (int)$settings['auto_approve_threshold'] - forum_user_approved_post_count($pdo_auth, $user_id));
    ?>
        <div class="fn-pending-note">
            <i class="bi bi-hourglass-split me-1"></i>
            <?= htmlspecialchars(sprintf(
                $TEXT['forum_threshold_notice'] ?? 'Your post will wait for admin approval. After %d more approved posts, your posts publish instantly.',
                $need
            )) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/forum/new/<?= htmlspecialchars(rawurlencode($category['slug']), ENT_QUOTES) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="fn-card mb-3">
            <label class="fn-label" for="title"><?= htmlspecialchars($TEXT['forum_field_thread_title'] ?? 'Title *') ?></label>
            <input id="title" name="title" type="text" required maxlength="200"
                   class="fn-input" value="<?= htmlspecialchars($form_title) ?>"
                   placeholder="<?= htmlspecialchars($TEXT['forum_title_placeholder'] ?? 'A short, descriptive title…') ?>">
        </div>

        <div class="fn-card mb-3">
            <label class="fn-label" for="body"><?= htmlspecialchars($TEXT['forum_field_body'] ?? 'Body * (Markdown)') ?></label>
            <textarea id="body" name="body" required><?= htmlspecialchars($form_body) ?></textarea>
            <div class="mt-2" style="color:#4a5568;font-size:.8rem">
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['forum_composer_hint'] ?? 'Drag images into the editor or click the image button (max 5 MB).') ?>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
            <a href="/forum/<?= htmlspecialchars(rawurlencode($category['slug']), ENT_QUOTES) ?>" class="fn-btn fn-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
            <button type="submit" class="fn-btn fn-btn-primary"><i class="bi bi-send me-1"></i><?= htmlspecialchars($TEXT['forum_submit_thread'] ?? 'Post Thread') ?></button>
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
        status: ['lines', 'words'],
        minHeight: '260px',
        autosave: { enabled: false },
        forceSync: true,
        placeholder: '<?= htmlspecialchars($TEXT['forum_composer_placeholder'] ?? "Write your post in Markdown…", ENT_QUOTES) ?>',
        previewRender: function (text, el) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF); fd.append('body', text);
            fetch('/news_preview', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json()).then(j => { el.innerHTML = j.html || ''; })
                .catch(() => { el.innerHTML = '<em>Preview unavailable.</em>'; });
            return el.innerHTML;
        },
        uploadImage: true,
        imageMaxSize: 5 * 1024 * 1024,
        imageAccept: 'image/png, image/jpeg, image/webp, image/gif',
        imageUploadFunction: function (file, onSuccess, onError) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF); fd.append('image', file);
            fetch('/forum_image', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                .then(({ ok, body }) => ok && body.url ? onSuccess(body.url) : onError(body.error || 'Upload failed.'))
                .catch(() => onError('Upload failed.'));
        },
        toolbar: ['bold','italic','strikethrough','|','heading-2','heading-3','|','quote','unordered-list','ordered-list','|','link','image','code','|','preview','side-by-side','fullscreen'],
    });

    // Body-class hook for fullscreen navbar-hide (matches admin_news)
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
