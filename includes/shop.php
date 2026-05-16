<?php
/**
 * Shop helper — in-game Battle Pay store management (reads/writes the repack's
 * `battle_pay_*` tables in the `world` DB).
 *
 * Phase A1 = read-only. Detection + the verified full-shop query only; no
 * write helpers yet (those land in A2/A3).
 *
 * Everything here is defensive: a missing `world` connection, a repack
 * without the battle_pay schema, or a query error must never fatal the
 * admin panel — callers get safe empties + a reason code.
 */

if (!defined('SHOP_CUSTOM_ID_BASE')) {
    // New custom rows are assigned ids ≥ this, to stay clear of repack ids
    // (which are low/sparse) and survive repack re-imports. Used from A2 on.
    define('SHOP_CUSTOM_ID_BASE', 9000);
}

if (!function_exists('shop_required_tables')) {
    function shop_required_tables(): array
    {
        return ['battle_pay_group', 'battle_pay_product', 'battle_pay_product_items', 'battle_pay_entry'];
    }
}

if (!function_exists('shop_tables_present')) {
    /**
     * True only when all 4 core battle_pay_* tables exist in the connected
     * world DB. INFORMATION_SCHEMA-checked so it works across repacks.
     */
    function shop_tables_present(?PDO $pdo_world): bool
    {
        if (!$pdo_world) return false;
        try {
            $need = shop_required_tables();
            $ph   = implode(',', array_fill(0, count($need), '?'));
            $stmt = $pdo_world->prepare(
                "SELECT COUNT(DISTINCT TABLE_NAME) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
            );
            $stmt->execute($need);
            return (int)$stmt->fetchColumn() === count($need);
        } catch (PDOException $e) {
            error_log('shop_tables_present: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_availability')) {
    /**
     * Resolve whether shop management is usable. Returns:
     *   [bool $ok, string $reason]
     * reason ∈ 'ok' | 'disabled' | 'no_world_db' | 'no_tables'
     */
    function shop_availability(?PDO $pdo_world, array $config): array
    {
        if (empty($config['features']['shop_admin'])) return [false, 'disabled'];
        if (!$pdo_world)                               return [false, 'no_world_db'];
        if (!shop_tables_present($pdo_world))          return [false, 'no_tables'];
        return [true, 'ok'];
    }
}

if (!function_exists('shop_counts')) {
    /**
     * Quick stats for the dashboard tile: category + tile (entry) counts.
     * Safe defaults on any error.
     */
    function shop_counts(?PDO $pdo_world): array
    {
        $out = ['categories' => 0, 'tiles' => 0];
        if (!$pdo_world) return $out;
        try {
            $out['categories'] = (int)$pdo_world->query("SELECT COUNT(*) FROM battle_pay_group")->fetchColumn();
            $out['tiles']      = (int)$pdo_world->query("SELECT COUNT(*) FROM battle_pay_entry")->fetchColumn();
        } catch (PDOException $e) {
            error_log('shop_counts: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('shop_get_full')) {
    /**
     * The whole shop, grouped by category for rendering. Uses LEFT JOINs so
     * empty categories AND tiles whose item lookup fails still appear (an
     * honest overview, not just the happy-path INNER-JOIN slice).
     *
     * Returns an ordered array of categories:
     *   [ ['id','idx','name','icon','type','tiles'=>[
     *         ['entry_id','entry_idx','entry_title','banner',
     *          'product_id','product_title','price','product_icon',
     *          'items'=>[ ['itemId','count','item_name'], ... ] ]
     *      ]], ... ]
     */
    function shop_get_full(PDO $pdo_world): array
    {
        try {
            $rows = $pdo_world->query(
                "SELECT g.id AS group_id, g.idx AS group_idx, g.name AS group_name,
                        g.icon AS group_icon, g.type AS group_type,
                        e.id AS entry_id, e.idx AS entry_idx, e.title AS entry_title,
                        e.banner AS entry_banner,
                        p.id AS product_id, p.title AS product_title, p.price AS price,
                        p.icon AS product_icon, p.choiceType AS choice_type,
                        pi.itemId AS item_id, pi.count AS item_count,
                        it.name AS item_name
                 FROM battle_pay_group g
                 LEFT JOIN battle_pay_entry e          ON e.groupId    = g.id
                 LEFT JOIN battle_pay_product p        ON p.id         = e.productId
                 LEFT JOIN battle_pay_product_items pi ON pi.productId = p.id
                 LEFT JOIN item_template it            ON it.entry     = pi.itemId
                 ORDER BY g.idx ASC, g.id ASC, e.idx ASC, e.id ASC, pi.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('shop_get_full: ' . $e->getMessage());
            return [];
        }

        $cats = [];
        foreach ($rows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($cats[$gid])) {
                $cats[$gid] = [
                    'id'    => $gid,
                    'idx'   => (int)$r['group_idx'],
                    'name'  => (string)$r['group_name'],
                    'icon'  => (int)$r['group_icon'],
                    'type'  => (int)$r['group_type'],
                    'tiles' => [],
                ];
            }
            if ($r['entry_id'] === null) continue; // empty category

            $eid = (int)$r['entry_id'];
            if (!isset($cats[$gid]['tiles'][$eid])) {
                $cats[$gid]['tiles'][$eid] = [
                    'entry_id'      => $eid,
                    'entry_idx'     => (int)$r['entry_idx'],
                    'entry_title'   => (string)$r['entry_title'],
                    'banner'        => (int)$r['entry_banner'],
                    'product_id'    => $r['product_id'] !== null ? (int)$r['product_id'] : null,
                    'product_title' => $r['product_title'],
                    'price'         => $r['price'] !== null ? (int)$r['price'] : null,
                    'product_icon'  => $r['product_icon'] !== null ? (int)$r['product_icon'] : null,
                    'choice_type'   => $r['choice_type'] !== null ? (int)$r['choice_type'] : null,
                    'items'         => [],
                ];
            }
            if ($r['item_id'] !== null) {
                $cats[$gid]['tiles'][$eid]['items'][] = [
                    'itemId'    => (int)$r['item_id'],
                    'count'     => (int)$r['item_count'],
                    'item_name' => $r['item_name'], // null if not in item_template
                ];
            }
        }

        // Flatten tile maps back to ordered lists
        $out = [];
        foreach ($cats as $c) {
            $c['tiles'] = array_values($c['tiles']);
            $out[] = $c;
        }
        return $out;
    }
}

// ─── Worldserver "pending restart" flag (Phase A2) ──────────────────────────
// battle_pay_* tables are read into worldserver memory at startup only. After
// ANY shop write the change is invisible in-game until the admin restarts the
// worldserver. We can't detect the restart from PHP, so we raise a persistent
// flag on write and let the admin clear it manually after restarting.
// Stored as a file under cache/shop/ (deny-all .htaccess, gitignored).

if (!function_exists('shop_dirty_flag_path')) {
    function shop_dirty_flag_path(): string
    {
        return __DIR__ . '/../cache/shop/pending_restart.flag';
    }
}
if (!function_exists('shop_mark_dirty')) {
    function shop_mark_dirty(): void
    {
        $p = shop_dirty_flag_path();
        $dir = dirname($p);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($p, (string)time());
    }
}
if (!function_exists('shop_is_dirty')) {
    /** Returns the unix ts of the first un-applied write, or null if clean. */
    function shop_is_dirty(): ?int
    {
        $p = shop_dirty_flag_path();
        if (!is_file($p)) return null;
        $ts = (int)@file_get_contents($p);
        return $ts > 0 ? $ts : time();
    }
}
if (!function_exists('shop_clear_dirty')) {
    function shop_clear_dirty(): void
    {
        $p = shop_dirty_flag_path();
        if (is_file($p)) @unlink($p);
    }
}

// ─── Write helpers — Category CRUD (Phase A2) ───────────────────────────────

if (!function_exists('shop_next_id')) {
    /**
     * Next manual id for a battle_pay table. No AUTO_INCREMENT in this schema.
     * Returns GREATEST(SHOP_CUSTOM_ID_BASE, MAX(id)+1) over the WHOLE table so
     * it (a) prefers the reserved ≥9000 range when repack ids are low (normal)
     * and (b) can never collide with any existing row, even an unusually high
     * repack id. $table is whitelisted (never user input).
     */
    function shop_next_id(PDO $pdo_world, string $table): int
    {
        $allowed = ['battle_pay_group', 'battle_pay_product', 'battle_pay_product_items', 'battle_pay_entry'];
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('shop_next_id: illegal table ' . $table);
        }
        $max = (int)$pdo_world->query("SELECT COALESCE(MAX(id),0) FROM `$table`")->fetchColumn();
        return max(SHOP_CUSTOM_ID_BASE, $max + 1);
    }
}

if (!function_exists('shop_category_get')) {
    function shop_category_get(PDO $pdo_world, int $id): ?array
    {
        try {
            $stmt = $pdo_world->prepare("SELECT id, idx, name, icon, type FROM battle_pay_group WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('shop_category_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('shop_category_add')) {
    /**
     * Insert a new category at the end (idx = max+1). 16-char name limit is the
     * caller's to enforce for UX, but we hard-truncate here as a safety net.
     * Returns the new id, or null on failure.
     */
    function shop_category_add(PDO $pdo_world, string $name, int $icon, int $type): ?int
    {
        $name = mb_substr(trim($name), 0, 16);
        if ($name === '') return null;
        $type = $type === 1 ? 1 : 0;
        try {
            $pdo_world->beginTransaction();
            $id  = shop_next_id($pdo_world, 'battle_pay_group');
            $idx = (int)$pdo_world->query("SELECT COALESCE(MAX(idx),0)+1 FROM battle_pay_group")->fetchColumn();
            $stmt = $pdo_world->prepare(
                "INSERT INTO battle_pay_group (id, idx, name, icon, type)
                 VALUES (:id, :idx, :name, :icon, :type)"
            );
            $stmt->execute(['id' => $id, 'idx' => $idx, 'name' => $name, 'icon' => max(0, $icon), 'type' => $type]);
            $pdo_world->commit();
            return $id;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_category_add: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('shop_category_update')) {
    function shop_category_update(PDO $pdo_world, int $id, string $name, int $icon, int $type): bool
    {
        $name = mb_substr(trim($name), 0, 16);
        if ($name === '' || $id <= 0) return false;
        $type = $type === 1 ? 1 : 0;
        try {
            $stmt = $pdo_world->prepare(
                "UPDATE battle_pay_group SET name = :name, icon = :icon, type = :type WHERE id = :id"
            );
            return $stmt->execute(['name' => $name, 'icon' => max(0, $icon), 'type' => $type, 'id' => $id]);
        } catch (PDOException $e) {
            error_log('shop_category_update: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_category_delete')) {
    /**
     * Delete a category. Transaction:
     *   1. delete its battle_pay_entry rows
     *   2. (if $cleanup_orphans) delete products that are now referenced by
     *      ZERO entries anywhere — and their product_items. A product can be
     *      shared by entries in other categories, so "orphan" = no remaining
     *      referencing entry, never a blind delete.
     *   3. delete the battle_pay_group row
     */
    function shop_category_delete(PDO $pdo_world, int $id, bool $cleanup_orphans = true): bool
    {
        if ($id <= 0) return false;
        try {
            $pdo_world->beginTransaction();

            // products referenced by THIS category's entries (candidates for orphan check)
            $cand = [];
            if ($cleanup_orphans) {
                $q = $pdo_world->prepare("SELECT DISTINCT productId FROM battle_pay_entry WHERE groupId = :id");
                $q->execute(['id' => $id]);
                $cand = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            }

            $pdo_world->prepare("DELETE FROM battle_pay_entry WHERE groupId = :id")->execute(['id' => $id]);

            if ($cleanup_orphans && $cand) {
                $stillRef = $pdo_world->prepare(
                    "SELECT COUNT(*) FROM battle_pay_entry WHERE productId = :pid"
                );
                $delPi = $pdo_world->prepare("DELETE FROM battle_pay_product_items WHERE productId = :pid");
                $delP  = $pdo_world->prepare("DELETE FROM battle_pay_product WHERE id = :pid");
                foreach ($cand as $pid) {
                    $stillRef->execute(['pid' => $pid]);
                    if ((int)$stillRef->fetchColumn() === 0) {
                        $delPi->execute(['pid' => $pid]);
                        $delP->execute(['pid' => $pid]);
                    }
                }
            }

            $pdo_world->prepare("DELETE FROM battle_pay_group WHERE id = :id")->execute(['id' => $id]);
            $pdo_world->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_category_delete: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_category_move')) {
    /**
     * Move a category one slot up/down in display order. Repack data has
     * duplicate idx values (e.g. Featured & Balance both idx=1), so a naive
     * idx-swap is unreliable. Instead: take the full list ordered by
     * (idx, id) — the same order the viewer uses — swap the target with its
     * neighbour, then rewrite idx = 1..N for ALL categories. Always takes
     * effect and de-dupes idx as a bonus. $dir ∈ 'up' | 'down'.
     */
    function shop_category_move(PDO $pdo_world, int $id, string $dir): bool
    {
        if ($id <= 0 || !in_array($dir, ['up', 'down'], true)) return false;
        try {
            $ids = array_map('intval', $pdo_world->query(
                "SELECT id FROM battle_pay_group ORDER BY idx ASC, id ASC"
            )->fetchAll(PDO::FETCH_COLUMN));

            $pos = array_search($id, $ids, true);
            if ($pos === false) return false;
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap < 0 || $swap >= count($ids)) return false; // already at edge

            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

            $pdo_world->beginTransaction();
            $upd = $pdo_world->prepare("UPDATE battle_pay_group SET idx = :idx WHERE id = :id");
            foreach ($ids as $i => $cid) {
                $upd->execute(['idx' => $i + 1, 'id' => $cid]);
            }
            $pdo_world->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_category_move: ' . $e->getMessage());
            return false;
        }
    }
}
