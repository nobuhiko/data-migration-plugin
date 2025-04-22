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

    /**
     * 指定したプラグインコードがインストール済みかどうかを返す
     *
     * @param \Doctrine\DBAL\Connection|\Doctrine\ORM\EntityManagerInterface $em
     * @param string $code
     * @return bool
     */
    public function isPluginInstalled($em, $code)
    {
        // DBAL Connection から直接SQLで判定
        $sql = "SELECT COUNT(*) FROM dtb_plugin WHERE code = ?";
        $count = $em->fetchOne($sql, [$code]);
        return $count > 0;
    }

    /**
     * plg_customerplusの移行処理
     * @param $em
     * @param $csvDir
     * @param $controller (ConfigController) メッセージ出力用
     */
    public function migrateCustomerPlus($em, $csvDir, $controller)
    {
        $platform = $this->begin($em);

        // plg_customerplus_dtb_customer_itemを先にインポートしてからplg_customerplus_dtb_customer_data/data_detailを作成
        $importOrder = [
            'plg_customerplus_dtb_customer_item',
            'plg_customerplus_dtb_customer_item_option',
            //'plg_customerplus_dtb_customer', 別に登録する
            'plg_customerplus_dtb_customer_data',
            'plg_customerplus_dtb_customer_data_detail',
            'plg_customerplus_dtb_order',
            'plg_customerplus_dtb_shipping',
            'plg_customerplus_dtb_customer_address', // 移行前のテーブル: plg_customerplus_dtb_other_deliv
        ];

        // 全テーブルのデータを削除
        foreach ($importOrder as $tableName) {
            if ($em->getSchemaManager()->tablesExist([$tableName])) {
                $this->resetTable($em, $tableName);
            }
        }

        // plg_customerplus_dtb_customerからplg_customerplus_dtb_customer_data.csvとplg_customerplus_dtb_customer_data_detail.csvを作成
        $customerCsv = $csvDir . 'plg_customerplus_dtb_customer.csv';
        $dataCsv = $csvDir . 'plg_customerplus_dtb_customer_data.csv';
        $detailCsv = $csvDir . 'plg_customerplus_dtb_customer_data_detail.csv';

        // 既存データをクリア
        file_put_contents($dataCsv, '');
        file_put_contents($detailCsv, '');

        // ヘッダ
        $dataHeader = ['id', 'customer_item_id', 'create_date', 'discriminator_type'];
        $detailHeader = ['id', 'customer_data_id', 'value', 'date_value', 'num_value', 'discriminator_type'];
        $dataFp = fopen($dataCsv, 'w');
        $detailFp = fopen($detailCsv, 'w');
        fputcsv($dataFp, $dataHeader);
        fputcsv($detailFp, $detailHeader);

        // customer_data_idをcustomerごとに採番
        $dataId = 1;
        $detailId = 1;
        $customerRowsForUpdate = [];
        // value -> customer_data_id のマッピングを保存
        $valueToDataIdMap = [];

        if (file_exists($customerCsv) && filesize($customerCsv) > 0) {
            if (($handle = fopen($customerCsv, 'r')) !== false) {
                $key = fgetcsv($handle);
                $key = array_filter(array_map('trim', $key));
                while (($row = fgetcsv($handle)) !== false) {
                    $dataRow = $this->convertNULL(array_combine($key, $row));
                    // valueカラムが配列やJSONの場合を想定
                    if (isset($dataRow['value']) && $dataRow['value'] !== null && $dataRow['value'] !== '') {
                        $values = @json_decode($dataRow['value'], true);
                        if (!is_array($values)) {
                            if (strpos($dataRow['value'], ',') !== false) {
                                $values = explode(',', $dataRow['value']);
                            } else {
                                $values = [$dataRow['value']];
                            }
                        }
                    } else {
                        $values = [null];
                    }
                    $customer_item_id = isset($dataRow['customer_item_id']) ? $dataRow['customer_item_id'] : null;
                    $customer_id = isset($dataRow['customer_id']) ? $dataRow['customer_id'] : null;
                    $create_date = isset($dataRow['create_date']) ? $dataRow['create_date'] : date('Y-m-d H:i:s');
                    // customer_data_idを採番
                    $customer_data_id = $dataId;
                    // valueとcustomer_data_idの関連付けを保存
                    $originalValue = isset($dataRow['value']) ? $dataRow['value'] : null;
                    if ($originalValue !== null) {
                        $valueToDataIdMap[$originalValue] = $customer_data_id;
                    }
                    // customerRowsForUpdateにcustomer_id, customer_item_id, customer_data_idを保存
                    $customerRowsForUpdate[$customer_id][] = $customer_data_id;
                    $dataCsvRow = [
                        $customer_data_id,
                        $customer_item_id,
                        $create_date,
                        'customerdata'
                    ];
                    fputcsv($dataFp, $dataCsvRow);
                    foreach ($values as $v) {
                        $detailCsvRow = [
                            $detailId,
                            $customer_data_id,
                            $v,
                            null,
                            null,
                            'customerdatadetail'
                        ];
                        fputcsv($detailFp, $detailCsvRow);
                        $detailId++;
                    }
                    $dataId++;
                }
                fclose($handle);
            }
        }
        fclose($dataFp);
        fclose($detailFp);

        // plg_customerplus_dtb_customer_option → plg_customerplus_dtb_customer_item_option へのマッピング
        $optionCsv = $csvDir . 'plg_customerplus_dtb_customer_option.csv';
        $itemOptionCsv = $csvDir . 'plg_customerplus_dtb_customer_item_option.csv';
        if (file_exists($optionCsv) && !file_exists($itemOptionCsv)) {
            rename($optionCsv, $itemOptionCsv);
        }

        // plg_customerplus_dtb_other_deliv → plg_customerplus_dtb_customer_address へのマッピング
        $otherDelivCsv = $csvDir . 'plg_customerplus_dtb_other_deliv.csv';
        $customerAddressCsv = $csvDir . 'plg_customerplus_dtb_customer_address.csv';
        if (file_exists($otherDelivCsv) && !file_exists($customerAddressCsv)) {
            rename($otherDelivCsv, $customerAddressCsv);
        }

        // plg_customerplus_dtb_customer
        $this->resetTable($em, "plg_customerplus_dtb_customer");
        $columns = $em->getSchemaManager()->listTableColumns("plg_customerplus_dtb_customer");
        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }

        $builder = new \nobuhiko\BulkInsertQuery\BulkInsertQuery($em, "plg_customerplus_dtb_customer");
        $builder->setColumns($listTableColumns);
        $plg_customerplus_dtb_customer_i = 1;
        foreach ($customerRowsForUpdate as $customer_id => $customerRows) {
            foreach ($customerRows as $customer_data_id) {
                $row = [
                    'id' => $plg_customerplus_dtb_customer_i,
                    'customer_id' => $customer_id,
                    'customer_data_id' => $customer_data_id,
                    'customer_item_id' => 1,
                    'create_date' => date('Y-m-d H:i:s'),
                    'discriminator_type' => 'customercustom'
                ];
                $builder->setValues($row);
                $builder->execute(); // 1件ずつ即時実行
                $plg_customerplus_dtb_customer_i++;
            }

            $plg_customerplus_dtb_customer_i++;
        }
        // // plg_customerplus_dtb_customer

        // テーブルごとにインポート
        foreach ($importOrder as $tableName) {
            $csvFile = $csvDir . $tableName . '.csv';
            if (!file_exists($csvFile) || filesize($csvFile) === 0) {
                $controller->addWarning($tableName . '.csv が見つからないか空です。', 'admin');
                continue;
            }

            $columns = $em->getSchemaManager()->listTableColumns($tableName);
            $listTableColumns = [];
            foreach ($columns as $column) {
                $listTableColumns[] = $column->getName();
            }

            $builder = new \nobuhiko\BulkInsertQuery\BulkInsertQuery($em, $tableName);
            $builder->setColumns($listTableColumns);

            if (($handle = fopen($csvFile, 'r')) !== false) {
                $key = fgetcsv($handle);
                $key = array_filter(array_map('trim', $key));
                $i = 1;
                $batchSize = 20;

                // --- 通常処理 ---
                while (($row = fgetcsv($handle)) !== false) {
                    $data = $this->convertNULL(array_combine($key, $row));
                    $value = [];
                    switch ($tableName) {
                        case 'plg_customerplus_dtb_customer':
                            // なにもしない

                            break;
                        case 'plg_customerplus_dtb_customer_item':
                            foreach ($listTableColumns as $column) {
                                if ($column === 'id' && isset($data['customer_item_id'])) {
                                    $value[$column] = $data['customer_item_id'];
                                } elseif ($column === 'name' && isset($data['title'])) {
                                    $value[$column] = $data['title'];
                                } elseif ($column === 'input_type' && isset($data['input_type'])) {
                                    $value[$column] = $data['input_type'];
                                } elseif ($column === 'is_required' && isset($data['is_require'])) {
                                    $value[$column] = $data['is_require'] ? 1 : 0;
                                } elseif ($column === 'disabled' && isset($data['disp_flg'])) {
                                    // disp_flgの値を逆にして disabled に設定
                                    $value[$column] = !$data['disp_flg'] ? 1 : 0;
                                } elseif ($column === 'sort_no' && isset($data['rank'])) {
                                    $value[$column] = $data['rank'];
                                } elseif ($column === 'create_date' && !isset($data['create_date'])) {
                                    $value[$column] = date('Y-m-d H:i:s');
                                } elseif ($column === 'update_date' && !isset($data['update_date'])) {
                                    $value[$column] = date('Y-m-d H:i:s');
                                } elseif ($column === 'discriminator_type') {
                                    $value[$column] = 'customeritem';
                                } else {
                                    $value[$column] = isset($data[$column]) ? $data[$column] : null;
                                }
                            }
                            break;
                        case 'plg_customerplus_dtb_customer_item_option':
                            foreach ($listTableColumns as $column) {
                                if ($column === 'sort_no') {
                                    if (isset($data['sort_no'])) {
                                        $value[$column] = $data['sort_no'];
                                    } elseif (isset($data['rank'])) {
                                        $value[$column] = $data['rank'];
                                    } else {
                                        $value[$column] = $i;
                                    }
                                } elseif ($column === 'discriminator_type') {
                                    $value[$column] = 'customeritemoption';
                                } else {
                                    $value[$column] = isset($data[$column]) ? $data[$column] : null;
                                }
                            }
                            break;
                        case 'plg_customerplus_dtb_shipping':
                            foreach ($listTableColumns as $column) {
                                if ($column === 'shipping_id' && isset($data['order_id']) && array_key_exists('shipping_id', $data)) {
                                    // $this->shipping_idマッピングから値を取得
                                    if (isset($controller->shipping_id[$data['order_id']][$data['shipping_id']])) {
                                        $value[$column] = $controller->shipping_id[$data['order_id']][$data['shipping_id']];
                                    } else {
                                        $value[$column] = null;
                                    }
                                } elseif ($column === 'discriminator_type') {
                                    $value[$column] = 'shipping';
                                } elseif ($column === 'customer_data_id' && isset($data['value'])) {
                                    // valueからcustomer_data_idへの変換
                                    $value[$column] = isset($valueToDataIdMap[$data['value']]) ? $valueToDataIdMap[$data['value']] : null;
                                } else {
                                    $value[$column] = isset($data[$column]) ? $data[$column] : null;
                                }
                            }

                            break;
                        case 'plg_customerplus_dtb_order':
                            foreach ($listTableColumns as $column) {
                                if ($column === 'customer_data_id' && isset($data['value'])) {
                                    // valueからcustomer_data_idへの変換
                                    $value[$column] = isset($valueToDataIdMap[$data['value']]) ? $valueToDataIdMap[$data['value']] : null;
                                } else {
                                    $value[$column] = isset($data[$column]) ? $data[$column] : null;
                                }
                            }
                            if (in_array('discriminator_type', $listTableColumns) && !isset($value['discriminator_type'])) {
                                $value['discriminator_type'] = 'order';
                            }
                            break;
                        case 'plg_customerplus_dtb_customer_address':
                            foreach ($listTableColumns as $column) {
                                if ($column === 'customer_data_id' && isset($data['value'])) {
                                    // valueからcustomer_data_idへの変換
                                    $value[$column] = isset($valueToDataIdMap[$data['value']]) ? $valueToDataIdMap[$data['value']] : null;
                                } else {
                                    $value[$column] = isset($data[$column]) ? $data[$column] : null;
                                }
                            }
                            if (in_array('discriminator_type', $listTableColumns) && !isset($value['discriminator_type'])) {
                                $value['discriminator_type'] = str_replace('plg_customerplus_dtb_', '', $tableName);
                            }
                            break;
                        default:
                            foreach ($listTableColumns as $column) {
                                $value[$column] = isset($data[$column]) ? $data[$column] : null;
                            }
                            if (in_array('discriminator_type', $listTableColumns) && !isset($value['discriminator_type'])) {
                                $value['discriminator_type'] = str_replace('plg_customerplus_dtb_', '', $tableName);
                            }
                            break;
                    }

                    $builder->setValues($value);

                    if (($i % $batchSize) === 0) {
                        $builder->execute();
                    }
                    $i++;
                }
                if (count($builder->getValues()) > 0) {
                    $builder->execute();
                }
                fclose($handle);
            }

            if ($platform == 'mysql') {
                $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
            }
            $em->commit();

            $controller->addSuccess($tableName . ' のデータを移行しました。', 'admin');
        }
    }
}
