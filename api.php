<?php
require_once 'db_config.php'; // db_config now handles JSON error for DB connection itself

header('Content-Type: application/json');

// Global exception handler to ensure JSON output for unhandled PHP errors/exceptions
set_exception_handler(function ($exception) {
    error_log("FATAL UNHANDLED EXCEPTION: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . " Stack trace: " . $exception->getTraceAsString());
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'message' => 'An critical unexpected server error occurred. Support has been notified.',
        // 'debug_exception' => $exception->getMessage() // For your eyes only during debugging
    ]);
    exit;
});
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { // This error code is not included in error_reporting
        return false;
    }
    $error_type = '';
    switch ($severity) {
        case E_ERROR: $error_type = 'E_ERROR'; break;
        case E_WARNING: $error_type = 'E_WARNING'; break;
        case E_PARSE: $error_type = 'E_PARSE'; break;
        case E_NOTICE: $error_type = 'E_NOTICE'; break;
        default: $error_type = 'UNKNOWN'; break;
    }
    error_log("PHP Error [$error_type]: $message in $file on line $line");
    // For critical errors like E_PARSE or E_ERROR, script might halt anyway.
    // This handler helps log them before they might break JSON output.
    // Don't echo JSON here if it's a notice/warning, let script continue if possible.
    // If it's a fatal error, set_exception_handler might catch it if it becomes an ErrorException.
    return false; // Execute PHP internal error handler
});


$input = $_POST;
$action = $input['action'] ?? '';
$telegram_user_id_raw = $input['telegram_user_id'] ?? '0'; // Keep as string initially for debug IDs
$telegram_username = $input['telegram_username'] ?? null;
$telegram_first_name = $input['telegram_first_name'] ?? null;
// $telegram_init_data_raw = $input['telegram_init_data'] ?? ''; // TODO: Implement server-side validation of initData

// Convert Telegram User ID to int if it's purely numeric, otherwise handle debug IDs.
$telegram_user_id = 0;
if (is_numeric($telegram_user_id_raw) && $telegram_user_id_raw > 0) {
    $telegram_user_id = (int)$telegram_user_id_raw;
} else if (strpos($telegram_user_id_raw, 'debug_') === 0) {
    // This is a debug user, handle appropriately (e.g., map to a specific test ID or use as is if schema allows strings)
    // For now, we'll try to use it as is if it's for getUserData (which might fail if user_id is not BIGINT UNSIGNED)
    // This part needs careful consideration based on how debug users are handled.
    // The schema expects BIGINT UNSIGNED, so string debug IDs will cause issues.
    // Best to map debug strings to a reserved range of numeric IDs for testing.
    // For simplicity now, if it's a debug ID, we'll assign a fixed test numeric ID.
    // THIS IS A SIMPLIFICATION FOR DEBUGGING.
    $telegram_user_id = (int) crc32($telegram_user_id_raw); // Example: generate a numeric hash
    if ($telegram_user_id < 0) $telegram_user_id *= -1; // Ensure positive
    $telegram_user_id = $telegram_user_id % 100000 + 1000000000; // Put in a high range
    error_log("Debug user ID '$telegram_user_id_raw' mapped to numeric test ID: $telegram_user_id");
}


if (empty($telegram_user_id) && $action !== 'test') {
    echo json_encode(['success' => false, 'message' => 'User identification failed (empty or invalid ID).']);
    exit;
}

/**
 * Gets or creates a user, and updates their energy.
 * IMPORTANT: This function now handles its own transaction for user creation/initial fetch.
 * Subsequent updates (tap, ad, task) should ideally be atomic operations on the fetched user data.
 */
function getUser(mysqli $conn, int $userId, ?string $username, ?string $firstName, ?int $referrerIdParam = null): ?array {
    if ($userId <= 0) { // Basic validation
        error_log("getUser called with invalid userId: {$userId}");
        return null;
    }

    $conn->begin_transaction();
    try {
        // Try to fetch existing user and lock the row for update
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
        if (!$stmt) throw new Exception("Prepare failed (SELECT user): " . $conn->error);
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed (SELECT user): " . $stmt->error);
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        $isNewUser = false;
        if (!$user) {
            $isNewUser = true;
            error_log("User {$userId} not found. Creating new user.");
            $initialEnergy = 100; // From max_energy default
            $energyPerSecond = DEFAULT_ENERGY_PER_SECOND; // Use constant
            $actualReferrerId = null;

            if ($referrerIdParam && $referrerIdParam != $userId && $referrerIdParam > 0) {
                $refStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
                if (!$refStmt) throw new Exception("Prepare failed (SELECT referrer): " . $conn->error);
                $refStmt->bind_param("i", $referrerIdParam);
                if (!$refStmt->execute()) throw new Exception("Execute failed (SELECT referrer): " . $refStmt->error);
                if ($refStmt->get_result()->num_rows > 0) {
                    $actualReferrerId = $referrerIdParam;
                }
                $refStmt->close();
            }

            $insertStmt = $conn->prepare("INSERT INTO users (user_id, username, first_name, points, energy, max_energy, energy_per_second, last_energy_update_ts, referred_by_user_id, join_date) VALUES (?, ?, ?, '0', ?, ?, ?, NOW(), ?, NOW())");
            if (!$insertStmt) throw new Exception("Prepare failed (INSERT user): " . $conn->error);
            $insertStmt->bind_param("issiddis", $userId, $username, $firstName, $initialEnergy, $initialEnergy, $energyPerSecond, $actualReferrerId);
            if (!$insertStmt->execute()) throw new Exception("Execute failed (INSERT user): " . $insertStmt->error);
            $insertStmt->close();
            error_log("User {$userId} created successfully. Referred by: " . ($actualReferrerId ?? 'None'));

            // Re-fetch the newly created user to ensure all defaults and timestamps are loaded
            $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?"); // No lock needed now
            if (!$stmt) throw new Exception("Prepare failed (SELECT new user): " . $conn->error);
            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) throw new Exception("Execute failed (SELECT new user): " . $stmt->error);
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) { // Should not happen if insert was successful
                 throw new Exception("CRITICAL: Failed to re-fetch newly created user {$userId} after insert.");
            }

            if ($actualReferrerId) {
                $pointsPerReferral = POINTS_PER_REFERRAL;
                $updateReferrerStmt = $conn->prepare("UPDATE users SET points = CAST(points AS DECIMAL(20,0)) + ?, referral_count = referral_count + 1 WHERE user_id = ?");
                if (!$updateReferrerStmt) throw new Exception("Prepare failed (UPDATE referrer points): " . $conn->error);
                $updateReferrerStmt->bind_param("si", $pointsPerReferral, $actualReferrerId); // pointsPerReferral is string
                if (!$updateReferrerStmt->execute()) throw new Exception("Execute failed (UPDATE referrer points): " . $updateReferrerStmt->error);
                $updateReferrerStmt->close();
                error_log("Awarded {$pointsPerReferral} points to referrer {$actualReferrerId}.");
            }
        }

        // Energy regeneration logic
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $lastUpdate = new DateTime($user['last_energy_update_ts'], new DateTimeZone('UTC')); // DB stores UTC
        $secondsPassed = $now->getTimestamp() - $lastUpdate->getTimestamp();
        
        $energyUpdated = false;
        if ($secondsPassed > 0 && (float)$user['energy'] < (int)$user['max_energy']) {
            $energyGained = $secondsPassed * (float)$user['energy_per_second'];
            $newEnergy = min((int)$user['max_energy'], (float)$user['energy'] + $energyGained);
            if ($newEnergy > (float)$user['energy']) {
                $user['energy'] = $newEnergy;
                $energyUpdated = true;
            }
        }
        // Always update last_energy_update_ts if energy was touched or simply to keep it current
        $user['last_energy_update_ts'] = $now->format('Y-m-d H:i:s');
        
        // If energy was updated or it's a new user (to save initial energy state), update DB.
        if ($energyUpdated || $isNewUser) {
            $updateEnergyStmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE user_id = ?");
            if (!$updateEnergyStmt) throw new Exception("Prepare failed (UPDATE energy): " . $conn->error);
            $updateEnergyStmt->bind_param("dsi", $user['energy'], $user['last_energy_update_ts'], $userId);
            if (!$updateEnergyStmt->execute()) throw new Exception("Execute failed (UPDATE energy): " . $updateEnergyStmt->error);
            $updateEnergyStmt->close();
        }
        
        $conn->commit();
        error_log("getUser successful for user {$userId}. Energy: {$user['energy']}. Points: {$user['points']}");

        // Ensure numeric fields are correctly typed for JSON if necessary
        $user['points'] = (string)$user['points']; // Always string for bcmath
        $user['energy'] = (float)$user['energy'];
        $user['max_energy'] = (int)$user['max_energy'];
        $user['energy_per_second'] = (float)$user['energy_per_second'];
        $user['taps_today'] = (int)$user['taps_today'];
        $user['ads_watched_today'] = (int)$user['ads_watched_today'];
        $user['daily_tasks_completed_mask'] = (int)$user['daily_tasks_completed_mask'];
        $user['referral_count'] = (int)$user['referral_count'];

        return $user;

    } catch (Exception $e) { // Catch custom Exceptions and mysqli_sql_exception
        $conn->rollback();
        error_log("getUser FAILED for userId {$userId}. ReferrerParam: {$referrerIdParam}. Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
        return null;
    }
}

// --- API ACTIONS ---
// Wrap actions in a try-catch to handle specific action errors more gracefully if needed.
try {
    switch ($action) {
        case 'getUserData':
            $referrerId = isset($input['referrer_id']) && is_numeric($input['referrer_id']) ? (int)$input['referrer_id'] : null;
            $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name, $referrerId);
            if ($user) {
                // Reset daily counters if date has changed (read-only check, actual save in action)
                $current_utc_date_str = gmdate('Y-m-d');
                if ($user['last_tap_date_utc'] != $current_utc_date_str) $user['taps_today'] = 0;
                if ($user['last_ad_date_utc'] != $current_utc_date_str) $user['ads_watched_today'] = 0;
                if ($user['last_daily_tasks_date_utc'] != $current_utc_date_str) $user['daily_tasks_completed_mask'] = 0;
                
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Could not load or create user data. Please check server logs.']);
            }
            break;

        case 'tap':
            $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name); // Gets latest energy
            if (!$user) { echo json_encode(['success' => false, 'message' => 'User session error.']); exit; }

            $current_utc_date = gmdate('Y-m-d');
            $taps_today = ($user['last_tap_date_utc'] == $current_utc_date) ? (int)$user['taps_today'] : 0;

            if ((float)$user['energy'] < ENERGY_PER_TAP) {
                echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'user' => $user]); exit;
            }
            if ($taps_today >= MAX_DAILY_TAPS) {
                echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'user' => $user]); exit;
            }

            $new_points = bcadd((string)$user['points'], '1');
            $new_energy = (float)$user['energy'] - ENERGY_PER_TAP;
            $taps_today++;
            
            $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, taps_today = ?, last_tap_date_utc = ?, last_energy_update_ts = NOW() WHERE user_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (tap): " . $conn->error);
            $stmt->bind_param("sdisi", $new_points, $new_energy, $taps_today, $current_utc_date, $telegram_user_id);
            
            if ($stmt->execute()) {
                $user['points'] = $new_points; $user['energy'] = $new_energy; $user['taps_today'] = $taps_today; $user['last_tap_date_utc'] = $current_utc_date;
                echo json_encode(['success' => true, 'user' => $user, 'message' => '+1 point!']);
            } else {
                throw new Exception("Execute failed (tap): " . $stmt->error);
            }
            $stmt->close();
            break;

        // ... Other cases (claimTask, watchAd, requestWithdrawal, getWithdrawalHistory) need similar robustness ...
        // For brevity, I'll assume they follow a similar pattern:
        // 1. Call getUser() to get the current user state (includes energy updates).
        // 2. Perform action-specific checks (limits, cooldowns).
        // 3. Update user data in DB within a transaction if multiple fields change.
        // 4. Return updated user object.

        case 'claimTask':
            $taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
            if ($taskId <= 0 || $taskId > NUM_DAILY_TASKS) {
                echo json_encode(['success' => false, 'message' => 'Invalid task ID.']); exit;
            }
            $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
            if (!$user) { echo json_encode(['success' => false, 'message' => 'User session error.']); exit; }

            $current_utc_date = gmdate('Y-m-d');
            $task_mask = ($user['last_daily_tasks_date_utc'] == $current_utc_date) ? (int)$user['daily_tasks_completed_mask'] : 0;
            
            $task_bit = 1 << ($taskId - 1);
            if (($task_mask & $task_bit) > 0) {
                echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'user' => $user]); exit;
            }

            $new_points = bcadd((string)$user['points'], POINTS_PER_TASK);
            $new_mask = $task_mask | $task_bit;

            $stmt = $conn->prepare("UPDATE users SET points = ?, daily_tasks_completed_mask = ?, last_daily_tasks_date_utc = ? WHERE user_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (claimTask): " . $conn->error);
            $stmt->bind_param("sisi", $new_points, $new_mask, $current_utc_date, $telegram_user_id);
            if ($stmt->execute()) {
                $user['points'] = $new_points; $user['daily_tasks_completed_mask'] = $new_mask; $user['last_daily_tasks_date_utc'] = $current_utc_date;
                echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_TASK, 'message' => 'Task claimed!']);
            } else {
                throw new Exception("Execute failed (claimTask): " . $stmt->error);
            }
            $stmt->close();
            break;

        case 'watchAd':
            $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
            if (!$user) { echo json_encode(['success' => false, 'message' => 'User session error.']); exit; }

            $current_utc_date = gmdate('Y-m-d');
            $ads_today = ($user['last_ad_date_utc'] == $current_utc_date) ? (int)$user['ads_watched_today'] : 0;

            if ($ads_today >= MAX_DAILY_ADS) {
                echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'user' => $user]); exit;
            }
            if ($user['last_ad_watched_timestamp']) {
                $lastAdTime = new DateTime($user['last_ad_watched_timestamp'], new DateTimeZone('UTC'));
                $now = new DateTime('now', new DateTimeZone('UTC'));
                if (($now->getTimestamp() - $lastAdTime->getTimestamp()) < AD_COOLDOWN_SECONDS) {
                    echo json_encode(['success' => false, 'message' => 'Ad cooldown active.', 'user' => $user]); exit;
                }
            }
            
            $new_points = bcadd((string)$user['points'], POINTS_PER_AD);
            $ads_today++;
            $current_timestamp_str = gmdate('Y-m-d H:i:s');

            $stmt = $conn->prepare("UPDATE users SET points = ?, ads_watched_today = ?, last_ad_date_utc = ?, last_ad_watched_timestamp = ? WHERE user_id = ?");
            if (!$stmt) throw new Exception("Prepare failed (watchAd): " . $conn->error);
            $stmt->bind_param("sisssi", $new_points, $ads_today, $current_utc_date, $current_timestamp_str, $telegram_user_id);
            if ($stmt->execute()) {
                $user['points'] = $new_points; $user['ads_watched_today'] = $ads_today; $user['last_ad_date_utc'] = $current_utc_date; $user['last_ad_watched_timestamp'] = $current_timestamp_str;
                echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_AD, 'message' => 'Ad reward claimed!']);
            } else {
                throw new Exception("Execute failed (watchAd): " . $stmt->error);
            }
            $stmt->close();
            break;

        case 'requestWithdrawal':
            $conn->begin_transaction();
            try {
                // Lock user row for this sensitive operation
                $stmt_user_lock = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
                if (!$stmt_user_lock) throw new Exception("Prepare lock failed: " . $conn->error);
                $stmt_user_lock->bind_param("i", $telegram_user_id);
                if (!$stmt_user_lock->execute()) throw new Exception("Execute lock failed: " . $stmt_user_lock->error);
                $user_result = $stmt_user_lock->get_result();
                $user = $user_result->fetch_assoc(); // Get fresh locked data
                $stmt_user_lock->close();

                if (!$user) { throw new Exception('User not found for withdrawal.'); }
                // Important: Convert points to string for bcmath
                $user['points'] = (string)$user['points'];


                $points_to_withdraw_str = isset($input['points_to_withdraw']) ? (string)$input['points_to_withdraw'] : '0';
                $method = $input['method'] ?? '';
                $wallet_address_or_id = $input['wallet_address_or_id'] ?? '';

                $valid_amounts = ['86000', '165000', '305000']; // Strings for comparison with bcmath if needed
                if (!in_array($points_to_withdraw_str, $valid_amounts) || empty($method) || empty($wallet_address_or_id)) {
                    throw new Exception('Invalid withdrawal details.');
                }
                if (bccomp($user['points'], $points_to_withdraw_str) < 0) {
                    throw new Exception('Not enough points for withdrawal.');
                }

                $new_points = bcsub($user['points'], $points_to_withdraw_str);
                $stmt_update_user = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
                if (!$stmt_update_user) throw new Exception("Prepare user points update failed: " . $conn->error);
                $stmt_update_user->bind_param("si", $new_points, $telegram_user_id);
                if (!$stmt_update_user->execute()) throw new Exception("Execute user points update failed: " . $stmt_update_user->error);
                $stmt_update_user->close();

                $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, wallet_address_or_id, status, requested_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
                if (!$stmt_insert_withdrawal) throw new Exception("Prepare withdrawal insert failed: " . $conn->error);
                $stmt_insert_withdrawal->bind_param("isss", $telegram_user_id, $points_to_withdraw_str, $method, $wallet_address_or_id); // points_withdrawn is string
                if (!$stmt_insert_withdrawal->execute()) throw new Exception("Execute withdrawal insert failed: " . $stmt_insert_withdrawal->error);
                $stmt_insert_withdrawal->close();
                
                $conn->commit();
                $user['points'] = $new_points; // Update user object for response
                $user['energy'] = (float)$user['energy']; // Ensure float for response
                echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'user' => $user]);
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Withdrawal action failed for user {$telegram_user_id}: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage(), 'user' => $user ?? null]); // Send current user state if available
            }
            break;
        
        case 'getWithdrawalHistory':
            $stmt = $conn->prepare("SELECT points_withdrawn, method, wallet_address_or_id, status, requested_at FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20");
            if (!$stmt) throw new Exception("Prepare failed (getHistory): " . $conn->error);
            $stmt->bind_param("i", $telegram_user_id);
            if (!$stmt->execute()) throw new Exception("Execute failed (getHistory): " . $stmt->error);
            $result = $stmt->get_result();
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $row['points_withdrawn'] = (string)$row['points_withdrawn']; // Ensure string for consistency
                $history[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
            break;
    }
} catch (Exception $e) { // Catch any exception from action handlers
    error_log("Error in action '{$action}' for user {$telegram_user_id}: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => "Server error processing '{$action}': " . $e->getMessage()
    ]);
}

$conn->close();
?>
