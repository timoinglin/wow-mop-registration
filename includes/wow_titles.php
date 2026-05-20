<?php
/**
 * Curated MoP-era character-title id → display string (suffix form).
 *
 * Deliberately a small subset — only the well-known ones — so we never
 * fall back to "Title #123" which would look broken. If the id isn't
 * mapped, the caller hides the Title row. Add entries as needed.
 *
 * The strings here are the bare title text (suffix form). The Armory
 * renders them as "Name <title>" (e.g., "Charname  the Patient").
 */

if (!function_exists('wl_title_text')) {
    function wl_title_text(int $id): ?string
    {
        if ($id <= 0) return null;
        static $m = [
            // Classic / TBC / Wrath leftovers still used in MoP
            1   => 'Private',           2   => 'Corporal',         3   => 'Sergeant',
            4   => 'Master Sergeant',   5   => 'Sergeant Major',   6   => 'Knight',
            7   => 'Knight-Lieutenant', 8   => 'Knight-Captain',   9   => 'Knight-Champion',
            10  => 'Lieutenant Commander', 11 => 'Commander',      12  => 'Marshal',
            13  => 'Field Marshal',     14  => 'Grand Marshal',
            15  => 'Scout',             16  => 'Grunt',            17  => 'Sergeant',
            18  => 'Senior Sergeant',   19  => 'First Sergeant',   20  => 'Stone Guard',
            21  => 'Blood Guard',       22  => 'Legionnaire',      23  => 'Centurion',
            24  => 'Champion',          25  => 'Lieutenant General', 26 => 'General',
            27  => 'Warlord',           28  => 'High Warlord',
            // Achievement-era staples
            41  => 'the Bold',
            42  => 'the Patient',
            43  => 'the Argent Champion',
            45  => 'Champion of the Naaru',
            47  => 'of the Shattered Sun',
            71  => 'Hand of A\'dal',
            72  => 'Champion of the Naaru',
            92  => 'Conqueror of Naxxramas',
            93  => 'Champion of the Frozen Wastes',
            95  => 'Twilight Vanquisher',
            110 => 'the Diplomat',
            111 => 'the Explorer',
            121 => 'the Astral Walker',
            122 => 'Salty',
            145 => 'Brewmaster',
            146 => 'the Insane',
            159 => 'the Light of Dawn',
            164 => 'the Kingslayer',
            177 => 'Bloodsail Admiral',
            // Cataclysm
            193 => 'Defender of a Shattered World',
            199 => 'the Camel-Hoarder',
            // MoP
            219 => 'the Tranquil Master',
            220 => 'the Hallowed',
            245 => 'Stormbreaker',
            247 => 'of the Vale',
            260 => 'the Wakener',
            264 => 'Strawberry',
        ];
        return $m[$id] ?? null;
    }
}
