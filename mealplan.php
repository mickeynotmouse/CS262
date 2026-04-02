<?php
require_once '../config.php';
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    getMealPlan();
} elseif ($method === 'POST') {
    saveMealPlan();
} elseif ($method === 'DELETE') {
    deleteMealPlanEntry();
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

// get meal plan for a week
function getMealPlan() {
    $db      = getDB();
    $user_id = $_GET['user_id'] ?? null;
    $week    = $_GET['week'] ?? date('Y-W');

    if (!$user_id) {
        echo json_encode(['error' => 'user_id required']);
        return;
    }

    // Get Monday of the requested week
    $monday = new DateTime();
    $monday->setISODate(explode('-', $week)[0], explode('-', $week)[1]);
    $sunday = clone $monday;
    $sunday->modify('+6 days');

    $stmt = $db->prepare('
        SELECT mp.id, mp.planned_date, mp.meal_slot,
               r.id AS recipe_id, r.title, r.image_path,
               n.calories
        FROM meal_plan mp
        JOIN recipes r ON mp.recipe_id = r.id
        LEFT JOIN nutrition_info n ON r.id = n.recipe_id
        WHERE mp.user_id = ?
        AND mp.planned_date BETWEEN ? AND ?
        ORDER BY mp.planned_date, mp.meal_slot
    ');

    $mondayStr = $monday->format('Y-m-d');
    $sundayStr = $sunday->format('Y-m-d');
    $stmt->bind_param('iss', $user_id, $mondayStr, $sundayStr);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode($result);
    $stmt->close();
    $db->close();
}

// save meal plan entry
function saveMealPlan() {
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $user_id      = $data['user_id'] ?? null;
    $recipe_id    = $data['recipe_id'] ?? null;
    $planned_date = $data['planned_date'] ?? null;
    $meal_slot    = $data['meal_slot'] ?? 'dinner';

    if (!$user_id || !$recipe_id || !$planned_date) {
        echo json_encode(['error' => 'user_id, recipe_id and planned_date are required']);
        return;
    }

    $stmt = $db->prepare('INSERT INTO meal_plan (user_id, recipe_id, planned_date, meal_slot) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $user_id, $recipe_id, $planned_date, $meal_slot);
    $stmt->execute();

    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
    $stmt->close();
    $db->close();
}

// delete meal plan entry
function deleteMealPlanEntry() {
    $db = getDB();
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['error' => 'id required']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM meal_plan WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    echo json_encode(['success' => true]);
    $stmt->close();
    $db->close();
}
?>