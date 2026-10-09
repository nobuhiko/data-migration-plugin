<?php

declare(strict_types=1);

namespace Plugin\DataMigration44\Tests\Web\Admin;


use Eccube\Entity\Member;
use Eccube\Common\Constant;
use Eccube\Entity\Customer;
use Eccube\Entity\Order;
use Eccube\Entity\Product;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ConfigControllerTest extends AbstractAdminWebTestCase
{
    /**
     * @return \Iterator<int, array{string, int, int, int, int}>
     */
    public static function versionProvider(): \Iterator
    {
        yield ['2_11_5', 1, 0, 3, 0];
        yield ['2_12_6', 1, 3, 2, 0];
        yield ['2_13_5', 1, 3, 2, 0];
        yield ['3_0_9', 1, 2, 6, 0];
        yield ['3_0_18', 1, 2, 4, 0];
        yield ['4_0_6', 1, 12, 20, 0];
        yield ['4_1_2', 1, 12, 20, 0];
        yield ['4_3_1', 2, 4, 3, 0];
        yield ['member_test', 0, 0, 0, 2];
    }

    #[DataProvider(methodName: 'versionProvider')]
    public function testバックアップファイルをアップロードできるかテスト(string $v, int $c, int $p, int $o, int $m = 0): void
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');

        $file = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/' . $v . '.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/test.tar.gz';

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
                $this->generateUrl('data_migration44_admin_config'),
                $post,
                ['config' => ['import_file' => $file]]
            );

            $customers = $this->entityManager->getRepository(Customer::class)->findAll();
            $this->assertCount($c, $customers);

            if ($p > 0) {
                $products = $this->entityManager->getRepository(Product::class)->findAll();
                $this->assertCount($p, $products);
            }

            $orders = $this->entityManager->getRepository(Order::class)->findAll();
            $this->assertCount($o, $orders);

            if ($m > 0) {
                $members = $this->entityManager->getRepository(Member::class)->findAll();
                $this->assertGreaterThanOrEqual($m, count($members), 'メンバーが正しくインポートされること');
                // 移行データ固有の値を検証
                $conn = $this->entityManager->getConnection();
                $testAdmin = $conn->fetchAssociative(
                    'SELECT * FROM dtb_member WHERE id = ?', [99]
                );
                $this->assertNotFalse($testAdmin, 'メンバーid=99が存在すること');
                $this->assertEquals('testadmin', $testAdmin['login_id']);
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
     * 4.3 → 4.4: 画面・テンプレートは 4.4 の初期データを残し、4.4 で追加された列はデフォルト値になることをテスト
     */
    public function test4系からの移行で画面とデフォルト値が4_4側に保たれる(): void
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');
        $conn = $this->entityManager->getConnection();

        $pagesBefore = (int) $conn->fetchOne('SELECT count(*) FROM dtb_page');
        $blocksBefore = (int) $conn->fetchOne('SELECT count(*) FROM dtb_block');
        $saleTypesBefore = (int) $conn->fetchOne('SELECT count(*) FROM mtb_sale_type');

        $file = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/4_3_1.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/test.tar.gz';
        (new Filesystem())->copy($file, $testFile);
        $upload = new UploadedFile($testFile, 'test.tar.gz', 'application/x-tar', null, true);

        $this->client->request(
            'POST',
            $this->generateUrl('data_migration44_admin_config'),
            ['config' => [Constant::TOKEN_NAME => 'dummy', 'import_file' => $upload, 'auth_magic' => 'dummy']],
            ['config' => ['import_file' => $upload]]
        );

        // 業務データは移行される
        $this->assertSame(2, (int) $conn->fetchOne('SELECT count(*) FROM dtb_customer'));
        $this->assertSame(4, (int) $conn->fetchOne('SELECT count(*) FROM dtb_product'));
        // 画面・ブロックは 4.4 の初期データのまま
        $this->assertSame($pagesBefore, (int) $conn->fetchOne('SELECT count(*) FROM dtb_page'));
        $this->assertSame($blocksBefore, (int) $conn->fetchOne('SELECT count(*) FROM dtb_block'));
        // マスタは 4.4 側の行が残る (4.3 に無い行が消えない)
        $this->assertGreaterThanOrEqual($saleTypesBefore, (int) $conn->fetchOne('SELECT count(*) FROM mtb_sale_type'));
        // 4.4 で追加された NOT NULL 列は 0 ではなく DB のデフォルト値になる
        $this->assertTrue((bool) $conn->fetchOne('SELECT order_pdf_visible_shop_name FROM dtb_base_info WHERE id = 1'));
        $this->assertSame(4, (int) $conn->fetchOne('SELECT count(*) FROM dtb_product WHERE refund_allowed = true'));
        // in_stock は在庫から再計算される (在庫無制限の規格は true)
        $this->assertSame(
            (int) $conn->fetchOne('SELECT count(*) FROM dtb_product_class WHERE stock_unlimited = true'),
            (int) $conn->fetchOne('SELECT count(*) FROM dtb_product_class WHERE stock_unlimited = true AND in_stock = true')
        );
    }

    /**
     * ECCUBE2Downloadsプラグインがインストール済みの場合、
     * ダウンロード商品のsale_type_idが222に書き換わることをテスト
     */
    public function testECCUBE2Downloadsダウンロード商品のsale_type_idが222になる(): void
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');
        $conn = $this->entityManager->getConnection();

        $file = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/2_13_5.tar.gz';
        $testFile = $project_dir . '/app/Plugin/DataMigration44/Tests/Fixtures/test.tar.gz';

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
                $this->generateUrl('data_migration44_admin_config'),
                $post,
                ['config' => ['import_file' => $file]]
            );

            // ダウンロード商品(product_id=3)のsale_type_idが222であること
            $saleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            $this->assertSame(222, (int)$saleTypeId, 'ダウンロード商品のsale_type_idが222であること');

            // 通常商品(product_id=1)のsale_type_idが222でないこと
            $normalSaleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_product_class WHERE product_id = ? AND visible = true LIMIT 1",
                [1]
            );
            $this->assertNotSame(222, (int)$normalSaleTypeId, '通常商品のsale_type_idは222でないこと');

            // ダウンロード配送(product_type_id=2)のdeliveryのsale_type_idが222であること
            $delivSaleTypeId = $conn->fetchOne(
                "SELECT sale_type_id FROM dtb_delivery WHERE id = ?",
                [2]
            );
            $this->assertSame(222, (int)$delivSaleTypeId, 'ダウンロード配送のsale_type_idが222であること');

            // down_filename, down_realfilenameが移行されていること
            $downFilename = $conn->fetchOne(
                "SELECT down_filename FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            $this->assertEquals('おなべレシピ.pdf', $downFilename, 'down_filenameが移行されていること');

            $downRealfilename = $conn->fetchOne(
                "SELECT down_realfilename FROM dtb_product_class WHERE product_id = ? AND visible = true",
                [3]
            );
            $this->assertEquals('recipe_onabe.pdf', $downRealfilename, 'down_realfilenameが移行されていること');
        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }
}
