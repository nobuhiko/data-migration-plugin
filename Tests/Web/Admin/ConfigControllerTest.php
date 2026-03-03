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
            ['2_11_5', 1, 0, 3, 0],
            ['2_12_6', 1, 3, 2, 0],
            ['2_13_5', 1, 3, 2, 0],
            ['3_0_9', 1, 2, 6, 0],
            ['3_0_18', 1, 2, 4, 0],
            ['4_0_6', 1, 12, 20, 0],
            ['4_1_2', 1, 12, 20, 0],
            ['member_test', 0, 0, 0, 2], // Member import test
        ];
    }

    /**
     * @dataProvider versionProvider
     */
    public function testバックアップファイルをアップロードできるかテスト($v, $c, $p, $o, $m = 0)
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
        if ($v == '2_11_5') {
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

            if ($m > 0) {
                $members = $this->entityManager->getRepository(\Eccube\Entity\Member::class)->findAll();
                self::assertGreaterThanOrEqual($m, count($members), 'メンバーが正しくインポートされること');
                // 移行データ固有の値を検証
                $conn = $this->entityManager->getConnection();
                $testAdmin = $conn->fetchAssociative(
                    'SELECT * FROM dtb_member WHERE id = ?', [99]
                );
                self::assertNotFalse($testAdmin, 'メンバーid=99が存在すること');
                self::assertEquals('testadmin', $testAdmin['login_id']);
            }

            // ECCUBE_AUTH_MAGICの値を取得してアサート
            //$eccubeConfig = $container->get('Eccube\Common\EccubeConfig');
            //$authMagic = $eccubeConfig->get('eccube_auth_magic');
            //self::assertEquals('dummy', $authMagic);
        } catch (\Exception $e) {
            // エラーが発生した場合は、トランザクションをリセットしてから例外を再スローする
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }

    /**
     * ECCUBE2Downloadsプラグインがインストール済みの場合、
     * ダウンロード商品のsale_type_idが222に書き換わることをテスト
     */
    public function testECCUBE2Downloadsダウンロード商品のsale_type_idが222になる()
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');
        $conn = $this->entityManager->getConnection();

        $file = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/2_13_5.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/test.tar.gz';

        $fs = new Filesystem();
        $fs->copy($file, $testFile);

        $file = new UploadedFile($testFile, 'test.tar.gz', 'application/x-tar', null, true);

        $post = [
            'config' => [
                Constant::TOKEN_NAME => 'dummy',
                'import_file' => $file,
                'auth_magic' => 'dummy',
            ]
        ];

        try {
            $this->client->request(
                'POST',
                $this->generateUrl('data_migration43_admin_config'),
                $post,
                ['config' => ['import_file' => $file]]
            );

            // ダウンロード商品(product_id=3)のsale_type_idが222であること
            $saleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            self::assertEquals(222, (int)$saleTypeId, 'ダウンロード商品のsale_type_idが222であること');

            // 通常商品(product_id=1)のsale_type_idが222でないこと
            $normalSaleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_product_class WHERE product_id = ? AND visible = true LIMIT 1",
                [1]
            );
            self::assertNotEquals(222, (int)$normalSaleTypeId, '通常商品のsale_type_idは222でないこと');

            // ダウンロード配送(product_type_id=2)のdeliveryのsale_type_idが222であること
            $delivSaleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_delivery WHERE id = ?",
                [2]
            );
            self::assertEquals(222, (int)$delivSaleTypeId, 'ダウンロード配送のsale_type_idが222であること');

            // down_filename, down_realfilenameが移行されていること
            $downFilename = $conn->fetchOne(
                "SELECT down_filename FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            self::assertEquals('おなべレシピ.pdf', $downFilename, 'down_filenameが移行されていること');

            $downRealfilename = $conn->fetchOne(
                "SELECT down_realfilename FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            self::assertEquals('recipe_onabe.pdf', $downRealfilename, 'down_realfilenameが移行されていること');
        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }
}
