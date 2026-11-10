<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Uploads;

/**
 * A photo from a phone is kept as its pixels only: upright, without its
 * EXIF (GPS position) or anything hidden in the file.
 */
class UploadsTest extends TestCase
{
    public function testAPhotoLosesItsExifAndHiddenDataAndStaysUpright(): void
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // EXIF (big-endian TIFF): one tag, Orientation (0x0112) = 6, "turn 90° clockwise to show"; then a GPS note.
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00" . 'GPSSECRET';
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff;
        $path = tempnam(sys_get_temp_dir(), 'zero-upload');
        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2) . 'HIDDEN-AFTER-THE-IMAGE');

        $written = null;
        $dir = $this->createStub(WriteInterface::class);
        $dir->method('writeFile')->willReturnCallback(function ($file, $data) use (&$written) {
            $written = $data;
            return strlen($data);
        });
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($dir);
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('addUpload')->willReturn(1);

        (new Uploads($filesystem, $conversations))->store(5, $path, 'phone.jpg');
        unlink($path);

        $this->assertNotNull($written);
        $this->assertStringNotContainsString('GPSSECRET', $written);
        $this->assertStringNotContainsString('HIDDEN-AFTER-THE-IMAGE', $written);
        $this->assertStringNotContainsString('Exif', $written);
        $size = getimagesizefromstring($written);
        if (function_exists('exif_read_data')) {
            $this->assertSame([20, 40], [$size[0], $size[1]], 'turned upright as the phone said');
        }
    }
}
