<?php
require_once '../config.php';
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    if ($action === 'single') getRecipe();
    else getAllRecipes();
} elseif ($method === 'POST') {
    if ($action === 'upload_image') uploadImage();
    else createRecipe();
} elseif ($method === 'PUT') {
    updateRecipe();
} elseif ($method === 'DELETE') {
    deleteRecipe();
} else {
    echo json_encode(['error' => 'Invalid request method']);
}


//Get all recipes
function getAllRecipes() {
    $db = getDB();

    $user_id = $_GET['user_id'] ?? null;
    $tag = $_GET['tag'] ?? null;
    $ingredient = $_GET['ingredient'] ?? null;

    $sql = 'SELECT DISTINCT r.id, r.title, r.servings, r.image_path, r.created_at, GROUP_CONCAT(DISTINCT t.name) AS tags FROM recipes r LEFT JOIN recipe_tags rt ON r.id = rt.recipe_id LEFT JOIN tags t ON rt.tag_id = t.id LEFT JOIN ingredients i ON r.id = i.recipe_id WHERE 1=1';

$params = [];
$types = '';

if ($user_id) {
    $sql .= ' AND r.user_id = ?';
    $params[] = $user_id;
    $types .= 'i';
}
if ($tag) {
    $sql .= ' AND t.name = ?';
    $params[] = $tag;
    $types .= 's';

}
if ($ingredient) {
    $sql .= ' AND i.name = ?';
    $params[] = "%$ingredient%";
    $types .= 's';
}

$sql .= ' GROUP BY r.id ORDER BY r.created_at DESC';

$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$recipes = [];
while ($row = $result->fetch_assoc()) {
        $row['tags'] = $row['tags'] ? explode(',', $row['tags']) : [];
        $recipes[] = $row;
}

echo json_encode($recipes);
$stmt->close();
$db->close();
}

//Get single recipe
function getRecipe() {
    $db = getDB();
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['error' => 'Recipe ID is required']);
        return;
    }

    // Get recipe
    $stmt = $db->prepare('SELECT * FROM recipes WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recipe = $result->fetch_assoc();

    if (!$recipe) {
        echo json_encode(['error' => 'Recipe not found']);
        return;
    }

    // Get ingredients
    $stmt = $db->prepare('SELECT name, quantity, unit FROM ingredients WHERE recipe_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $recipe['ingredients'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get tags
    $stmt = $db->prepare('SELECT t.name FROM tags t JOIN recipe_tags rt ON t.id = rt.tag_id WHERE rt.recipe_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tags = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipe['tags'] = array_column($tags, 'name');

    // Get nutrition
    $stmt = $db->prepare('SELECT calories, protein, fat, carbs FROM nutrition_info WHERE recipe_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $recipe['nutrition'] = $stmt->get_result()->fetch_assoc();

    echo json_encode($recipe);
    $stmt->close();
    $db->close();
}

//Create recipe
function createRecipe() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $user_id = $data['user_id'] ?? null;
    $title = trim($data['title'] ?? '');
    $instructions = trim($data['instructions'] ?? '');
    $servings = intval($data['servings'] ?? 1);
    $image_path = $data['image_path'] ?? null;
    $ingredients = $data['ingredients'] ?? [];
    $tags = $data['tags'] ?? [];
    $nutrition = $data['nutrition'] ?? null;

    if(!$user_id || !$title) {
        echo json_encode(['error' => 'User ID and title are required']);
        return;
    }

    //Insert recipe
    $stmt = $db->prepare('INSERT INTO recipes (user_id, title, instructions, servings, image_path) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issis', $user_id, $title, $instructions, $servings, $image_path);
    $stmt->execute();
    $recipe_id = $stmt->insert_id;

    // Insert ingredients
    foreach ($ingredients as $ing) {
        $stmt = $db->prepare('INSERT INTO ingredients (recipe_id, name, quantity, unit) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isds', $recipe_id, $ing['name'], $ing['quantity'], $ing['unit']);
        $stmt->execute();
    }

    // Insert tags
    foreach ($tags as $tag_name) {
        $tag_name = trim($tag_name);
        // Insert tag if it doesn't exist
        $stmt = $db->prepare('INSERT IGNORE INTO tags (name) VALUES (?)');
        $stmt->bind_param('s', $tag_name);
        $stmt->execute();

        // Get tag ID
        $stmt = $db->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->bind_param('s', $tag_name);
        $stmt->execute();
        $tag = $stmt->get_result()->fetch_assoc();

        // Link tag to recipe
        $stmt = $db->prepare('INSERT IGNORE INTO recipe_tags (recipe_id, tag_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $recipe_id, $tag['id']);
        $stmt->execute();
    }

    // Insert nutrition info
    if ($nutrition) {
        $stmt = $db->prepare('INSERT INTO nutrition_info (recipe_id, calories, protein, fat, carbs) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iiddd', $recipe_id, $nutrition['calories'], $nutrition['protein'], $nutrition['fat'], $nutrition['carbs']);
        $stmt->execute();
    }

    echo json_encode(['success' => true, 'recipe_id' => $recipe_id]);
    $stmt->close();
    $db->close();
}

//Update recipe
function updateRecipe() {
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $id           = $data['id'] ?? null;
    $title        = trim($data['title'] ?? '');
    $instructions = trim($data['instructions'] ?? '');
    $servings     = intval($data['servings'] ?? 1);
    $ingredients  = $data['ingredients'] ?? [];
    $tags         = $data['tags'] ?? [];

    if (!$id || !$title) {
        echo json_encode(['error' => 'id and title are required']);
        return;
    }

    // Update recipe
    $stmt = $db->prepare('UPDATE recipes SET title=?, instructions=?, servings=? WHERE id=?');
    $stmt->bind_param('ssii', $title, $instructions, $servings, $id);
    $stmt->execute();

    // Replace ingredients
    $stmt = $db->prepare('DELETE FROM ingredients WHERE recipe_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    foreach ($ingredients as $ing) {
        $stmt = $db->prepare('INSERT INTO ingredients (recipe_id, name, quantity, unit) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isds', $id, $ing['name'], $ing['quantity'], $ing['unit']);
        $stmt->execute();
    }

    // Replace tags
    $stmt = $db->prepare('DELETE FROM recipe_tags WHERE recipe_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    foreach ($tags as $tag_name) {
        $tag_name = trim($tag_name);
        $stmt = $db->prepare('INSERT IGNORE INTO tags (name) VALUES (?)');
        $stmt->bind_param('s', $tag_name);
        $stmt->execute();

        $stmt = $db->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->bind_param('s', $tag_name);
        $stmt->execute();
        $tag = $stmt->get_result()->fetch_assoc();

        $stmt = $db->prepare('INSERT IGNORE INTO recipe_tags (recipe_id, tag_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $id, $tag['id']);
        $stmt->execute();
    }

    echo json_encode(['success' => true]);
    $stmt->close();
    $db->close();
}

//Delete recipe
function deleteRecipe() {
    $db = getDB();
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['error' => 'Recipe ID required']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM recipes WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    echo json_encode(['success' => true]);
    $stmt->close();
    $db->close();
}


//Image upload
function uploadImage() {
    if (!isset($_FILES['image'])) {
        echo json_encode(['error' => 'No image uploaded']);
        return;
    }

    $file      = $_FILES['image'];
    $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize   = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowed)) {
        echo json_encode(['error' => 'Only JPG, PNG, GIF, WEBP allowed']);
        return;
    }

    if ($file['size'] > $maxSize) {
        echo json_encode(['error' => 'Image must be under 5MB']);
        return;
    }

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('img_') . '.' . $ext;
    $dest     = '../../uploads/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => true, 'image_path' => $filename]);
    } else {
        echo json_encode(['error' => 'Failed to save image']);
    }
}
?>