<?php
/**
 * ImageCompressor — Resize and compress uploaded images on save.
 *
 * Call compressImage() after move_uploaded_file() to reduce storage.
 * Replaces the file in-place. Originals are NOT preserved.
 *
 * Target: max 1200px wide, JPEG quality 70 → ~80-150KB from 2-3MB camera shots.
 * If GD is not available, silently returns (no error, no compression).
 */

function compressImage(string $filePath, int $maxWidth = 1200, int $quality = 70): bool
{
    if (!file_exists($filePath)) return false;
    if (!function_exists('imagecreatefromjpeg')) return false; // GD not available

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) return false;

    // Skip if already small (under 200KB)
    $size = filesize($filePath);
    if ($size < 200 * 1024) return true;

    // Load image
    $src = null;
    switch ($ext) {
        case 'jpg': case 'jpeg':
            $src = @imagecreatefromjpeg($filePath); break;
        case 'png':
            $src = @imagecreatefrompng($filePath); break;
        case 'webp':
            if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($filePath); break;
    }
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);

    // Only resize if wider than max
    if ($w > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int)($h * ($maxWidth / $w));
        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG
        if ($ext === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    // Auto-rotate based on EXIF (camera orientation)
    if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'])) {
        $exif = @exif_read_data($filePath);
        $orient = $exif['Orientation'] ?? 1;
        switch ($orient) {
            case 3: $src = imagerotate($src, 180, 0); break;
            case 6: $src = imagerotate($src, -90, 0); break;
            case 8: $src = imagerotate($src, 90, 0); break;
        }
    }

    // Save as JPEG (always — even PNG gets converted for storage savings)
    $result = imagejpeg($src, $filePath, $quality);
    imagedestroy($src);

    return $result;
}
