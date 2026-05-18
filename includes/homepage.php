<?php
/**
 * Customizable home page — section model + resolver + custom renderers.
 *
 * The homepage BODY (everything in index.php between the global nav and
 * footer) is an ordered list of sections stored in site_settings['homepage'].
 * Same DB-override → default-fallback model as footer/theme/settings: when
 * nothing is saved the default layout = the shipped order, so an
 * un-customized install renders pixel-identically.
 *
 * Two kinds of section:
 *  - BUILT-IN (hero/news/forum/steps/counters/features/faq): content stays
 *    code/config/DB driven. The editor only toggles on/off + reorders them;
 *    index.php captures their existing markup unchanged via output buffering.
 *  - CUSTOM: admin-created instances of fixed types (text/card-grid/cta/faq)
 *    with structured, sanitised fields, rendered here into the existing
 *    .game-card / grid CSS so they can't break layout and auto-theme.
 *
 * Security: predefined types only, structured fields, markdown via the
 * shared safe-mode render_markdown(), URLs via footer_link_url_ok(), icons
 * allow-listed, counts capped. No raw HTML/CSS (the Theme tab's custom-CSS
 * box is the separate, already-sanitised escape hatch).
 */

require_once __DIR__ . '/site_settings.php'; // site_setting(), footer_link_url_ok()
require_once __DIR__ . '/markdown.php';      // render_markdown() (Parsedown safe-mode)

if (!function_exists('homepage_builtin_keys')) {
    /** Built-in sections in their shipped order (also the default layout). */
    function homepage_builtin_keys(): array
    {
        return ['hero', 'news', 'forum', 'steps', 'counters', 'features', 'faq'];
    }
}

if (!function_exists('homepage_custom_types')) {
    /** Custom section types an admin can add (v1). NOTE: the custom Q&A type
     *  is `qa`, deliberately distinct from the built-in `faq` section so the
     *  two never collide in the layout normaliser. */
    function homepage_custom_types(): array
    {
        return ['card-grid', 'text', 'cta', 'qa'];
    }
}

if (!function_exists('homepage_section_meta')) {
    /** UI metadata: key => [i18n label key, bootstrap icon, is-built-in]. */
    function homepage_section_meta(): array
    {
        return [
            // built-in
            'hero'      => ['cz_hp_s_hero',     'bi-stars',                1],
            'news'      => ['cz_hp_s_news',     'bi-newspaper',            1],
            'forum'     => ['cz_hp_s_forum',    'bi-chat-square-text',     1],
            'steps'     => ['cz_hp_s_steps',    'bi-list-ol',              1],
            'counters'  => ['cz_hp_s_counters', 'bi-bar-chart-fill',       1],
            'features'  => ['cz_hp_s_features', 'bi-grid-3x3-gap-fill',    1],
            'faq'       => ['cz_hp_s_faq',      'bi-question-circle',      1],
            // custom
            'card-grid' => ['cz_hp_s_cardgrid', 'bi-grid-fill',            0],
            'text'      => ['cz_hp_s_text',     'bi-text-paragraph',       0],
            'cta'       => ['cz_hp_s_cta',      'bi-megaphone-fill',       0],
            'qa'        => ['cz_hp_s_qa',       'bi-patch-question-fill',  0],
        ];
    }
}

if (!function_exists('homepage_default_layout')) {
    /** Shipped order, all on — used until the admin saves a layout. */
    function homepage_default_layout(): array
    {
        $out = [];
        foreach (homepage_builtin_keys() as $k) {
            $out[] = ['id' => $k, 'type' => $k, 'on' => 1];
        }
        return $out;
    }
}

if (!function_exists('homepage_icon_ok')) {
    /** Allow only a Bootstrap-icon class token. */
    function homepage_icon_ok(string $s): bool
    {
        return (bool)preg_match('/^bi-[a-z0-9-]{1,40}$/', $s);
    }
}

if (!function_exists('homepage_sanitize_custom_data')) {
    /**
     * Clean one custom section's structured data per type. Always returns a
     * safe, fully-shaped array (so a renderer can trust it blindly).
     */
    function homepage_sanitize_custom_data(string $type, $data): array
    {
        $data = is_array($data) ? $data : [];
        $txt  = function ($v, $max) {
            return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string)$v) ?? ''), 0, $max);
        };

        if ($type === 'text') {
            return [
                'title' => $txt($data['title'] ?? '', 120),
                'body'  => mb_substr((string)($data['body'] ?? ''), 0, 8000),
            ];
        }
        if ($type === 'cta') {
            $url = trim((string)($data['btn_url'] ?? ''));
            return [
                'title'     => $txt($data['title'] ?? '', 120),
                'text'      => $txt($data['text'] ?? '', 400),
                'btn_label' => $txt($data['btn_label'] ?? '', 60),
                'btn_url'   => (function_exists('footer_link_url_ok') && footer_link_url_ok($url)) ? mb_substr($url, 0, 300) : '',
            ];
        }
        if ($type === 'qa') {
            $items = [];
            foreach ((array)($data['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                $q = $txt($it['q'] ?? '', 200);
                $a = mb_substr((string)($it['a'] ?? ''), 0, 2000);
                if ($q === '' || trim($a) === '') continue;
                $items[] = ['q' => $q, 'a' => $a];
                if (count($items) >= 20) break;
            }
            return ['title' => $txt($data['title'] ?? '', 120), 'items' => $items];
        }
        if ($type === 'card-grid') {
            $cols  = (int)($data['cols'] ?? 3);
            if (!in_array($cols, [2, 3, 4], true)) $cols = 3;
            $cards = [];
            foreach ((array)($data['cards'] ?? []) as $c) {
                if (!is_array($c)) continue;
                $title = $txt($c['title'] ?? '', 80);
                $text  = $txt($c['text'] ?? '', 400);
                if ($title === '' && $text === '') continue;
                $icon = trim((string)($c['icon'] ?? ''));
                $url  = trim((string)($c['url'] ?? ''));
                $cards[] = [
                    'icon'  => homepage_icon_ok($icon) ? $icon : '',
                    'title' => $title,
                    'text'  => $text,
                    'url'   => (function_exists('footer_link_url_ok') && footer_link_url_ok($url)) ? mb_substr($url, 0, 300) : '',
                ];
                if (count($cards) >= 12) break;
            }
            return ['title' => $txt($data['title'] ?? '', 120), 'cols' => $cols, 'cards' => $cards];
        }
        return [];
    }
}

if (!function_exists('homepage_normalize_layout')) {
    /**
     * Produce a safe, fully-shaped ordered layout from whatever is stored:
     * keep the stored order, drop garbage, validate/clean custom sections,
     * then append any built-in not present (forward-compat when a new
     * built-in ships) so it can still be toggled. Cap custom sections.
     */
    function homepage_normalize_layout($stored): array
    {
        $builtins = homepage_builtin_keys();
        $custom   = homepage_custom_types();
        $out = [];
        $seen_builtin = [];
        $custom_n = 0;

        if (is_array($stored)) {
            foreach ($stored as $sec) {
                if (!is_array($sec)) continue;
                $type = (string)($sec['type'] ?? '');
                $on   = !empty($sec['on']) ? 1 : 0;

                if (in_array($type, $builtins, true)) {
                    if (isset($seen_builtin[$type])) continue;       // de-dupe
                    $seen_builtin[$type] = true;
                    $out[] = ['id' => $type, 'type' => $type, 'on' => $on];
                } elseif (in_array($type, $custom, true)) {
                    if ($custom_n >= 30) continue;                    // hard cap
                    $custom_n++;
                    $id = (string)($sec['id'] ?? '');
                    if (!preg_match('/^c_[a-z0-9]{2,16}$/', $id)) {
                        $id = 'c_' . substr(bin2hex(random_bytes(6)), 0, 10);
                    }
                    $out[] = [
                        'id'   => $id,
                        'type' => $type,
                        'on'   => $on,
                        'data' => homepage_sanitize_custom_data($type, $sec['data'] ?? []),
                    ];
                }
            }
        }
        // ensure every built-in exists (missing ones appended, enabled)
        foreach ($builtins as $k) {
            if (!isset($seen_builtin[$k])) {
                $out[] = ['id' => $k, 'type' => $k, 'on' => 1];
            }
        }
        return $out;
    }
}

if (!function_exists('homepage_layout_get')) {
    /**
     * Effective layout = saved (site_settings['homepage']) normalised, else
     * the shipped default. Null-/error-safe (defaults on any failure).
     */
    function homepage_layout_get(?PDO $pdo, array $config): array
    {
        $stored = site_setting($pdo, 'homepage', null);
        if (!is_array($stored) || $stored === []) {
            return homepage_default_layout();
        }
        $norm = homepage_normalize_layout($stored);
        return $norm ?: homepage_default_layout();
    }
}

// ─── Custom-section renderers (return safe HTML; never echo) ─────────────────

if (!function_exists('homepage_render_custom')) {
    /**
     * Render a custom section to HTML. $ctx carries $TEXT etc. Unknown/empty
     * sections render nothing.
     */
    function homepage_render_custom(array $sec, array $ctx): string
    {
        $type = (string)($sec['type'] ?? '');
        $data = is_array($sec['data'] ?? null) ? $sec['data'] : [];
        switch ($type) {
            case 'text':      return _hp_render_text($data);
            case 'card-grid': return _hp_render_card_grid($data);
            case 'cta':       return _hp_render_cta($data);
            case 'qa':        return _hp_render_faq($data, $sec['id'] ?? 'f');
            default:          return '';
        }
    }
}

if (!function_exists('_hp_render_text')) {
    function _hp_render_text(array $d): string
    {
        $title = (string)($d['title'] ?? '');
        $body  = (string)($d['body'] ?? '');
        if ($title === '' && trim($body) === '') return '';
        $h = '<section class="content-section py-5 my-4 rounded"><div class="container" style="max-width:820px">';
        if ($title !== '') {
            $h .= '<div class="section-title text-center mb-5"><h2>'
                . htmlspecialchars($title) . '</h2></div>';
        }
        $h .= '<div class="hp-richtext" style="color:rgba(255,255,255,.8);line-height:1.8;font-size:1rem">'
            . render_markdown($body) . '</div>';
        return $h . '</div></section>';
    }
}

if (!function_exists('_hp_render_card_grid')) {
    function _hp_render_card_grid(array $d): string
    {
        $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
        if (!$cards) return '';
        $cols  = (int)($d['cols'] ?? 3);
        $col_cls = $cols === 2 ? 'col-md-6'
                 : ($cols === 4 ? 'col-md-6 col-lg-3' : 'col-md-6 col-lg-4');
        $title = (string)($d['title'] ?? '');
        $h = '<section class="content-section py-5 my-4 rounded"><div class="container">';
        if ($title !== '') {
            $h .= '<div class="section-title text-center mb-5"><h2>'
                . htmlspecialchars($title) . '</h2></div>';
        }
        $h .= '<div class="row justify-content-center g-4">';
        foreach ($cards as $c) {
            $ic = (string)($c['icon'] ?? '');
            $ti = (string)($c['title'] ?? '');
            $tx = (string)($c['text'] ?? '');
            $ur = (string)($c['url'] ?? '');
            $inner  = '<div class="game-card h-100 text-center"' . ($ur !== '' ? ' style="cursor:pointer"' : '') . '>'
                    . '<div class="card-body d-flex flex-column p-4">';
            if ($ic !== '') {
                $inner .= '<div class="step-icon"><i class="bi ' . htmlspecialchars($ic) . '" style="color:var(--accent)"></i></div>';
            }
            if ($ti !== '') $inner .= '<h4 class="text-accent mb-3">' . htmlspecialchars($ti) . '</h4>';
            if ($tx !== '') $inner .= '<p style="color:var(--text-muted)">' . htmlspecialchars($tx) . '</p>';
            $inner .= '</div></div>';
            $h .= '<div class="' . $col_cls . '">';
            $h .= $ur !== ''
                ? '<a href="' . htmlspecialchars($ur) . '" style="text-decoration:none;color:inherit">' . $inner . '</a>'
                : $inner;
            $h .= '</div>';
        }
        return $h . '</div></div></section>';
    }
}

if (!function_exists('_hp_render_cta')) {
    function _hp_render_cta(array $d): string
    {
        $title = (string)($d['title'] ?? '');
        $text  = (string)($d['text'] ?? '');
        $bl    = (string)($d['btn_label'] ?? '');
        $bu    = (string)($d['btn_url'] ?? '');
        if ($title === '' && $text === '' && $bl === '') return '';
        $h = '<section class="content-section py-5 my-4 rounded"><div class="container text-center" style="max-width:760px">';
        if ($title !== '') $h .= '<h2 class="mb-3" style="color:var(--accent);font-weight:700">' . htmlspecialchars($title) . '</h2>';
        if ($text !== '')  $h .= '<p class="mb-4" style="color:rgba(255,255,255,.7);font-size:1.1rem">' . htmlspecialchars($text) . '</p>';
        if ($bl !== '' && $bu !== '') {
            $h .= '<a class="btn btn-gold btn-lg px-5" href="' . htmlspecialchars($bu) . '">' . htmlspecialchars($bl) . '</a>';
        }
        return $h . '</div></section>';
    }
}

if (!function_exists('_hp_render_faq')) {
    function _hp_render_faq(array $d, string $id): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        if (!$items) return '';
        $acc = 'hpfaq_' . preg_replace('/[^a-z0-9_]/', '', strtolower($id));
        $title = (string)($d['title'] ?? '');
        $h = '<section class="content-section py-5 my-4 rounded"><div class="container" style="max-width:800px">';
        if ($title !== '') {
            $h .= '<div class="section-title text-center mb-5"><h2><i class="bi bi-question-circle me-2"></i>'
                . htmlspecialchars($title) . '</h2></div>';
        }
        $h .= '<div class="accordion" id="' . $acc . '">';
        foreach ($items as $i => $it) {
            $q = htmlspecialchars((string)($it['q'] ?? ''));
            $a = render_markdown((string)($it['a'] ?? ''));
            $cid = $acc . '_' . $i;
            $h .= '<div class="accordion-item faq-item">'
                . '<h2 class="accordion-header"><button class="accordion-button collapsed faq-btn" type="button"'
                . ' data-bs-toggle="collapse" data-bs-target="#' . $cid . '" aria-expanded="false">'
                . '<i class="bi bi-patch-question me-2" style="color:var(--accent)"></i>' . $q . '</button></h2>'
                . '<div id="' . $cid . '" class="accordion-collapse collapse" data-bs-parent="#' . $acc . '">'
                . '<div class="accordion-body faq-body">' . $a . '</div></div></div>';
        }
        return $h . '</div></div></section>';
    }
}
