<?php

declare(strict_types=1);

namespace Plugin\DataMigration44\Tests\Service;

use Eccube\Tests\EccubeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\DataMigration44\Service\DataMigrationService;
use Symfony\Component\Filesystem\Filesystem;

final class ArchiveExtractionTest extends EccubeTestCase
{
    private ?DataMigrationService $service = null;

    private ?string $fixturesDir = null;

    private ?string $tmpDir = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = self::getContainer()->get(DataMigrationService::class);
        $this->fixturesDir = __DIR__ . '/../Fixtures/';
        $this->tmpDir = sys_get_temp_dir() . '/datamigration_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    public function tearDown(): void
    {
        $fs = new Filesystem();
        if (is_dir($this->tmpDir)) {
            $fs->remove($this->tmpDir);
        }
        parent::tearDown();
    }

    /**
     * @return \Iterator<string, array{string, list<string>}>
     */
    public static function tarGzProvider(): \Iterator
    {
        yield '2.11系' => ['2_11_5.tar.gz', ['bkup_data.csv', 'autoinc_data.csv']];
        yield '2.12系' => ['2_12_6.tar.gz', ['dtb_customer.csv', 'dtb_order.csv', 'dtb_member.csv']];
        yield '2.13系' => ['2_13_5.tar.gz', ['dtb_customer.csv', 'dtb_order.csv', 'dtb_member.csv']];
        yield '3.0.9' => ['3_0_9.tar.gz', ['dtb_product.csv', 'dtb_customer.csv']];
        yield '3.0.18' => ['3_0_18.tar.gz', ['dtb_product.csv', 'dtb_customer.csv']];
        yield '4.0系' => ['4_0_6.tar.gz', ['dtb_order_item.csv', 'dtb_customer.csv']];
        yield '4.1系' => ['4_1_2.tar.gz', ['dtb_order_item.csv', 'dtb_customer.csv']];
        yield 'member_test' => ['member_test.tar.gz', ['dtb_member.csv', 'mtb_authority.csv']];
    }

    #[DataProvider(methodName: 'tarGzProvider')]
    public function testTarGz解凍(string $filename, array $expectedFiles): void
    {
        $archivePath = $this->fixturesDir . $filename;
        $fileNames = $this->service->extractArchive($archivePath, $this->tmpDir);

        $this->assertNotEmpty($fileNames, $filename . ' のファイル一覧が空');

        // 期待するファイルが解凍されているか確認
        foreach ($expectedFiles as $expected) {
            $found = false;
            // サブディレクトリ内も検索
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getFilename() === $expected) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, $filename . ' から ' . $expected . ' が解凍されていること');
        }
    }

    public function testZip解凍(): void
    {
        // テスト用ZIPを作成
        $zipPath = $this->tmpDir . '/test.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('dtb_customer.csv', "id,name\n1,test\n");
        $zip->addFromString('dtb_product.csv', "id,name\n1,product\n");
        $zip->close();

        $outputDir = $this->tmpDir . '/output';
        mkdir($outputDir, 0777, true);

        $fileNames = $this->service->extractArchive($zipPath, $outputDir);

        $this->assertContains('dtb_customer.csv', $fileNames);
        $this->assertContains('dtb_product.csv', $fileNames);
        $this->assertFileExists($outputDir . '/dtb_customer.csv');
        $this->assertFileExists($outputDir . '/dtb_product.csv');
    }

    public function test不正なファイルで例外(): void
    {
        $badFile = $this->tmpDir . '/bad.tar.gz';
        file_put_contents($badFile, 'not an archive');

        $this->expectException(\RuntimeException::class);
        $this->service->extractArchive($badFile, $this->tmpDir);
    }
}
