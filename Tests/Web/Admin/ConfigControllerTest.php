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
                self::assertEquals($m, count($members), 'メンバーが正しくインポートされること');
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
     * 移行を実行するヘルパー。フィクスチャ名を指定して POST → DBAL Connection を返す。
     */
    private function performMigration(string $fixture, array $extraPost = []): \Doctrine\DBAL\Connection
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');

        $file = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/' . $fixture . '.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/test.tar.gz';

        $fs = new Filesystem();
        $fs->copy($file, $testFile);

        $file = new UploadedFile($testFile, 'test.tar.gz', 'application/x-tar', null, true);

        $post = [
            'config' => array_merge([
                Constant::TOKEN_NAME => 'dummy',
                'import_file' => $file,
                'auth_magic' => 'dummy',
            ], $extraPost),
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('data_migration43_admin_config'),
            $post,
            ['config' => ['import_file' => $file]]
        );

        return $this->entityManager->getConnection();
    }

    public function test2系のデータ移行内容が正しいこと()
    {
        try {
            $conn = $this->performMigration('2_13_5');

            // --- 会員 ---
            $customer = $conn->fetchAssociative('SELECT * FROM dtb_customer WHERE id = ?', [1]);
            self::assertNotFalse($customer, '会員id=1が存在すること');
            self::assertSame('てすと', $customer['name01']);
            self::assertSame('たろう', $customer['name02']);
            self::assertSame('テスト', $customer['kana01']);
            self::assertSame('タロウ', $customer['kana02']);
            self::assertSame('hoge@example.com', $customer['email']);
            self::assertSame('7772222', $customer['postal_code']);
            self::assertEquals(2, (int) $customer['sex_id']);
            self::assertEquals(12, (int) $customer['job_id']);
            self::assertEquals(6, (int) $customer['pref_id']);
            // status=2, del_flg=0 → customer_status_id=2
            self::assertEquals(2, (int) $customer['customer_status_id']);
            self::assertSame('customer', $customer['discriminator_type']);

            // --- 商品 ---
            $products = $conn->fetchAllAssociative('SELECT * FROM dtb_product ORDER BY id');
            self::assertCount(3, $products);
            self::assertSame('アイスクリーム', $products[0]['name']);
            self::assertSame('おなべ', $products[1]['name']);
            self::assertSame('おなべレシピ', $products[2]['name']);

            // product_status_id: status=1, del_flg=0 → 1
            foreach ($products as $p) {
                self::assertEquals(1, (int) $p['product_status_id'], $p['name'] . 'のproduct_status_idが1であること');
            }

            // product_class: 各商品にvisible=trueのレコードが存在
            foreach ([1, 2, 3] as $pid) {
                $visibleCount = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM dtb_product_class WHERE product_id = ? AND visible = true',
                    [$pid]
                );
                self::assertGreaterThan(0, $visibleCount, "product_id={$pid}にvisible=trueのproduct_classが存在すること");
            }

            // product_stock: 在庫レコードが存在
            $stockCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_product_stock');
            self::assertGreaterThan(0, $stockCount, '在庫レコードが存在すること');

            // product_image: 画像レコードが存在（2.xはインラインカラムから変換）
            $imageCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_product_image');
            self::assertGreaterThan(0, $imageCount, '商品画像レコードが存在すること');

            // --- 受注 ---
            $orders = $conn->fetchAllAssociative('SELECT * FROM dtb_order ORDER BY id');
            self::assertCount(2, $orders);
            // order_id=1: 会員注文
            self::assertEquals(1, (int) $orders[0]['customer_id'], '受注1はcustomer_id=1であること');
            // order_id=2: ゲスト (customer_id=0→NULL)
            self::assertNull($orders[1]['customer_id'], '受注2はゲスト注文のためcustomer_id=NULLであること');

            foreach ($orders as $order) {
                self::assertSame('JPY', $order['currency_code'], 'currency_codeがJPYであること');
                self::assertNotNull($order['order_date'], 'order_dateが設定されていること');
            }

            // order_item: 商品明細(type=1)が存在
            $productItems = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order_item WHERE order_item_type_id = 1'
            );
            self::assertGreaterThan(0, $productItems, '商品明細(type=1)が存在すること');

            // order_item: 送料(type=2)が生成されること
            $delivFeeItems = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order_item WHERE order_item_type_id = 2'
            );
            self::assertGreaterThan(0, $delivFeeItems, '送料(type=2)のorder_itemが生成されること');

            // tax計算が行われていること (tax > 0)
            $taxItems = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order_item WHERE tax > 0'
            );
            self::assertGreaterThan(0, $taxItems, 'taxが計算されていること');

            // --- 配送 ---
            $shippings = $conn->fetchAllAssociative('SELECT * FROM dtb_shipping ORDER BY id');
            self::assertCount(2, $shippings);
            // delivery_idがorderのdeliv_idから正しく設定されていること
            foreach ($shippings as $shipping) {
                self::assertNotNull($shipping['delivery_id'], 'delivery_idが設定されていること');
            }

            // --- カテゴリ ---
            $categoryCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_category');
            self::assertGreaterThanOrEqual(6, $categoryCount, 'カテゴリが6件以上存在すること');

            // --- 税率 ---
            $taxRate = $conn->fetchOne(
                'SELECT tax_rate FROM dtb_tax_rule WHERE product_id IS NULL AND product_class_id IS NULL LIMIT 1'
            );
            self::assertEquals(8, (int) $taxRate, '税率が8%であること');

        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }

    public function test3系のデータ移行内容が正しいこと()
    {
        try {
            $conn = $this->performMigration('3_0_18');

            // --- 会員 ---
            $customer = $conn->fetchAssociative('SELECT * FROM dtb_customer WHERE id = ?', [1]);
            self::assertNotFalse($customer, '会員id=1が存在すること');
            self::assertSame('足立', $customer['name01']);
            self::assertSame('智広', $customer['name02']);
            self::assertSame('chihiro_adachi@ec-cube.co.jp', $customer['email']);
            self::assertEquals(1, (int) $customer['pref_id']);

            // --- 商品 ---
            $products = $conn->fetchAllAssociative('SELECT * FROM dtb_product ORDER BY id');
            self::assertCount(2, $products);
            self::assertSame('ディナーフォーク', $products[0]['name']);
            self::assertSame('パーコレーター', $products[1]['name']);

            // product_classが存在
            foreach ([1, 2] as $pid) {
                $pcCount = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM dtb_product_class WHERE product_id = ?',
                    [$pid]
                );
                self::assertGreaterThan(0, $pcCount, "product_id={$pid}にproduct_classが存在すること");
            }

            // --- 受注 ---
            $orderCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_order');
            self::assertEquals(4, $orderCount, '受注が4件であること');

            // order_item: 商品明細が存在
            $productItems = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order_item WHERE order_item_type_id = 1'
            );
            self::assertGreaterThan(0, $productItems, '商品明細(type=1)が存在すること');

            // --- 配送 ---
            $shippingCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_shipping');
            self::assertGreaterThanOrEqual(5, $shippingCount, '配送が5件以上であること（order 4がマルチ配送）');

            // order 4のshippingが2件であること
            $order4ShippingCount = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_shipping WHERE order_id = ?',
                [4]
            );
            self::assertEquals(2, $order4ShippingCount, 'order 4のshippingが2件であること（マルチ配送）');

            // delivery_idが設定されていること
            $nullDeliveryCount = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_shipping WHERE delivery_id IS NULL'
            );
            self::assertEquals(0, $nullDeliveryCount, '全配送にdelivery_idが設定されていること');

            // --- 税率 ---
            $taxRate = $conn->fetchOne(
                'SELECT tax_rate FROM dtb_tax_rule WHERE product_id IS NULL AND product_class_id IS NULL LIMIT 1'
            );
            self::assertEquals(8, (int) $taxRate, '税率が8%であること');

        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }

    public function test4系のデータ移行内容が正しいこと()
    {
        try {
            $conn = $this->performMigration('4_1_2');

            // --- 会員 ---
            $customer = $conn->fetchAssociative('SELECT * FROM dtb_customer WHERE id = ?', [1]);
            self::assertNotFalse($customer, '会員id=1が存在すること');
            self::assertSame('大垣', $customer['name01']);
            self::assertSame('翼', $customer['name02']);
            self::assertEquals(55784, (int) $customer['point'], 'pointが55784であること');

            // --- 商品 ---
            $productCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_product');
            self::assertEquals(12, $productCount, '商品が12件であること');

            // --- 受注 ---
            $orderCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM dtb_order');
            self::assertEquals(20, $orderCount, '受注が20件であること');

            // --- 受注明細: 各種order_item_type_idが存在 ---
            foreach ([1, 2, 3, 4] as $typeId) {
                $count = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM dtb_order_item WHERE order_item_type_id = ?',
                    [$typeId]
                );
                self::assertGreaterThan(0, $count, "order_item_type_id={$typeId}のレコードが存在すること");
            }

        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }

    public function testメンバーのデータ移行内容が正しいこと()
    {
        try {
            $conn = $this->performMigration('member_test');

            // id=99: テスト管理者
            $member99 = $conn->fetchAssociative('SELECT * FROM dtb_member WHERE id = ?', [99]);
            self::assertNotFalse($member99, 'メンバーid=99が存在すること');
            self::assertSame('テスト管理者', $member99['name']);
            self::assertSame('testadmin', $member99['login_id']);
            self::assertEquals(0, (int) $member99['authority_id'], 'id=99のauthority_idが0（システム管理者）であること');

            // id=100: テスト店舗オーナー
            $member100 = $conn->fetchAssociative('SELECT * FROM dtb_member WHERE id = ?', [100]);
            self::assertNotFalse($member100, 'メンバーid=100が存在すること');
            self::assertSame('テスト店舗オーナー', $member100['name']);
            self::assertSame('testowner', $member100['login_id']);
            self::assertEquals(1, (int) $member100['authority_id'], 'id=100のauthority_idが1（店舗オーナー）であること');

        } catch (\Exception $e) {
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
