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
        
        // PostgreSQLの場合、トランザクション状態を安全にクリア
        if ($this->entityManager) {
            $connection = $this->entityManager->getConnection();
            if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                // 安全にトランザクションをクリア
                try {
                    while ($connection->getTransactionNestingLevel() > 0 && $connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                } catch (\Exception $e) {
                    // PostgreSQLでエラーが発生した場合、接続をリセット
                    try {
                        $connection->close();
                        $connection->connect();
                    } catch (\Exception $e2) {
                        // 接続リセットに失敗した場合は無視
                    }
                }
            }
        }
    }

    public function tearDown(): void
    {
        // PostgreSQLの場合、トランザクション状態を安全にクリア
        if ($this->entityManager) {
            $connection = $this->entityManager->getConnection();
            if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                try {
                    // ネスティングレベルとアクティブ状態の両方をチェック
                    while ($connection->getTransactionNestingLevel() > 0 && $connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                } catch (\Exception $e) {
                    // PostgreSQLエラーの場合は接続をクリア
                    try {
                        $connection->close();
                    } catch (\Exception $e2) {
                        // 無視
                    }
                }
            }
        }
        
        parent::tearDown();
    }

    public function versionProvider()
    {
        return [
            ['2_11_5', 1, 0, 3],
            ['2_12_6', 1, 3, 2],
            ['2_13_5', 1, 3, 2],
            ['3_0_9', 1, 2, 6],
            ['3_0_18', 1, 2, 4],
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

        // 2.11系のmysqlにはcreate tableが使われているので、商品を除外してテストする
        if ($v == '2_11_5' && $this->entityManager->getConnection()->getDatabasePlatform()->getName() === 'mysql') {
            $post['config']['customer_order_only'] = 1;
        }

        try {
            $this->client->request(
                'POST',
                $this->generateUrl('data_migration43_admin_config'),
                $post,
                ['config' => ['import_file' => $file]]
            );
            
            $customers = $this->entityManager->getRepository(Customer::class)->findAll();
            self::assertEquals($c, count($customers));
    
            if ($p > 0) {
                $products = $this->entityManager->getRepository(Product::class)->findAll();
                self::assertEquals($p, count($products));
            }
    
            $orders = $this->entityManager->getRepository(Order::class)->findAll();
            self::assertEquals($o, count($orders));
    
            // ECCUBE_AUTH_MAGICの値を取得してアサート
            //$eccubeConfig = $container->get('Eccube\Common\EccubeConfig');
            //$authMagic = $eccubeConfig->get('eccube_auth_magic');
            //self::assertEquals('dummy', $authMagic);
        } catch (\Exception $e) {
            // エラーが発生した場合は、トランザクションを安全にクリア
            $connection = $this->entityManager->getConnection();
            
            // PostgreSQL特有の処理
            if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                try {
                    // ネスティングレベルとアクティブ状態の両方をチェック
                    while ($connection->getTransactionNestingLevel() > 0 && $connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                } catch (\Exception $rollbackException) {
                    // PostgreSQLエラーの場合は接続をリセット
                    try {
                        $connection->close();
                        $connection->connect();
                    } catch (\Exception $reconnectException) {
                        // 再接続に失敗した場合は無視
                    }
                }
            } else {
                // 他のDBの場合の通常処理
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (\Exception $rollbackException) {
                        // ROLLBACKエラーは無視
                    }
                }
            }
            
            throw $e;
        }
    }
}
