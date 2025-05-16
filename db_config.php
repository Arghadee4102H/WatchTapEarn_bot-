<?php
// Set timezone to UTC for all date/time operations
date_default_timezone_set('UTC');

define('DB_SERVER', 'sql212.infinityfree.com'); // Replace with your DB server if not localhost
define('DB_USERNAME', 'if0_38996539');    // Replace with your DB username
define('DB_PASSWORD', 'arghadeep858066');        // Replace with your DB password
define('DB_DATABASE', 'if0_38996539_watchtapearn_db'); // Your database name

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    error_log("Database connection failed: " . $conn->connect_error); // Log error
    die();
}

$conn->set_charset("utf8mb4");

// Constants
define('MAX_DAILY_TAPS', 2500);
define('MAX_DAILY_ADS', 45);
define('POINTS_PER_AD', 40);
define('POINTS_PER_TASK', 50);
define('POINTS_PER_REFERRAL', 20);
define('AD_COOLDOWN_SECONDS', 3 * 60); // 3 minutes
define('NUM_DAILY_TASKS', 4);
define('ENERGY_PER_TAP', 1); // Define energy cost per tap
?>
