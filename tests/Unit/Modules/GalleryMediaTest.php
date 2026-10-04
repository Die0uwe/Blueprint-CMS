<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Modules\Gallery\GalleryPoster;
use CommunityFusion\Modules\Gallery\GalleryThumbnailer;
use CommunityFusion\Modules\Media\MediaController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Galerij-fixes: thumbnailer zonder GD, video-Range-serving en videoposter-validatie. */
final class GalleryMediaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cf_gal_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/gallery', 0755, true);
    }

    private function png(): string
    {
        $p = $this->dir . '/src.png';
        imagepng(imagecreatetruecolor(64, 32), $p);
        return $p;
    }

    private function mediaRequest(string $path, ?string $range): Request
    {
        // Request::param() leest uit de router-parameters; zonder router: via reflectie in `query`-vrije route.
        $r = new Request('GET', '/media/' . $path, [], [], $range !== null ? ['Range' => $range] : [], [], [], []);
        $r->setParams(['path' => $path]);
        return $r;
    }

    #[Test]
    public function thumbnailerWithoutGdKeepsTheUploadAndReturnsDimensions(): void
    {
        $dest = $this->dir . '/thumbs/t.jpg';
        $dims = (new GalleryThumbnailer(480, false))->generate($this->png(), $dest);
        $this->assertSame(['width' => 64, 'height' => 32], $dims);
        $this->assertFileDoesNotExist($dest);
    }

    #[Test]
    public function thumbnailerWithGdMakesAJpeg(): void
    {
        $dest = $this->dir . '/thumbs/t.jpg';
        $dims = (new GalleryThumbnailer(16))->generate($this->png(), $dest);
        $this->assertSame(['width' => 64, 'height' => 32], $dims);
        $this->assertFileExists($dest);
        $this->assertSame([16, 8], array_slice(getimagesize($dest), 0, 2));
    }

    #[Test]
    public function thumbnailerIgnoresNonImages(): void
    {
        file_put_contents($this->dir . '/x.mp4', 'not an image');
        $this->assertNull((new GalleryThumbnailer())->generate($this->dir . '/x.mp4', $this->dir . '/t.jpg'));
    }

    #[Test]
    public function parseRangeCoversTheRfcForms(): void
    {
        $this->assertSame([0, 99], MediaController::parseRange('bytes=0-99', 1000));
        $this->assertSame([500, 999], MediaController::parseRange('bytes=500-', 1000));
        $this->assertSame([900, 999], MediaController::parseRange('bytes=-100', 1000));
        $this->assertSame([0, 999], MediaController::parseRange('bytes=0-99999', 1000));
        $this->assertFalse(MediaController::parseRange('bytes=2000-', 1000));
        $this->assertFalse(MediaController::parseRange('bytes=50-10', 1000));
        $this->assertNull(MediaController::parseRange('', 1000));
        $this->assertNull(MediaController::parseRange('garbage', 1000));
        $this->assertNull(MediaController::parseRange('bytes=0-1,5-9', 1000));
    }

    #[Test]
    public function videoIsServedInPartsWithRangeHeaders(): void
    {
        $data = random_bytes(5000);
        file_put_contents($this->dir . '/gallery/v.mp4', $data);
        $ctl = new MediaController(new UploadManager($this->dir, 1024, true));

        $r = $ctl->show($this->mediaRequest('gallery/v.mp4', 'bytes=100-199'));
        $this->assertSame(206, $r->getStatus());
        $this->assertSame(substr($data, 100, 100), $r->getBody());
        $h = (new \ReflectionProperty($r, 'headers'))->getValue($r);
        $this->assertSame('bytes 100-199/5000', $h['Content-Range']);
        $this->assertSame('bytes', $h['Accept-Ranges']);
        $this->assertSame('video/mp4', $h['Content-Type']);

        $full = $ctl->show($this->mediaRequest('gallery/v.mp4', null));
        $this->assertSame(200, $full->getStatus());
        $this->assertSame($data, $full->getBody());

        $bad = $ctl->show($this->mediaRequest('gallery/v.mp4', 'bytes=9000-'));
        $this->assertSame(416, $bad->getStatus());
        $this->assertSame(404, $ctl->show($this->mediaRequest('gallery/nope.mp4', null))->getStatus());
        $this->assertSame(404, $ctl->show($this->mediaRequest('../etc/passwd', null))->getStatus());
    }

    #[Test]
    public function posterAcceptsRealImagesOnly(): void
    {
        $jpg = $this->dir . '/p.jpg';
        imagejpeg(imagecreatetruecolor(40, 30), $jpg);
        $url = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpg));

        $name = GalleryPoster::save($url, $this->dir . '/thumbs', 'abc');
        $this->assertSame('abc.jpg', $name);
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($this->dir . '/thumbs/abc.jpg')[2]);

        foreach ([
            '', 'data:text/html;base64,' . base64_encode('<script>alert(1)</script>'),
            'data:image/jpeg;base64,' . base64_encode('<?php echo 1;'),
            'data:image/svg+xml;base64,' . base64_encode('<svg/>'),
            'data:image/jpeg;base64,@@@', 'javascript:alert(1)',
            'data:image/jpeg;base64,' . str_repeat('A', 2_000_001),
        ] as $bad) {
            $this->assertNull(GalleryPoster::decode($bad), substr($bad, 0, 40));
        }
    }
}
