<?php


namespace Plugin\DataMigration4\Tests\Web\Admin;


use Eccube\Common\Constant;
use Eccube\Tests\Web\Admin\AbstractAdminWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ConfigControllerTest extends AbstractAdminWebTestCase
{
    public function setUp()
    {
        parent::setUp();
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    public function testアップロードできるか()
    {
        $file = __DIR__.'/../../Fixtures/foo.zip';

        $fs = new Filesystem();
        $fs->copy($file, $file . '.backup');

        $file = new UploadedFile($file, 'foo.zip', 'application/zip', null, null, true);
        $this->client->request(
            'POST',
            $this->generateUrl('data_migration4_admin_config'),
            [
                'config' => [
                    Constant::TOKEN_NAME => 'dummy',
                    'import_file' => $file
                ],
                [
                    'import_file' => $file
                ]
            ]
        );

        $fs->rename($file . '.backup', $file, true);

        self::assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('data_migration4_admin_config')));
    }
}
