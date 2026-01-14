<?php
require_once __DIR__ . '/security.php';
ensure_session_started();

// Настройки
$width = 160;
$height = 48;
$length = 5;
$fontSize = 22;
mb_internal_encoding('UTF-8');
// Русские буквы (без похожих) и цифры
$chars = ['А','Б','В','Г','Д','Е','Ж','З','И','К','Л','М','Н','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ы','Э','Ю','Я','2','3','4','5','6','7','8','9'];

// Генерируем текст
$text = '';
for ($i = 0; $i < $length; $i++) {
    $text .= $chars[random_int(0, count($chars) - 1)];
}
$_SESSION['captcha_text'] = $text;

$image = imagecreatetruecolor($width, $height);
$bg = imagecolorallocate($image, 15, 23, 42);
$textColor = imagecolorallocate($image, 226, 232, 240);
$noiseColor = imagecolorallocate($image, 102, 126, 234);

// Фон
imagefill($image, 0, 0, $bg);

// Шум
for ($i = 0; $i < 50; $i++) {
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $noiseColor);
}
for ($i = 0; $i < 200; $i++) {
    imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noiseColor);
}

// Текст
$fontFile = __DIR__ . '/assets/fonts/DejaVuSans-Bold.ttf';
if (!file_exists($fontFile)) {
    $sysFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontFile = file_exists($sysFont) ? $sysFont : null;
}

if ($fontFile) {
    $charCount = mb_strlen($text);
    $x = 14;
    $y = ($height / 2) + ($fontSize / 2) - 2;
    for ($i = 0; $i < $charCount; $i++) {
        $char = mb_substr($text, $i, 1);
        $angle = random_int(-12, 12);
        imagettftext($image, $fontSize, $angle, (int)$x, (int)$y, $textColor, $fontFile, $char);
        $x += $fontSize + 4;
    }
} else {
    $x = 10;
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        imagestring($image, 5, $x, ($height / 2) - 7, $ch, $textColor);
        $x += 20;
    }
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
imagepng($image);
imagedestroy($image);
