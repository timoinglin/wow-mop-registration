<?php
/**
 * MoP profession / secondary-skill id → display name.
 *
 * Keep tight — only the skills we actually render. Anything not in the
 * map is silently skipped by the Armory Professions panel, so we never
 * surface "Skill #ID" garbage. Add entries as needed.
 */

if (!function_exists('wl_skill_name')) {
    function wl_skill_name(int $id): ?string
    {
        static $m = [
            // Primary professions
            164 => 'Blacksmithing',
            165 => 'Leatherworking',
            171 => 'Alchemy',
            182 => 'Herbalism',
            186 => 'Mining',
            197 => 'Tailoring',
            202 => 'Engineering',
            333 => 'Enchanting',
            393 => 'Skinning',
            755 => 'Jewelcrafting',
            773 => 'Inscription',
            // Secondary skills
            129 => 'First Aid',
            185 => 'Cooking',
            356 => 'Fishing',
            794 => 'Archaeology',
        ];
        return $m[$id] ?? null;
    }
}

if (!function_exists('wl_skill_is_primary')) {
    /** True for the 11 primary professions; false for secondaries (first aid, cooking, fishing, archaeology). */
    function wl_skill_is_primary(int $id): bool
    {
        return in_array($id, [164, 165, 171, 182, 186, 197, 202, 333, 393, 755, 773], true);
    }
}

if (!function_exists('wl_skill_icon')) {
    /**
     * Wowhead-hosted profession icon URL (medium = 36x36 jpg).
     * Names match the official tradeskill icon textures so the Armory
     * looks visually consistent with the in-game profession UI.
     */
    function wl_skill_icon(int $id): ?string
    {
        static $m = [
            164 => 'trade_blacksmithing',
            165 => 'trade_leatherworking',
            171 => 'trade_alchemy',
            182 => 'trade_herbalism',
            186 => 'trade_mining',
            197 => 'trade_tailoring',
            202 => 'trade_engineering',
            333 => 'trade_engraving',          // Enchanting
            393 => 'inv_misc_pelt_wolf_01',    // Skinning (no trade_skinning texture exists)
            755 => 'inv_misc_gem_diamond_07',  // Jewelcrafting
            773 => 'inv_inscription_tradeskill01',
            129 => 'inv_misc_bandage_15',      // First Aid
            185 => 'inv_misc_food_15',         // Cooking
            356 => 'trade_fishing',
            794 => 'trade_archaeology',
        ];
        $name = $m[$id] ?? null;
        return $name ? "https://wow.zamimg.com/images/wow/icons/medium/{$name}.jpg" : null;
    }
}
