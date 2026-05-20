<?php
/**
 * MoP 5.4.8 talents — per class, 6 tiers x 3 talents.
 * Tiers correspond to character levels 15 / 30 / 45 / 60 / 75 / 90.
 * Used by the Armory Talents grid (in-game-style 6x3 view).
 *
 * Every non-zero entry was verified against Wowhead's mop-classic
 * spell=ID redirect slug (one-shot pass; see git history). Cells
 * marked 0 are slots where the candidate ID didn't resolve to the
 * expected Wowhead talent — the Armory renderer treats 0 as
 * "unknown, hide" (chip view continues to show the chosen spell).
 *
 * Coverage: 192 / 198 cells verified. Zeroed slots:
 *   Rogue Lv75 third (Versatility) · Rogue Lv90 third
 *   Priest Lv45 third (Power Word: Solace / Insanity)
 *   Shaman Lv45 third (Totemic Projection)
 *   Mage Lv90 third (Incanter's Ward)
 *   Monk Lv15 third (Momentum)
 */

if (!function_exists('wl_mop_talents')) {
    function wl_mop_talents(int $classId): ?array
    {
        static $m = [
            // Warrior
            1 => [
                [103826, 103827, 103828], // 15: juggernaut, double-time, warbringer
                [55694,  29838,  103840], // 30: enraged-regeneration, second-wind, impending-victory
                [107566, 12323,  102060], // 45: staggering-shout, piercing-howl, disrupting-shout
                [46924,  46968,  118000], // 60: bladestorm, shockwave, dragon-roar
                [114028, 114029, 114030], // 75: mass-spell-reflection, safeguard, vigilance
                [107574, 12292,  107570], // 90: avatar, bloodbath, storm-bolt
            ],
            // Paladin
            2 => [
                [85499,  87172,  26023 ], // 15: speed-of-light, long-arm-of-the-law, pursuit-of-justice
                [105593, 20066,  110300], // 30: fist-of-justice, repentance, burden-of-guilt
                [85804,  114163, 20925 ], // 45: selfless-healer, eternal-flame, sacred-shield
                [114039, 114154, 105622], // 60: hand-of-purity, unbreakable-spirit, clemency
                [105809, 53376,  86172 ], // 75: holy-avenger, sanctified-wrath, divine-purpose
                [114165, 114158, 114157], // 90: holy-prism, lights-hammer, execution-sentence
            ],
            // Hunter
            3 => [
                [109215, 109298, 118675], // 15: posthaste, narrow-escape, crouching-tiger-hidden-chimera
                [34490,  19386,  109248], // 30: silencing-shot, wyvern-sting, binding-shot
                [109304, 109260, 109212], // 45: exhilaration, aspect-of-the-iron-hawk, spirit-bond
                [82726,  120679, 109306], // 60: fervor, dire-beast, thrill-of-the-hunt
                [131894, 130392, 120697], // 75: a-murder-of-crows, blink-strikes, lynx-rush
                [117050, 109259, 120360], // 90: glaive-toss, powershot, barrage
            ],
            // Rogue
            4 => [
                [14062,  108208, 108209], // 15: nightstalker, subterfuge, shadow-focus
                [26679,  108210, 74001 ], // 30: deadly-throw, nerve-strike, combat-readiness
                [31230,  108211, 79008 ], // 45: cheat-death, leeching-poison, elusiveness
                [138106, 36554,  108212], // 60: cloak-and-dagger, shadowstep, burst-of-speed
                [14185,  114014, 0     ], // 75: preparation, shuriken-toss, [versatility id unverified]
                [114015, 137619, 0     ], // 90: anticipation, marked-for-death, [third id unverified]
            ],
            // Priest
            5 => [
                [108920, 108921, 605   ], // 15: void-tendrils, psyfiend, dominate-mind
                [64129,  121536, 108942], // 30: body-and-soul, angelic-feather, phantasm
                [109186, 123040, 0     ], // 45: from-darkness-comes-light, mindbender, [third id unverified]
                [19236,  112833, 108945], // 60: desperate-prayer, spectral-guise, angelic-bulwark
                [109142, 10060,  109175], // 75: twist-of-fate, power-infusion, divine-insight
                [121135, 110744, 120517], // 90: cascade, divine-star, halo
            ],
            // Death Knight
            6 => [
                [108170, 123693, 115989], // 15: roiling-blood, plague-leech, unholy-blight
                [49039,  51052,  114556], // 30: lichborne, anti-magic-zone, purgatory
                [96268,  50041,  108194], // 45: deaths-advance, chilblains, asphyxiate
                [48743,  108196, 119975], // 60: death-pact, death-siphon, conversion
                [45529,  81229,  51460 ], // 75: blood-tap, runic-empowerment, runic-corruption
                [108199, 108200, 108201], // 90: gorefiends-grasp, remorseless-winter, desecrated-ground
            ],
            // Shaman
            7 => [
                [30884,  114893, 108271], // 15: natures-guardian, stone-bulwark, astral-shift
                [63374,  51485,  108273], // 30: frozen-power, earthgrab-totem, windwalk-totem
                [108285, 108284, 0     ], // 45: call-of-the-elements, totemic-persistence, [totemic-projection unverified]
                [16166,  16188,  108283], // 60: elemental-mastery, ancestral-swiftness, echo-of-the-elements
                [147074, 108281, 108282], // 75: rushing-streams, ancestral-guidance, conductivity
                [117012, 117013, 117014], // 90: unleashed-fury, primal-elementalist, elemental-blast
            ],
            // Mage
            8 => [
                [12043,  108843, 108839], // 15: presence-of-mind, blazing-speed, ice-floes
                [115610, 140468, 11426 ], // 30: temporal-shield, flameglow, ice-barrier
                [113724, 111264, 102051], // 45: ring-of-frost, ice-ward, frostjaw
                [110959, 86949,  11958 ], // 60: greater-invisibility, cauterize, cold-snap
                [114923, 44457,  112948], // 75: nether-tempest, living-bomb, frost-bomb
                [114003, 116011, 0     ], // 90: invocation, rune-of-power, [incanter's ward unverified]
            ],
            // Warlock
            9 => [
                [108359, 108370, 108371], // 15: dark-regeneration, soul-leech, harvest-life
                [5484,   6789,   30283 ], // 30: howl-of-terror, mortal-coil, shadowfury
                [108415, 108416, 110913], // 45: soul-link, sacrificial-pact, dark-bargain
                [111397, 111400, 108482], // 60: blood-horror, burning-rush, unbound-will
                [108499, 108501, 108503], // 75: grimoire-of-supremacy, grimoire-of-service, grimoire-of-sacrifice
                [108505, 137587, 108508], // 90: archimondes-darkness, kiljaedens-cunning, mannoroths-fury
            ],
            // Monk
            10 => [
                [115173, 116841, 0     ], // 15: celerity, tigers-lust, [momentum id unverified]
                [115098, 124081, 123986], // 30: chi-wave, zen-sphere, chi-burst
                [121817, 115396, 115399], // 45: power-strikes, ascension, chi-brew
                [116844, 119392, 119381], // 60: ring-of-peace, charging-ox-wave, leg-sweep
                [122280, 122278, 122783], // 75: healing-elixirs, dampen-harm, diffuse-magic
                [116847, 123904, 115008], // 90: rushing-jade-wind, invoke-xuen-the-white-tiger, chi-torpedo
            ],
            // Druid
            11 => [
                [131768, 102280, 102401], // 15: feline-swiftness, displacer-beast, wild-charge
                [132158, 108238, 102351], // 30: natures-swiftness, renewal, cenarion-ward
                [102355, 102359, 132469], // 45: faerie-swarm, mass-entanglement, typhoon
                [114107, 106731, 106737], // 60: soul-of-the-forest, incarnation, force-of-nature
                [99,     102793, 5211  ], // 75: disorienting-roar, ursols-vortex, mighty-bash
                [108288, 108373, 124974], // 90: heart-of-the-wild, dream-of-cenarius, natures-vigil
            ],
        ];
        return $m[$classId] ?? null;
    }
}

if (!function_exists('wl_mop_talent_tier_level')) {
    /** Character level a given tier index unlocks at (0->15, 1->30, ..., 5->90). */
    function wl_mop_talent_tier_level(int $tierIdx): int
    {
        $L = [15, 30, 45, 60, 75, 90];
        return $L[max(0, min(5, $tierIdx))];
    }
}

if (!function_exists('wl_mop_talents_have_data')) {
    /**
     * True when the (tier x option) matrix has at least one non-zero ID per
     * tier - i.e. it's populated enough to render the in-game grid for
     * this class. Zeros mean "unverified ID, hide that cell"; if any tier
     * is all-zero we can't draw the grid, and the Armory falls back to the
     * existing chip view of the chosen talents.
     */
    function wl_mop_talents_have_data(?array $tiers): bool
    {
        if (!is_array($tiers) || count($tiers) !== 6) return false;
        foreach ($tiers as $row) {
            if (!is_array($row)) return false;
            $any = false;
            foreach ($row as $id) if ((int)$id > 0) { $any = true; break; }
            if (!$any) return false;
        }
        return true;
    }
}
