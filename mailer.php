<?php
require_once '../vendor/autoload.php';
require_once '../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$data    = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'] ?? null;
$items   = $data['items'] ?? [];
$week    = $data['week'] ?? '';

if (!$user_id || !$items) {
    echo json_encode(['error' => 'Missing required data']);
    exit;
}

// Get user email from DB
$db   = getDB();
$stmt = $db->prepare('SELECT email, username FROM users WHERE id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$user) {
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Build email body
$itemRows = '';
foreach ($items as $item) {
    $itemRows .= "
        <tr>
            <td style='padding:8px 12px;border-bottom:1px solid #f0f0f0'>{$item['name']}</td>
            <td style='padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#27ae60;font-weight:bold'>{$item['quantity']} {$item['unit']}</td>
            <td style='padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#aaa;font-size:0.85em'>{$item['recipes']}</td>
        </tr>";
}

$emailBody = "
<!DOCTYPE html>
<html>
<body style='font-family:Arial,sans-serif;background:#f4f4f4;padding:20px'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1)'>
        <div style='background:linear-gradient(135deg,#2c3e50,#27ae60);padding:2rem;text-align:center'>
            <h1 style='color:white;margin:0'>🛒 Your Grocery List</h1>
            <p style='color:rgba(255,255,255,0.85);margin:0.5rem 0 0'>Week of {$week}</p>
        </div>
        <div style='padding:2rem'>
            <p style='color:#555'>Hi {$user['username']}, here is your grocery list for the week!</p>
            <table style='width:100%;border-collapse:collapse;margin-top:1rem'>
                <thead>
                    <tr style='background:#f8f9fa'>
                        <th style='padding:10px 12px;text-align:left;color:#2c3e50'>Ingredient</th>
                        <th style='padding:10px 12px;text-align:left;color:#2c3e50'>Amount</th>
                        <th style='padding:10px 12px;text-align:left;color:#2c3e50'>Used in</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemRows}
                </tbody>
            </table>
            <p style='color:#aaa;font-size:0.85em;margin-top:2rem;text-align:center'>
                Sent from your Recipe Manager
            </p>
        </div>
    </div>
</body>
</html>";

// Send email
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'mikkelmaalmand@gmail.com';
    $mail->Password   = 'cyhgcquvlsbogypf';       //google generated app password
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom('mikkelmaalmand@gmail.com', 'Recipe Manager');
    $mail->addAddress($user['email'], $user['username']);

    $mail->isHTML(true);
    $mail->Subject = "🛒 Your Grocery List — {$week}";
    $mail->Body    = $emailBody;

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Grocery list sent to ' . $user['email']]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Email failed: ' . $mail->ErrorInfo]);
}
?>