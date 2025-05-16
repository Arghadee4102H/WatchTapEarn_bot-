<?php
require_once 'db_config.php'; // Includes DB connection and constants

header('Content-Type: application/json');
// Allow requests from the Telegram WebApp origin (or '*' for development)
// header('Access-Control-Allow-Origin: *'); // Potentially dangerous in production
// Consider more specific origin if your TWA has a fixed one.

// Get input data (prefer POST)
$input = $_POST;
if (empty($input)) {
    $input = $_GET; // Fallback for simple GET tests, but POST is better
}

$action = $input['action'] ?? '';
$telegram_user_id = isset($input['telegram_user_id']) ? (int)$input['telegram_user_id'] : 0;
$telegram_username = $input['telegram_username'] ?? null;
$telegram_first_name = $input['telegram_first_name'] ?? null;

// Basic validation for Telegram User ID
if (empty($telegram_user_id) && $action !== 'test') { // 'test' action might not need user_id
    echo json_encode(['success' => false, 'message' => 'User identification failed.']);
    exit;
}

// --- HELPER FUNCTIONS ---

function getUser(mysqli $conn, int $userId, ?string $username, ?string $firstName, ?int $referrerId = null): ?array {
    // Try to fetch existing user
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $isNewUser = false;
    if (!$user) {
        // Create new user
        $isNewUser = true;
        $initialEnergy = 100; // Default max_energy
        $energyPerSecond = 0.1; // Default energy_per_second

        $insertStmt = $conn->prepare("INSERT INTO users (user_id, username, first_name, energy, max_energy, energy_per_second, last_energy_update_ts, referred_by_user_id, join_date) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW())");
        $insertStmt->bind_param("issidis", $userId, $username, $firstName, $initialEnergy, $initialEnergy, $energyPerSecond, $referrerId);
        if ($insertStmt->execute()) {
            $user = [
                'user_id' => $userId,
                'username' => $username,
                'first_name' => $firstName,
                'points' => 0,
                'energy' => $initialEnergy,
                'max_energy' => $initialEnergy,
                'energy_per_second' => $energyPerSecond,
                'last_energy_update_ts' => date('Y-m-d H:i:s'), // Current time
                'taps_today' => 0,
                'last_tap_date_utc' => null,
                'ads_watched_today' => 0,
                'last_ad_date_utc' => null,
                'last_ad_watched_timestamp' => null,
                'daily_tasks_completed_mask' => 0,
                'last_daily_tasks_date_utc' => null,
                'referred_by_user_id' => $referrerId,
                'referral_count' => 0,
                'join_date' => date('Y-m-d H:i:s')
            ];
        } else {
            error_log("Failed to create user: " . $insertStmt->error);
            return null;
        }
        $insertStmt->close();

        // If referred, award points to referrer
        if ($referrerId) {
            $updateReferrerStmt = $conn->prepare("UPDATE users SET points = points + ?, referral_count = referral_count + 1 WHERE user_id = ?");
            $pointsPerReferral = POINTS_PER_REFERRAL;
            $updateReferrerStmt->bind_param("ii", $pointsPerReferral, $referrerId);
            $updateReferrerStmt->execute();
            $updateReferrerStmt->close();
            // Optional: Log this referral in referral_log table
        }
    }

    // Update energy based on time passed
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $lastUpdate = new DateTime($user['last_energy_update_ts'], new DateTimeZone('UTC'));
    $secondsPassed = $now->getTimestamp() - $lastUpdate->getTimestamp();
    
    if ($secondsPassed > 0 && $user['energy'] < $user['max_energy']) {
        $energyGained = $secondsPassed * $user['energy_per_second'];
        $newEnergy = min($user['max_energy'], $user['energy'] + $energyGained);
        if ($newEnergy > $user['energy']) {
            $user['energy'] = $newEnergy;
            $updateEnergyStmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = NOW() WHERE user_id = ?");
            $updateEnergyStmt->bind_param("di", $user['energy'], $userId); // d for double/float
            $updateEnergyStmt->execute();
            $updateEnergyStmt->close();
        }
    }
     // Always update last_energy_update_ts if it's an old record from a different day without activity to avoid huge energy gains in one go.
    // Or ensure energy gain is capped properly by max_energy. The min() above handles this.

    // Check and reset daily limits (taps, ads, tasks)
    $current_utc_date = gmdate('Y-m-d');

    // Taps reset
    if ($user['last_tap_date_utc'] != $current_utc_date) {
        $user['taps_today'] = 0;
        $user['last_tap_date_utc'] = $current_utc_date; // This will be saved on next tap action
    }
    // Ads reset
    if ($user['last_ad_date_utc'] != $current_utc_date) {
        $user['ads_watched_today'] = 0;
        // last_ad_watched_timestamp is handled per ad
    }
    // Tasks reset
    if ($user['last_daily_tasks_date_utc'] != $current_utc_date) {
        $user['daily_tasks_completed_mask'] = 0;
    }
    
    // If any daily values were reset, reflect this in DB if user is not new
    // This logic is often better handled within specific actions (tap, watchAd, claimTask)
    // to avoid unnecessary DB writes on every getUserData call.
    // For now, the specific actions will handle updating their respective date fields.

    return $user;
}

// --- API ACTIONS ---

switch ($action) {
    case 'getUserData':
        $referrerId = isset($input['referrer_id']) ? (int)$input['referrer_id'] : null;
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name, $referrerId);
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not load user data.']);
        }
        break;

    case 'tap':
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_tap_date_utc'] != $current_utc_date) {
            $user['taps_today'] = 0; // Reset for new day
            $user['last_tap_date_utc'] = $current_utc_date;
        }

        if ($user['energy'] < 1) {
            echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'user' => $user]);
            exit;
        }
        if ($user['taps_today'] >= MAX_DAILY_TAPS) {
            echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'user' => $user]);
            exit;
        }

        $user['points'] += 1; // 1 point per tap
        $user['energy'] -= 1; // 1 energy per tap
        $user['taps_today'] += 1;
        
        $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, taps_today = ?, last_tap_date_utc = ?, last_energy_update_ts = NOW() WHERE user_id = ?");
        $stmt->bind_param("iissi", $user['points'], $user['energy'], $user['taps_today'], $user['last_tap_date_utc'], $telegram_user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'user' => $user, 'message' => '+1 point!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record tap.']);
        }
        $stmt->close();
        break;

    case 'claimTask':
        $taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
        if ($taskId <= 0 || $taskId > NUM_DAILY_TASKS) {
            echo json_encode(['success' => false, 'message' => 'Invalid task.']);
            exit;
        }

        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        
        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_daily_tasks_date_utc'] != $current_utc_date) {
            $user['daily_tasks_completed_mask'] = 0; // Reset for new day
            $user['last_daily_tasks_date_utc'] = $current_utc_date;
        }

        $task_bit = 1 << ($taskId - 1);
        if (($user['daily_tasks_completed_mask'] & $task_bit) > 0) {
            echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'user' => $user]);
            exit;
        }

        $user['points'] += POINTS_PER_TASK;
        $user['daily_tasks_completed_mask'] |= $task_bit;

        $stmt = $conn->prepare("UPDATE users SET points = ?, daily_tasks_completed_mask = ?, last_daily_tasks_date_utc = ? WHERE user_id = ?");
        $stmt->bind_param("iisi", $user['points'], $user['daily_tasks_completed_mask'], $user['last_daily_tasks_date_utc'], $telegram_user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'user' => $user, 'message' => 'Task claimed! +' . POINTS_PER_TASK . ' points.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to claim task.']);
        }
        $stmt->close();
        break;

    case 'watchAd':
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_ad_date_utc'] != $current_utc_date) {
            $user['ads_watched_today'] = 0; // Reset for new day
            $user['last_ad_date_utc'] = $current_utc_date;
        }

        if ($user['ads_watched_today'] >= MAX_DAILY_ADS) {
            echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'user' => $user]);
            exit;
        }

        // Cooldown check (3 minutes)
        if ($user['last_ad_watched_timestamp']) {
            $lastAdTime = new DateTime($user['last_ad_watched_timestamp']);
            $now = new DateTime();
            $diffSeconds = $now->getTimestamp() - $lastAdTime->getTimestamp();
            if ($diffSeconds < AD_COOLDOWN_SECONDS) {
                echo json_encode(['success' => false, 'message' => 'Please wait before watching another ad.', 'user' => $user]);
                exit;
            }
        }
        
        $user['points'] += POINTS_PER_AD;
        $user['ads_watched_today'] += 1;
        $user['last_ad_watched_timestamp'] = gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE users SET points = ?, ads_watched_today = ?, last_ad_date_utc = ?, last_ad_watched_timestamp = NOW() WHERE user_id = ?");
        $stmt->bind_param("iissi", $user['points'], $user['ads_watched_today'], $user['last_ad_date_utc'], $telegram_user_id);

        if ($stmt->execute()) {
            // Return updated user data
            $updatedUser = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name); // Re-fetch to get latest state
            echo json_encode(['success' => true, 'user' => $updatedUser, 'points_earned' => POINTS_PER_AD, 'message' => 'Ad reward claimed!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to claim ad reward.']);
        }
        $stmt->close();
        break;

    case 'requestWithdrawal':
        $points_to_withdraw = isset($input['points_to_withdraw']) ? (int)$input['points_to_withdraw'] : 0;
        $method = $input['method'] ?? '';
        $wallet_address_or_id = $input['wallet_address_or_id'] ?? '';

        // Basic validation
        if ($points_to_withdraw <= 0 || empty($method) || empty($wallet_address_or_id)) {
            echo json_encode(['success' => false, 'message' => 'Invalid withdrawal details.']);
            exit;
        }
        // Validate withdrawal amounts
        $valid_amounts = [86000, 165000, 305000];
        if (!in_array($points_to_withdraw, $valid_amounts)) {
            echo json_encode(['success' => false, 'message' => 'Invalid withdrawal amount.']);
            exit;
        }

        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        if ($user['points'] < $points_to_withdraw) {
            echo json_encode(['success' => false, 'message' => 'Not enough points.', 'user' => $user]);
            exit;
        }

        // Start transaction
        $conn->begin_transaction();
        try {
            // Deduct points
            $new_points = $user['points'] - $points_to_withdraw;
            $stmt_update_user = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
            $stmt_update_user->bind_param("ii", $new_points, $telegram_user_id);
            $stmt_update_user->execute();

            // Insert withdrawal record
            $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, wallet_address_or_id, status, requested_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt_insert_withdrawal->bind_param("iisss", $telegram_user_id, $points_to_withdraw, $method, $wallet_address_or_id);
            $stmt_insert_withdrawal->execute();
            
            $conn->commit();
            $user['points'] = $new_points; // Update local user object
            echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'user' => $user]);

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log("Withdrawal failed: " . $exception->getMessage());
            echo json_encode(['success' => false, 'message' => 'Withdrawal request failed. Please try again.']);
        }
        break;
    
    case 'getWithdrawalHistory':
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        $stmt = $conn->prepare("SELECT points_withdrawn, method, wallet_address_or_id, status, requested_at FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20");
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
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}

$conn->close();
?>
