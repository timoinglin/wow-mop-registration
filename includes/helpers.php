<?php

/**
 * helpers.php
 * Game utility helpers: icons, names, formatting.
 */

/**
 * Checks if a specific TCP port is open on a host.
 */
function check_port_status(string $host, int $port, int $timeout = 1): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

/**
 * Gets the path for the race/gender icon.
 */
function get_race_icon_path(int $raceId, int $genderId): string
{
    $genderId = ($genderId === 1) ? 1 : 0;
    return 'assets/img/race/' . $raceId . '-' . $genderId . '.gif';
}

/**
 * Gets the path for the class icon.
 */
function get_class_icon_path(int $classId): string
{
    $class_map = [
        1 => 'warrior', 2 => 'paladin', 3 => 'hunter',  4 => 'rogue',
        5 => 'priest',  6 => 'dk',      7 => 'chaman',   8 => 'mage',
        9 => 'warlock', 10 => 'monk',   11 => 'druid',
    ];
    $base = $class_map[$classId] ?? 'default';
    return 'assets/img/class/' . $base . '.gif';
}

/**
 * Converts total seconds into a human-readable playtime string (e.g., 2d 3h 15m).
 */
function format_playtime(int $totalSeconds): string
{
    if ($totalSeconds <= 0) return '0m';

    $days    = floor($totalSeconds / 86400); $totalSeconds %= 86400;
    $hours   = floor($totalSeconds / 3600);  $totalSeconds %= 3600;
    $minutes = floor($totalSeconds / 60);

    $out = '';
    if ($days)           $out .= $days . 'd ';
    if ($hours)          $out .= $hours . 'h ';
    if ($minutes || !$out) $out .= $minutes . 'm';

    return trim($out);
}

/**
 * Formats a copper amount into readable gold/silver/copper (e.g., "1g 23s 45c").
 */
function format_gold(int $copper): string
{
    $gold   = floor($copper / 10000); $copper %= 10000;
    $silver = floor($copper / 100);   $copper %= 100;

    $result = '';
    if ($gold)                   $result .= $gold . 'g ';
    if ($silver || $gold)        $result .= $silver . 's ';
    $result .= $copper . 'c';

    return trim($result);
}

/**
 * Returns the CSS class name for styling a WoW class.
 */
function get_class_color_css(int $classId): string
{
    $map = [
        1 => 'warrior', 2 => 'paladin',    3 => 'hunter', 4 => 'rogue',
        5 => 'priest',  6 => 'deathknight', 7 => 'shaman', 8 => 'mage',
        9 => 'warlock', 10 => 'monk',       11 => 'druid',
    ];
    return 'class-' . strtolower($map[$classId] ?? 'default');
}

/**
 * Returns the display name for a WoW race ID.
 */
function get_race_name(int $raceId): string
{
    $races = [
        1 => 'Human',  2 => 'Orc',       3 => 'Dwarf',         4 => 'Night Elf',
        5 => 'Undead', 6 => 'Tauren',    7 => 'Gnome',         8 => 'Troll',
        9 => 'Goblin', 10 => 'Blood Elf', 11 => 'Draenei',     22 => 'Worgen',
        24 => 'Pandaren', 25 => 'Pandaren (A)', 26 => 'Pandaren (H)',
    ];
    return $races[$raceId] ?? 'Unknown Race';
}

/**
 * Returns the display name for a WoW class ID.
 */
function get_class_name(int $classId): string
{
    $classes = [
        1 => 'Warrior', 2 => 'Paladin',     3 => 'Hunter', 4 => 'Rogue',
        5 => 'Priest',  6 => 'Death Knight', 7 => 'Shaman', 8 => 'Mage',
        9 => 'Warlock', 10 => 'Monk',        11 => 'Druid',
    ];
    return $classes[$classId] ?? 'Unknown Class';
}
