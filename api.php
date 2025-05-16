<?php
require_once 'db_config.php'; // Includes DB connection and constants

header('Content-Type: application/json');
// Allow requests from the Telegram WebApp origin (or '*' for development)
// header('Access-Control-Allow-Origin: *'); // Potentially dangerous in production
// Consider more specific origin if your TWA has a fixed one.
// IMPORTANT: For production, you should validate tg.initData to prevent spoofing.

$input = $_POST;
if (empty($input)) {
    $input = $_GET; // Fallback for simple GET tests, but POST is better
}

$action = $input['action'] ?? '';
$telegram_user_id = isset($input['telegram_user_id']) ? (int)$input['telegram_user_id'] : 0;
$telegram_username = $input['telegram_username'] ?? null;
$telegram_first_name = $input['telegram_first_name'] ?? null;
// $telegram_init_data_raw = $input['telegram_init_data'] ?? ''; // For potential future validation

if (empty($telegram_user_id) && !in_array($action, ['test'])) {
    echo json_encode(['success' => false, 'message' => 'User identification failed.']);
    exit;
}

function getUser(mysqli $conn, int $userId, ?string $username, ?string $firstName, ?int $referrerIdParam = null): ?array {
    $conn->begin_transaction();
    try {
        // Lock the user row for update to prevent race conditions
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
        if (!$stmt) { error_log("Prepare failed (select user for update): " . $conn->error); $conn->rollback(); return null; }
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) { error_log("Execute failed (select user for update): " . $stmt->error); $stmt->close(); $conn->rollback(); return null; }
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        $isNewUser = false;
        if (!$user) {
            $isNewUser = true;
            $initialEnergy = 100.0; // Ensure float
            $energyPerSecond = 0.1;
            $actualReferrerId = null;

            if ($referrerIdParam && $referrerIdParam != $userId) {
                $refStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
                if (!$refStmt) { error_log("Prepare failed (check referrer): " . $conn->error); $conn->rollback(); return null; }
                $refStmt->bind_param("i", $referrerIdParam);
                if (!$refStmt->execute()) { error_log("Execute failed (check referrer): " . $refStmt->error); $refStmt->close(); $conn->rollback(); return null; }
                $refResult = $refStmt->get_result();
                if ($refResult->num_rows > 0) {
                    $actualReferrerId = $referrerIdParam;
                }
                $refStmt->close();
            }

            $insertStmt = $conn->prepare("INSERT INTO users (user_id, username, first_name, energy, max_energy, energy_per_second, last_energy_update_ts, referred_by_user_id, join_date) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW())");
            if (!$insertStmt) { error_log("Prepare failed (insert user): " . $conn->error); $conn->rollback(); return null; }
            $insertStmt->bind_param("issiddis", $userId, $username, $firstName, $initialEnergy, $initialEnergy, $energyPerSecond, $actualReferrerId);
            if (!$insertStmt->execute()) {
                error_log("Failed to create user {$userId}: " . $insertStmt->error);
                $insertStmt->close();
                $conn->rollback();
                return null;
            }
            $insertStmt->close();
            
            // Re-fetch the newly created user
            $stmtFetchNew = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
            if (!$stmtFetchNew) { error_log("Prepare failed (fetch new user): " . $conn->error); $conn->rollback(); return null; }
            $stmtFetchNew->bind_param("i", $userId);
            if (!$stmtFetchNew->execute()) { error_log("Execute failed (fetch new user): " . $stmtFetchNew->error); $stmtFetchNew->close(); $conn->rollback(); return null; }
            $user = $stmtFetchNew->get_result()->fetch_assoc();
            $stmtFetchNew->close();

            if ($actualReferrerId) {
                $pointsPerReferral = POINTS_PER_REFERRAL;
                $updateReferrerStmt = $conn->prepare("UPDATE users SET points = points + ?, referral_count = referral_count + 1 WHERE user_id = ?");
                if (!$updateReferrerStmt) { error_log("Prepare failed (update referrer): " . $conn->error); $conn->rollback(); return null; }
                $updateReferrerStmt->bind_param("ii", $pointsPerReferral, $actualReferrerId); // Points for referrer is small, int is fine
                if (!$updateReferrerStmt->execute()) { error_log("Execute failed (update referrer): " . $updateReferrerStmt->error); /* Continue user creation */ }
                $updateReferrerStmt->close();
            }
        }

        // Energy regeneration
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $lastUpdate = new DateTime($user['last_energy_update_ts'], new DateTimeZone('UTC'));
        $secondsPassed = $now->getTimestamp() - $lastUpdate->getTimestamp();
        
        $currentEnergy = (float)$user['energy'];
        $maxEnergy = (float)$user['max_energy'];
        $energyPerSecondRate = (float)$user['energy_per_second'];

        if ($secondsPassed > 0 && $currentEnergy < $maxEnergy) {
            $energyGained = $secondsPassed * $energyPerSecondRate;
            $newEnergy = min($maxEnergy, $currentEnergy + $energyGained);
            if ($newEnergy > $currentEnergy) {
                $user['energy'] = $newEnergy;
            }
        }
        $user['last_energy_update_ts'] = $now->format('Y-m-d H:i:s'); // Always update timestamp

        // Update user record with new energy and last_energy_update_ts
        $updateEnergyStmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE user_id = ?");
        if (!$updateEnergyStmt) { error_log("Prepare failed (update energy): " . $conn->error); $conn->rollback(); return null; }
        $updateEnergyStmt->bind_param("dsi", $user['energy'], $user['last_energy_update_ts'], $userId);
        if (!$updateEnergyStmt->execute()) { error_log("Execute failed (update energy): " . $updateEnergyStmt->error); $updateEnergyStmt->close(); $conn->rollback(); return null; }
        $updateEnergyStmt->close();

        // Handle daily resets for the data to be returned (DB updates happen in specific actions)
        $current_utc_date_str = gmdate('Y-m-d');
        if ($user['last_tap_date_utc'] != $current_utc_date_str) $user['taps_today'] = 0;
        if ($user['last_ad_date_utc'] != $current_utc_date_str) $user['ads_watched_today'] = 0;
        if ($user['last_daily_tasks_date_utc'] != $current_utc_date_str) $user['daily_tasks_completed_mask'] = 0;
        
        $conn->commit();
        $user['energy'] = (float)$user['energy']; // Ensure float type for JSON
        $user['points'] = (string)$user['points']; // Ensure string for bcmath compatibility
        return $user;

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log("getUser transaction failed for user {$userId}: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
        return null;
    } catch (Exception $e) { // Catch any other generic exception
        $conn->rollback();
        error_log("Generic exception in getUser for user {$userId}: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        return null;
    }
}


switch ($action) {
    case 'getUserData':
        $referrerId = isset($input['referrer_id']) ? (int)$input['referrer_id'] : null;
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name, $referrerId);
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not load or create user data. Please check server logs.']);
        }
        break;

    case 'tap':
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found for tap.']); exit; }

        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_tap_date_utc'] != $current_utc_date) $user['taps_today'] = 0;

        if ((float)$user['energy'] < ENERGY_PER_TAP) { echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'user' => $user]); exit; }
        if ($user['taps_today'] >= MAX_DAILY_TAPS) { echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'user' => $user]); exit; }

        $user['points'] = bcadd($user['points'], '1');
        $user['energy'] = (float)$user['energy'] - ENERGY_PER_TAP;
        $user['taps_today'] += 1;
        $user['last_tap_date_utc'] = $current_utc_date;
        $user['last_energy_update_ts'] = gmdate('Y-m-d H:i:s');

        // ***** CORRECTED BINDING FOR POINTS ('s' instead of 'i') *****
        $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, taps_today = ?, last_tap_date_utc = ?, last_energy_update_ts = ? WHERE user_id = ?");
        if (!$stmt) { error_log("Prepare failed (tap update): " . $conn->error); echo json_encode(['success' => false, 'message' => 'Server error during tap.']); exit; }
        $stmt->bind_param("sdisssi", $user['points'], $user['energy'], $user['taps_today'], $user['last_tap_date_utc'], $user['last_energy_update_ts'], $telegram_user_id); 
        
        if ($stmt->execute()) {
            $user['energy'] = (float)$user['energy'];
            $user['points'] = (string)$user['points'];
            echo json_encode(['success' => true, 'user' => $user, 'message' => '+1 point!']);
        } else {
            error_log("Failed to record tap for user {$telegram_user_id}: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to record tap.']);
        }
        $stmt->close();
        break;

    case 'claimTask':
        $taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
        if ($taskId <= 0 || $taskId > NUM_DAILY_TASKS) { echo json_encode(['success' => false, 'message' => 'Invalid task ID.']); exit; }

        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found for task.']); exit; }
        
        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_daily_tasks_date_utc'] != $current_utc_date) $user['daily_tasks_completed_mask'] = 0;

        $task_bit = 1 << ($taskId - 1);
        if (($user['daily_tasks_completed_mask'] & $task_bit) > 0) { echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'user' => $user]); exit; }

        $user['points'] = bcadd($user['points'], (string)POINTS_PER_TASK);
        $user['daily_tasks_completed_mask'] |= $task_bit;
        $user['last_daily_tasks_date_utc'] = $current_utc_date;

        $stmt = $conn->prepare("UPDATE users SET points = ?, daily_tasks_completed_mask = ?, last_daily_tasks_date_utc = ? WHERE user_id = ?");
        if (!$stmt) { error_log("Prepare failed (claim task): " . $conn->error); echo json_encode(['success' => false, 'message' => 'Server error claiming task.']); exit; }
        $stmt->bind_param("sisi", $user['points'], $user['daily_tasks_completed_mask'], $user['last_daily_tasks_date_utc'], $telegram_user_id);
        
        if ($stmt->execute()) {
            $user['energy'] = (float)$user['energy'];
            $user['points'] = (string)$user['points'];
            echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_TASK, 'message' => 'Task claimed!']);
        } else {
            error_log("Failed to claim task {$taskId} for user {$telegram_user_id}: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to claim task.']);
        }
        $stmt->close();
        break;

    case 'watchAd':
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found for ad.']); exit; }

        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_ad_date_utc'] != $current_utc_date) $user['ads_watched_today'] = 0;

        if ($user['ads_watched_today'] >= MAX_DAILY_ADS) { echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'user' => $user]); exit; }

        if ($user['last_ad_watched_timestamp']) {
            $lastAdTime = new DateTime($user['last_ad_watched_timestamp'], new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $diffSeconds = $now->getTimestamp() - $lastAdTime->getTimestamp();
            if ($diffSeconds < AD_COOLDOWN_SECONDS) { echo json_encode(['success' => false, 'message' => 'Ad cooldown: Please wait ' . (AD_COOLDOWN_SECONDS - $diffSeconds) . 's.', 'user' => $user]); exit; }
        }
        
        $user['points'] = bcadd($user['points'], (string)POINTS_PER_AD);
        $user['ads_watched_today'] += 1;
        $user['last_ad_date_utc'] = $current_utc_date;
        $user['last_ad_watched_timestamp'] = gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE users SET points = ?, ads_watched_today = ?, last_ad_date_utc = ?, last_ad_watched_timestamp = ? WHERE user_id = ?");
        if (!$stmt) { error_log("Prepare failed (watch ad): " . $conn->error); echo json_encode(['success' => false, 'message' => 'Server error watching ad.']); exit; }
        $stmt->bind_param("sisssi", $user['points'], $user['ads_watched_today'], $user['last_ad_date_utc'], $user['last_ad_watched_timestamp'], $telegram_user_id);

        if ($stmt->execute()) {
            $user['energy'] = (float)$user['energy'];
            $user['points'] = (string)$user['points'];
            echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_AD, 'message' => 'Ad reward claimed!']);
        } else {
            error_log("Failed to claim ad reward for user {$telegram_user_id}: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to claim ad reward.']);
        }
        $stmt->close();
        break;

    case 'requestWithdrawal':
        $conn->begin_transaction();
        try {
            $stmt_user_lock = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
            if (!$stmt_user_lock) { throw new Exception("Prepare lock failed: " . $conn->error); }
            $stmt_user_lock->bind_param("i", $telegram_user_id);
            if (!$stmt_user_lock->execute()) { throw new Exception("Execute lock failed: " . $stmt_user_lock->error); }
            $user_result = $stmt_user_lock->get_result();
            $user = $user_result->fetch_assoc();
            $stmt_user_lock->close();

            if (!$user) { throw new Exception('User not found for withdrawal.'); }
            $user['points'] = (string)$user['points']; // Ensure string for bcmath

            $points_to_withdraw_input = isset($input['points_to_withdraw']) ? (int)$input['points_to_withdraw'] : 0;
            $points_to_withdraw = (string)$points_to_withdraw_input; // For bcmath
            $method = $input['method'] ?? '';
            $wallet_address_or_id = $input['wallet_address_or_id'] ?? '';

            $valid_amounts = [86000, 165000, 305000];
            if (!in_array($points_to_withdraw_input, $valid_amounts) || empty($method) || empty($wallet_address_or_id)) {
                throw new Exception('Invalid withdrawal details.');
            }

            if (bccomp($user['points'], $points_to_withdraw) < 0) {
                 echo json_encode(['success' => false, 'message' => 'Not enough points.', 'user' => $user]); // Send current user state
                 $conn->rollback(); // Rollback before exit if not enough points
                 exit;
            }

            $new_points = bcsub($user['points'], $points_to_withdraw);
            $stmt_update_user = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
            if (!$stmt_update_user) { throw new Exception("Prepare points update failed: " . $conn->error); }
            $stmt_update_user->bind_param("si", $new_points, $telegram_user_id);
            if(!$stmt_update_user->execute()){ throw new Exception("Execute points update failed: " . $stmt_update_user->error); }
            $stmt_update_user->close();

            $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, wallet_address_or_id, status, requested_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            if (!$stmt_insert_withdrawal) { throw new Exception("Prepare withdrawal insert failed: " . $conn->error); }
            // points_withdrawn in withdrawals table is BIGINT UNSIGNED, so int from input is fine here
            $stmt_insert_withdrawal->bind_param("iisss", $telegram_user_id, $points_to_withdraw_input, $method, $wallet_address_or_id);
            if(!$stmt_insert_withdrawal->execute()){ throw new Exception("Execute withdrawal insert failed: " . $stmt_insert_withdrawal->error); }
            $stmt_insert_withdrawal->close();
            
            $conn->commit();
            $user['points'] = $new_points;
            $user['energy'] = (float) $user['energy'];
            echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'user' => $user]);

        } catch (Exception $e) { // Catch both mysqli_sql_exception and generic Exception
            $conn->rollback();
            error_log("Withdrawal transaction failed for user {$telegram_user_id}: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            // Send a more generic error to the client for security
            echo json_encode(['success' => false, 'message' => 'Withdrawal request failed. Please try again later.']);
        }
        break;
    
    case 'getWithdrawalHistory':
        $stmt = $conn->prepare("SELECT withdrawal_id, points_withdrawn, method, wallet_address_or_id, status, requested_at FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20");
        if (!$stmt) { error_log("Prepare failed (get history): " . $conn->error); echo json_encode(['success' => false, 'message' => 'Server error getting history.']); exit; }
        $stmt->bind_param("i", $telegram_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'history' => $history]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}

$conn->close();
?>
