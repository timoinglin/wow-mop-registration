<?php
/**
 * MoP 5.4 reputation factions — id → display name.
 *
 * Scope: the major MoP-era factions players actively grind (Klaxxi
 * paragons, Shado-Pan Assault, Black Prince, etc.). Pre-MoP city
 * factions and obscure quest reputations are intentionally left out —
 * the Armory panel is meant to surface meaningful progress, not every
 * numeric scoreboard row. The Tillers' individual-NPC friendships
 * are also skipped (rep IDs vary by core).
 */

if (!function_exists('wl_faction_name')) {
    function wl_faction_name(int $id): ?string
    {
        static $m = [
            // MoP 5.0 — Pandaria launch
            1216 => "The Klaxxi",
            1228 => "The Lorewalkers",
            1242 => "The Anglers",
            1269 => "The Tillers",
            1270 => "Pearlfin Jinyu",
            1271 => "Forest Hozen",
            1272 => "Operation: Shieldwall",
            1273 => "Dominance Offensive",
            1275 => "The Golden Lotus",
            1276 => "Order of the Cloud Serpent",
            1277 => "Shado-Pan",
            1278 => "Sunreaver Onslaught",
            1279 => "Kirin Tor Offensive",
            1281 => "The Black Prince",
            1283 => "Shado-Pan Assault",
            1341 => "The August Celestials",
            // MoP 5.4 — Siege of Orgrimmar
            1374 => "Emperor Shaohao",
        ];
        return $m[$id] ?? null;
    }
}

if (!function_exists('wl_rep_rank')) {
    /**
     * Cumulative standing (signed int as stored in character_reputation.standing)
     * → rank index 0..7. TrinityCore stores standing as "earned minus the
     * bottom of Neutral", so Neutral starts at 0 and Exalted starts at 42000.
     */
    function wl_rep_rank(int $standing): int
    {
        if ($standing >= 42000) return 7;  // Exalted
        if ($standing >= 21000) return 6;  // Revered
        if ($standing >=  9000) return 5;  // Honored
        if ($standing >=  3000) return 4;  // Friendly
        if ($standing >=     0) return 3;  // Neutral
        if ($standing >= -3000) return 2;  // Unfriendly
        if ($standing >= -6000) return 1;  // Hostile
        return 0;                          // Hated
    }
}

if (!function_exists('wl_rep_rank_label')) {
    function wl_rep_rank_label(int $rank): string
    {
        $L = ['Hated','Hostile','Unfriendly','Neutral','Friendly','Honored','Revered','Exalted'];
        return $L[max(0, min(7, $rank))];
    }
}

if (!function_exists('wl_rep_rank_color')) {
    /** Hex colour for a rank — matches the in-game palette feel. */
    function wl_rep_rank_color(int $rank): string
    {
        $C = [
            '#c64545', // Hated
            '#cc6633', // Hostile
            '#cc8c33', // Unfriendly
            '#9a9a9a', // Neutral
            '#5dd87c', // Friendly
            '#5dd87c', // Honored
            '#7ec1ff', // Revered
            '#ffd96a', // Exalted (gold)
        ];
        return $C[max(0, min(7, $rank))];
    }
}

if (!function_exists('wl_rep_progress')) {
    /**
     * Returns ['value' => int, 'max' => int] showing progress WITHIN the
     * current rank. Exalted shows as 999/999 (full bar, no further progress).
     */
    function wl_rep_progress(int $standing): array
    {
        $bottoms = [-42000, -6000, -3000, 0, 3000, 9000, 21000, 42000];
        $widths  = [ 36000,  3000,  3000, 3000, 6000, 12000, 21000,  999];
        $rank = wl_rep_rank($standing);
        if ($rank === 7) return ['value' => 999, 'max' => 999];
        $value = $standing - $bottoms[$rank];
        return ['value' => max(0, $value), 'max' => $widths[$rank]];
    }
}
