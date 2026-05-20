<?php
/**
 * MoP 5.4.8 talents — per class, 6 tiers x 3 talents.
 * Tiers correspond to character levels 15 / 30 / 45 / 60 / 75 / 90.
 * Used by the Armory Talents grid (in-game-style 6x3 view).
 * Spell IDs verified against Wowhead. Cells with 0 = unverified, hide tooltip.
 *
 * Policy: any cell where the assistant was not certain the spell ID resolves
 * to the correct MoP 5.4.8 talent on Wowhead is recorded as 0,
 * per project instruction "better one missing entry than a wrong spell ID".
 *
 * Only the Warrior row is high-confidence (verified by user against screenshot).
 * The other 10 classes are intentionally zeroed pending Wowhead cross-check.
 */

if (!function_exists('wl_mop_talents')) {
    function wl_mop_talents(int $classId): ?array
    {
        static $m = [
            // Warrior — verified against in-game screenshot.
            1 => [
                [103826, 103827, 103828], // 15: Juggernaut, Double Time, Warbringer
                [55694,  29838,  103840], // 30: Enraged Regeneration, Second Wind, Impending Victory
                [107566, 12323,  102060], // 45: Staggering Shout, Piercing Howl, Disrupting Shout
                [46924,  46968,  118000], // 60: Bladestorm, Shockwave, Dragon Roar
                [114028, 114029, 114030], // 75: Mass Spell Reflection, Safeguard, Vigilance
                [107574, 12292,  107570], // 90: Avatar, Bloodbath, Storm Bolt
            ],
            // Paladin — UNVERIFIED, all flagged.
            2 => [
                [0, 0, 0], // 15: Speed of Light, Long Arm of the Law, Pursuit of Justice
                [0, 0, 0], // 30: Fist of Justice, Repentance, Burden of Guilt
                [0, 0, 0], // 45: Selfless Healer, Eternal Flame, Sacred Shield
                [0, 0, 0], // 60: Hand of Purity, Unbreakable Spirit, Clemency
                [0, 0, 0], // 75: Holy Avenger, Sanctified Wrath, Divine Purpose
                [0, 0, 0], // 90: Holy Prism, Light's Hammer, Execution Sentence
            ],
            // Hunter — UNVERIFIED, all flagged.
            3 => [
                [0, 0, 0], // 15: Posthaste, Narrow Escape, Exhilaration
                [0, 0, 0], // 30: Crouching Tiger Hidden Chimera, Silencing Shot, Wyvern Sting
                [0, 0, 0], // 45: Binding Shot, Wyvern Sting, Intimidation
                [0, 0, 0], // 60: Iron Hawk, Spirit Bond, Aspect of the Iron Hawk
                [0, 0, 0], // 75: Fervor, Dire Beast, Thrill of the Hunt
                [0, 0, 0], // 90: A Murder of Crows, Blink Strikes, Lynx Rush
            ],
            // Rogue — UNVERIFIED, all flagged.
            4 => [
                [0, 0, 0], // 15: Nightstalker, Subterfuge, Shadow Focus
                [0, 0, 0], // 30: Deadly Throw, Nerve Strike, Combat Readiness
                [0, 0, 0], // 45: Cheat Death, Leeching Poison, Elusiveness
                [0, 0, 0], // 60: Cloak and Dagger, Shadowstep, Burst of Speed
                [0, 0, 0], // 75: Prey on the Weak, Paralytic Poison, Dirty Tricks
                [0, 0, 0], // 90: Shadow Blades, Marked for Death, Anticipation
            ],
            // Priest — UNVERIFIED, all flagged.
            5 => [
                [0, 0, 0], // 15: Void Tendrils, Psyfiend, Dominate Mind
                [0, 0, 0], // 30: Body and Soul, Angelic Feather, Phantasm
                [0, 0, 0], // 45: From Darkness Comes Light, Mindbender, Solace and Insanity
                [0, 0, 0], // 60: Desperate Prayer, Spectral Guise, Angelic Bulwark
                [0, 0, 0], // 75: Twist of Fate, Power Infusion, Divine Insight
                [0, 0, 0], // 90: Cascade, Divine Star, Halo
            ],
            // Death Knight — UNVERIFIED, all flagged.
            6 => [
                [0, 0, 0], // 15: Roiling Blood, Plague Leech, Unholy Blight
                [0, 0, 0], // 30: Lichborne, Anti-Magic Zone, Purgatory
                [0, 0, 0], // 45: Death's Advance, Chilblains, Asphyxiate
                [0, 0, 0], // 60: Death Pact, Death Siphon, Conversion
                [0, 0, 0], // 75: Blood Tap, Runic Empowerment, Runic Corruption
                [0, 0, 0], // 90: Gorefiend's Grasp, Remorseless Winter, Desecrated Ground
            ],
            // Shaman — UNVERIFIED, all flagged.
            7 => [
                [0, 0, 0], // 15: Nature's Guardian, Stone Bulwark Totem, Astral Shift
                [0, 0, 0], // 30: Frozen Power, Earthgrab Totem, Windwalk Totem
                [0, 0, 0], // 45: Call of the Elements, Totemic Restoration, Totemic Projection
                [0, 0, 0], // 60: Elemental Mastery, Ancestral Swiftness, Echo of the Elements
                [0, 0, 0], // 75: Rushing Streams, Ancestral Guidance, Conductivity
                [0, 0, 0], // 90: Unleashed Fury, Primal Elementalist, Elemental Blast
            ],
            // Mage — UNVERIFIED, all flagged.
            8 => [
                [0, 0, 0], // 15: Presence of Mind, Blazing Speed, Ice Floes
                [0, 0, 0], // 30: Temporal Shield, Flameglow, Ice Barrier
                [0, 0, 0], // 45: Ring of Frost, Ice Ward, Frostjaw
                [0, 0, 0], // 60: Greater Invisibility, Cauterize, Cold Snap
                [0, 0, 0], // 75: Nether Tempest, Living Bomb, Frost Bomb
                [0, 0, 0], // 90: Invocation, Rune of Power, Incanter's Ward
            ],
            // Warlock — UNVERIFIED, all flagged.
            9 => [
                [0, 0, 0], // 15: Dark Regeneration, Soul Leech, Harvest Life
                [0, 0, 0], // 30: Howl of Terror, Mortal Coil, Shadowfury
                [0, 0, 0], // 45: Soul Link, Sacrificial Pact, Dark Bargain
                [0, 0, 0], // 60: Blood Horror, Burning Rush, Unbound Will
                [0, 0, 0], // 75: Grimoire of Supremacy, Grimoire of Service, Grimoire of Sacrifice
                [0, 0, 0], // 90: Archimonde's Vengeance, Kil'jaeden's Cunning, Mannoroth's Fury
            ],
            // Monk — UNVERIFIED, all flagged.
            10 => [
                [0, 0, 0], // 15: Celerity, Tiger's Lust, Momentum
                [0, 0, 0], // 30: Chi Wave, Zen Sphere, Chi Burst
                [0, 0, 0], // 45: Power Strikes, Ascension, Chi Brew
                [0, 0, 0], // 60: Ring of Peace, Charging Ox Wave, Leg Sweep
                [0, 0, 0], // 75: Healing Elixirs, Dampen Harm, Diffuse Magic
                [0, 0, 0], // 90: Rushing Jade Wind, Invoke Xuen the White Tiger, Chi Torpedo
            ],
            // Druid — UNVERIFIED, all flagged.
            11 => [
                [0, 0, 0], // 15: Feline Swiftness, Displacer Beast, Wild Charge
                [0, 0, 0], // 30: Nature's Swiftness, Renewal, Cenarion Ward
                [0, 0, 0], // 45: Faerie Swarm, Mass Entanglement, Typhoon
                [0, 0, 0], // 60: Soul of the Forest, Incarnation, Force of Nature
                [0, 0, 0], // 75: Disorienting Roar, Ursol's Vortex, Mighty Bash
                [0, 0, 0], // 90: Heart of the Wild, Dream of Cenarius, Nature's Vigil
            ],
        ];
        return $m[$classId] ?? null;
    }
}

if (!function_exists('wl_mop_talent_tier_level')) {
    /** Returns the character level a given tier index unlocks at (0->15, 1->30, ..., 5->90). */
    function wl_mop_talent_tier_level(int $tierIdx): int
    {
        $L = [15, 30, 45, 60, 75, 90];
        return $L[max(0, min(5, $tierIdx))];
    }
}

if (!function_exists('wl_mop_talents_have_data')) {
    /**
     * True when the (tier x option) matrix has at least one non-zero ID per
     * tier - i.e. it's fully populated enough to render the in-game grid for
     * this class. Zeros mean "unverified ID, hide"; if any tier is all-zero
     * we don't have enough data to draw the grid, so the Armory falls back
     * to the existing chip view for the chosen talents.
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
