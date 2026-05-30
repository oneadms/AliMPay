<?php
ob_start();
date_default_timezone_set('Asia/Shanghai');

$config = require __DIR__ . '/config/alipay.php';

if (ob_get_length() > 0) {
    ob_clean();
}

$type = $_GET['type'] ?? 'business';
$token = $_GET['token'] ?? '';

$expectedToken = md5('qrcode_access_' . date('Y-m-d'));
if ($token !== $expectedToken) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid token';
    exit;
}

header('Cache-Control: public, max-age=3600');

try {
    switch ($type) {
        case 'business':
            $qrCodePath = $config['payment']['business_qr_mode']['qr_code_path'];

            if (!file_exists($qrCodePath)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo '经营码二维码文件不存在，请先上传到: ' . $qrCodePath;
                exit;
            }

            $imageData = file_get_contents($qrCodePath);
            $imageInfo = getimagesizefromstring($imageData);
            header('Content-Type: ' . ($imageInfo['mime'] ?? 'image/png'));
            echo $imageData;
            break;

        default:
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid QR code type';
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error loading QR code: ' . $e->getMessage();
}
