<?php
require_once 'db_config.php';

header('Content-Type: application/json');
// IMPORTANT: For production, consider validating tg.initData to prevent spoofing.
// A simple check could be to ensure telegram_user_id from POST matches user_id in parsed initData.
// For now, we trust the client-sent telegram_user_id for simplicity as per initial request.

$input = $_POST;
$action = $input['action'] ?? '';
$telegram_user_id = isset($input['telegram_user_id']) ? (int)$input['telegram_user_id'] : 0;
$telegram_username = $input['telegram_username'] ?? null;
$telegram_first_name = $input['telegram_first_name'] ?? null;
// $telegram_init_data_raw = $input['telegram_init_data'] ?? ''; // For potential future validation

if (empty($telegram_user_id) && !in_array($action, ['test'])) { // Allow some actions without user_id if necessary
    echo json_encode(['success' => false, 'message' => 'User identification failed.']);
    exit;
}

function getUser(mysqli $conn, int $userId, ?string $username, ?string $firstName, ?int $referrerIdParam = null): ?array {
    $conn->begin_transaction(); // Start transaction for user creation/update
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE"); // Lock row for update
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        $isNewUser = false;
        if (!$user) {
            $isNewUser = true;
            $initialEnergy = 100;
            $energyPerSecond = 0.1; // Defined in db_config or here
            $actualReferrerId = null;

            if ($referrerIdParam && $referrerIdParam != $userId) { // Check not self-referral
                // Check if referrer exists
                $refStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
                $refStmt->bind_param("i", $referrerIdParam);
                $refStmt->execute();
                $refResult = $refStmt->get_result();
                if ($refResult->num_rows > 0) {
                    $actualReferrerId = $referrerIdParam;
                }
                $refStmt->close();
            }

            $insertStmt = $conn->prepare("INSERT INTO users (user_id, username, first_name, energy, max_energy, energy_per_second, last_energy_update_ts, referred_by_user_id, join_date) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW())");
            $insertStmt->bind_param("issiddis", $userId, $username, $firstName, $initialEnergy, $initialEnergy, $energyPerSecond, $actualReferrerId);
            if (!$insertStmt->execute()) {
                error_log("Failed to create user {$userId}: " . $insertStmt->error);
                $conn->rollback();
                return null;
            }
            $insertStmt->close();
            // Re-fetch the newly created user for consistency
            $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($actualReferrerId) {
                $pointsPerReferral = POINTS_PER_REFERRAL;
                $updateReferrerStmt = $conn->prepare("UPDATE users SET points = points + ?, referral_count = referral_count + 1 WHERE user_id = ?");
                $updateReferrerStmt->bind_param("ii", $pointsPerReferral, $actualReferrerId);
                $updateReferrerStmt->execute();
                $updateReferrerStmt->close();
            }
        }

        // Energy regeneration
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $lastUpdate = new DateTime($user['last_energy_update_ts'], new DateTimeZone('UTC'));
        $secondsPassed = $now->getTimestamp() - $lastUpdate->getTimestamp();
        
        if ($secondsPassed > 0 && $user['energy'] < $user['max_energy']) {
            $energyGained = $secondsPassed * $user['energy_per_second'];
            $newEnergy = min((float)$user['max_energy'], (float)$user['energy'] + $energyGained); // Cast to float
            if ($newEnergy > (float)$user['energy']) { // Cast to float
                $user['energy'] = $newEnergy;
                // This update will be committed with other potential updates or at the end.
            }
        }
        // Always update last_energy_update_ts to prevent large jumps after long inactivity if energy was already full.
        $user['last_energy_update_ts'] = $now->format('Y-m-d H:i:s');

        // Update user record with new energy and last_energy_update_ts
        // This needs to happen regardless of other daily resets to ensure energy is saved.
        $updateEnergyStmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE user_id = ?");
        $updateEnergyStmt->bind_param("dsi", $user['energy'], $user['last_energy_update_ts'], $userId);
        $updateEnergyStmt->execute();
        $updateEnergyStmt->close();


        // Handle daily resets (these prepare the $user array, actual DB save happens in specific actions)
        $current_utc_date_str = gmdate('Y-m-d');
        
        if ($user['last_tap_date_utc'] != $current_utc_date_str) {
            $user['taps_today'] = 0;
            // $user['last_tap_date_utc'] will be set by 'tap' action
        }
        if ($user['last_ad_date_utc'] != $current_utc_date_str) {
            $user['ads_watched_today'] = 0;
            // $user['last_ad_date_utc'] will be set by 'watchAd' action
        }
        if ($user['last_daily_tasks_date_utc'] != $current_utc_date_str) {
            $user['daily_tasks_completed_mask'] = 0;
            // $user['last_daily_tasks_date_utc'] will be set by 'claimTask' action
        }
        
        $conn->commit();
        return $user;

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log("getUser transaction failed for user {$userId}: " . $exception->getMessage());
        return null;
    }
}

switch ($action) {
    case 'getUserData':
        $referrerId = isset($input['referrer_id']) ? (int)$input['referrer_id'] : null;
        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name, $referrerId);
        if ($user) {
            // Cast energy to float for JSON if it's not already
            $user['energy'] = (float) $user['energy'];
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not load or create user data.']);
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
        }

        if ($user['energy'] < ENERGY_PER_TAP) {
            echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'user' => $user]);
            exit;
        }
        if ($user['taps_today'] >= MAX_DAILY_TAPS) {
            echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'user' => $user]);
            exit;
        }

        $user['points'] = bcadd($user['points'], '1'); // Use bcmath for large numbers if points can exceed PHP_INT_MAX
        $user['energy'] -= ENERGY_PER_TAP;
        $user['taps_today'] += 1;
        $user['last_tap_date_utc'] = $current_utc_date; // Set the date for today's taps
        
        // Update last_energy_update_ts as energy was consumed
        $user['last_energy_update_ts'] = gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, taps_today = ?, last_tap_date_utc = ?, last_energy_update_ts = ? WHERE user_id = ?");
        $stmt->bind_param("sdisssi", $user['points'], $user['energy'], $user['taps_today'], $user['last_tap_date_utc'], $user['last_energy_update_ts'], $telegram_user_id); // s for points if using bcmath string
        
        if ($stmt->execute()) {
            $user['energy'] = (float) $user['energy']; // Ensure float for response
            echo json_encode(['success' => true, 'user' => $user, 'message' => '+1 point!']);
        } else {
            error_log("Failed to record tap for user {$telegram_user_id}: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to record tap.']);
        }
        $stmt->close();
        break;

    case 'claimTask':
        $taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
        if ($taskId <= 0 || $taskId > NUM_DAILY_TASKS) {
            echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
            exit;
        }

        $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        
        $current_utc_date = gmdate('Y-m-d');
        if ($user['last_daily_tasks_date_utc'] != $current_utc_date) {
            $user['daily_tasks_completed_mask'] = 0; // Reset mask for new day
        }

        $task_bit = 1 << ($taskId - 1);
        if (($user['daily_tasks_completed_mask'] & $task_bit) > 0) {
            echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'user' => $user]);
            exit;
        }

        $user['points'] = bcadd($user['points'], (string)POINTS_PER_TASK);
        $user['daily_tasks_completed_mask'] |= $task_bit;
        $user['last_daily_tasks_date_utc'] = $current_utc_date; // Set the date for today's tasks

        $stmt = $conn->prepare("UPDATE users SET points = ?, daily_tasks_completed_mask = ?, last_daily_tasks_date_utc = ? WHERE user_id = ?");
        $stmt->bind_param("sisi", $user['points'], $user['daily_tasks_completed_mask'], $user['last_daily_tasks_date_utc'], $telegram_user_id);
        
        if ($stmt->execute()) {
            $user['energy'] = (float) $user['energy'];
            echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_TASK, 'message' => 'Task claimed!']);
        } else {
            error_log("Failed to claim task {$taskId} for user {$telegram_user_id}: " . $stmt->error);
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
            $user['ads_watched_today'] = 0;
        }

        if ($user['ads_watched_today'] >= MAX_DAILY_ADS) {
            echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'user' => $user]);
            exit;
        }

        if ($user['last_ad_watched_timestamp']) {
            $lastAdTime = new DateTime($user['last_ad_watched_timestamp'], new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $diffSeconds = $now->getTimestamp() - $lastAdTime->getTimestamp();
            if ($diffSeconds < AD_COOLDOWN_SECONDS) {
                echo json_encode(['success' => false, 'message' => 'Ad cooldown: Please wait ' . (AD_COOLDOWN_SECONDS - $diffSeconds) . 's.', 'user' => $user]);
                exit;
            }
        }
        
        $user['points'] = bcadd($user['points'], (string)POINTS_PER_AD);
        $user['ads_watched_today'] += 1;
        $user['last_ad_date_utc'] = $current_utc_date; // Set date for today's ad
        $user['last_ad_watched_timestamp'] = gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE users SET points = ?, ads_watched_today = ?, last_ad_date_utc = ?, last_ad_watched_timestamp = ? WHERE user_id = ?");
        $stmt->bind_param("sisssi", $user['points'], $user['ads_watched_today'], $user['last_ad_date_utc'], $user['last_ad_watched_timestamp'], $telegram_user_id);

        if ($stmt->execute()) {
            $user['energy'] = (float) $user['energy'];
            echo json_encode(['success' => true, 'user' => $user, 'points_earned' => POINTS_PER_AD, 'message' => 'Ad reward claimed!']);
        } else {
            error_log("Failed to claim ad reward for user {$telegram_user_id}: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to claim ad reward.']);
        }
        $stmt->close();
        break;

    case 'requestWithdrawal':
        // This action requires a lock on the user row to prevent race conditions on points.
        $conn->begin_transaction();
        try {
            $stmt_user_lock = $conn->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
            $stmt_user_lock->bind_param("i", $telegram_user_id);
            $stmt_user_lock->execute();
            $user_result = $stmt_user_lock->get_result();
            $user = $user_result->fetch_assoc();
            $stmt_user_lock->close();

            if (!$user) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }
            // Refresh energy before withdrawal decision, though not strictly necessary for withdrawal logic itself
            // $user = getUser($conn, $telegram_user_id, $telegram_username, $telegram_first_name); // Re-fetch inside transaction might be complex.
            // The FOR UPDATE lock ensures we have the latest point data.

            $points_to_withdraw = isset($input['points_to_withdraw']) ? (int)$input['points_to_withdraw'] : 0;
            $method = $input['method'] ?? '';
            $wallet_address_or_id = $input['wallet_address_or_id'] ?? '';

            $valid_amounts = [86000, 165000, 305000];
            if (!in_array($points_to_withdraw, $valid_amounts) || empty($method) || empty($wallet_address_or_id)) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Invalid withdrawal details.']);
                exit;
            }

            if (bccomp($user['points'], (string)$points_to_withdraw) < 0) { // Use bccomp for large numbers
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Not enough points.', 'user' => $user]); // Return current user state
                exit;
            }

            $new_points = bcsub($user['points'], (string)$points_to_withdraw);
            $stmt_update_user = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
            $stmt_update_user->bind_param("si", $new_points, $telegram_user_id);
            if(!$stmt_update_user->execute()){
                 $conn->rollback();
                 error_log("Withdrawal user point update failed: " . $stmt_update_user->error);
                 echo json_encode(['success' => false, 'message' => 'Withdrawal failed: could not update points.']);
                 exit;
            }
            $stmt_update_user->close();

            $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, wallet_address_or_id, status, requested_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt_insert_withdrawal->bind_param("iisss", $telegram_user_id, $points_to_withdraw, $method, $wallet_address_or_id);
             if(!$stmt_insert_withdrawal->execute()){
                 $conn->rollback();
                 error_log("Withdrawal record insert failed: " . $stmt_insert_withdrawal->error);
                 echo json_encode(['success' => false, 'message' => 'Withdrawal failed: could not record request.']);
                 exit;
            }
            $stmt_insert_withdrawal->close();
            
            $conn->commit();
            $user['points'] = $new_points; // Update local user object for response
            $user['energy'] = (float) $user['energy'];
            echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'user' => $user]);

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log("Withdrawal transaction failed for user {$telegram_user_id}: " . $exception->getMessage());
            echo json_encode(['success' => false, 'message' => 'Withdrawal request failed due to a server error. Please try again.']);
        }
        break;
    
    case 'getWithdrawalHistory':
        // No need to call full getUser here if we only need user_id for the query
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
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}

$conn->close();
?>
