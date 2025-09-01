<?php


namespace Plugin\DataMigration43\Tests\Web\Admin;


use Eccube\Common\Constant;
use Eccube\Entity\Customer;
use Eccube\Entity\Order;
use Eccube\Entity\Product;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ConfigControllerTest extends AbstractAdminWebTestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function versionProvider()
    {
        return [
            ['2_11_5', 1, 0, 3],
            ['2_12_6', 1, 3, 2],
            ['2_13_5', 1, 3, 2],
            ['3_0_9', 1, 2, 6],   // PostgreSQL対応により3.x系も復活
            ['3_0_18', 1, 2, 4],  // PostgreSQL対応により3.x系も復活
            ['4_0_6', 1, 12, 20],
            ['4_1_2', 1, 12, 20],
        ];
    }

    /**
     * @dataProvider versionProvider
     */
    public function testバックアップファイルをアップロードできるかテスト($v, $c, $p, $o)
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');

        $file = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/' . $v . '.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/test.tar.gz';

        $fs = new Filesystem();
        $fs->copy($file, $testFile);

        $file = new UploadedFile($testFile, 'test.tar.gz', 'application/x-tar', null, true);

        $post =
            [
                'config' => [
                    Constant::TOKEN_NAME => 'dummy',
                    'import_file' => $file,
                    'auth_magic' => 'dummy',
                ]
            ];

        // 2.11系には商品関連でcreate tableが使われているので、商品を除外してテストする
        if ($v == '2_11_5') {
            $post['config']['customer_order_only'] = 1;
        }

        // PostgreSQL環境でのトランザクション管理を事前に行う
        $connection = $this->entityManager->getConnection();
        $isPostgreSQL = $connection->getDatabasePlatform()->getName() === 'postgresql';
        
        try {
            $this->client->request(
                'POST',
                $this->generateUrl('data_migration43_admin_config'),
                $post,
                ['config' => ['import_file' => $file]]
            );
            
            // PostgreSQL環境の場合、結果を確認する前にトランザクション状態を適切に管理
            if ($isPostgreSQL) {
                try {
                    // EntityManagerをクリアして新鮮な状態にする
                    $this->entityManager->clear();
                    
                    // テスト検証のため、現在のトランザクション状態をクリアし、
                    // データが永続化された状態で検証する
                    if ($connection->isTransactionActive()) {
                        // 移行処理のトランザクションは既にコミットされているはずだが、
                        // テスト用のトランザクションが残っている場合はクリア
                        $connection->rollBack();
                        error_log("PostgreSQL test: Rolled back test transaction before verification");
                    }
                    
                    // 検証は別のトランザクションで実行（オートコミットモードでクエリ）
                    
                } catch (\Exception $txError) {
                    error_log("PostgreSQL test: Pre-verification transaction handling failed: " . $txError->getMessage());
                    try {
                        $this->entityManager->clear();
                        $connection->close();
                        $connection->connect();
                    } catch (\Exception $reconnectError) {
                        error_log("PostgreSQL test: Connection reset failed: " . $reconnectError->getMessage());
                    }
                }
            }
            
            $customers = $this->entityManager->getRepository(Customer::class)->findAll();
            self::assertEquals($c, count($customers));
    
            if ($p > 0) {
                $products = $this->entityManager->getRepository(Product::class)->findAll();
                self::assertEquals($p, count($products));
            }
    
            $orders = $this->entityManager->getRepository(Order::class)->findAll();
            self::assertEquals($o, count($orders));
            
            // PostgreSQL環境でテスト終了時にフレームワーク用トランザクションを開始
            if ($isPostgreSQL) {
                try {
                    if (!$connection->isTransactionActive()) {
                        $connection->beginTransaction();
                        error_log("PostgreSQL test: Started framework transaction after verification");
                    }
                } catch (\Exception $txError) {
                    error_log("PostgreSQL test: Framework transaction start failed: " . $txError->getMessage());
                }
            }
    
            // ECCUBE_AUTH_MAGICの値を取得してアサート
            //$eccubeConfig = $container->get('Eccube\Common\EccubeConfig');
            //$authMagic = $eccubeConfig->get('eccube_auth_magic');
            //self::assertEquals('dummy', $authMagic);
        } catch (\Exception $e) {
            // PostgreSQL環境でのエラーハンドリング
            if ($isPostgreSQL) {
                try {
                    $this->entityManager->clear();
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                        error_log("PostgreSQL test: Rolled back transaction after exception");
                    }
                    $connection->beginTransaction();
                    error_log("PostgreSQL test: Reset transaction state after exception: " . $e->getMessage());
                } catch (\Exception $txError) {
                    error_log("PostgreSQL test: Transaction reset failed: " . $txError->getMessage());
                }
            } else {
                // MySQL用の既存のロジック
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                    $connection->beginTransaction();
                }
            }
            throw $e;
        }
    }
}
