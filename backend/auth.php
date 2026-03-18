<?php
require_once '../config.php';
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if ($action === 'register') {
        register($data);
    } elseif ($action === 'login') {
        login($data);
    } elseif ($action === 'logout') {
        logout();
    } else {
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

function register($data) {
    $db = getDB();

    $username = trim($data['username'] ?? '');
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$username || !$email || !$password) {
        echo json_encode(['error' => 'All fields are required']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Invalid email address']);
        return;
    }

    if (strlen($password) < 6) {
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $email, $hash);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Account created successfully']);
    } else {
        echo json_encode(['error' => 'Username or email already exists']);
    }

    $stmt->close();
    $db->close();
}

function login($data) {
    $db = getDB();

    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['error' => 'Email and password are required']);
        return;
    }

    $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        echo json_encode([
            'success'  => true,
            'user_id'  => $user['id'],
            'username' => $user['username']
        ]);
    } else {
        echo json_encode(['error' => 'Invalid email or password']);
    }

    $stmt->close();
    $db->close();
}

function logout() {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out']);
}
?>