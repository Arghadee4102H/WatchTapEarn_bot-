<?php
// db_config.php
// IMPORTANT: Replace with your actual InfinityFree database credentials
define('DB_SERVER', 'sql312.infinityfree.com'); // e.g., sql201.infinityfree.com
define('DB_USERNAME', 'if0_39005251');         // Your InfinityFree username, e.g., if0_12345678
define('DB_PASSWORD', 'art454500');     // Your database password
define('DB_NAME', 'if0_39005251_tapwatchearn_db'); // Your database name

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Log error to a file instead of echoing in production
    error_log("Connection failed: " . $conn->connect_error);
    // For API responses, it's better to send a JSON error
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Database connection error. Please try again later.']);
    exit();
}

// Set charset to utf8mb4 for full Unicode support (including emojis)
$conn->set_charset("utf8mb4");

// Function to handle daily resets. Call this at the beginning of relevant PHP scripts.
function handle_daily_reset($conn, $userId) {
    $stmt = $conn->prepare("SELECT last_daily_reset_utc, tasks_completed_today_json FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) return; // User not found

    $current_utc_date = gmdate('Y-m-d');

    if ($user['last_daily_reset_utc'] == null || $user['last_daily_reset_utc'] < $current_utc_date) {
        $new_tasks_json = json_encode([]); // Reset completed tasks for the new day

        $update_stmt = $conn->prepare("UPDATE users SET 
            spins_today_count = 0, 
            ad_spins_today_count = 0, 
            ads_watched_today_count = 0,
            tasks_completed_today_json = ?,
            last_daily_reset_utc = ?
            WHERE user_id = ?");
        $update_stmt->bind_param("ssi", $new_tasks_json, $current_utc_date, $userId);
        $update_stmt->execute();
        $update_stmt->close();
    }
}
?>
