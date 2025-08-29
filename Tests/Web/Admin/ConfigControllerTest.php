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
        
        // PostgreSQLの場合、既存のトランザクションをクリアして新しく開始
        if ($this->entityManager) {
            $connection = $this->entityManager->getConnection();
            if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                // 既存のトランザクションがある場合はクリア
                while ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (\Exception $e) {
                        break;
                    }
                }
                
                // 新しいトランザクションを開始
                if (!$connection->isTransactionActive()) {
                    try {
                        $connection->beginTransaction();
                    } catch (\Exception $e) {
                        // 開始に失敗した場合は無視
                    }
                }
            }
        }
    }

    public function tearDown(): void
    {
        // PostgreSQLの場合、トランザクションエラーをクリア
        if ($this->entityManager) {
            $connection = $this->entityManager->getConnection();
            if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                while ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (\Exception $e) {
                        // エラーを無視して続行
                        break;
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
            // エラーが発生した場合は、トランザクションをリセットしてから例外を再スローする
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (\Exception $rollbackException) {
                    // PostgreSQLの場合はROLLBACKも失敗する可能性がある
                }
                
                // PostgreSQLの場合は新しいトランザクションを開始する前に接続をリセット
                if ($connection->getDatabasePlatform()->getName() === 'postgresql') {
                    // 完全にトランザクションをクリアする
                    while ($connection->getTransactionNestingLevel() > 0) {
                        try {
                            $connection->rollBack();
                        } catch (\Exception $e2) {
                            break;
                        }
                    }
                }
                
                try {
                    $connection->beginTransaction();
                } catch (\Exception $beginException) {
                    // トランザクション開始に失敗した場合は無視
                }
            }
            throw $e;
        }
    }
}
