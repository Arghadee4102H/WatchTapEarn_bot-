<?php
// Database Configuration for InfinityFree (or similar)
define('DB_SERVER', 'sql312.infinityfree.com'); // Replace with your SQL server from InfinityFree
define('DB_USERNAME', 'if0_39005251'); // Replace with your InfinityFree username
define('DB_PASSWORD', 'art454500'); // Replace with your InfinityFree database password
define('DB_NAME', 'if0_39005251_tapwatchearn_db'); // Your database name

// Timezone
date_default_timezone_set('UTC');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => "Connection failed: " . $conn->connect_error]));
}

// Set charset
$conn->set_charset("utf8mb4");

// Telegram Bot Token (REQUIRED FOR initData VALIDATION - NOT IMPLEMENTED HERE FOR BREVITY)
// define('BOT_TOKEN', '7604220248:AAGz9-D3uTRIFEuznPVZ6HtqBgm0geR9dpA');
?>
