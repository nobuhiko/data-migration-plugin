<?php


namespace Plugin\DataMigration43\Tests\Web\Admin;


use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Eccube\Common\Constant;
use Eccube\Entity\Customer;
use Eccube\Entity\Order;
use Eccube\Entity\Product;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group integration
 */
class ConfigControllerTest extends AbstractAdminWebTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // PostgreSQLの場合、より積極的なトランザクション管理
        if ($this->entityManager->getConnection()->getDatabasePlatform()->getName() === 'postgresql') {
            StaticDriver::setKeepStaticConnections(false);
            
            // 既存のトランザクションを完全にクリア
            $connection = $this->entityManager->getConnection();
            try {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                // 新しいトランザクションを開始
                if (!$connection->isTransactionActive()) {
                    $connection->beginTransaction();
                }
            } catch (\Exception $e) {
                // エラーは無視
            }
        }
    }

    public function tearDown(): void
    {
        // PostgreSQLでの完全なクリーンアップ
        if ($this->entityManager && $this->entityManager->getConnection()->getDatabasePlatform()->getName() === 'postgresql') {
            $connection = $this->entityManager->getConnection();
            try {
                // トランザクションが中断状態の場合は完全にリセット
                if ($connection->isTransactionActive()) {
                    // 強制的にロールバック
                    while ($connection->getTransactionNestingLevel() > 0) {
                        try {
                            $connection->rollBack();
                        } catch (\Exception $e) {
                            break; // これ以上ロールバックできない
                        }
                    }
                }
                
                // 接続をリセット
                if ($connection->isTransactionActive()) {
                    $connection->close();
                    $connection->connect();
                }
                
            } catch (\Exception $e) {
                // 全てのエラーを無視
            }
            
            StaticDriver::setKeepStaticConnections(true);
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
            
            // PostgreSQLでエラーが発生した場合のデバッグ情報
            $response = $this->client->getResponse();
            if ($response->getStatusCode() >= 400) {
                echo "Response Status: " . $response->getStatusCode() . "\n";
                echo "Response Content: " . $response->getContent() . "\n";
            }
            
        } catch (\Exception $e) {
            // PostgreSQLの場合、トランザクション回復を試行
            if ($this->entityManager->getConnection()->getDatabasePlatform()->getName() === 'postgresql') {
                echo "PostgreSQL Error: " . $e->getMessage() . "\n";
                echo "Error Code: " . $e->getCode() . "\n";
                
                // トランザクション回復を試行
                $connection = $this->entityManager->getConnection();
                try {
                    // 完全にトランザクションをクリア
                    while ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    
                    // 接続をリセット
                    $connection->close();
                    $connection->connect();
                    
                    echo "PostgreSQL Transaction recovered\n";
                } catch (\Exception $recoveryException) {
                    echo "PostgreSQL Recovery failed: " . $recoveryException->getMessage() . "\n";
                }
            }
            throw $e;
        }
        
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
    }
}
