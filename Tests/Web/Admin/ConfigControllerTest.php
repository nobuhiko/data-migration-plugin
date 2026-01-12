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
            //['2_11_5', 1, 0, 3],
            //['2_12_6', 1, 3, 2],
            ['2_13_5', 1, 3, 2],
            //['3_0_9', 1, 2, 6],   // PostgreSQL対応により3.x系も復活
            //['3_0_18', 1, 2, 4],  // PostgreSQL対応により3.x系も復活
            //['4_0_6', 1, 12, 20],
            //['4_1_2', 1, 12, 20],
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

    public function testUpsertAuthorityAndMember()
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');
        $fixtureDir = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/member_test/';

        // Controllerのインスタンスを取得
        $controller = $container->get('Plugin\DataMigration43\Controller\Admin\ConfigController');

        // ReflectionClassを使ってprotectedメソッドにアクセス
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('upsertAuthorityAndMember');
        $method->setAccessible(true);

        // EntityManagerの接続を取得
        $em = $this->entityManager->getConnection();

        try {
            // テスト実行前に既存のメンバーを削除（idが99, 100の場合）
            $em->executeStatement('DELETE FROM dtb_member WHERE id IN (99, 100)');
            $em->executeStatement('DELETE FROM mtb_authority WHERE id IN (0, 1)');

            // メソッドを実行
            $method->invoke($controller, $em, $fixtureDir);

            // 権限マスタが正しくインポートされたか確認
            $authorities = $em->fetchAllAssociative('SELECT * FROM mtb_authority ORDER BY id');
            self::assertCount(2, $authorities, '権限マスタが2件インポートされること');
            self::assertEquals(0, $authorities[0]['id']);
            self::assertEquals('システム管理者', $authorities[0]['name']);
            self::assertEquals(1, $authorities[1]['id']);
            self::assertEquals('店舗オーナー', $authorities[1]['name']);

            // メンバーが正しくインポートされたか確認
            $members = $em->fetchAllAssociative('SELECT * FROM dtb_member WHERE id IN (99, 100) ORDER BY id');
            self::assertCount(2, $members, 'メンバーが2件インポートされること');
            self::assertEquals(99, $members[0]['id']);
            self::assertEquals('テスト管理者', $members[0]['name']);
            self::assertEquals('testadmin', $members[0]['login_id']);
            self::assertEquals(0, $members[0]['authority_id']);
            self::assertEquals(1, $members[0]['work_id'], 'work_idが1（稼働中）であること');

            self::assertEquals(100, $members[1]['id']);
            self::assertEquals('テスト店舗オーナー', $members[1]['name']);
            self::assertEquals('testowner', $members[1]['login_id']);
            self::assertEquals(1, $members[1]['authority_id']);
            self::assertEquals(1, $members[1]['work_id'], 'work_idが1（稼働中）であること');

        } catch (\Exception $e) {
            // エラーが発生した場合は、トランザクションをリセットしてから例外を再スローする
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }

    public function testUpsertAuthorityAndMemberでログイン可能()
    {
        $container = self::getContainer();
        $project_dir = $container->getParameter('kernel.project_dir');
        $fixtureDir = $project_dir . '/app/Plugin/DataMigration43/Tests/Fixtures/member_test/';

        // Controllerのインスタンスを取得
        $controller = $container->get('Plugin\DataMigration43\Controller\Admin\ConfigController');

        // ReflectionClassを使ってprotectedメソッドにアクセス
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('upsertAuthorityAndMember');
        $method->setAccessible(true);

        // EntityManagerの接続を取得
        $em = $this->entityManager->getConnection();

        try {
            // テスト実行前に既存のメンバーを削除（idが99, 100の場合）
            $em->executeStatement('DELETE FROM dtb_member WHERE id IN (99, 100)');

            // 正しいパスワードハッシュでメンバーデータを更新
            $encoder = $container->get('security.user_password_encoder.generic');
            $memberRepository = $this->entityManager->getRepository(\Eccube\Entity\Member::class);

            // 既存の管理者を取得してパスワードハッシュを参考にする
            $existingMember = $memberRepository->find(1);
            if ($existingMember) {
                // 実際にログイン可能なパスワードハッシュを生成
                $testPassword = 'testpassword123';

                // フィクスチャファイルを一時的に更新（本番では別の方法が望ましい）
                $hashedPassword = password_hash($testPassword, PASSWORD_BCRYPT);

                $csvContent = "id,name,department,login_id,password,authority_id,work_id,creator_id,create_date,update_date,discriminator_type\n";
                $csvContent .= "99,テスト管理者,開発部,testadmin,$hashedPassword,0,1,1,2024-01-01 00:00:00,2024-01-01 00:00:00,member\n";

                file_put_contents($fixtureDir . 'dtb_member.csv', $csvContent);
            }

            // メソッドを実行
            $method->invoke($controller, $em, $fixtureDir);

            // ログアウト
            $this->logoutTo();

            // インポートしたメンバーでログインを試みる
            $this->client->request('POST', $this->generateUrl('admin_login'), [
                'login_id' => 'testadmin',
                'password' => 'testpassword123',
            ]);

            // ログイン成功を確認（管理画面にリダイレクトされること）
            self::assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('admin_homepage')),
                'インポートしたメンバーでログインできること');

        } catch (\Exception $e) {
            // エラーが発生した場合は、トランザクションをリセットしてから例外を再スローする
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
                $this->entityManager->getConnection()->beginTransaction();
            }
            throw $e;
        }
    }
}
