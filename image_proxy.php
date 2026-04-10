<?php
require_once '../config.php';

header('Content-Type: application/json');

$url = $_GET['url'] ?? null;
if (!$url) {
    echo json_encode(['error' => 'No URL provided']);
    exit;
}

$imageData = file_get_contents($url);
if (!$imageData) {
    echo json_encode(['success' => false]);
    exit;
}

$ext      = 'jpg';
$filename = uniqid('img_') . '.' . $ext;
$dest     = '/Applications/XAMPP/htdocs/recipe-manager/uploads/' . $filename;

file_put_contents($dest, $imageData);
echo json_encode(['success' => true, 'image_path' => $filename]);
?>