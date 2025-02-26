<?php

namespace Plugin\DataMigration43\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware;
use wapmorgan\UnifiedArchive\UnifiedArchive;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DataMigrationService
{
    private $migrationVersion = '2';

    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function disableLogging(Connection $em)
    {
        $configuration = $em->getConfiguration();
        $middlewares = $configuration->getMiddlewares();
        foreach ($middlewares as $key => $value) {
            if ($value instanceof Middleware) {
                unset($middlewares[$key]);
            }
        }
        $configuration->setMiddlewares($middlewares);
    }

    public function setMigrationVersion($em, $tmpDir, $tmpFile)
    {
        $archive = UnifiedArchive::open($tmpDir . '/' . $tmpFile);
        $fileNames = $archive->getFileNames();
        // 解凍
        $archive->extractFiles($tmpDir, $fileNames);

        // 圧縮方式の間違いに対応する
        $path = pathinfo($fileNames[0]);

        if ($path['dirname'] != '.') {
            $csvDir = $tmpDir . '/' . $path['dirname'] . '/';
        } else {
            $csvDir = $tmpDir . '/';
        }

        // 2.4.4系の場合の処理
        if (file_exists($csvDir . 'bkup_data.csv')) {
            $this->cutOff24($csvDir, 'bkup_data.csv');
            // 2.4.4系の場合の処理
            if (file_exists($csvDir . 'dtb_products_class.csv')) {
                // 2.11の場合は通さない
                if (!file_exists($csvDir . 'dtb_class_combination.csv')) {
                    $this->migrationVersion = '2.4.4';
                }
            }
        }

        // 4.0/4.1系の場合
        if (file_exists($csvDir . 'dtb_order_item.csv')) {
            $this->migrationVersion = '4.0/4.1';
        }

        if ($this->migrationVersion != "4.0/4.1") {
            // 3系の場合
            if (file_exists($csvDir . 'dtb_product.csv')) {
                $this->migrationVersion = '3';
            }
        }

        return $csvDir;
    }

    public function isVersion($version)
    {
        return $this->migrationVersion === $version;
    }

    public function updateEnv($newMagicValue)
    {
        $projectDir = $this->params->get('kernel.project_dir');
        $envFile = $projectDir . '/.env';

        if (!file_exists($envFile)) {
            touch($envFile);
        }

        $env = file_get_contents($envFile);
        if (strpos($env, 'ECCUBE_AUTH_MAGIC=') !== false) {
            $env = preg_replace('/ECCUBE_AUTH_MAGIC=.*/', 'ECCUBE_AUTH_MAGIC=' . $newMagicValue, $env);
        } else {
            $env .= "\nECCUBE_AUTH_MAGIC=" . $newMagicValue;
        }
        file_put_contents($envFile, $env);
    }

    public function resetTable(Connection $em, $tableName)
    {
        $platform = $em->getDatabasePlatform()->getName();

        if ($platform == 'mysql') {
            $em->exec('DELETE FROM ' . $tableName);
        } else {
            $em->exec('DELETE FROM ' . $tableName);
        }
    }

    public function convertNULL($data)
    {
        foreach ($data as &$v) {
            if ($v === "NULL") {
                $v = null;
            }
        }
        return $data;
    }

    public function checkUploadSize()
    {
        if (!$filesize = ini_get('upload_max_filesize')) {
            $filesize = '5M';
        }

        if ($postsize = ini_get('post_max_size')) {
            return min($filesize, $postsize);
        } else {
            return $filesize;
        }
    }

    public function fixDeletedProduct($em)
    {
        $sql = 'UPDATE
            dtb_product_class
        SET
            visible = true
        WHERE
            id IN(
                SELECT
                    product_class_id
                FROM
                    (
                        SELECT
                            t1.id AS product_class_id
                        FROM
                            dtb_product_class AS t1
                            LEFT JOIN
                                dtb_product AS t2
                            on  t1.product_id = t2.id
                        WHERE
                            t2.product_status_id = 3
                        AND t1.visible = false
                    ) AS t
            )';

        $em->exec($sql);

        // リレーションエラーになるので
        $em->exec('DELETE FROM dtb_cart');
        $em->exec('DELETE FROM dtb_cart_item');

        // 外部キー制約エラーになるデータを消す
        $em->exec('DELETE FROM dtb_class_category WHERE id = 0');
        $em->exec('UPDATE dtb_product_class SET class_category_id1 = NULL WHERE class_category_id1 not in (select id from dtb_class_category)');
        $em->exec('UPDATE dtb_product_class SET class_category_id2 = NULL WHERE class_category_id2 not in (select id from dtb_class_category)');

        $em->exec('delete from dtb_product_tag where id in (
                        select id from (select t1.id from dtb_product_tag t1 left join dtb_tag t2 on t1.tag_id = t2.id where t2.id is null) as tmp
                    );');
        $em->exec('delete from dtb_product_tag where id in (
                        select id from (select t1.id from dtb_product_tag t1 left join dtb_product t2 on t1.product_id = t2.id where t2.id is null) as tmp
                    );');
    }

    public function begin($em)
    {
        $em->beginTransaction();
        $platform = $em->getDatabasePlatform()->getName();

        if ($platform == 'mysql') {
            $em->exec('SET FOREIGN_KEY_CHECKS = 0;');
            $em->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'"); // STRICT_TRANS_TABLESを無効にする。
        } else {
            $em->exec('SET session_replication_role = replica;'); // need super user
        }

        return $platform;
    }

    public function setIdSeq($em, $tableName)
    {
        $max = $em->fetchOne('SELECT coalesce(max(id), 0) + 1  FROM ' . $tableName);
        $seq = $tableName . '_id_seq';
        $count = $em->fetchOne("select count(*) from pg_class where relname = '$seq';");
        if ($count) {
            $em->exec("SELECT setval('$seq', $max);");
        }
    }

    // 2.4.4から
    public function cutOff24($tmpDir, $csvName)
    {
        $tbl_flg = false;
        $col_flg = false;

        if (($handle = fopen($tmpDir . $csvName, 'r')) !== false) {
            $fpcsv = '';
            while (($row = fgetcsv($handle)) !== false) {
                //空白行のときはテーブル変更
                if (count($row) <= 1 and $row[0] == '') {
                    $tbl_flg = false;
                    $col_flg = false;
                    $enablePoint = false;
                    $key = [];
                    $i = 1;

                    continue;
                }

                // テーブルフラグがたっていない場合にはテーブル名セット
                if (!$tbl_flg) {
                    // 特定のテーブルのみ
                    switch ($row[0]) {
                        case 'dtb_baseinfo':
                        case 'dtb_payment':
                        case 'dtb_deliv':
                        case 'dtb_delivfee':
                        case 'dtb_delivtime':
                        case 'dtb_customer':
                        case 'dtb_products':
                        case 'dtb_products_class':
                        case 'dtb_product_categories':
                        case 'dtb_category':
                        case 'dtb_class':
                        case 'dtb_classcategory':
                        case 'dtb_class_combination':
                        case 'dtb_order':
                        case 'dtb_order_detail':
                        case 'dtb_shipping':
                        case 'dtb_shipment_item':
                        case 'dtb_mail_history':
                            $tableName = $row[0];
                            $allow_zero = false;
                            $tbl_flg = true;

                            $fpcsv = fopen($tmpDir . $tableName . '.csv', 'w');
                            break;

                        case 'dtb_other_deliv':
                            //$tableName = 'dtb_customer_address';
                            $tableName = $row[0];
                            $allow_zero = true;
                            $tbl_flg = true;

                            $fpcsv = fopen($tmpDir . $tableName . '.csv', 'w');
                            break;
                        case 'dtb_index_list': // ゴミデータが交じるので
                            $tbl_flg = true;
                            $tableName = $row[0];
                            $fpcsv = fopen($tmpDir . $tableName . '.csv', 'w');
                            break;

                        case 'dtb_member':
                        case 'mtb_authority':
                        case 'mtb_sex':
                        case 'mtb_job':
                        case 'mtb_product_type':
                            $tableName = $row[0];
                            $allow_zero = true;
                            $tbl_flg = true;
                            $fpcsv = fopen($tmpDir . $tableName . '.csv', 'w');
                            break;
                    }
                    continue;
                }

                if ($tbl_flg) {
                    fputcsv($fpcsv, $row);
                }
            } // end while
            fclose($fpcsv);
            fclose($handle);
        }
    }
}
