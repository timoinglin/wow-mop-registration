<?php
// Load unified configuration
$config = require __DIR__ . '/../config.php';

// Data Source Name (DSN) for Auth database
$dsn_auth = "mysql:host=" . $config['db']['host'] . ";port=" . $config['db']['port'] . ";dbname=" . $config['db']['name_auth'] . ";charset=utf8mb4";

// PDO Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Turn on errors in the form of exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Make the default fetch be an associative array
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Turn off emulation mode for real prepared statements
];

// Create PDO instance for authentication database
try {
    $pdo_auth = new PDO($dsn_auth, $config['db']['user'], $config['db']['password'], $options);
} catch (PDOException $e) {
    // Log the error securely, don't expose details to the user
    error_log("Database Connection Error: " . $e->getMessage());
    // Display a generic error message
    die("Could not connect to the database. Please try again later."); // Or handle this more gracefully
}

// --- Connection for Characters Database ---
$dsn_chars = "mysql:host=" . $config['db']['host'] . ";port=" . $config['db']['port'] . ";dbname=" . $config['db']['name_chars'] . ";charset=utf8mb4";

// Create PDO instance for characters database
try {
    $pdo_chars = new PDO($dsn_chars, $config['db']['user'], $config['db']['password'], $options);
} catch (PDOException $e) {
    // Log the error securely, don't expose details to the user
    error_log("Database Connection Error (Characters): " . $e->getMessage());
    // Set a flag or default value, don't die if only one DB fails if others are needed
    $pdo_chars = null; // Indicate connection failure
    // Optionally, add an error to display on the site if this connection is critical
}

// Ensure Nightmare Characters Database connection is explicitly null as it's been removed
$pdo_chars_nm = null;
