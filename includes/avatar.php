<?php
/**
 * Avatar helper — renders a user's avatar as an <img> when they've uploaded
 * one, or a deterministic colored-initials <div> when they haven't.
 *
 * The fallback is generated entirely client-side (no Gravatar / external
 * service) using a stable HSL hue derived from the username, so every user
 * has a visually distinct placeholder out of the box.
 *
 * Usage:
 *   require_once __DIR__ . '/avatar.php';
 *   $av = avatar_get($pdo_auth, $user_id);
 *   echo render_avatar($username, $av, 96);
 */

if (!function_exists('avatar_get')) {
    /**
     * Look up a user's avatar row, or null if they haven't uploaded one.
     * Returns ['filename' => '...', 'mime_type' => '...', 'uploaded_at' => '...'].
     */
    function avatar_get(PDO $pdo, int $account_id): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT filename, mime_type, uploaded_at FROM user_avatars WHERE account_id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $account_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('avatar_get failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('avatar_url')) {
    /**
     * Build a public URL for an uploaded avatar with a cache-buster derived
     * from the upload timestamp. Returns '' when $avatar is null.
     */
    function avatar_url(?array $avatar): string
    {
        if (!$avatar || empty($avatar['filename'])) return '';
        $v = strtotime($avatar['uploaded_at'] ?? '') ?: time();
        return '/uploads/avatars/' . rawurlencode($avatar['filename']) . '?v=' . $v;
    }
}

if (!function_exists('avatar_initials')) {
    function avatar_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '?';
        // Strip non-alphanumeric to be safe, take first 1-2 chars
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        if ($clean === '' || $clean === null) return strtoupper(mb_substr($name, 0, 1));
        return strtoupper(mb_substr($clean, 0, 2));
    }
}

if (!function_exists('avatar_color')) {
    /**
     * Deterministic HSL color from a username. Returns [hue, saturation, lightness]
     * (0..360, 0..100, 0..100). Used for the initials background.
     */
    function avatar_color(string $name): array
    {
        // crc32 → wide 32-bit hash; map low bits to hue, keep saturation/lightness
        // in a band that's readable against light text and matches the gaming UI.
        $hash = crc32(strtolower(trim($name)));
        $hue  = $hash % 360;
        return [$hue, 55, 32]; // saturated but darker than the foreground gold
    }
}

if (!function_exists('render_avatar')) {
    /**
     * Render either an <img> (when avatar uploaded) or a colored-initials
     * <div> (fallback). $size is the side length in px (it's always rendered
     * as a circle via CSS).
     *
     * $extra_class lets a caller add a wrapping class for hero/list/etc.
     */
    function render_avatar(string $username, ?array $avatar, int $size = 96, string $extra_class = ''): string
    {
        $size = max(16, min(512, $size));
        $base_style = 'width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;'
                    . 'flex-shrink:0;border:2px solid rgba(200,169,110,.5);'
                    . 'box-shadow:0 4px 12px rgba(0,0,0,.4);overflow:hidden;'
                    . 'display:inline-flex;align-items:center;justify-content:center;';

        $cls = 'wl-avatar' . ($extra_class !== '' ? ' ' . htmlspecialchars($extra_class, ENT_QUOTES) : '');

        if ($avatar && !empty($avatar['filename'])) {
            $url = avatar_url($avatar);
            $alt = htmlspecialchars($username . " avatar", ENT_QUOTES);
            return '<div class="' . $cls . '" style="' . $base_style . 'background:#0a0a0f">'
                 . '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $alt . '" '
                 . 'style="width:100%;height:100%;object-fit:cover;display:block">'
                 . '</div>';
        }

        [$h, $s, $l] = avatar_color($username);
        $bg = "hsl({$h},{$s}%,{$l}%)";
        $initials = htmlspecialchars(avatar_initials($username), ENT_QUOTES);
        $font_size = (int)round($size * 0.42);

        return '<div class="' . $cls . '" style="' . $base_style . 'background:' . $bg . ';'
             . 'color:#fff;font-weight:700;font-size:' . $font_size . 'px;letter-spacing:1px;'
             . 'text-shadow:0 1px 2px rgba(0,0,0,.4);font-family:sans-serif">'
             . $initials . '</div>';
    }
}
