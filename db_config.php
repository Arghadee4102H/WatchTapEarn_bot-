<?php
// Set timezone to UTC for all date/time operations
date_default_timezone_set('UTC');

// Error reporting: Log errors, don't display them in production JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0); // Crucial: Do not output PHP errors directly
ini_set('log_errors', 1);    // Log errors to your PHP error log
// ini_set('error_log', '/path/to/your/php-error.log'); // Optional: specify custom log file

define('DB_SERVER', 'sql212.infinityfree.com'); // Your DB server
define('DB_USERNAME', 'if0_38996539');    // Your DB username
define('DB_PASSWORD', 'arghadeep858066');        // Your DB password
define('DB_DATABASE', 'if0_38996539_watchtapearn_db'); // Your database name

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

if ($conn->connect_error) {
    // Log the detailed error for server-side debugging
    error_log("FATAL: Database connection failed: " . $conn->connect_error . " (Host: " . DB_SERVER . ", User: " . DB_USERNAME . ", DB: " . DB_DATABASE . ")");
    
    // Output a JSON error message for the client
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Critical error: Cannot connect to the database. Please notify support.',
        // 'debug_db_error' => $conn->connect_error // For your eyes only during debugging
    ]);
    die(); // Stop script execution
}

$conn->set_charset("utf8mb4");

// Constants
define('MAX_DAILY_TAPS', 2500);
define('MAX_DAILY_ADS', 45);
define('POINTS_PER_AD', '40'); // Store as string for bcmath
define('POINTS_PER_TASK', '50'); // Store as string
define('POINTS_PER_REFERRAL', '20'); // Store as string
define('AD_COOLDOWN_SECONDS', 3 * 60); // 180 seconds
define('NUM_DAILY_TASKS', 4);
define('ENERGY_PER_TAP', 1);
define('DEFAULT_ENERGY_PER_SECOND', 0.1);
?>
