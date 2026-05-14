<?php
/**
 * Forum helper — settings, categories, bans, slug, and auto-approval logic.
 *
 * No public/threaded reading is done here yet (that comes in Phase 3+). This
 * module exists so Phase 2 (admin config) and the navbar's enabled-check can
 * share a single source of truth.
 */

if (!function_exists('forum_settings_get')) {
    /**
     * Returns the single-row settings, with safe defaults if the table is
     * missing or the seed row hasn't been inserted yet.
     */
    function forum_settings_get(PDO $pdo): array
    {
        try {
            $row = $pdo->query("SELECT enabled, auto_approve_threshold FROM forum_settings WHERE id = 1 LIMIT 1")
                       ->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'enabled' => (bool)$row['enabled'],
                    'auto_approve_threshold' => (int)$row['auto_approve_threshold'],
                ];
            }
        } catch (PDOException $e) {
            error_log('forum_settings_get: ' . $e->getMessage());
        }
        return ['enabled' => false, 'auto_approve_threshold' => 3];
    }
}

if (!function_exists('forum_settings_update')) {
    function forum_settings_update(PDO $pdo, bool $enabled, int $threshold): bool
    {
        $threshold = max(0, min(1000, $threshold));
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO forum_settings (id, enabled, auto_approve_threshold)
                 VALUES (1, :en, :th)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled),
                                         auto_approve_threshold = VALUES(auto_approve_threshold)"
            );
            return $stmt->execute(['en' => $enabled ? 1 : 0, 'th' => $threshold]);
        } catch (PDOException $e) {
            error_log('forum_settings_update: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('forum_is_enabled')) {
    /**
     * Fast enabled-check for the navbar — no exception thrown, returns false on
     * any DB hiccup so we never break unauthenticated page renders.
     */
    function forum_is_enabled(PDO $pdo): bool
    {
        try {
            $v = $pdo->query("SELECT enabled FROM forum_settings WHERE id = 1 LIMIT 1")->fetchColumn();
            return (bool)$v;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('forum_slugify')) {
    function forum_slugify(string $title): string
    {
        $s = mb_strtolower(trim($title), 'UTF-8');
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        $s = trim($s, '-');
        $s = strtolower($s);
        if ($s === '') $s = 'item-' . substr(bin2hex(random_bytes(3)), 0, 6);
        return substr($s, 0, 160);
    }
}

if (!function_exists('forum_unique_slug')) {
    function forum_unique_slug(PDO $pdo, string $table, string $base, ?int $exclude_id = null): string
    {
        $slug = $base;
        $i = 2;
        while (true) {
            $sql = "SELECT id FROM $table WHERE slug = :s" . ($exclude_id ? " AND id != :ex" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $params = ['s' => $slug];
            if ($exclude_id) $params['ex'] = $exclude_id;
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) return $slug;
            $slug = substr($base, 0, 155) . '-' . $i++;
        }
    }
}

// ─── Categories ──────────────────────────────────────────────────────────────
if (!function_exists('forum_categories_list')) {
    function forum_categories_list(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT c.id, c.slug, c.name, c.description, c.icon, c.sort_order, c.created_at,
                        (SELECT COUNT(*) FROM forum_threads t
                         WHERE t.category_id = c.id AND t.status = 'published') AS thread_count
                 FROM forum_categories c
                 ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_categories_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_category_get')) {
    function forum_category_get(PDO $pdo, int $id): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM forum_categories WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('forum_category_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('forum_category_save')) {
    /**
     * Insert or update a category. Returns the (new or existing) id on success.
     */
    function forum_category_save(PDO $pdo, ?int $id, string $name, string $description, string $icon, int $sort_order, ?string $slug_override = null): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        $base = $slug_override !== null && trim($slug_override) !== ''
            ? forum_slugify($slug_override)
            : forum_slugify($name);
        $slug = forum_unique_slug($pdo, 'forum_categories', $base, $id);

        if (!preg_match('/^bi-[a-z0-9-]+$/i', $icon)) $icon = 'bi-chat-square-text';

        try {
            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE forum_categories
                     SET slug = :slug, name = :name, description = :desc, icon = :icon, sort_order = :sort
                     WHERE id = :id"
                );
                $stmt->execute([
                    'slug' => $slug, 'name' => $name, 'desc' => $description !== '' ? $description : null,
                    'icon' => $icon, 'sort' => $sort_order, 'id' => $id,
                ]);
                return $id;
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO forum_categories (slug, name, description, icon, sort_order)
                     VALUES (:slug, :name, :desc, :icon, :sort)"
                );
                $stmt->execute([
                    'slug' => $slug, 'name' => $name, 'desc' => $description !== '' ? $description : null,
                    'icon' => $icon, 'sort' => $sort_order,
                ]);
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log('forum_category_save: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('forum_category_delete')) {
    /**
     * Hard-deletes a category. Cascades to its threads and posts (no FKs in the
     * schema, so we do it in PHP). Returns true on success.
     */
    function forum_category_delete(PDO $pdo, int $id): bool
    {
        try {
            $pdo->beginTransaction();
            // Posts → Threads in this category
            $del_posts = $pdo->prepare(
                "DELETE p FROM forum_posts p
                 JOIN forum_threads t ON t.id = p.thread_id
                 WHERE t.category_id = :id"
            );
            $del_posts->execute(['id' => $id]);

            $del_threads = $pdo->prepare("DELETE FROM forum_threads WHERE category_id = :id");
            $del_threads->execute(['id' => $id]);

            $del_cat = $pdo->prepare("DELETE FROM forum_categories WHERE id = :id");
            $del_cat->execute(['id' => $id]);

            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_category_delete: ' . $e->getMessage());
            return false;
        }
    }
}

// ─── Bans ────────────────────────────────────────────────────────────────────
if (!function_exists('forum_bans_list')) {
    function forum_bans_list(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT account_id, username, banned_by, reason, banned_at, expires_at
                 FROM forum_bans
                 ORDER BY banned_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_bans_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_is_user_banned')) {
    function forum_is_user_banned(PDO $pdo, int $account_id): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM forum_bans
                 WHERE account_id = :id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $stmt->execute(['id' => $account_id]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('forum_ban_user')) {
    /**
     * Ban a user by their account row. Returns [bool $ok, ?string $error_key].
     * Refuses to ban GM 9+ accounts (prevents admin-vs-admin lockout).
     */
    function forum_ban_user(PDO $pdo, int $account_id, string $username, string $banned_by, ?string $reason, ?string $expires_at): array
    {
        // Refuse to ban admins
        try {
            $gm = $pdo->prepare("SELECT MAX(gmlevel) FROM account_access WHERE id = :id");
            $gm->execute(['id' => $account_id]);
            if ((int)($gm->fetchColumn() ?: 0) >= 9) {
                return [false, 'cannot_ban_admin'];
            }
        } catch (PDOException $e) { /* ignore; proceed */ }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO forum_bans (account_id, username, banned_by, reason, expires_at)
                 VALUES (:id, :u, :by, :r, :e)
                 ON DUPLICATE KEY UPDATE
                    username = VALUES(username),
                    banned_by = VALUES(banned_by),
                    reason = VALUES(reason),
                    expires_at = VALUES(expires_at),
                    banned_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                'id' => $account_id, 'u' => $username, 'by' => $banned_by,
                'r' => $reason !== null && $reason !== '' ? $reason : null,
                'e' => $expires_at !== null && $expires_at !== '' ? $expires_at : null,
            ]);
            return [true, null];
        } catch (PDOException $e) {
            error_log('forum_ban_user: ' . $e->getMessage());
            return [false, 'db'];
        }
    }
}

if (!function_exists('forum_unban_user')) {
    function forum_unban_user(PDO $pdo, int $account_id): bool
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM forum_bans WHERE account_id = :id");
            $stmt->execute(['id' => $account_id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('forum_unban_user: ' . $e->getMessage());
            return false;
        }
    }
}

// ─── Approval ────────────────────────────────────────────────────────────────
if (!function_exists('forum_user_approved_post_count')) {
    /**
     * Counts how many of the user's posts (threads + replies, OP rows + reply
     * rows in forum_posts) have status 'published'. Used to decide whether a
     * new post should be auto-approved.
     */
    function forum_user_approved_post_count(PDO $pdo, int $account_id): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM forum_posts
                 WHERE author_id = :id AND status = 'published'"
            );
            $stmt->execute(['id' => $account_id]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('forum_should_auto_approve')) {
    /**
     * Should a new post by this user be auto-approved? GMs (9+) always pass;
     * threshold of 0 means everyone passes; otherwise compare approved-post
     * count against the threshold.
     */
    function forum_should_auto_approve(PDO $pdo, int $account_id, int $gm_level, array $settings): bool
    {
        if ($gm_level >= 9) return true;
        $threshold = (int)($settings['auto_approve_threshold'] ?? 3);
        if ($threshold <= 0) return true;
        return forum_user_approved_post_count($pdo, $account_id) >= $threshold;
    }
}

// ─── Public-read fetchers (Phase 3) ──────────────────────────────────────────

if (!function_exists('forum_categories_with_stats')) {
    /**
     * Categories + thread count + last-activity info, in display order.
     * Joins forum_threads to surface "last reply by X at Y" so the index
     * feels alive even when there's just one row in each category.
     */
    function forum_categories_with_stats(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT c.id, c.slug, c.name, c.description, c.icon, c.sort_order,
                        (SELECT COUNT(*) FROM forum_threads t
                         WHERE t.category_id = c.id AND t.status = 'published') AS thread_count,
                        latest.title          AS latest_title,
                        latest.slug           AS latest_slug,
                        latest.last_reply_at  AS latest_at,
                        latest.last_reply_by  AS latest_by
                 FROM forum_categories c
                 LEFT JOIN (
                     SELECT t1.category_id, t1.title, t1.slug, t1.last_reply_at, t1.last_reply_by
                     FROM forum_threads t1
                     JOIN (
                         SELECT category_id, MAX(last_reply_at) AS max_at
                         FROM forum_threads
                         WHERE status = 'published' AND last_reply_at IS NOT NULL
                         GROUP BY category_id
                     ) m ON m.category_id = t1.category_id AND m.max_at = t1.last_reply_at
                     WHERE t1.status = 'published'
                 ) latest ON latest.category_id = c.id
                 ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_categories_with_stats: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_category_get_by_slug')) {
    function forum_category_get_by_slug(PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM forum_categories WHERE slug = :s LIMIT 1");
            $stmt->execute(['s' => $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('forum_threads_count_in_category')) {
    function forum_threads_count_in_category(PDO $pdo, int $category_id): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM forum_threads WHERE category_id = :id AND status = 'published'"
            );
            $stmt->execute(['id' => $category_id]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('forum_threads_in_category')) {
    /**
     * Paginated thread list for a category. Sticky threads always first,
     * then ordered by last_reply_at DESC.
     */
    function forum_threads_in_category(PDO $pdo, int $category_id, int $page = 1, int $per_page = 20): array
    {
        $per_page = max(1, min(100, $per_page));
        $page     = max(1, $page);
        $offset   = ($page - 1) * $per_page;
        try {
            $stmt = $pdo->prepare(
                "SELECT id, slug, title, author_id, author_name, is_sticky, is_locked,
                        view_count, reply_count, last_reply_at, last_reply_by, created_at
                 FROM forum_threads
                 WHERE category_id = :id AND status = 'published'
                 ORDER BY is_sticky DESC, last_reply_at DESC
                 LIMIT $per_page OFFSET $offset"
            );
            $stmt->execute(['id' => $category_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_threads_in_category: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_thread_get_by_slug')) {
    /**
     * Look up a thread by slug. Admins (GM 9+) see all statuses; the thread's
     * author sees their own pending thread; everyone else only sees published.
     */
    function forum_thread_get_by_slug(PDO $pdo, string $slug, ?int $viewer_id = null, bool $is_admin = false): ?array
    {
        try {
            $where = "t.slug = :s";
            $params = ['s' => $slug];
            if (!$is_admin) {
                if ($viewer_id) {
                    $where .= " AND (t.status = 'published' OR (t.status = 'pending' AND t.author_id = :uid))";
                    $params['uid'] = $viewer_id;
                } else {
                    $where .= " AND t.status = 'published'";
                }
            }
            $sql = "SELECT t.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
                    FROM forum_threads t
                    JOIN forum_categories c ON c.id = t.category_id
                    WHERE $where LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('forum_threads_in_category_for_user')) {
    /**
     * Paginated thread list with viewer-aware visibility. Admins see all
     * (including pending + hidden); a logged-in viewer additionally sees
     * their own pending threads.
     */
    function forum_threads_in_category_for_user(PDO $pdo, int $category_id, ?int $viewer_id, bool $is_admin, int $page = 1, int $per_page = 20): array
    {
        $per_page = max(1, min(100, $per_page));
        $page     = max(1, $page);
        $offset   = ($page - 1) * $per_page;

        $where = "category_id = :id";
        $params = ['id' => $category_id];
        if ($is_admin) {
            $where .= " AND status IN ('published','pending')";
        } elseif ($viewer_id) {
            $where .= " AND (status = 'published' OR (status = 'pending' AND author_id = :uid))";
            $params['uid'] = $viewer_id;
        } else {
            $where .= " AND status = 'published'";
        }

        try {
            $sql = "SELECT id, slug, title, author_id, author_name, status, is_sticky, is_locked,
                           view_count, reply_count, last_reply_at, last_reply_by, created_at
                    FROM forum_threads
                    WHERE $where
                    ORDER BY is_sticky DESC, last_reply_at DESC, created_at DESC
                    LIMIT $per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_threads_in_category_for_user: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_threads_count_in_category_for_user')) {
    function forum_threads_count_in_category_for_user(PDO $pdo, int $category_id, ?int $viewer_id, bool $is_admin): int
    {
        $where = "category_id = :id";
        $params = ['id' => $category_id];
        if ($is_admin) {
            $where .= " AND status IN ('published','pending')";
        } elseif ($viewer_id) {
            $where .= " AND (status = 'published' OR (status = 'pending' AND author_id = :uid))";
            $params['uid'] = $viewer_id;
        } else {
            $where .= " AND status = 'published'";
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_threads WHERE $where");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('forum_thread_increment_views')) {
    function forum_thread_increment_views(PDO $pdo, int $thread_id): void
    {
        try {
            $stmt = $pdo->prepare("UPDATE forum_threads SET view_count = view_count + 1 WHERE id = :id");
            $stmt->execute(['id' => $thread_id]);
        } catch (PDOException $e) {
            // non-fatal
        }
    }
}

if (!function_exists('forum_posts_count_in_thread')) {
    function forum_posts_count_in_thread(PDO $pdo, int $thread_id): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM forum_posts WHERE thread_id = :id AND status = 'published'"
            );
            $stmt->execute(['id' => $thread_id]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('forum_posts_in_thread')) {
    /**
     * Paginated post list (OP first, then replies in chronological order).
     * Returns the OP on every page (it's always shown above the replies)
     * by querying replies-only and prepending the OP manually in the caller.
     */
    function forum_posts_in_thread(PDO $pdo, int $thread_id, int $page = 1, int $per_page = 20): array
    {
        $per_page = max(1, min(100, $per_page));
        $page     = max(1, $page);
        $offset   = ($page - 1) * $per_page;
        try {
            $stmt = $pdo->prepare(
                "SELECT id, thread_id, author_id, author_name, body, status, is_op,
                        edited_at, edited_by, created_at
                 FROM forum_posts
                 WHERE thread_id = :id AND status = 'published'
                 ORDER BY is_op DESC, created_at ASC
                 LIMIT $per_page OFFSET $offset"
            );
            $stmt->execute(['id' => $thread_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_posts_in_thread: ' . $e->getMessage());
            return [];
        }
    }
}

// ─── Write helpers (Phase 4) ─────────────────────────────────────────────────

if (!function_exists('forum_can_user_post')) {
    /**
     * Quick allow-check before showing the composer. Returns [bool $ok, string $reason_key].
     * $reason_key is one of: 'not_logged_in', 'forum_disabled', 'banned', 'locked', 'ok'.
     */
    function forum_can_user_post(PDO $pdo, ?int $account_id, int $gm_level, array $settings, ?array $thread = null): array
    {
        if (!$settings['enabled'] && $gm_level < 9) return [false, 'forum_disabled'];
        if (!$account_id)                          return [false, 'not_logged_in'];
        if (forum_is_user_banned($pdo, $account_id)) return [false, 'banned'];
        if ($thread && !empty($thread['is_locked']) && $gm_level < 9) return [false, 'locked'];
        return [true, 'ok'];
    }
}

if (!function_exists('forum_create_thread')) {
    /**
     * Insert a new thread + its OP post in a single transaction.
     * Returns ['thread_id', 'thread_slug', 'status'] (status is 'published' or 'pending').
     * Returns null on failure.
     */
    function forum_create_thread(PDO $pdo, int $category_id, int $author_id, string $author_name, string $title, string $body, bool $auto_approve): ?array
    {
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || $body === '') return null;

        $base = forum_slugify($title);
        $slug = forum_unique_slug($pdo, 'forum_threads', $base);
        $status = $auto_approve ? 'published' : 'pending';

        try {
            $pdo->beginTransaction();

            $ins_t = $pdo->prepare(
                "INSERT INTO forum_threads
                   (category_id, slug, title, author_id, author_name, status, last_reply_at, last_reply_by)
                 VALUES
                   (:cid, :slug, :title, :aid, :aname, :status,
                    " . ($auto_approve ? "NOW()" : "NULL") . ",
                    " . ($auto_approve ? ":aname2" : "NULL") . ")"
            );
            $params = [
                'cid' => $category_id, 'slug' => $slug, 'title' => $title,
                'aid' => $author_id, 'aname' => $author_name, 'status' => $status,
            ];
            if ($auto_approve) $params['aname2'] = $author_name;
            $ins_t->execute($params);
            $thread_id = (int)$pdo->lastInsertId();

            $ins_p = $pdo->prepare(
                "INSERT INTO forum_posts
                   (thread_id, author_id, author_name, body, status, is_op)
                 VALUES
                   (:tid, :aid, :aname, :body, :status, 1)"
            );
            $ins_p->execute([
                'tid' => $thread_id, 'aid' => $author_id, 'aname' => $author_name,
                'body' => $body, 'status' => $status,
            ]);

            $pdo->commit();
            return ['thread_id' => $thread_id, 'thread_slug' => $slug, 'status' => $status];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_create_thread: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('forum_create_reply')) {
    /**
     * Insert a reply post. When approved, also bumps the thread's reply_count
     * and last_reply_at/by. Returns ['post_id', 'status'] or null on failure.
     */
    function forum_create_reply(PDO $pdo, int $thread_id, int $author_id, string $author_name, string $body, bool $auto_approve): ?array
    {
        $body = trim($body);
        if ($body === '') return null;
        $status = $auto_approve ? 'published' : 'pending';

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare(
                "INSERT INTO forum_posts
                   (thread_id, author_id, author_name, body, status, is_op)
                 VALUES
                   (:tid, :aid, :aname, :body, :status, 0)"
            );
            $ins->execute([
                'tid' => $thread_id, 'aid' => $author_id, 'aname' => $author_name,
                'body' => $body, 'status' => $status,
            ]);
            $post_id = (int)$pdo->lastInsertId();

            if ($auto_approve) {
                $upd = $pdo->prepare(
                    "UPDATE forum_threads
                     SET reply_count   = reply_count + 1,
                         last_reply_at = NOW(),
                         last_reply_by = :name
                     WHERE id = :id"
                );
                $upd->execute(['name' => $author_name, 'id' => $thread_id]);
            }

            $pdo->commit();
            return ['post_id' => $post_id, 'status' => $status];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_create_reply: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('forum_post_get')) {
    /**
     * Load a post + the thread + category it lives in, in one query.
     * Returns null if the post doesn't exist.
     */
    function forum_post_get(PDO $pdo, int $post_id): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT p.*,
                        t.title AS thread_title,
                        t.slug  AS thread_slug,
                        t.is_locked,
                        c.name  AS category_name,
                        c.slug  AS category_slug
                 FROM forum_posts p
                 JOIN forum_threads t ON t.id = p.thread_id
                 JOIN forum_categories c ON c.id = t.category_id
                 WHERE p.id = :id
                 LIMIT 1"
            );
            $stmt->execute(['id' => $post_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('forum_post_edit')) {
    /**
     * Update a post's body, marking it edited. Returns true on success.
     * Permission is the caller's responsibility (own-post or GM).
     */
    function forum_post_edit(PDO $pdo, int $post_id, string $body, string $editor_name): bool
    {
        $body = trim($body);
        if ($body === '') return false;
        try {
            $stmt = $pdo->prepare(
                "UPDATE forum_posts
                 SET body = :body, edited_at = NOW(), edited_by = :who
                 WHERE id = :id"
            );
            return $stmt->execute(['body' => $body, 'who' => $editor_name, 'id' => $post_id]);
        } catch (PDOException $e) {
            error_log('forum_post_edit: ' . $e->getMessage());
            return false;
        }
    }
}

// ─── Moderation queue (approve / reject pending content) ────────────────────

if (!function_exists('forum_pending_threads_list')) {
    /**
     * Pending threads with their OP body (first post). Used by the admin queue.
     */
    function forum_pending_threads_list(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            return $pdo->query(
                "SELECT t.id, t.slug, t.title, t.author_id, t.author_name, t.created_at,
                        t.category_id, c.name AS category_name, c.slug AS category_slug,
                        p.id AS op_post_id, p.body AS op_body
                 FROM forum_threads t
                 JOIN forum_categories c ON c.id = t.category_id
                 LEFT JOIN forum_posts p ON p.thread_id = t.id AND p.is_op = 1
                 WHERE t.status = 'pending'
                 ORDER BY t.created_at ASC
                 LIMIT $limit"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_pending_threads_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_pending_posts_list')) {
    /**
     * Pending REPLY posts (is_op=0) — the OP rows are reflected in the
     * pending-threads list above and shouldn't be double-counted here.
     */
    function forum_pending_posts_list(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            return $pdo->query(
                "SELECT p.id, p.thread_id, p.author_id, p.author_name, p.body, p.created_at,
                        t.title AS thread_title, t.slug AS thread_slug,
                        c.name AS category_name, c.slug AS category_slug
                 FROM forum_posts p
                 JOIN forum_threads t ON t.id = p.thread_id
                 JOIN forum_categories c ON c.id = t.category_id
                 WHERE p.status = 'pending' AND p.is_op = 0
                 ORDER BY p.created_at ASC
                 LIMIT $limit"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_pending_posts_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('forum_pending_count')) {
    function forum_pending_count(PDO $pdo): int
    {
        try {
            $n  = (int)$pdo->query("SELECT COUNT(*) FROM forum_threads WHERE status = 'pending'")->fetchColumn();
            $n += (int)$pdo->query("SELECT COUNT(*) FROM forum_posts   WHERE status = 'pending' AND is_op = 0")->fetchColumn();
            return $n;
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('forum_approve_thread')) {
    /**
     * Approve a pending thread: flip both the thread row AND its OP post to
     * 'published', and (if not already set) stamp last_reply_at/by from the
     * thread's creation so the category index sorts it correctly.
     */
    function forum_approve_thread(PDO $pdo, int $thread_id): bool
    {
        try {
            $pdo->beginTransaction();
            $u1 = $pdo->prepare(
                "UPDATE forum_threads
                 SET status = 'published',
                     last_reply_at = COALESCE(last_reply_at, created_at),
                     last_reply_by = COALESCE(last_reply_by, author_name)
                 WHERE id = :id AND status = 'pending'"
            );
            $u1->execute(['id' => $thread_id]);
            $changed = $u1->rowCount() > 0;

            $u2 = $pdo->prepare(
                "UPDATE forum_posts SET status = 'published' WHERE thread_id = :tid AND is_op = 1 AND status = 'pending'"
            );
            $u2->execute(['tid' => $thread_id]);

            $pdo->commit();
            return $changed;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_approve_thread: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('forum_approve_post')) {
    /**
     * Approve a pending REPLY: flip the post to 'published' AND bump the
     * parent thread's reply_count / last_reply_at/by.
     */
    function forum_approve_post(PDO $pdo, int $post_id): bool
    {
        try {
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT thread_id, author_name, status, is_op FROM forum_posts WHERE id = :id");
            $sel->execute(['id' => $post_id]);
            $p = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['status'] !== 'pending' || (int)$p['is_op'] === 1) {
                $pdo->rollBack();
                return false;
            }

            $u = $pdo->prepare("UPDATE forum_posts SET status = 'published' WHERE id = :id");
            $u->execute(['id' => $post_id]);

            $bump = $pdo->prepare(
                "UPDATE forum_threads
                 SET reply_count = reply_count + 1,
                     last_reply_at = NOW(),
                     last_reply_by = :name
                 WHERE id = :tid"
            );
            $bump->execute(['name' => $p['author_name'], 'tid' => $p['thread_id']]);

            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_approve_post: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('forum_reject_thread')) {
    /**
     * Reject + hard-delete a pending thread plus all of its posts.
     * Only fires on threads currently in 'pending' status so we don't
     * accidentally delete already-published content.
     */
    function forum_reject_thread(PDO $pdo, int $thread_id): bool
    {
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT status FROM forum_threads WHERE id = :id");
            $check->execute(['id' => $thread_id]);
            if ($check->fetchColumn() !== 'pending') {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare("DELETE FROM forum_posts WHERE thread_id = :id")->execute(['id' => $thread_id]);
            $pdo->prepare("DELETE FROM forum_threads WHERE id = :id AND status = 'pending'")->execute(['id' => $thread_id]);
            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('forum_reject_thread: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('forum_reject_post')) {
    function forum_reject_post(PDO $pdo, int $post_id): bool
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM forum_posts WHERE id = :id AND status = 'pending' AND is_op = 0");
            $stmt->execute(['id' => $post_id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('forum_reject_post: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('forum_posts_in_thread_for_user')) {
    /**
     * Same as forum_posts_in_thread() but viewer-aware: admins see all
     * statuses, the current user sees their own pending posts, anonymous
     * visitors only see published content.
     */
    function forum_posts_in_thread_for_user(PDO $pdo, int $thread_id, ?int $user_id, bool $is_admin = false, int $page = 1, int $per_page = 20): array
    {
        $per_page = max(1, min(100, $per_page));
        $page     = max(1, $page);
        $offset   = ($page - 1) * $per_page;
        try {
            $sql_visibility = $is_admin
                ? "status IN ('published','pending')"
                : ("status = 'published'" . ($user_id ? " OR (status = 'pending' AND author_id = :uid)" : ""));
            $sql = "SELECT id, thread_id, author_id, author_name, body, status, is_op,
                           edited_at, edited_by, created_at
                    FROM forum_posts
                    WHERE thread_id = :tid AND ($sql_visibility)
                    ORDER BY is_op DESC, created_at ASC
                    LIMIT $per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $params = ['tid' => $thread_id];
            if (!$is_admin && $user_id) $params['uid'] = $user_id;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('forum_posts_in_thread_for_user: ' . $e->getMessage());
            return [];
        }
    }
}

// ─── Lookup by username (used by the admin ban form) ─────────────────────────
if (!function_exists('forum_find_account_by_username')) {
    /**
     * Look up an account by case-insensitive username. Returns [id, username]
     * or null.
     */
    function forum_find_account_by_username(PDO $pdo, string $username): ?array
    {
        $username = trim($username);
        if ($username === '') return null;
        try {
            $stmt = $pdo->prepare("SELECT id, username FROM account WHERE username = :u LIMIT 1");
            $stmt->execute(['u' => strtoupper($username)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
