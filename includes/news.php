<?php
/**
 * News helper — slug generation + fetchers for the `news_posts` table.
 *
 * Posts are monolingual: admins write in whatever language fits their audience.
 * A starter post is seeded by sql/setup.sql on first install (idempotent).
 */

if (!function_exists('news_slugify')) {
    function news_slugify(string $title): string
    {
        $s = mb_strtolower(trim($title), 'UTF-8');
        // Replace accented chars with ASCII where iconv is available
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        $s = trim($s, '-');
        $s = strtolower($s);
        if ($s === '') $s = 'post-' . substr(bin2hex(random_bytes(3)), 0, 6);
        return substr($s, 0, 160);
    }
}

if (!function_exists('news_unique_slug')) {
    function news_unique_slug(PDO $pdo, string $base, ?int $exclude_id = null): string
    {
        $slug = $base;
        $i = 2;
        while (true) {
            $sql = "SELECT id FROM news_posts WHERE slug = :s" . ($exclude_id ? " AND id != :ex" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $params = ['s' => $slug];
            if ($exclude_id) $params['ex'] = $exclude_id;
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) return $slug;
            $slug = substr($base, 0, 155) . '-' . $i++;
        }
    }
}

if (!function_exists('news_latest_published')) {
    function news_latest_published(PDO $pdo, int $limit = 3): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $pdo->prepare(
            "SELECT id, slug, title, excerpt, icon, author_name, published_at
             FROM news_posts
             WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
             ORDER BY published_at DESC
             LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('news_get_by_slug')) {
    function news_get_by_slug(PDO $pdo, string $slug, bool $include_drafts = false): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM news_posts WHERE slug = :slug"
            . ($include_drafts ? "" : " AND status = 'published' AND published_at <= NOW()")
            . " LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('news_count_published')) {
    function news_count_published(PDO $pdo): int
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM news_posts WHERE status = 'published' AND published_at <= NOW()");
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('news_published_page')) {
    function news_published_page(PDO $pdo, int $page = 1, int $per_page = 9): array
    {
        $per_page = max(1, min(50, $per_page));
        $page     = max(1, $page);
        $offset   = ($page - 1) * $per_page;
        $stmt = $pdo->prepare(
            "SELECT id, slug, title, excerpt, icon, author_name, published_at
             FROM news_posts
             WHERE status = 'published' AND published_at <= NOW()
             ORDER BY published_at DESC
             LIMIT $per_page OFFSET $offset"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
