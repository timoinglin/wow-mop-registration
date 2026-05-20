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
