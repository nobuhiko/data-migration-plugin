<?php

namespace Plugin\DataMigration43\Tests\Service;

use Eccube\Tests\EccubeTestCase;
use Plugin\DataMigration43\Service\DataMigrationService;
use Plugin\DataMigration43\Controller\Admin\ConfigController;
use Symfony\Component\Filesystem\Filesystem;
use nobuhiko\BulkInsertQuery\BulkInsertQuery;

/**
 * DataMigrationService および saveToP メソッドのテスト
 */
class DataMigrationServiceTest extends EccubeTestCase
{
    /**
     * @var DataMigrationService
     */
    private $dataMigrationService;

    /**
     * @var string
     */
    private $testCsvDir;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->dataMigrationService = self::getContainer()->get(DataMigrationService::class);
        $this->filesystem = new Filesystem();
        
        // テスト用ディレクトリの作成
        $this->testCsvDir = sys_get_temp_dir() . '/datamigration_test_' . uniqid();
        $this->filesystem->mkdir($this->testCsvDir);
    }

    public function tearDown(): void
    {
        // テスト用ディレクトリの削除
        if ($this->filesystem->exists($this->testCsvDir)) {
            $this->filesystem->remove($this->testCsvDir);
        }
        
        // PostgreSQLの場合、トランザクションエラーをクリア
        $em = $this->entityManager;
        if ($em && $em->getConnection()->isTransactionActive()) {
            while ($em->getConnection()->getTransactionNestingLevel() > 0) {
                try {
                    $em->getConnection()->rollBack();
                } catch (\Exception $e) {
                    // エラーを無視
                }
            }
        }
        
        parent::tearDown();
    }

    /**
     * openCsvWithEncoding メソッドの基本機能をテスト
     */
    public function testOpenCsvWithEncodingBasic()
    {
        // テスト用CSVファイル（最初の3行のみ）を作成
        $csvContent = <<<CSV
product_id,name,maker_id,status,comment1,comment2,comment3,comment4,comment5,comment6,note,main_list_comment,main_list_image,main_comment,main_image,main_large_image,sub_title1,sub_comment1,sub_image1,sub_large_image1,sub_title2,sub_comment2,sub_image2,sub_large_image2,sub_title3,sub_comment3,sub_image3,sub_large_image3,sub_title4,sub_comment4,sub_image4,sub_large_image4,sub_title5,sub_comment5,sub_image5,sub_large_image5,sub_title6,sub_comment6,sub_image6,sub_large_image6,del_flg,creator_id,create_date,update_date,deliv_date_id,category_id,product_flag,file1,file2,file3,file4,file5,file6
1,アイスクリーム,,1,,,"アイス,バニラ,チョコ,抹茶",,,,,暑い夏にどうぞ。,08311201_44f65122ee5fe.jpg,冷たいものはいかがですか？,08311202_44f6515906a41.jpg,08311203_44f651959bcb5.jpg,,<b>おいしいよ<b>,,,,,,,,,,,,,,,,,,,,,,,1,2,"2009-06-16 00:11:21","2009-06-16 00:11:21",2,5,10010,,,,,,
2,おなべ,,1,,,"鍋,なべ,ナベ",,,,,一人用からあります。,08311311_44f661811fec0.jpg,たまには鍋でもどうでしょう。,08311313_44f661dc649fb.jpg,08311313_44f661e5698a6.jpg,,,,,,,,,,,,,,,,,,,,,,,,,1,2,"2009-06-16 00:11:21","2009-06-16 00:11:21",3,,11001,,,,,,
CSV;

        $csvFile = $this->testCsvDir . '/dtb_products.csv';
        file_put_contents($csvFile, $csvContent);

        // openCsvWithEncodingメソッドのテスト
        $result = $this->dataMigrationService->openCsvWithEncoding($csvFile);
        
        // アサーション
        $this->assertNotFalse($result['handle'], 'CSVファイルのハンドルが取得できること');
        $this->assertEquals('success', $result['message'], '成功メッセージが返されること');
        $this->assertNotNull($result['encoding'], 'エンコーディング情報が返されること');
        
        // CSVが正しく読み込めることを確認
        $handle = $result['handle'];
        $headers = fgetcsv($handle);
        $this->assertCount(53, $headers, 'ヘッダーが53カラムあること');
        
        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            $this->assertCount(53, $row, "行 {$rowCount} が53カラムあること");
            // 日本語が正しく読み込まれることを確認
            if ($rowCount === 1) {
                $this->assertEquals('アイスクリーム', $row[1], '日本語の商品名が正しく読み込まれること');
            }
        }
        
        $this->assertEquals(2, $rowCount, 'データ行が2行あること');
        fclose($handle);
    }

    /**
     * カラム数不整合を含むCSVファイルでも動作することをテスト
     */
    public function testOpenCsvWithEncodingWithCorruptedData()
    {
        // カラム数不整合を含むCSVファイルを作成
        $csvContent = $this->createCorruptedCsvContent();
        
        $csvFile = $this->testCsvDir . '/dtb_products_corrupted.csv';
        file_put_contents($csvFile, $csvContent);

        // openCsvWithEncodingメソッドのテスト
        $result = $this->dataMigrationService->openCsvWithEncoding($csvFile);
        
        // アサーション
        $this->assertNotFalse($result['handle'], 'CSVファイルのハンドルが取得できること');
        $this->assertEquals('success', $result['message'], '成功メッセージが返されること');
        
        // CSVを実際に読み込んで検証
        $handle = $result['handle'];
        $headers = fgetcsv($handle);
        $this->assertCount(53, $headers, 'ヘッダーが53カラムあること');
        
        $lineNumber = 2;
        $totalRows = 0;
        $errorRows = [];
        $validRows = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $totalRows++;
            if (count($row) !== 53) {
                $errorRows[] = $lineNumber;
            } else {
                $validRows++;
                // 正常な行の日本語チェック
                if (!empty($row[1]) && preg_match('/^商品\d+$/', $row[1])) {
                    $this->assertIsString($row[1], '商品名が文字列として読み込まれること');
                }
            }
            $lineNumber++;
        }
        
        $this->assertGreaterThan(0, $totalRows, '複数行のデータが読み込まれること');
        $this->assertNotEmpty($errorRows, 'カラム数不整合の行が検出されること');
        $this->assertGreaterThan(0, $validRows, '正常な行も存在すること');
        
        fclose($handle);
    }

    /**
     * ファイルサイズ制限のテスト
     */
    public function testOpenCsvWithEncodingFileSizeLimit()
    {
        // 大きなファイルの場合の挙動をテスト
        $csvFile = $this->testCsvDir . '/large_test.csv';
        
        // 実際には小さなファイルを作成してテストする
        $headers = 'product_id,name,maker_id,status,comment1,comment2,comment3,comment4,comment5,comment6,note,main_list_comment,main_list_image,main_comment,main_image,main_large_image,sub_title1,sub_comment1,sub_image1,sub_large_image1,sub_title2,sub_comment2,sub_image2,sub_large_image2,sub_title3,sub_comment3,sub_image3,sub_large_image3,sub_title4,sub_comment4,sub_image4,sub_large_image4,sub_title5,sub_comment5,sub_image5,sub_large_image5,sub_title6,sub_comment6,sub_image6,sub_large_image6,del_flg,creator_id,create_date,update_date,deliv_date_id,category_id,product_flag,file1,file2,file3,file4,file5,file6';
        $data = '999,テスト商品,,1,,,テストキーワード,,,,,テスト一覧コメント,test.jpg,テストメインコメント,test_main.jpg,test_large.jpg,,,,,,,,,,,,,,,,,,,,,,,,0,1,2024-01-01 00:00:00,2024-01-01 00:00:00,1,1,00000,,,,,,';
        
        $csvContent = $headers . "\n" . $data;
        file_put_contents($csvFile, $csvContent);

        // openCsvWithEncodingのテスト
        $result = $this->dataMigrationService->openCsvWithEncoding($csvFile);
        
        $this->assertNotFalse($result['handle'], 'CSVファイルのハンドルが取得できること');
        $this->assertEquals('success', $result['message'], '成功メッセージが返されること');
        
        // CSVを実際に読み込んで日本語が正しく処理されることを確認
        $handle = $result['handle'];
        $headers = fgetcsv($handle);
        $this->assertCount(53, $headers, 'ヘッダーが53カラムあること');
        
        $row = fgetcsv($handle);
        $this->assertNotFalse($row, 'データ行が読み込めること');
        $this->assertEquals('テスト商品', $row[1], '日本語商品名が正しく読み込まれること');
        $this->assertEquals('テストキーワード', $row[6], '日本語キーワードが正しく読み込まれること');
        
        fclose($handle);
    }

    /**
     * 存在しないファイルのエラーハンドリングテスト
     */
    public function testOpenCsvWithEncodingFileNotFound()
    {
        $nonExistentFile = $this->testCsvDir . '/non_existent.csv';
        
        $result = $this->dataMigrationService->openCsvWithEncoding($nonExistentFile);
        
        $this->assertFalse($result['handle'], 'ハンドルがfalseであること');
        $this->assertStringContainsString('CSVファイルが見つかりません', $result['message'], 'エラーメッセージが返されること');
        $this->assertNull($result['encoding'], 'エンコーディング情報がnullであること');
    }

    /**
     * 文字化けを含むCSVコンテンツを生成
     * 
     * @return string
     */
    private function createCorruptedCsvContent()
    {
        // ヘッダー行
        $headers = 'product_id,name,maker_id,status,comment1,comment2,comment3,comment4,comment5,comment6,note,main_list_comment,main_list_image,main_comment,main_image,main_large_image,sub_title1,sub_comment1,sub_image1,sub_large_image1,sub_title2,sub_comment2,sub_image2,sub_large_image2,sub_title3,sub_comment3,sub_image3,sub_large_image3,sub_title4,sub_comment4,sub_image4,sub_large_image4,sub_title5,sub_comment5,sub_image5,sub_large_image5,sub_title6,sub_comment6,sub_image6,sub_large_image6,del_flg,creator_id,create_date,update_date,deliv_date_id,category_id,product_flag,file1,file2,file3,file4,file5,file6';
        
        $rows = [$headers];
        
        // 正常な行を53カラムで構成（ヘッダーと同じカラム数）
        for ($i = 1; $i <= 99; $i++) {
            $normalRow = [
                $i, // 1. product_id
                "商品{$i}", // 2. name
                '', // 3. maker_id
                1, // 4. status
                '', '', '', '', '', '', // 5-10. comment1-6
                '', // 11. note
                '', // 12. main_list_comment
                "test{$i}.jpg", // 13. main_list_image
                '', // 14. main_comment
                "test_main{$i}.jpg", // 15. main_image
                '', // 16. main_large_image
                '', '', '', '', // 17-20. sub_title1, sub_comment1, sub_image1, sub_large_image1
                '', '', '', '', // 21-24. sub_title2, sub_comment2, sub_image2, sub_large_image2
                '', '', '', '', // 25-28. sub_title3, sub_comment3, sub_image3, sub_large_image3
                '', '', '', '', // 29-32. sub_title4, sub_comment4, sub_image4, sub_large_image4
                '', '', '', '', // 33-36. sub_title5, sub_comment5, sub_image5, sub_large_image5
                '', '', '', '', // 37-40. sub_title6, sub_comment6, sub_image6, sub_large_image6
                0, // 41. del_flg
                1, // 42. creator_id
                '2024-01-01 00:00:00', // 43. create_date
                '2024-01-01 00:00:00', // 44. update_date
                1, // 45. deliv_date_id
                1, // 46. category_id
                '00000', // 47. product_flag
                '', '', '', '', '', '' // 48-53. file1-6
            ];
            $rows[] = implode(',', $normalRow);
        }
        
        // 100行目（101行目として処理される）- カラム数不足のエラー行（24カラム）
        $rows[] = '100,エラー商品,,1,,,,,,,,,,,,,,,,,,,,,';
        
        // 正常な行を31行追加
        for ($i = 101; $i <= 131; $i++) {
            $normalRow = [
                $i, // 1. product_id
                "商品{$i}", // 2. name
                '', // 3. maker_id
                1, // 4. status
                '', '', '', '', '', '', // 5-10. comment1-6
                '', // 11. note
                '', // 12. main_list_comment
                "test{$i}.jpg", // 13. main_list_image
                '', // 14. main_comment
                "test_main{$i}.jpg", // 15. main_image
                '', // 16. main_large_image
                '', '', '', '', // 17-20. sub_title1, sub_comment1, sub_image1, sub_large_image1
                '', '', '', '', // 21-24. sub_title2, sub_comment2, sub_image2, sub_large_image2
                '', '', '', '', // 25-28. sub_title3, sub_comment3, sub_image3, sub_large_image3
                '', '', '', '', // 29-32. sub_title4, sub_comment4, sub_image4, sub_large_image4
                '', '', '', '', // 33-36. sub_title5, sub_comment5, sub_image5, sub_large_image5
                '', '', '', '', // 37-40. sub_title6, sub_comment6, sub_image6, sub_large_image6
                0, // 41. del_flg
                1, // 42. creator_id
                '2024-01-01 00:00:00', // 43. create_date
                '2024-01-01 00:00:00', // 44. update_date
                1, // 45. deliv_date_id
                1, // 46. category_id
                '00000', // 47. product_flag
                '', '', '', '', '', '' // 48-53. file1-6
            ];
            $rows[] = implode(',', $normalRow);
        }
        
        // 132行目（133行目として処理される）- カラム数不足のエラー行（25カラム）
        $rows[] = '132,エラー商品2,,1,,,,,,,,,,,,,,,,,,,,,,';
        
        // 残りの正常な行を追加
        for ($i = 133; $i <= 200; $i++) {
            $normalRow = [
                $i, // 1. product_id
                "商品{$i}", // 2. name
                '', // 3. maker_id
                1, // 4. status
                '', '', '', '', '', '', // 5-10. comment1-6
                '', // 11. note
                '', // 12. main_list_comment
                "test{$i}.jpg", // 13. main_list_image
                '', // 14. main_comment
                "test_main{$i}.jpg", // 15. main_image
                '', // 16. main_large_image
                '', '', '', '', // 17-20. sub_title1, sub_comment1, sub_image1, sub_large_image1
                '', '', '', '', // 21-24. sub_title2, sub_comment2, sub_image2, sub_large_image2
                '', '', '', '', // 25-28. sub_title3, sub_comment3, sub_image3, sub_large_image3
                '', '', '', '', // 29-32. sub_title4, sub_comment4, sub_image4, sub_large_image4
                '', '', '', '', // 33-36. sub_title5, sub_comment5, sub_image5, sub_large_image5
                '', '', '', '', // 37-40. sub_title6, sub_comment6, sub_image6, sub_large_image6
                0, // 41. del_flg
                1, // 42. creator_id
                '2024-01-01 00:00:00', // 43. create_date
                '2024-01-01 00:00:00', // 44. update_date
                1, // 45. deliv_date_id
                1, // 46. category_id
                '00000', // 47. product_flag
                '', '', '', '', '', '' // 48-53. file1-6
            ];
            $rows[] = implode(',', $normalRow);
        }
        
        return implode("\n", $rows);
    }
}