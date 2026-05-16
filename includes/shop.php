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
