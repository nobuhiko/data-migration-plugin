<?php

namespace Plugin\DataMigration43\Tests\Service;

use Eccube\Tests\EccubeTestCase;
use Plugin\DataMigration43\Service\DataMigrationService;
use Symfony\Component\Filesystem\Filesystem;

class ArchiveExtractionTest extends EccubeTestCase
{
    /** @var DataMigrationService */
    private $service;

    /** @var string */
    private $fixturesDir;

    /** @var string */
    private $tmpDir;

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

    public function tarGzProvider()
    {
        return [
            '2.11系' => ['2_11_5.tar.gz', ['bkup_data.csv', 'autoinc_data.csv']],
            '2.12系' => ['2_12_6.tar.gz', ['dtb_customer.csv', 'dtb_order.csv', 'dtb_member.csv']],
            '2.13系' => ['2_13_5.tar.gz', ['dtb_customer.csv', 'dtb_order.csv', 'dtb_member.csv']],
            '3.0.9' => ['3_0_9.tar.gz', ['dtb_product.csv', 'dtb_customer.csv']],
            '3.0.18' => ['3_0_18.tar.gz', ['dtb_product.csv', 'dtb_customer.csv']],
            '4.0系' => ['4_0_6.tar.gz', ['dtb_order_item.csv', 'dtb_customer.csv']],
            '4.1系' => ['4_1_2.tar.gz', ['dtb_order_item.csv', 'dtb_customer.csv']],
            'member_test' => ['member_test.tar.gz', ['dtb_member.csv', 'mtb_authority.csv']],
        ];
    }

    /**
     * @dataProvider tarGzProvider
     */
    public function testTarGz解凍(string $filename, array $expectedFiles)
    {
        $archivePath = $this->fixturesDir . $filename;
        $fileNames = $this->service->extractArchive($archivePath, $this->tmpDir);

        self::assertNotEmpty($fileNames, $filename . ' のファイル一覧が空');

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
            self::assertTrue($found, $filename . ' から ' . $expected . ' が解凍されていること');
        }
    }

    public function testZip解凍()
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

        self::assertContains('dtb_customer.csv', $fileNames);
        self::assertContains('dtb_product.csv', $fileNames);
        self::assertFileExists($outputDir . '/dtb_customer.csv');
        self::assertFileExists($outputDir . '/dtb_product.csv');
    }

    public function test不正なファイルで例外()
    {
        $badFile = $this->tmpDir . '/bad.tar.gz';
        file_put_contents($badFile, 'not an archive');

        $this->expectException(\RuntimeException::class);
        $this->service->extractArchive($badFile, $this->tmpDir);
    }
}
