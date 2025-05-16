<?php
// Set timezone to UTC for all date/time operations
date_default_timezone_set('UTC');

// Error reporting for InfinityFree:
// It's good practice to log errors, but InfinityFree might have its own error display settings.
// The global handlers in api.php will try to catch things.
error_reporting(E_ALL);
ini_set('display_errors', 0); // Attempt to hide direct PHP error output
ini_set('log_errors', 1);
// InfinityFree usually has a specific place for error logs, or they might be visible in cPanel.
// You might not be able to set a custom error_log path.

// --- !!! IMPORTANT: REPLACE THESE WITH YOUR ACTUAL INFINITYFREE DETAILS !!! ---
define('DB_SERVER', 'sql212.infinityfree.com'); // e.g., sql101.epizy.com
define('DB_USERNAME', 'if0_38996539'); // e.g., epiz_xxxxxxx
define('DB_PASSWORD', 'arghadeep858066');
define('DB_DATABASE', 'if0_38996539_watchtapearn_db'); // e.g., epiz_xxxxxxx_dbname
// --- !!! IMPORTANT: END OF VALUES TO REPLACE !!! ---

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

if ($conn->connect_error) {
    error_log("FATAL: Database connection failed on InfinityFree. Host: " . DB_SERVER . ", User: " . DB_USERNAME . ", DB: " . DB_DATABASE . ". Error: " . $conn->connect_error);
    
    header('Content-Type: application/json');
    // Send a slightly more user-friendly error, but log details.
    echo json_encode([
        'success' => false,
        'message' => 'Server error: Could not connect to the database. Please try again later or contact support if the issue persists.',
        // 'debug_db_error' => $conn->connect_error // For your debugging if you can see this output directly
    ]);
    die();
}

$conn->set_charset("utf8mb4");

// Constants (ensure these match your schema/logic)
define('MAX_DAILY_TAPS', 2500);
define('MAX_DAILY_ADS', 45);
define('POINTS_PER_AD', '40');
define('POINTS_PER_TASK', '50');
define('POINTS_PER_REFERRAL', '20');
define('AD_COOLDOWN_SECONDS', 180); // 3 minutes
define('NUM_DAILY_TASKS', 4);
define('ENERGY_PER_TAP', 1);
define('DEFAULT_ENERGY_PER_SECOND', 0.1);
?>
