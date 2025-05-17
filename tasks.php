<?php
// tasks.php
header('Content-Type: application/json');
require_once 'db_config.php'; // Includes $conn and handle_daily_reset

// Define tasks (could be from a database table in a more complex app)
// For "daily refresh", we check against `tasks_completed_today_json` in the user's record.
$defined_tasks = [
    [
        'id' => 'task_channel_1', 
        'title' => 'Join Telegram Channel 1', 
        'description' => 'Join our main news channel.',
        'link' => 'https://t.me/Watchtapearn', // REPLACE WITH ACTUAL LINK
        'points_reward' => 50
    ],
    [
        'id' => 'task_group_1', 
        'title' => 'Join Telegram Group', 
        'description' => 'Join our community group.',
        'link' => 'https://t.me/Watchtapearnchat', // REPLACE WITH ACTUAL LINK
        'points_reward' => 50
    ],
    [
        'id' => 'task_channel_2', 
        'title' => 'Join Partner Channel', 
        'description' => 'Support our partner by joining their channel.',
        'link' => 'https://t.me/earningsceret', // REPLACE WITH ACTUAL LINK
        'points_reward' => 50
    ],
    [
        'id' => 'task_channel_3', 
        'title' => 'Join Announcement Channel', 
        'description' => 'Get important announcements here.',
        'link' => 'https://t.me/ShopEarnHub4102h', // REPLACE WITH ACTUAL LINK
        'points_reward' => 50
    ],
];

$user_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = isset($_GET['user_id']) ? filter_var($_GET['user_id'], FILTER_VALIDATE_INT) : null;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'User ID required for GET.']);
        exit();
    }
    
    handle_daily_reset($conn, $user_id); // Ensure daily data is fresh

    $stmt = $conn->prepare("SELECT tasks_completed_today_json FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_tasks_data = $result->fetch_assoc();
    $stmt->close();

    $completed_tasks_today = [];
    if ($user_tasks_data && $user_tasks_data['tasks_completed_today_json']) {
        $completed_tasks_today = json_decode($user_tasks_data['tasks_completed_today_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $completed_tasks_today = []; // Handle potential JSON decode error
        }
    }

    $tasks_with_status = [];
    foreach ($defined_tasks as $task) {
        $task['completed'] = in_array($task['id'], $completed_tasks_today);
        $tasks_with_status[] = $task;
    }
    echo json_encode(['success' => true, 'tasks' => $tasks_with_status]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id'], $input['task_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. User ID and Task ID required.']);
        exit();
    }

    $user_id = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $task_id = filter_var($input['task_id'], FILTER_SANITIZE_STRING);

    if (!$user_id || !$task_id) {
        echo json_encode(['success' => false, 'message' => 'Valid User ID and Task ID are required.']);
        exit();
    }

    handle_daily_reset($conn, $user_id); // Ensure daily data is fresh

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT points, tasks_completed_today_json FROM users WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            throw new Exception('User not found.');
        }

        $current_task = null;
        foreach ($defined_tasks as $dt) {
            if ($dt['id'] === $task_id) {
                $current_task = $dt;
                break;
            }
        }

        if (!$current_task) {
            throw new Exception('Invalid task ID.');
        }

        $completed_tasks_today = $user['tasks_completed_today_json'] ? json_decode($user['tasks_completed_today_json'], true) : [];
         if (json_last_error() !== JSON_ERROR_NONE) $completed_tasks_today = [];


        if (in_array($task_id, $completed_tasks_today)) {
            throw new Exception('Task already completed today.');
        }

        $points_reward = $current_task['points_reward'];
        $completed_tasks_today[] = $task_id;
        $new_tasks_json = json_encode($completed_tasks_today);

        $update_stmt = $conn->prepare("UPDATE users SET points = points + ?, tasks_completed_today_json = ? WHERE user_id = ?");
        $update_stmt->bind_param("isi", $points_reward, $new_tasks_json, $user_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update user data for task completion.');
        }
        $update_stmt->close();
        $conn->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Task completed successfully!',
            'points_earned' => $points_reward,
            'new_total_points' => $user['points'] + $points_reward,
            'tasks_completed_today_json' => $completed_tasks_today // send back the updated array
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}

$conn->close();
?>
