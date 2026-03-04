<?php
// Start session if not already started (keep for other session uses)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define allowed languages
$allowed_langs = ['en', 'es'];
$default_lang = 'en';
$lang = $default_lang; // Start with default

// 1. Check for language cookie first (for returning visitors)
if (isset($_COOKIE['language']) && in_array($_COOKIE['language'], $allowed_langs)) {
    $lang = $_COOKIE['language'];
}

// 2. Check for GET parameter (overrides cookie for explicit selection)
if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed_langs)) {
    $lang = $_GET['lang'];
    // Set/update the cookie for persistence (1 year expiry, HttpOnly)
    setcookie('language', $lang, time() + (86400 * 365), "/", "", isset($_SERVER['HTTPS']), true); 
}

// Remove session storage for language (no longer needed)
// unset($_SESSION['lang']); // Optional: clean up old session var if desired

// Construct the path to the language file
$lang_file = __DIR__ . '/../lang/' . $lang . '.php';

// Load the language file; default to English if the selected file doesn't exist
if (file_exists($lang_file)) {
    $TEXT = require $lang_file;
} else {
    // Fallback to default language if file missing (shouldn't happen with validation)
    $lang = $default_lang; 
    $TEXT = require __DIR__ . '/../lang/' . $lang . '.php';
}
