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

// ─── Item search / validation (Phase A3) ────────────────────────────────────

if (!function_exists('shop_wowhead_item_url')) {
    /**
     * MoP-Classic Wowhead URL for an item — same domain the armory uses, so
     * the existing power.js widget iconises + tooltips these links.
     */
    function shop_wowhead_item_url(int $itemId): string
    {
        return 'https://www.wowhead.com/mop-classic/item=' . $itemId;
    }
}

if (!function_exists('shop_item_search')) {
    /**
     * Search item_template by name (or exact entry id if the term is numeric).
     * Only the verified columns are touched (entry, name). Each result also
     * carries `suggest_icon`: the FileDataID of an existing battle_pay_product
     * that already grants this item (if any) — used to auto-prefill the tile
     * icon so admins rarely have to hunt a FileDataID by hand.
     * Returns [ ['entry'=>int,'name'=>string,'suggest_icon'=>int], ... ].
     */
    function shop_item_search(PDO $pdo_world, string $term, int $limit = 25): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) return [];
        $limit = max(1, min(50, $limit));
        $iconSub = "(SELECT p.icon FROM battle_pay_product_items spi
                      JOIN battle_pay_product p ON p.id = spi.productId
                      WHERE spi.itemId = it.entry AND p.icon > 0 LIMIT 1)";
        try {
            if (ctype_digit($term)) {
                $stmt = $pdo_world->prepare(
                    "SELECT it.entry, it.name, $iconSub AS suggest_icon
                     FROM item_template it
                     WHERE it.entry = :id OR it.name LIKE :q
                     ORDER BY (it.entry = :id2) DESC, it.name ASC LIMIT $limit"
                );
                $stmt->execute(['id' => (int)$term, 'id2' => (int)$term, 'q' => '%' . $term . '%']);
            } else {
                $stmt = $pdo_world->prepare(
                    "SELECT it.entry, it.name, $iconSub AS suggest_icon
                     FROM item_template it
                     WHERE it.name LIKE :q ORDER BY it.name ASC LIMIT $limit"
                );
                $stmt->execute(['q' => '%' . $term . '%']);
            }
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'entry'        => (int)$r['entry'],
                    'name'         => (string)$r['name'],
                    'suggest_icon' => (int)($r['suggest_icon'] ?? 0),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log('shop_item_search: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('shop_icon_catalog')) {
    /**
     * Distinct icons already in use by products, with a representative title,
     * for the "copy icon from an existing tile" picker. Ordered by title.
     * Returns [ ['icon'=>int,'label'=>string], ... ].
     */
    function shop_icon_catalog(PDO $pdo_world): array
    {
        try {
            $rows = $pdo_world->query(
                "SELECT icon, MIN(title) AS label
                 FROM battle_pay_product
                 WHERE icon > 0 AND title <> ''
                 GROUP BY icon ORDER BY label ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['icon' => (int)$r['icon'], 'label' => (string)$r['label']];
            }
            return $out;
        } catch (PDOException $e) {
            error_log('shop_icon_catalog: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('shop_item_name')) {
    /** Item name for an entry id, or null if it's not in item_template. */
    function shop_item_name(PDO $pdo_world, int $entry): ?string
    {
        if ($entry <= 0) return null;
        try {
            $stmt = $pdo_world->prepare("SELECT name FROM item_template WHERE entry = :e LIMIT 1");
            $stmt->execute(['e' => $entry]);
            $n = $stmt->fetchColumn();
            return $n === false ? null : (string)$n;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('shop_categories_simple')) {
    /** id => name list in display order, for the move/category dropdown. */
    function shop_categories_simple(PDO $pdo_world): array
    {
        try {
            $rows = $pdo_world->query(
                "SELECT id, name FROM battle_pay_group ORDER BY idx ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) $out[(int)$r['id']] = (string)$r['name'];
            return $out;
        } catch (PDOException $e) {
            error_log('shop_categories_simple: ' . $e->getMessage());
            return [];
        }
    }
}

// ─── Tile (product + product_items + entry) CRUD (Phase A3) ──────────────────

if (!function_exists('shop_tile_get')) {
    /**
     * Full tile for the editor: the entry row + its product + every
     * product_items row (with resolved item name). null if not found.
     */
    function shop_tile_get(PDO $pdo_world, int $entry_id): ?array
    {
        if ($entry_id <= 0) return null;
        try {
            $stmt = $pdo_world->prepare(
                "SELECT e.id AS entry_id, e.groupId, e.productId, e.idx AS entry_idx,
                        e.title AS entry_title, e.description AS entry_desc,
                        e.icon AS entry_icon, e.displayId AS entry_displayId,
                        e.banner, e.flags AS entry_flags,
                        p.title AS p_title, p.description AS p_desc, p.icon AS p_icon,
                        p.price, p.discount, p.displayId AS p_displayId,
                        p.type AS p_type, p.choiceType, p.flags AS p_flags, p.flagsInfo
                 FROM battle_pay_entry e
                 JOIN battle_pay_product p ON p.id = e.productId
                 WHERE e.id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $entry_id]);
            $tile = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tile) return null;

            $pi = $pdo_world->prepare(
                "SELECT pi.id, pi.itemId, pi.count, it.name AS item_name
                 FROM battle_pay_product_items pi
                 LEFT JOIN item_template it ON it.entry = pi.itemId
                 WHERE pi.productId = :pid ORDER BY pi.id ASC"
            );
            $pi->execute(['pid' => (int)$tile['productId']]);
            $tile['items'] = $pi->fetchAll(PDO::FETCH_ASSOC);
            return $tile;
        } catch (PDOException $e) {
            error_log('shop_tile_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('shop_validate_items')) {
    /**
     * Validate a submitted item set. Returns [bool $ok, $cleanRows|$badId].
     * On success: array of ['itemId'=>int,'count'=>int] (count clamped ≥1).
     * On failure: the first itemId not present in item_template.
     */
    function shop_validate_items(PDO $pdo_world, array $items): array
    {
        $clean = [];
        foreach ($items as $row) {
            $iid = (int)($row['itemId'] ?? 0);
            $cnt = max(1, (int)($row['count'] ?? 1));
            if ($iid <= 0) continue;
            if (shop_item_name($pdo_world, $iid) === null) {
                return [false, $iid];
            }
            $clean[] = ['itemId' => $iid, 'count' => $cnt];
        }
        if (empty($clean)) return [false, 0]; // need at least one valid item
        return [true, $clean];
    }
}

if (!function_exists('shop_tile_add')) {
    /**
     * Create a tile = product + product_items + entry, transactionally.
     * New products get the verified known-good single-item defaults
     * (type=0, choiceType=1, flags=47, flagsInfo=0). entry.icon mirrors
     * product.icon, banner=2 (mount-tile value). Returns new entry id.
     *
     * $d: title, description, price, discount, icon, displayId, items[]
     */
    function shop_tile_add(PDO $pdo_world, int $groupId, array $d): ?int
    {
        if ($groupId <= 0) return null;
        $title = mb_substr(trim((string)($d['title'] ?? '')), 0, 50);
        $desc  = mb_substr((string)($d['description'] ?? ''), 0, 500);
        if ($title === '') return null;
        $price    = max(0, (int)($d['price'] ?? 0));
        $discount = max(0, (int)($d['discount'] ?? 0));
        $icon     = max(0, (int)($d['icon'] ?? 0));
        $displayId = max(0, (int)($d['displayId'] ?? 0));
        $items    = $d['items'] ?? [];
        if (empty($items)) return null;

        try {
            $pdo_world->beginTransaction();
            $pid = shop_next_id($pdo_world, 'battle_pay_product');
            $pdo_world->prepare(
                "INSERT INTO battle_pay_product
                   (id, title, description, icon, price, discount, displayId, type, choiceType, flags, flagsInfo)
                 VALUES (:id,:t,:d,:i,:pr,:disc,:disp,0,1,47,0)"
            )->execute(['id'=>$pid,'t'=>$title,'d'=>$desc,'i'=>$icon,'pr'=>$price,'disc'=>$discount,'disp'=>$displayId]);

            $insPi = $pdo_world->prepare(
                "INSERT INTO battle_pay_product_items (id, itemId, count, productId)
                 VALUES (:id,:iid,:c,:pid)"
            );
            foreach ($items as $it) {
                $insPi->execute([
                    'id'  => shop_next_id($pdo_world, 'battle_pay_product_items'),
                    'iid' => (int)$it['itemId'],
                    'c'   => max(1, (int)$it['count']),
                    'pid' => $pid,
                ]);
            }

            $eid = shop_next_id($pdo_world, 'battle_pay_entry');
            $idx = (int)$pdo_world->query(
                "SELECT COALESCE(MAX(idx),0)+1 FROM battle_pay_entry WHERE groupId = " . (int)$groupId
            )->fetchColumn();
            $pdo_world->prepare(
                "INSERT INTO battle_pay_entry
                   (id, groupId, productId, idx, title, description, icon, displayId, banner, flags)
                 VALUES (:id,:g,:p,:idx,:t,:d,:i,:disp,2,0)"
            )->execute(['id'=>$eid,'g'=>$groupId,'p'=>$pid,'idx'=>$idx,'t'=>$title,'d'=>$desc,'i'=>$icon,'disp'=>$displayId]);

            $pdo_world->commit();
            return $eid;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_tile_add: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('shop_tile_update')) {
    /**
     * Update a tile's user-facing fields. Arcane product columns
     * (type/choiceType/flags/flagsInfo) and entry banner/flags are
     * PRESERVED — never clobbered (some existing products legitimately
     * differ, e.g. boost/balance). Moving to another category appends the
     * tile to the end of the new category's order. product_items are
     * fully replaced from the submitted (pre-validated) set.
     */
    function shop_tile_update(PDO $pdo_world, int $entry_id, array $d): bool
    {
        if ($entry_id <= 0) return false;
        $title = mb_substr(trim((string)($d['title'] ?? '')), 0, 50);
        $desc  = mb_substr((string)($d['description'] ?? ''), 0, 500);
        if ($title === '') return false;
        $price    = max(0, (int)($d['price'] ?? 0));
        $discount = max(0, (int)($d['discount'] ?? 0));
        $icon     = max(0, (int)($d['icon'] ?? 0));
        $displayId = max(0, (int)($d['displayId'] ?? 0));
        $newGroup = (int)($d['groupId'] ?? 0);
        $items    = $d['items'] ?? [];
        if (empty($items)) return false;

        try {
            $cur = $pdo_world->prepare("SELECT productId, groupId FROM battle_pay_entry WHERE id = :id");
            $cur->execute(['id' => $entry_id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            $pid = (int)$row['productId'];
            $oldGroup = (int)$row['groupId'];

            $pdo_world->beginTransaction();

            $pdo_world->prepare(
                "UPDATE battle_pay_product
                 SET title=:t, description=:d, icon=:i, price=:pr, discount=:disc, displayId=:disp
                 WHERE id=:pid"
            )->execute(['t'=>$title,'d'=>$desc,'i'=>$icon,'pr'=>$price,'disc'=>$discount,'disp'=>$displayId,'pid'=>$pid]);

            // Move category? append to new group's order.
            if ($newGroup > 0 && $newGroup !== $oldGroup) {
                $nidx = (int)$pdo_world->query(
                    "SELECT COALESCE(MAX(idx),0)+1 FROM battle_pay_entry WHERE groupId = " . $newGroup
                )->fetchColumn();
                $pdo_world->prepare(
                    "UPDATE battle_pay_entry
                     SET groupId=:g, idx=:idx, title=:t, description=:d, icon=:i, displayId=:disp
                     WHERE id=:eid"
                )->execute(['g'=>$newGroup,'idx'=>$nidx,'t'=>$title,'d'=>$desc,'i'=>$icon,'disp'=>$displayId,'eid'=>$entry_id]);
            } else {
                $pdo_world->prepare(
                    "UPDATE battle_pay_entry
                     SET title=:t, description=:d, icon=:i, displayId=:disp
                     WHERE id=:eid"
                )->execute(['t'=>$title,'d'=>$desc,'i'=>$icon,'disp'=>$displayId,'eid'=>$entry_id]);
            }

            // Replace the product_items set
            $pdo_world->prepare("DELETE FROM battle_pay_product_items WHERE productId = :pid")
                      ->execute(['pid' => $pid]);
            $insPi = $pdo_world->prepare(
                "INSERT INTO battle_pay_product_items (id, itemId, count, productId)
                 VALUES (:id,:iid,:c,:pid)"
            );
            foreach ($items as $it) {
                $insPi->execute([
                    'id'  => shop_next_id($pdo_world, 'battle_pay_product_items'),
                    'iid' => (int)$it['itemId'],
                    'c'   => max(1, (int)$it['count']),
                    'pid' => $pid,
                ]);
            }

            $pdo_world->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_tile_update: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_tile_delete')) {
    /**
     * Delete a tile (entry). If its product is then referenced by ZERO
     * entries anywhere, the product + its product_items are removed too
     * (a product can be shared across entries — never a blind delete).
     */
    function shop_tile_delete(PDO $pdo_world, int $entry_id): bool
    {
        if ($entry_id <= 0) return false;
        try {
            $q = $pdo_world->prepare("SELECT productId FROM battle_pay_entry WHERE id = :id");
            $q->execute(['id' => $entry_id]);
            $pid = $q->fetchColumn();
            if ($pid === false) return false;
            $pid = (int)$pid;

            $pdo_world->beginTransaction();
            $pdo_world->prepare("DELETE FROM battle_pay_entry WHERE id = :id")->execute(['id' => $entry_id]);

            $still = $pdo_world->prepare("SELECT COUNT(*) FROM battle_pay_entry WHERE productId = :pid");
            $still->execute(['pid' => $pid]);
            if ((int)$still->fetchColumn() === 0) {
                $pdo_world->prepare("DELETE FROM battle_pay_product_items WHERE productId = :pid")->execute(['pid' => $pid]);
                $pdo_world->prepare("DELETE FROM battle_pay_product WHERE id = :pid")->execute(['pid' => $pid]);
            }
            $pdo_world->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_tile_delete: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_tile_move')) {
    /**
     * Reorder a tile within its OWN category (entry.idx). Reindexes 1..N
     * over that group's tiles in (idx,id) order — robust against duplicate
     * idx, same approach as shop_category_move. $dir ∈ 'up' | 'down'.
     */
    function shop_tile_move(PDO $pdo_world, int $entry_id, string $dir): bool
    {
        if ($entry_id <= 0 || !in_array($dir, ['up', 'down'], true)) return false;
        try {
            $g = $pdo_world->prepare("SELECT groupId FROM battle_pay_entry WHERE id = :id");
            $g->execute(['id' => $entry_id]);
            $gid = $g->fetchColumn();
            if ($gid === false) return false;
            $gid = (int)$gid;

            $ids = array_map('intval', $pdo_world->query(
                "SELECT id FROM battle_pay_entry WHERE groupId = $gid ORDER BY idx ASC, id ASC"
            )->fetchAll(PDO::FETCH_COLUMN));

            $pos = array_search($entry_id, $ids, true);
            if ($pos === false) return false;
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap < 0 || $swap >= count($ids)) return false;
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

            $pdo_world->beginTransaction();
            $upd = $pdo_world->prepare("UPDATE battle_pay_entry SET idx = :idx WHERE id = :id");
            foreach ($ids as $i => $eid) $upd->execute(['idx' => $i + 1, 'id' => $eid]);
            $pdo_world->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo_world->inTransaction()) $pdo_world->rollBack();
            error_log('shop_tile_move: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('shop_price_update')) {
    /** Quick price edit on a product. Price clamped to unsigned int. */
    function shop_price_update(PDO $pdo_world, int $product_id, int $price): bool
    {
        if ($product_id <= 0) return false;
        $price = max(0, $price);
        try {
            $stmt = $pdo_world->prepare("UPDATE battle_pay_product SET price = :p WHERE id = :id");
            return $stmt->execute(['p' => $price, 'id' => $product_id]);
        } catch (PDOException $e) {
            error_log('shop_price_update: ' . $e->getMessage());
            return false;
        }
    }
}
