<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;

class ArchiveDownloaderTest extends Base
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/modmenu-dl-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function source(int $bytes): string
    {
        $path = $this->dir . '/src.zip';
        file_put_contents($path, str_repeat('z', $bytes));
        return 'file://' . $path;
    }

    public function testRefusesAnythingButHttpsByDefault()
    {
        // The default protocol set is https only, for the first request and every redirect.
        $this->expectException(ModMenuException::class);
        (new ArchiveDownloader())->fetch($this->source(10), $this->dir . '/out.zip');
    }

    public function testDownloadsTheBytesWithinTheCap()
    {
        $dest = $this->dir . '/out.zip';
        (new ArchiveDownloader(1024, CURLPROTO_FILE))->fetch($this->source(1000), $dest);
        $this->assertSame(1000, filesize($dest));
    }

    public function testStopsAtTheCapAndLeavesNoFile()
    {
        $dest = $this->dir . '/out.zip';
        try {
            (new ArchiveDownloader(1024, CURLPROTO_FILE))->fetch($this->source(5000), $dest);
            $this->fail('an archive over the cap must be refused');
        } catch (ModMenuException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest);
    }
}
