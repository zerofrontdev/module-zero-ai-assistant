<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Images uploaded into a conversation: kept in var/zero_ai_assistant (not
 * public; the chat shows them through the admin), sent to Claude scaled down,
 * copied into pub/media when a tool puts them on a product.
 */
class Uploads
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Pictures larger than this are refused before they are decoded (memory). */
    private const MAX_PIXELS = 40_000_000;
    /** The long side a photo is kept at: enough for a product page, small enough to handle. */
    private const STORE_SIDE = 2400;
    private const DIR = 'zero_ai_assistant';
    /** Claude's own limit for an image's long side; larger ones cost tokens for nothing. */
    private const API_SIDE = 1568;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Conversations $conversations
    ) {
    }

    /**
     * Keeps an uploaded file (PHP's tmp_name) for the conversation.
     *
     * @throws LocalizedException
     */
    public function store(int $conversationId, string $tmpPath, string $name): int
    {
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            throw new LocalizedException(__('The image didn\'t arrive.'));
        }
        if (filesize($tmpPath) > self::MAX_BYTES) {
            throw new LocalizedException(__('%1 is larger than 10 MB.', $name));
        }
        set_error_handler(static fn () => true);
        try {
            $info = getimagesize($tmpPath);
        } finally {
            restore_error_handler();
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!isset(self::TYPES[$mime])) {
            throw new LocalizedException(__('%1 is not a JPEG, PNG, WebP or GIF image.', $name));
        }
        // Read from the header, before anything is decoded: a small file can claim a huge picture.
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            throw new LocalizedException(__('%1 is larger than 40 megapixels.', $name));
        }
        [$data, $mime] = $this->clean($tmpPath, $mime, $name);
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $file = self::DIR . '/' . $conversationId . '/' . bin2hex(random_bytes(12)) . '.' . self::TYPES[$mime];
        $var->writeFile($file, $data);

        return $this->conversations->addUpload($conversationId, $file, $name, $mime);
    }

    /**
     * The picture drawn again from its pixels, upright and at most STORE_SIDE on its long side: nothing of the
     * file but the picture is kept (no EXIF, no GPS position from a phone, no data hidden after the image).
     * A GIF becomes a PNG (its first frame).
     *
     * @return array{0: string, 1: string} the new file's bytes and type
     * @throws LocalizedException
     */
    private function clean(string $path, string $mime, string $name): array
    {
        set_error_handler(static fn () => true);
        try {
            $image = imagecreatefromstring((string) file_get_contents($path));
            $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (int) ((exif_read_data($path) ?: [])['Orientation'] ?? 1) : 1;
        } finally {
            restore_error_handler();
        }
        if (!$image) {
            throw new LocalizedException(__('%1 could not be read as an image.', $name));
        }
        // The phone's "turn it this way" flag is lost with the EXIF: turn the pixels instead.
        $image = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        } ?: $image;
        [$width, $height] = [imagesx($image), imagesy($image)];
        if (max($width, $height) > self::STORE_SIDE) {
            $scale = self::STORE_SIDE / max($width, $height);
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale)) ?: $image;
        }
        ob_start();
        if ($mime === 'image/jpeg') {
            imagejpeg($image, null, 88);
        } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
            imagesavealpha($image, true);
            imagewebp($image, null, 88);
        } else {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            $mime = 'image/png';
        }

        return [(string) ob_get_clean(), $mime];
    }

    public function absolutePath(array $upload): string
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath((string) $upload['file']);
    }

    /**
     * The image as a Messages API content block, its long side at most 1568 px.
     */
    public function contentBlock(array $upload): array
    {
        $path = $this->absolutePath($upload);
        $mime = (string) $upload['mime'];
        $data = (string) file_get_contents($path);
        [$width, $height] = (array) getimagesize($path) + [0, 0];
        if (max($width, $height) > self::API_SIDE && function_exists('imagecreatefromstring') && $mime !== 'image/gif') {
            $image = imagecreatefromstring($data);
            if ($image) {
                $scale = self::API_SIDE / max($width, $height);
                $small = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
                ob_start();
                imagejpeg($small ?: $image, null, 85);
                $data = (string) ob_get_clean();
                $mime = 'image/jpeg';
            }
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)]];
    }

    /**
     * A copy under pub/media/tmp, where Magento's media gallery takes files from.
     */
    public function copyToMedia(array $upload): string
    {
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $target = 'tmp/zero_ai_assistant/' . basename((string) $upload['file']);
        $media->create(dirname($target));
        $media->getDriver()->copy($this->absolutePath($upload), $media->getAbsolutePath($target));

        return $media->getAbsolutePath($target);
    }

    /**
     * A copy under pub/media/<dir>, named after the photo (a slug of its name
     * and a short hash of the file, so the same photo lands under the same
     * name): a category image, a page's or block's HTML. The path relative to
     * pub/media.
     */
    public function publish(array $upload, string $dir): string
    {
        $source = $this->absolutePath($upload);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo((string) $upload['name'], PATHINFO_FILENAME))), '-');
        $target = trim($dir, '/') . '/' . ($slug !== '' ? $slug : 'photo') . '-' . substr(hash_file('sha256', $source), 0, 8)
            . '.' . pathinfo((string) $upload['file'], PATHINFO_EXTENSION);
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $media->create(dirname($target));
        $media->getDriver()->copy($source, $media->getAbsolutePath($target));

        return $target;
    }
}
