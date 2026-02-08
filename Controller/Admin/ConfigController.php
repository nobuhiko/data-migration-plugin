<?php

namespace Plugin\DataMigration43\Controller\Admin;

use Doctrine\DBAL\Connection;
use Eccube\Controller\AbstractController;
use Eccube\Service\PluginService;
use Eccube\Util\StringUtil;
use nobuhiko\BulkInsertQuery\BulkInsertQuery;
use Plugin\DataMigration43\Form\Type\Admin\ConfigType;
use Plugin\DataMigration43\Service\DataMigrationService;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

class ConfigController extends AbstractController
{
    /** @var pluginService */
    protected $pluginService;
    protected $dataMigrationService;

    /** @var array */
    protected $tax_rule = [];

    /** @var array */
    protected $delivery_time = [];

    /** @var Connection */
    protected $em;
    /** @var array */
    protected $delivery_id = [];
    /** @var array */
    protected $stock = [];
    /** @var array */
    public $shipping_id = [];
    /** @var array */
    protected $product_class_id = [];
    /** @var array */
    protected $order_item = [];
    /** @var array */
    protected $product_images = [];
    /** @var array */
    protected $baseinfo = [];
    /** @var array */
    protected $dtb_class_combination = [];
    /** @var array */
    protected $shipping_order = [];
    /** @var array */
    protected $customer_point = [];
    protected $memberIdSet = null; // array<int,bool>
    protected $missingCreatorIds = []; // array<int,bool>
    /** @var int|null ECCUBE2Downloads用: 2.xのダウンロード商品 product_type_id */
    protected $downloadProductTypeId = null;

    /**
     * constructor.
     *
     * @param pluginService $pluginService
     */
    public function __construct(
        PluginService $pluginService,
        DataMigrationService $dataMigrationService
    ) {
        $this->pluginService = $pluginService;
        $this->dataMigrationService = $dataMigrationService;
    }

    /**
     * @Route("/%eccube_admin_route%/datamigration43/config", name="data_migration43_admin_config")
     * @Template("@DataMigration43/admin/config.twig")
     */
    public function index(Request $request, Connection $em)
    {
        $this->delivery_id = [];
        $this->stock = [];
        $this->shipping_id = [];
        $this->product_class_id = [];
        $this->order_item = [];
        $this->product_images = [];

        $form = $this->createForm(ConfigType::class);
        $form->handleRequest($request);

        if (0 === strpos(PHP_OS, 'WIN')) {
            setlocale(LC_CTYPE, 'C');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em = $em;

            // logをオフにしてメモリを減らす
            $this->dataMigrationService->disableLogging($em);

            $formFile = $form['import_file']->getData();

            $tmpFile = $formFile->getClientOriginalName();
            $tmpDir = $this->pluginService->createTempDir();
            $formFile->move($tmpDir, $tmpFile);

            $csvDir = $this->dataMigrationService->setMigrationVersion($em, $tmpDir, $tmpFile);

            if ($this->dataMigrationService->isVersion('2.4.4')) {
                // create dtb_shipping
                $this->fix24Shipping($em, $csvDir);
                $this->fix24ProductsClass($em, $csvDir);
            } elseif ($this->dataMigrationService->isVersion('3')) {
                $this->fixPlgPoint($em, $csvDir); // ポイントプラグイン
            }

            // 2.13以外全部
            if (!file_exists($csvDir . 'dtb_tax_rule.csv')) {
                // 税率など
                $this->fix24baseinfo($em, $csvDir);
            }

            if ($this->dataMigrationService->isVersion('4.0/4.1')) {
                // 権限/メンバーを先に処理（外部キー制約のため）
                $this->upsertAuthorityAndMember($em, $csvDir);
                $this->collectMissingCreatorIds($csvDir, [
                    'dtb_delivery',
                    'dtb_delivery_time',
                    'dtb_delivery_fee',
                    'dtb_payment',
                    'dtb_order',
                    'dtb_shipping',
                    'dtb_mail_history'
                ]);

                // $csvDir 内のファイルをすべて読み込む
                // PostgreSQLはUPSERT方式を使うため、TRUNCATE不要
                $files = scandir($csvDir);
                foreach ($files as $file) {
                    // csvファイルのみ処理
                    if (is_file($csvDir . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'csv') {
                        // dtb_member, dtb_plugin はスキップ（別途処理済み or 処理不要）
                        if ($file !== 'dtb_member.csv' && $file !== 'dtb_plugin.csv') {
                            $this->fix4x($em, $csvDir, $file);
                        }
                    }
                }
            } else {
                if ($form['customer_order_only']->getData()) {
                    // 会員・受注のみ移行
                    $this->saveCustomerAndOrder($em, $csvDir);
                } else {
                    // 権限/メンバーは最終的に UPSERT (PostgreSQL) / 再投入 (MySQL)。
                    // dtb_member は一旦全件を非稼働(work_id=0)にした上で CSV の内容を反映。
                    $this->upsertAuthorityAndMember($em, $csvDir);
                    $this->collectMissingCreatorIds($csvDir, [
                        'dtb_delivery',
                        'dtb_delivery_time',
                        'dtb_delivery_fee',
                        'dtb_payment',
                        'dtb_order',
                        'dtb_shipping',
                        'dtb_mail_history'
                    ]);

                    // 全データ移行
                    $this->saveCustomer($em, $csvDir);
                    $this->saveProduct($em, $csvDir);
                    $this->saveOrder($em, $csvDir);
                }

                // plg_customerplusの移行処理を作る
                if ($this->dataMigrationService->isVersion('2') && $this->dataMigrationService->isPluginInstalled($em, 'CustomerPlus42')) {
                    $this->dataMigrationService->migrateCustomerPlus($em, $csvDir, $this);
                }
            }

            // 削除
            $fs = new Filesystem();
            $fs->remove($tmpDir);
            // .envのECCUBE_AUTH_MAGICを書き換える
            $this->dataMigrationService->updateEnv($form['auth_magic']->getData());

            // 存在しないルート名を修正
            return $this->redirectToRoute('data_migration43_admin_config');
        }

        // バリデーションエラー時の内容を確認
        if ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addDanger($error->getMessage(), 'admin');
            }
        }

        return [
            'form' => $form->createView(),
            'max_upload_size' => $this->dataMigrationService->checkUploadSize(),
        ];
    }


    private function saveCustomerAndOrder($em, $csvDir)
    {
        $platform = $this->dataMigrationService->begin($em, "CustomerAndOrder");

        // 先に権限/メンバーを反映し creator_id の参照整合性を確保
        //$this->upsertAuthorityAndMember($em, $csvDir);
        $this->collectMissingCreatorIds($csvDir, [
            'dtb_delivery',
            'dtb_delivery_time',
            'dtb_delivery_fee',
            'dtb_payment',
            'dtb_order',
            'dtb_shipping',
            'dtb_mail_history'
        ]);

        // 会員
        $this->saveToC($em, $csvDir, 'dtb_customer');

        if ($this->dataMigrationService->isVersion('4.0/4.1')) {
            $this->saveToC($em, $csvDir, 'dtb_customer_address');
            $this->saveToO($em, $csvDir, 'dtb_delivery_time');
        } else if ($this->dataMigrationService->isVersion('3')) {
            $this->saveToC($em, $csvDir, 'dtb_customer_address');
            $this->saveToO($em, $csvDir, 'dtb_delivery_time');
        } else {
            $this->saveToC($em, $csvDir, 'dtb_other_deliv', 'dtb_customer_address', false, 1/*$index*/);
        }

        // 受注
        $this->saveToO($em, $csvDir, 'dtb_order');
        $this->saveToO($em, $csvDir, 'dtb_shipping');
        $this->saveToO($em, $csvDir, 'dtb_mail_history', 'dtb_mail_history');
        if ($this->dataMigrationService->isVersion('4.0/4.1')) {
            $this->saveToO($em, $csvDir, 'dtb_order_item');
        } else {
            $this->saveToO($em, $csvDir, 'dtb_order_detail', 'dtb_order_item', true);
        }

        if (!empty($this->order_item)) {
            // すでに移行されている税率設定から取得する
            $sql = 'SELECT * FROM dtb_tax_rule WHERE product_id IS NULL AND product_class_id IS NULL ORDER BY apply_date DESC';
            $stmt = $em->query($sql);
            $tax_rules = $stmt->fetchAllAssociative();
            foreach ($tax_rules as $tax_rule) {
                $this->tax_rule[$tax_rule['apply_date']] = [
                    'rounding_type_id' => $tax_rule['rounding_type_id'],
                    'tax_rate' => $tax_rule['tax_rate'],
                    'apply_date' => $tax_rule['apply_date'],
                ];
            }
            $this->saveOrderItem($em);
        }

        if ($platform == 'mysql') {
            $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
        } else {
            $this->dataMigrationService->setIdSeq($em, 'dtb_customer');
            $this->dataMigrationService->setIdSeq($em, 'dtb_customer_address');
            $this->dataMigrationService->setIdSeq($em, 'dtb_order');
            $this->dataMigrationService->setIdSeq($em, 'dtb_order_item');
            $this->dataMigrationService->setIdSeq($em, 'dtb_shipping');
            $this->dataMigrationService->setIdSeq($em, 'dtb_mail_history');
        }

        $em->commit();
        $this->addSuccess('会員データ・受注データを登録しました。', 'admin');
    }

    private function saveCustomer($em, $csvDir)
    {
        // 会員系
        if (file_exists($csvDir . 'dtb_customer.csv') && filesize($csvDir . 'dtb_customer.csv') > 0) {

            $platform = $this->dataMigrationService->begin($em, "Customer");

            $this->saveToC($em, $csvDir, 'mtb_job', null, true);
            $this->saveToC($em, $csvDir, 'mtb_sex', null, true);

            if ($this->dataMigrationService->isVersion('4.0/4.1')) {
                $this->saveToC($em, $csvDir, 'mtb_customer_order_status', null, true);
                $this->saveToC($em, $csvDir, 'mtb_customer_status', null, true);
            }

            $this->saveToC($em, $csvDir, 'dtb_customer');
            if ($this->dataMigrationService->isVersion('4.0/4.1')) {
                $this->saveToC($em, $csvDir, 'dtb_customer_address');
            } else if ($this->dataMigrationService->isVersion('3')) {
                // fixme 余計なデータが移行される
                $this->saveToC($em, $csvDir, 'dtb_customer_address');
            } else {
                $this->saveToC($em, $csvDir, 'dtb_other_deliv', 'dtb_customer_address', false, 1);
            }



            if ($platform == 'mysql') {
                $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
            } else {
                $this->dataMigrationService->setIdSeq($em, 'dtb_member');
                $this->dataMigrationService->setIdSeq($em, 'dtb_customer');
                $this->dataMigrationService->setIdSeq($em, 'dtb_customer_address');
            }
            $em->commit();

            $this->addSuccess('会員データ登録しました。', 'admin');
        } else {
            $this->addDanger('会員データが見つかりませんでした', 'admin');
        }
    }

    private function saveToC($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
        $this->dataMigrationService->resetTable($em, $tableName);

        if (!file_exists($tmpDir . $csvName . '.csv')) {
            return; // CSV 無し
        }
        if (filesize($tmpDir . $csvName . '.csv') === 0) {
            return; // 空
        }

        if (($handle = fopen($tmpDir . $csvName . '.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            $key = array_filter(array_map('trim', $key));

            $columns = $em->getSchemaManager()->listTableColumns($tableName);
            $listTableColumns = [];
            foreach ($columns as $column) {
                $listTableColumns[] = $column->getName();
            }

            $builder = new BulkInsertQuery($em, $tableName);
            $builder->setColumns($listTableColumns);
            $batchSize = 20;

            while (($row = fgetcsv($handle)) !== false) {
                $value = [];
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                foreach ($listTableColumns as $column) {
                    if ($this->dataMigrationService->isVersion('4.0/4.1') == true) {
                        if ($column == 'sort_no') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'creator_id') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : date('Y:m:d H:i:s');
                        } elseif ($column == 'login_date' || $column == 'first_buy_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        } elseif ($column == 'point') {
                            $value[$column] = empty($data[$column]) ? 0 : (int) $data[$column];
                        } elseif ($column == 'two_factor_auth_enabled' && ($tableName == 'dtb_member' || $tableName == 'dtb_customer')) {
                            // 4.0系には存在しないカラム。デフォルト値として0（無効）を設定
                            $value[$column] = isset($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'two_factor_auth_key' && ($tableName == 'dtb_member' || $tableName == 'dtb_customer')) {
                            // 4.0系には存在しないカラム。NULLを設定
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    } else {
                        if ($column == 'id' && $tableName == 'dtb_customer') { // fixme
                            $value[$column] = $data['customer_id'];
                        } elseif ($column == 'customer_status_id') {
                            $value[$column] = ($data['del_flg'] == 1) ? '3' : $data['status'];
                        } elseif ($column == 'postal_code') {
                            $value[$column] = mb_substr(mb_convert_kana($data['zip01'] . $data['zip02'], 'a'), 0, 8);
                            if (empty($value[$column])) {
                                $value[$column] = null;
                            }
                        } elseif ($column == 'phone_number') {
                            $value[$column] = mb_substr(mb_convert_kana($data['tel01'] . $data['tel02'] . $data['tel03'], 'a'), 0, 14);
                            if (empty($value[$column])) {
                                $value[$column] = null;
                            }
                        } elseif ($column == 'sex_id') {
                            $value[$column] = empty($data['sex']) ? null : $data['sex'];
                        } elseif ($column == 'job_id') {
                            $value[$column] = empty($data['job']) ? null : $data['job'];
                        } elseif ($column == 'pref_id') {
                            $value[$column] = empty($data['pref']) ? null : $data['pref'];
                        } elseif ($column == 'work_id') {
                            $value[$column] = ($data['del_flg'] == 1) ? 0 : $data['work'];
                        } elseif ($column == 'authority_id') {
                            $value[$column] = $data['authority'];
                        } elseif ($column == 'email') {
                            if ($data['del_flg'] == 1) {
                                $value[$column] = StringUtil::random(60) . '@dummy.dummy';
                            } else {
                                $value[$column] = empty($data[$column]) ? 'Not null violation' : $data[$column];
                            }
                        } elseif ($column == 'password' || $column == 'name01' || $column == 'name02') {
                            $value[$column] = empty($data[$column]) ? 'Not null violation' : $data[$column];
                        } elseif ($column == 'sort_no') {
                            $value[$column] = $this->dataMigrationService->isVersion('4.0/4.1') ? $data['sort_no'] : $data['rank'];
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = (isset($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : date('Y-m-d H:i:s');
                        } elseif ($column == 'login_date' || $column == 'first_buy_date') {
                            $value[$column] = (!empty($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : null;
                        } elseif ($column == 'secret_key') {
                            $value[$column] = uniqid('secret_key_' . mt_rand() . '.', true);
                        } elseif ($column == 'point') {
                            if ($this->dataMigrationService->isVersion('3') == true && isset($this->customer_point[$data['customer_id']])) {
                                $value[$column] = $this->customer_point[$data['customer_id']]['plg_point_current'];
                            } else {
                                $value[$column] = empty($data[$column]) ? 0 : (int) $data[$column];
                            }
                        } elseif ($column == 'salt') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        } elseif ($column == 'creator_id') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
                        } elseif ($column == 'two_factor_auth_enabled' && $tableName == 'dtb_member') {
                            // 4.0系には存在しないカラム。デフォルト値として0（無効）を設定
                            $value[$column] = isset($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'plg_mailmagazine_flg') {
                            $value[$column] = (!empty($data['mailmaga_flg']) && $data['mailmaga_flg'] != 3) ? 1 : 0;
                        } elseif ($column == 'id' && $tableName == 'dtb_member') {
                            $value[$column] = $data['member_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_customer_address') {
                            $value[$column] = $i; // 連番
                        } elseif ($column == 'discriminator_type') {
                            $search = ['dtb_', 'mtb_', '_'];
                            $value[$column] = str_replace($search, '', $tableName);
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    }
                }

                $value = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $value);
                $builder->setValues($value);

                if (($i % $batchSize) === 0) {
                    try {
                        $builder->execute();
                    } catch (\Exception $e) {
                        error_log("BulkInsertQuery execute error in saveToC table '$tableName' at row $i: " . $e->getMessage());
                        error_log("Failed data for row $i: " . json_encode($value));
                        error_log("Original CSV data: " . json_encode($data));
                        throw $e;
                    }
                }
                $i++;
            }

            if (count($builder->getValues()) > 0) {
                try {
                    $builder->execute();
                } catch (\Exception $e) {
                    error_log("BulkInsertQuery final execute error in saveToC table '$tableName': " . $e->getMessage());
                    error_log("Failed final batch, data count: " . count($builder->getValues()));
                    throw $e;
                }
            }

            fclose($handle);
            return $i; // index
        }
    }

    private function saveProduct($em, $csvDir)
    {
        if ($this->dataMigrationService->isVersion('4.0/4.1')) {
            $product_db_name = 'dtb_product';
        } else if ($this->dataMigrationService->isVersion('3')) {
            $product_db_name = 'dtb_product';
        } else {
            $product_db_name = 'dtb_products';
        }

        if (file_exists($csvDir . $product_db_name . '.csv') && filesize($csvDir . $product_db_name . '.csv') > 0) {
            $platform = $this->dataMigrationService->begin($em, "Product");

            // ECCUBE2Downloads がインストール済みの場合、ダウンロード商品の product_type_id を検出
            if (file_exists($csvDir . 'mtb_product_type.csv') && $this->dataMigrationService->isPluginInstalled($em, 'ECCUBE2Downloads')) {
                $csvFile = $csvDir . 'mtb_product_type.csv';
                if (($handle = fopen($csvFile, 'r')) !== false) {
                    $key = fgetcsv($handle);
                    $key = array_filter(array_map('trim', $key));
                    while (($row = fgetcsv($handle)) !== false) {
                        $data = array_combine($key, $row);
                        $name = $data['name'] ?? '';
                        if (mb_strpos($name, 'ダウンロード') !== false) {
                            $this->downloadProductTypeId = (int)($data['id'] ?? 0);
                            break;
                        }
                    }
                    fclose($handle);
                }
            }

            // 2.11系の処理
            if (file_exists($csvDir . 'dtb_class_combination.csv')) {
                $this->fix211classCombination($em, $platform, $csvDir);
            }

            if ($this->dataMigrationService->isVersion('3')) {
                // 依存関係順: class_name -> class_category -> category -> product -> product_class / others
                $this->saveToP($em, $csvDir, 'dtb_class_name');
                $this->saveToP($em, $csvDir, 'dtb_class_category');
                $this->saveToP($em, $csvDir, 'dtb_category');
                $this->saveToP($em, $csvDir, 'dtb_product');
                $this->saveToP($em, $csvDir, 'dtb_product_class');
                $this->saveToP($em, $csvDir, 'dtb_product_category');
                $this->saveToP($em, $csvDir, 'dtb_product_stock');
                $this->saveToP($em, $csvDir, 'dtb_product_image');
                $this->saveToP($em, $csvDir, 'dtb_product_tag');
                $this->saveToP($em, $csvDir, 'mtb_tag', 'dtb_tag');
                $this->saveToP($em, $csvDir, 'dtb_customer_favorite_product');
            } else {
                // 4.x 系 正しい依存関係順:
                // class_name(dtb_class) -> class_category -> category -> product -> product_class -> product_category -> tag -> product_tag -> favorites
                $this->saveToP($em, $csvDir, 'dtb_class', 'dtb_class_name');                 // 親: class
                $this->saveToP($em, $csvDir, 'dtb_classcategory', 'dtb_class_category');     // 参照: class
                $this->saveToP($em, $csvDir, 'dtb_category');                               // category (product_category が参照)
                $this->saveToP($em, $csvDir, 'dtb_products', 'dtb_product');                 // product (以降の多くが参照)
                $this->saveToP($em, $csvDir, 'dtb_products_class', 'dtb_product_class');     // 参照: product + class_category
                $this->saveToP($em, $csvDir, 'dtb_product_categories', 'dtb_product_category'); // 参照: product + category
                $this->saveToP($em, $csvDir, 'mtb_status', 'dtb_tag');                       // タグ (product_tag が参照?)
                $this->saveToP($em, $csvDir, 'dtb_product_status', 'dtb_product_tag');       // product_tag (参照: product + tag)
                $this->saveToP($em, $csvDir, 'dtb_customer_favorite_products', 'dtb_customer_favorite_product'); // 参照: product
                // 在庫 (product_class 参照済み後)
                $this->saveStock($em);
                // 画像 (product 参照)
                $this->saveProductImage($em);
            }
            if (file_exists($csvDir . 'mtb_product_type.csv')) {
                // デフォルト判定により discriminator / rank->sort_no マッピングは upsertMaster 内で自動適用
                $this->upsertMaster($em, $csvDir, 'mtb_product_type', 'mtb_sale_type', true);
            }

            // 削除済み商品を4系のデータ構造に合わせる
            $this->dataMigrationService->fixDeletedProduct($em);

            if ($platform == 'mysql') {
                $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
            } else {
                // シーケンスを進めてあげないといけない
                $this->dataMigrationService->setIdSeq($em, 'dtb_product');
                $this->dataMigrationService->setIdSeq($em, 'dtb_product_class');
                $this->dataMigrationService->setIdSeq($em, 'dtb_class_category');
                $this->dataMigrationService->setIdSeq($em, 'dtb_class_name');
                $this->dataMigrationService->setIdSeq($em, 'dtb_category');
                $this->dataMigrationService->setIdSeq($em, 'dtb_product_stock');
                $this->dataMigrationService->setIdSeq($em, 'dtb_product_image');
                $this->dataMigrationService->setIdSeq($em, 'dtb_product_tag');
                $this->dataMigrationService->setIdSeq($em, 'dtb_tag');
                $this->dataMigrationService->setIdSeq($em, 'dtb_customer_favorite_product');
                // PostgreSQLで mtb_sale_type をUPSERTした場合もシーケンス同期
                if (file_exists($csvDir . 'mtb_product_type.csv')) {
                    $this->dataMigrationService->setIdSeq($em, 'mtb_sale_type');
                }
            }

            $em->commit();

            $this->addSuccess('商品データを登録しました。', 'admin');
        } else {
            $this->addDanger('商品データがが見つかりませんでした', 'admin');
        }
    }

    private function saveToP($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
        // 通常: 既存のフルリセット (UPsert 対象マスタは saveProduct から upsertMaster 経由で別処理済)
        $this->dataMigrationService->resetTable($em, $tableName);

        if (file_exists($tmpDir . $csvName . '.csv') == false) {
            // 無視する
            return;
        }
        if (filesize($tmpDir . $csvName . '.csv') == 0) {
            // 無視する
            return;
        }

        if (($handle = fopen($tmpDir . $csvName . '.csv', 'r')) !== false) {
            // 文字コード問題が起きる可能性が高いので後で調整が必要になると思う
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            $columns = $em->getSchemaManager()->listTableColumns($tableName);
            $listTableColumns = [];
            foreach ($columns as $column) {
                $listTableColumns[] = $column->getName();
            }

            $builder = new BulkInsertQuery($em, $tableName);
            $builder->setColumns($listTableColumns);

            $batchSize = 20;

            while (($row = fgetcsv($handle)) !== false) {
                $value = [];

                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                // PostgreSQL対応: 数値フィールドの空文字をNULLに変換
                $data = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $data);

                // --- 前処理: リレーション整合性クレンジング (saveToP) ---
                if ($tableName === 'dtb_class_category') {
                    // class_name_id=0 もしくは class_id=0 (旧データ) は未設定扱いでスキップ
                    if ((isset($data['class_name_id']) && (int)$data['class_name_id'] === 0) || (isset($data['class_id']) && (int)$data['class_id'] === 0)) {
                        continue; // 次行へ
                    }
                }
                if ($tableName === 'dtb_product_class') {
                    // 存在しないカテゴリ参照は NULL に変更
                    foreach (['class_category_id1', 'class_category_id2', 'classcategory_id1', 'classcategory_id2'] as $catCol) {
                        if (isset($data[$catCol]) && $data[$catCol] !== null && $data[$catCol] !== '') {
                            $catId = (int)$data[$catCol];
                            if ($catId === 0) {
                                $data[$catCol] = null;
                            } else {
                                $exists = $em->fetchOne('SELECT 1 FROM dtb_class_category WHERE id = ?', [$catId]);
                                if (!$exists) {
                                    $data[$catCol] = null;
                                }
                            }
                        }
                    }
                }
                // --- 前処理ここまで ---

                // --- 前処理: リレーション整合性クレンジング ---
                if ($tableName === 'dtb_class_category') {
                    // class_name_id=0 は旧データの未設定値なのでスキップ
                    if (isset($data['class_name_id']) && (int)$data['class_name_id'] === 0) {
                        continue; // 次行へ
                    }
                }
                if ($tableName === 'dtb_product_class') {
                    // 存在しないカテゴリ参照は NULL に変更
                    foreach (['class_category_id1', 'class_category_id2'] as $catCol) {
                        if (isset($data[$catCol]) && $data[$catCol] !== null && $data[$catCol] !== '') {
                            $catId = (int)$data[$catCol];
                            if ($catId === 0) {
                                $data[$catCol] = null;
                            } else {
                                $exists = $em->fetchOne('SELECT 1 FROM dtb_class_category WHERE id = ?', [$catId]);
                                if (!$exists) {
                                    $data[$catCol] = null;
                                }
                            }
                        }
                    }
                }
                // --- 前処理ここまで ---


                if ($this->dataMigrationService->isVersion('3')) {
                    if (isset($data['class_category_id1'])) {
                        $data['classcategory_id1'] = $data['class_category_id1'];
                    }
                    if (isset($data['class_category_id2'])) {
                        $data['classcategory_id2'] = $data['class_category_id2'];
                    }
                    if (isset($data['class_category_id'])) {
                        $data['classcategory_id'] = $data['class_category_id'];
                    }
                    if (isset($data['class_name_id'])) {
                        $data['class_id'] = $data['class_name_id'];
                    }
                    if (isset($data['description_detail'])) {
                        $data['main_comment'] = $data['description_detail'];
                    }
                    if (isset($data['search_word'])) {
                        $data['comment3'] = $data['search_word'];
                    }
                }

                // Schemaにあわせた配列を作成する
                foreach ($listTableColumns as $column) {
                    if ($this->dataMigrationService->isVersion('4.0/4.1') == true) {
                        if ($column == 'class_category_id1') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        } elseif ($column == 'class_category_id2') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        } elseif ($column == 'stock_unlimited') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'sort_no') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'creator_id') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : date('Y:m:d H:i:s');
                        } elseif ($column == 'display_order_count') {
                            $value[$column] = empty($data[$column]) ? 0 : $data[$column];
                        } elseif ($column == 'visible') {
                            $value[$column] = empty($data[$column]) ? 0 : $data[$column];
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    } else {
                        if ($column == 'id' && $tableName == 'dtb_product') {
                            $value[$column] = $data['product_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_customer_favorite_product') {
                            $value[$column] = $i;
                        } elseif ($column == 'product_status_id') {
                            // 退会が追加された
                            $value[$column] = ($data['del_flg'] == 1) ? '3' : $data['status'];
                        } elseif ($column == 'price02') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'name') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : '';

                            // カラム名が違うので
                        } elseif ($column == 'description_list') {
                            $value[$column] = isset($data['main_list_comment'])
                                ? mb_substr($data['main_list_comment'], 0, 3999)
                                : null;
                        } elseif ($column == 'description_detail') {
                            $value[$column] = isset($data['main_comment'])
                                ? mb_substr($data['main_comment'], 0, 3999)
                                : null;
                        } elseif ($column == 'search_word') {
                            $value[$column] = isset($data['comment3'])
                                ? mb_substr($data['comment3'], 0, 3999)
                                : null;
                        } elseif ($column == 'free_area' && isset($data['sub_title1'])) {
                            $value[$column] = $data['sub_title1'] . "\n" . $data['sub_comment1'] . "\n"
                                . $data['sub_title2'] . "\n" . $data['sub_comment2'] . "\n"
                                . $data['sub_title3'] . "\n" . $data['sub_comment3'] . "\n"
                                . $data['sub_title4'] . "\n" . $data['sub_comment4'] . "\n"
                                . $data['sub_title5'] . "\n" . $data['sub_comment5'] . "\n";

                            // ---> dtb_product_class
                        } elseif ($column == 'sale_type_id') {
                            $productTypeId = isset($data['product_type_id']) ? (int)$data['product_type_id'] : 1;
                            if ($this->downloadProductTypeId && $productTypeId === $this->downloadProductTypeId) {
                                $value[$column] = 222; // ECCUBE2Downloads の販売種別ID
                            } else {
                                $value[$column] = $productTypeId;
                            }
                        } elseif ($column == 'class_category_id1') {
                            $value[$column] = !empty($data['classcategory_id1']) ? $data['classcategory_id1'] : null;

                            if (!empty($this->dtb_class_combination) && !empty($data['class_combination_id'])) {
                                $value[$column] = $this->dtb_class_combination[$data['class_combination_id']]['classcategory_id1'];
                            }
                        } elseif ($column == 'class_category_id2') {
                            $value[$column] = !empty($data['classcategory_id2']) ? $data['classcategory_id2'] : null;

                            if (!empty($this->dtb_class_combination) && !empty($data['class_combination_id'])) {
                                $value[$column] = $this->dtb_class_combination[$data['class_combination_id']]['classcategory_id2'];
                            }
                        } elseif ($column == 'delivery_fee') {
                            $value[$column] = (isset($data['delivery_fee']) && is_numeric($data['delivery_fee'])) ? $data['delivery_fee'] : null;
                        } elseif ($column == 'stock') {
                            $value[$column] = isset($data['stock']) && $data['stock'] !== ''
                                ? $data['stock']
                                : null;

                            // dtb_product_stock
                            // todo 2.4系の場合、データが足りない
                            $this->stock[$data['product_class_id']] = $value[$column];

                            // class_category
                        } elseif ($column == 'class_category_id') {
                            $value[$column] = !empty($data['classcategory_id']) ? $data['classcategory_id'] : 0;
                        } elseif ($column == 'class_name_id') {
                            $value[$column] = isset($data['class_id']) ? $data['class_id'] : null;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = (isset($data[$column]) && strpos($data[$column], '000') === false) ? self::convertTz($data[$column]) : date('Y-m-d H:i:s');
                        } elseif ($column == 'login_date' || $column == 'first_buy_date') {
                            $value[$column] = (!empty($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : null;
                        } elseif ($column == 'creator_id') {
                            $value[$column] = null; // 固定
                        } elseif ($column == 'stock_unlimited') {
                            $value[$column] = empty($data[$column]) ? 0 : 1;
                        } elseif ($column == 'sort_no') {
                            $value[$column] = $data['rank'];
                        } elseif ($column == 'hierarchy') {
                            $value[$column] = $data['level'];
                        } elseif ($column == 'id' && $tableName == 'dtb_product_class') {
                            $value[$column] = $data['product_class_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_category') {
                            $value[$column] = $data['category_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_class_category') {
                            $value[$column] = $data['classcategory_id'];
                        } elseif ($column == 'visible' && $tableName == 'dtb_class_category') {
                            $value[$column] = ($data['del_flg']) ? 0 : 1;
                        } elseif ($column == 'id' && $tableName == 'dtb_class_name') {
                            $value[$column] = $data['class_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_product_stock') {
                            $value[$column] = $data['product_stock_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_product_image') {
                            $value[$column] = $data['product_image_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_product_tag') {
                            $value[$column] = $i;
                        } elseif ($column == 'tag_id' && $tableName == 'dtb_product_tag') {
                            if ($this->dataMigrationService->isVersion('3')) {
                                $value[$column] = isset($data['tag']) && strlen($data['tag'] > 0) ? $data['tag'] : 0;
                            } else {
                                $value[$column] = isset($data['product_status_id']) && strlen($data['product_status_id'] > 0) ? $data['product_status_id'] : 0;
                            }
                            // 共通処理
                        } elseif ($column == 'discriminator_type') {
                            $search = ['dtb_', 'mtb_', '_'];
                            $value[$column] = str_replace($search, '', $tableName);
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }

                        // delivery_duration_id
                        if (isset($data['deliv_date_id'])) {
                            // delivery_date_id <-- deliv_date_id (dtb_products)
                            $this->delivery_id[$data['product_id']] = $data['deliv_date_id'];
                        }

                        // product_image
                        if (!empty($data['main_large_image'])) {
                            $this->product_images[$data['product_id']] = [$data['main_large_image']];
                        } elseif (!empty($data['main_image'])) {
                            $this->product_images[$data['product_id']] = [$data['main_image']];
                        } elseif (!empty($data['main_list_image'])) {
                            $this->product_images[$data['product_id']] = [$data['main_list_image']];
                        }
                        for ($sub_image_id = 1; $sub_image_id <= 6; $sub_image_id++) {
                            if (!empty($data['sub_large_image' . $sub_image_id])) {
                                $this->product_images[$data['product_id']][] = $data['sub_large_image' . $sub_image_id];
                            } elseif (!empty($data['sub_image' . $sub_image_id])) {
                                $this->product_images[$data['product_id']][] = $data['sub_image' . $sub_image_id];
                            }
                        }
                    }
                }

                // 別テーブルからのデータなど
                switch ($tableName) {
                    case 'dtb_product_class':
                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            $value['delivery_duration_id'] = !empty($this->delivery_id[$value['product_id']]) ? $this->delivery_id[$value['product_id']] : null;

                            // 244用
                            if ($this->dataMigrationService->isVersion('2.4.4')) {
                                $this->product_class_id[$data['product_id']][$data['classcategory_id1']][$data['classcategory_id2']] = $data['product_class_id'];
                            }

                            $value['currency_code'] = 'JPY'; // とりあえず固定

                            // del_flgの代わり
                            if (isset($data['status']) && $data['status'] == 1) {
                                $value['visible'] = $data['status']; // todo
                            } else {
                                $value['visible'] = !empty($data['del_flg']) ? 0 : 1;
                            }
                        } else {
                            $value['visible'] = empty($data['visible']) ? 0 : (int) $data['visible'];
                        }
                        break;
                    case 'dtb_customer_favorite_product':

                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            // 3系には del_flg がある
                            if (isset($data['del_flg']) && $data['del_flg'] == 1) {
                                unset($value);
                                continue 2;
                            }
                        }

                        break;
                }

                // PostgreSQL対応: 最終チェックで数値フィールドの空文字をNULLに変換
                $value = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $value);

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

            return $i; // indexを返す
        }
    }

    /**
     * 共通: PostgreSQL 用 mtb_* マスタ UPSERT 処理
     * options:
     *  - discriminator: string  discriminator_type の値
     *  - columnMappings: ['sourceCsvCol' => 'targetCol']  CSV->DB マッピング (存在しなければスキップ)
     */
    private function upsertMasterFromCsv($em, $dir, $csvName, $tableName, array $options = [])
    {
        $file = $dir . $csvName . '.csv';
        if (!file_exists($file) || filesize($file) === 0) {
            return 0;
        }
        if (($handle = fopen($file, 'r')) === false) {
            return 0;
        }
        $key = fgetcsv($handle);
        $key = array_filter(array_map('trim', $key));
        if (empty($key)) {
            fclose($handle);
            return 0;
        }
        $columns = $em->getSchemaManager()->listTableColumns($tableName);
        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }
        $updateCols = array_filter($listTableColumns, function ($c) {
            return $c !== 'id' && $c !== 'create_date';
        });
        $discriminator = $options['discriminator'] ?? null;
        $colMap = $options['columnMappings'] ?? [];
        $rowCount = 0;
        $now = date('Y-m-d H:i:s');
        while (($row = fgetcsv($handle)) !== false) {
            $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));
            $data = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $data);
            // 任意のカラムマッピング (CSV->DB) 例: rank -> sort_no
            foreach ($colMap as $src => $dest) {
                if (!isset($data[$dest]) && isset($data[$src])) {
                    $data[$dest] = $data[$src];
                }
            }
            $insertValues = [];
            foreach ($listTableColumns as $col) {
                if ($col === 'discriminator_type') {
                    $insertValues[$col] = $discriminator ?? ($data[$col] ?? null);
                } elseif (array_key_exists($col, $data)) {
                    $insertValues[$col] = $data[$col];
                } else {
                    $insertValues[$col] = null;
                }
            }
            if (!isset($insertValues['id']) || $insertValues['id'] === '' || $insertValues['id'] === null) {
                continue; // PK 無し
            }
            // name 空文字補完 (存在する場合)
            if (array_key_exists('name', $insertValues) && ($insertValues['name'] === null || $insertValues['name'] === '')) {
                $insertValues['name'] = '';
            }
            if (array_key_exists('sort_no', $insertValues) && ($insertValues['sort_no'] === null || $insertValues['sort_no'] === '')) {
                $insertValues['sort_no'] = is_numeric($insertValues['id']) ? (int) $insertValues['id'] : 0;
            }
            foreach (['create_date', 'update_date'] as $dcol) {
                if (array_key_exists($dcol, $insertValues) && ($insertValues[$dcol] === null || $insertValues[$dcol] === '' || strpos((string)$insertValues[$dcol], '0000') === 0)) {
                    $insertValues[$dcol] = $now;
                }
            }
            $colsSql = implode(',', array_map(fn($c) => '"' . $c . '"', array_keys($insertValues)));
            $placeholders = implode(',', array_fill(0, count($insertValues), '?'));
            $updateSql = implode(', ', array_map(function ($c) {
                return '"' . $c . '" = EXCLUDED."' . $c . '"';
            }, $updateCols));
            $sql = 'INSERT INTO ' . $tableName . ' (' . $colsSql . ') VALUES (' . $placeholders . ') ON CONFLICT (id) DO UPDATE SET ' . $updateSql;
            $em->prepare($sql)->executeStatement(array_values($insertValues));
            $rowCount++;
        }
        fclose($handle);
        return $rowCount;
    }

    /**
     * 上位ラッパ: mtb_* マスタを PostgreSQL では UPSERT / それ以外は既存 saveToP
     * @param string $csvName 読み取るCSV(元)ファイル名 (拡張子抜き)
     * @param string|null $tableName 挿入先テーブル (省略時 = $csvName)
     * @param bool $allow_zero 既存 saveToP の互換引数
     * @param array $options upsertMasterFromCsv に渡すオプション (discriminator, columnMappings など)
     */
    private function upsertMaster($em, $dir, $csvName, $tableName = null, $allow_zero = false, array $options = [])
    {
        $tableName = $tableName ?: $csvName;
        $isPostgres = $em->getDatabasePlatform()->getName() === 'postgresql';
        if ($isPostgres) {
            $mergedOptions = $this->buildMasterUpsertOptions($tableName, $options);
            return $this->upsertMasterFromCsv($em, $dir, $csvName, $tableName, $mergedOptions);
        }
        // MySQL 等: 従来どおり全消し後インサート
        return $this->saveToP($em, $dir, $csvName, $tableName, $allow_zero);
    }

    /**
     * 指定 mtb_* テーブル向け UPSERT オプションのデフォルト構築 + マージ
     * @param string $tableName 実テーブル名
     * @param array $override 呼び出し側オプション(優先)
     * @return array マージ済オプション(discriminator, columnMappings 等)
     */
    private function buildMasterUpsertOptions(string $tableName, array $override): array
    {
        // すべての mtb_* で共通: rank -> sort_no マッピングを基本付与
        $base = [
            'columnMappings' => ['rank' => 'sort_no'],
        ];

        // discriminator 未指定なら自動生成: mtb_ プレフィックス除去しアンダースコア除去
        // 例) mtb_sale_type -> saletype, mtb_device_type -> devicetype
        if (!isset($override['discriminator'])) {
            if (strpos($tableName, 'mtb_') === 0) {
                $discriminator = substr($tableName, 4); // プレフィックス除去
            } else {
                $discriminator = $tableName;
            }
            $discriminator = str_replace('_', '', $discriminator);
            $base['discriminator'] = $discriminator;
        }

        // オーバーライド: columnMappings はマージ (override 優先)
        if (isset($override['columnMappings'])) {
            $base['columnMappings'] = array_merge($base['columnMappings'], (array)$override['columnMappings']);
        }
        foreach ($override as $k => $v) {
            if ($k === 'columnMappings') {
                continue;
            }
            $base[$k] = $v; // 上書き (discriminator 等)
        }
        return $base;
    }

    /**
     * mtb_authority と dtb_member の最終同期:
     *  - PostgreSQL: mtb_authority を汎用 UPSERT, その後 dtb_member をカスタム UPSERT (全件 work_id=0 強制)
     *  - MySQL: 既存 truncate+insert(saveToC) 後に work_id=0 へ更新
     */
    protected function upsertAuthorityAndMember($em, $dir)
    {
        $platform = $this->dataMigrationService->begin($em);
        $authorityCsv = $dir . 'mtb_authority.csv';
        $memberCsv    = $dir . 'dtb_member.csv';

        $hasAuthority = file_exists($authorityCsv) && filesize($authorityCsv) > 0;
        $hasMember    = file_exists($memberCsv) && filesize($memberCsv) > 0;
        if (!$hasAuthority && !$hasMember) {
            return; // どちらも無し
        }

        if ($platform === 'postgresql') {
            if ($hasAuthority) {
                // 権限マスタを汎用 UPSERT (discriminator 付与)
                $this->upsertMaster($em, $dir, 'mtb_authority', null, true, [
                    'discriminator' => 'authority'
                ]);
            }
            if ($hasMember) {
                // 既存メンバーを一旦非稼働化
                $em->exec('UPDATE dtb_member SET work_id = 0');
                // CSV を読み取り UPSERT
                $file = $memberCsv;
                if (($handle = fopen($file, 'r')) === false) {
                    return;
                }
                $key = fgetcsv($handle);
                $key = array_filter(array_map('trim', $key));
                if (empty($key)) {
                    fclose($handle);
                    return;
                }
                $columns = $em->getSchemaManager()->listTableColumns('dtb_member');
                $listTableColumns = [];
                foreach ($columns as $c) {
                    $listTableColumns[] = $c->getName();
                }
                $updateCols = array_filter($listTableColumns, fn($c) => $c !== 'id' && $c !== 'create_date');
                $now = date('Y-m-d H:i:s');
                while (($row = fgetcsv($handle)) !== false) {
                    $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));
                    $insertValues = [];
                    foreach ($listTableColumns as $col) {

                        if ($col === 'discriminator_type') {
                            $insertValues[$col] = $data[$col] ?? 'member';
                            continue;
                        }
                        // 4.0系のカラム名マッピング
                        if ($col === 'work' && !array_key_exists($col, $data) && array_key_exists('work_id', $data)) {
                            $insertValues[$col] = $data['work_id'];
                            continue;
                        }
                        if ($col === 'authority' && !array_key_exists($col, $data) && array_key_exists('authority_id', $data)) {
                            $insertValues[$col] = $data['authority_id'];
                            continue;
                        }
                        if (array_key_exists($col, $data)) {
                            $insertValues[$col] = $data[$col];
                        } else {
                            $insertValues[$col] = null;
                        }
                    }
                    if (!isset($insertValues['id']) || $insertValues['id'] === '') {
                        continue; // PK無
                    }
                    foreach (['create_date', 'update_date'] as $dcol) {
                        if (isset($insertValues[$dcol]) && (empty($insertValues[$dcol]) || strpos($insertValues[$dcol], '0000') === 0)) {
                            $insertValues[$dcol] = $now;
                        }
                    }
                    // login_dateなどのNULL許可のタイムスタンプカラムは、空文字列をnullに変換
                    foreach (['login_date', 'first_buy_date', 'last_buy_date', 'payment_date'] as $dcol) {
                        if (isset($insertValues[$dcol]) && (empty($insertValues[$dcol]) || strpos($insertValues[$dcol], '0000') === 0)) {
                            $insertValues[$dcol] = null;
                        }
                    }
                    // 4.0系には存在しないカラムのデフォルト値を設定
                    if (array_key_exists('two_factor_auth_enabled', $insertValues) && $insertValues['two_factor_auth_enabled'] === null) {
                        $insertValues['two_factor_auth_enabled'] = 0;
                    }
                    if (array_key_exists('two_factor_auth_key', $insertValues) && $insertValues['two_factor_auth_key'] === null) {
                        $insertValues['two_factor_auth_key'] = null; // NULL許可（この行は冗長だが明示的に残す）
                    }
                    $colsSql = implode(',', array_map(fn($c) => '"' . $c . '"', array_keys($insertValues)));
                    $placeholders = implode(',', array_fill(0, count($insertValues), '?'));
                    $updateSql = implode(', ', array_map(fn($c) => '"' . $c . '" = EXCLUDED."' . $c . '"', $updateCols));
                    $sql = 'INSERT INTO dtb_member (' . $colsSql . ') VALUES (' . $placeholders . ') ON CONFLICT (id) DO UPDATE SET ' . $updateSql;
                    $em->prepare($sql)->executeStatement(array_values($insertValues));
                }
                fclose($handle);
            }
            // メンバーIDキャッシュ
            try {
                $ids = $em->fetchFirstColumn('SELECT id FROM dtb_member');
                $this->memberIdSet = [];
                foreach ($ids as $id) {
                    $this->memberIdSet[(int)$id] = true;
                }
            } catch (\Exception $e) {
                $this->memberIdSet = [];
            }
        } else { // MySQL 他
            if ($hasAuthority) {
                $this->saveToC($em, $dir, 'mtb_authority', null, true);
            }
            if ($hasMember) {
                $this->saveToC($em, $dir, 'dtb_member', null, true);
                // work_idはsaveToC内でCSVから正しく設定されているため、ここで上書きしない
            }
            try {
                $ids = $em->fetchFirstColumn('SELECT id FROM dtb_member');
                $this->memberIdSet = [];
                foreach ($ids as $id) {
                    $this->memberIdSet[(int)$id] = true;
                }
            } catch (\Exception $e) {
                $this->memberIdSet = [];
            }
        }
        $this->addSuccess('管理者データを登録しました。', 'admin');
        $em->commit();
    }

    private function collectMissingCreatorIds(string $csvDir, array $csvNames): void
    {
        if ($this->memberIdSet === null) {
            return;
        }
        $newMissing = [];
        foreach ($csvNames as $name) {
            $path = $csvDir . $name . '.csv';
            if (!file_exists($path) || filesize($path) === 0) {
                continue;
            }
            if (($h = fopen($path, 'r')) === false) {
                continue;
            }
            $header = fgetcsv($h);
            if (!$header) {
                fclose($h);
                continue;
            }
            $header = array_map('trim', $header);
            $idx = array_search('creator_id', $header, true);
            if ($idx === false) {
                fclose($h);
                continue;
            }
            while (($row = fgetcsv($h)) !== false) {
                if (!isset($row[$idx]) || $row[$idx] === '' || strtoupper($row[$idx]) === 'NULL') {
                    continue;
                }
                $cid = (int)$row[$idx];
                if ($cid > 0 && !isset($this->memberIdSet[$cid])) {
                    $newMissing[$cid] = true;
                }
            }
            fclose($h);
        }
        if ($newMissing) {
            foreach ($newMissing as $k => $_) {
                $this->missingCreatorIds[$k] = true;
            }
            $all = array_keys($this->missingCreatorIds);
            sort($all);
            $preview = array_slice($all, 0, 10);
            $this->addWarning('存在しない creator_id 検出: ' . count($all) . ' 件 (例: ' . implode(',', $preview) . (count($all) > 10 ? '...' : '') . ') は 1 にフォールバックします。', 'admin');
        }
    }

    private function fix24shipping($em, $tmpdir)
    {
        if (($handle = fopen($tmpdir . 'dtb_order.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keysize = count($key);

            $i = 1;
            $add_value = [];
            while (($row = fgetcsv($handle)) !== false) {
                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertnull(array_combine($key, $row));

                $value = [];

                foreach ($data as $k => $v) {
                    $value[str_replace('deliv_', 'shipping_', $k)] = $v;
                }

                $value['deliv_time_id'] = isset($data['deliv_time_id']) ? $data['deliv_time_id'] : null;
                $value['shipping_id'] = 0;
                $value['rank'] = 0;
                if (!empty($value['shipping_date'])) {
                    // 変な文字が来る 18/12/29(土)
                    preg_match_all('/[\d.]+/', $value['shipping_date'], $matches);
                    $value['shipping_date'] = date('y-m-d', mktime(0, 0, 0, $matches[0][1], $matches[0][2], '20' . $matches[0][0]));
                }
                $value['del_flg'] = $data['del_flg'];
                $value['order_id'] = $data['order_id'];
                $value['create_date'] = self::converttz($data['create_date']);
                $value['update_date'] = self::converttz($data['update_date']);
                $value['shipping_commit_date'] = self::converttz($data['commit_date']);

                $add_value[$i] = $value;
                $i++;
            }

            fclose($handle);

            $fpcsv = fopen($tmpdir . 'dtb_shipping.csv', 'a');

            foreach ($add_value as $row) {
                if ($row === reset($add_value)) {
                    // 最初
                    fputcsv($fpcsv, array_keys($row));
                }
                fputcsv($fpcsv, array_values($row));
            }
            fclose($fpcsv);
        }
    }

    // 2.4系のclassを追加する
    private function fix24ProductsClass($em, $tmpDir)
    {
        if (($handle = fopen($tmpDir . 'dtb_products_class.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            $i = -1;
            while (($row = fgetcsv($handle)) !== false) {
                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));
                // 規格がある場合,
                if ($data['classcategory_id1'] != 0 && $data['classcategory_id2'] != 0) {
                    $data['classcategory_id1'] = 0;
                    $data['classcategory_id2'] = 0;
                    $data['status'] = 1;
                    $data['product_class_id'] = $i; // 苦肉の策
                    $add_value[$data['product_id']] = $data;
                    $i--;
                }
            }

            fclose($handle);

            if (!empty($add_value)) {
                $fpcsv = fopen($tmpDir . 'dtb_products_class.csv', 'a');
                foreach ($add_value as $row) {
                    fputcsv($fpcsv, array_values($row));
                }
                fclose($fpcsv);
            }
        }
    }

    private function fix211classCombination($em, $platform, $tmpDir)
    {
        if (($handle = fopen($tmpDir . 'dtb_class_combination.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            if ($platform == 'mysql') {
                // mysql5.6でエラーになるのでtempは使えない
                $em->exec('
                    CREATE TABLE IF NOT EXISTS dtb_class_combination (
                    class_combination_id int NOT NULL,
                    parent_class_combination_id int,
                    classcategory_id int NOT NULL,
                    level int,
                    PRIMARY KEY(class_combination_id)
                    ) ENGINE=InnoDB;
                ');
            } else {
                $em->exec('
                    CREATE TEMP TABLE dtb_class_combination (
                        class_combination_id int,
                        parent_class_combination_id int,
                        classcategory_id int,
                        level int,
                        PRIMARY KEY (class_combination_id)
                    );
                ');
            }

            $builder = new BulkInsertQuery($em, 'dtb_class_combination');
            $builder->setColumns(['class_combination_id', 'parent_class_combination_id', 'classcategory_id', 'level']);

            $i = 1;
            $batchSize = 20;
            while (($row = fgetcsv($handle)) !== false) {
                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                if (!$data['parent_class_combination_id']) {
                    $data['parent_class_combination_id'] = null;
                }

                $builder->setValues($data);

                if (($i % $batchSize) === 0) {
                    $builder->execute();
                }
            }
            if (count($builder->getValues()) > 0) {
                $builder->execute();
            }

            fclose($handle);
        }

        $stmt = $em->query('
        SELECT
        class_combination_id
        , (select classcategory_id from dtb_class_combination where class_combination_id = c1.parent_class_combination_id) as classcategory_id1
        , classcategory_id as classcategory_id2
        FROM dtb_class_combination as c1
        where parent_class_combination_id is not null
        ');
        $all = $stmt->fetchAllAssociative();

        $this->dtb_class_combination = [];
        foreach ($all as $line) {
            $this->dtb_class_combination[$line['class_combination_id']] = $line;
        }

        $stmt = $em->query('
        SELECT
        class_combination_id
        , classcategory_id as classcategory_id1
        , NULL as classcategory_id2
        FROM dtb_class_combination as c1
        where parent_class_combination_id is null
        ');
        $all = $stmt->fetchAllAssociative();

        foreach ($all as $line) {
            $this->dtb_class_combination[$line['class_combination_id']] = $line;
        }
    }

    private function saveStock($em)
    {
        $tableName = 'dtb_product_stock';
        $columns = $em->getSchemaManager()->listTableColumns($tableName);

        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }

        $builder = new BulkInsertQuery($em, $tableName);
        $builder->setColumns($listTableColumns);

        $em->exec('DELETE FROM ' . $tableName);

        $i = 1;
        $batchSize = 20;
        foreach ($this->stock as $product_class_id => $stock) {
            $data['id'] = $i;
            $data['product_class_id'] = $product_class_id;
            $data['creator_id'] = null; // 固定 ?
            $data['stock'] = $stock;
            $data['create_date'] = $data['update_date'] = date('Y-m-d H:i:s');
            $data['discriminator_type'] = 'productstock';

            $builder->setValues($data);

            // 20件に1回SQLを発行してメモリを開放する。
            if (($i % $batchSize) === 0) {
                $builder->execute();
            }
            $i++;
        }
        if (count($builder->getValues()) > 0) {
            $builder->execute();
            sleep(1);
        }
    }

    private function saveProductImage($em)
    {
        $tableName = 'dtb_product_image';
        $columns = $em->getSchemaManager()->listTableColumns($tableName);

        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }

        $builder = new BulkInsertQuery($em, $tableName);
        $builder->setColumns($listTableColumns);

        $em->exec('DELETE FROM ' . $tableName);

        $i = 1;
        $batchSize = 20;
        foreach ($this->product_images as $product_id => $file_names) {
            foreach ($file_names as $image_id => $file_name) {
                $data['id'] = $i;
                $data['product_id'] = $product_id;
                $data['creator_id'] = null;
                $data['file_name'] = $file_name;
                $data['sort_no'] = $image_id + 1;

                $data['create_date'] = date('Y-m-d H:i:s');
                $data['discriminator_type'] = 'productimage';

                $builder->setValues($data);

                // 20件に1回SQLを発行してメモリを開放する。
                if (($i % $batchSize) === 0) {
                    $builder->execute();
                }
                $i++;
            }
        }
        if (count($builder->getValues()) > 0) {
            $builder->execute();
            sleep(1);
        }
    }



    private function saveOrder($em, $csvDir)
    {
        // 会員系
        if (file_exists($csvDir . 'dtb_order.csv') && filesize($csvDir . 'dtb_order.csv') > 0) {
            $platform = $this->dataMigrationService->begin($em, "Order");

            // 2.4には存在しないデータ
            if (!$this->dataMigrationService->isVersion('2.4.4')) {
                $this->upsertMaster($em, $csvDir, 'mtb_device_type', null, true);
            }
            // todo mtb_order_status.display_order_count
            $this->upsertMaster($em, $csvDir, 'mtb_device_type', null, true);

            if ($this->dataMigrationService->isVersion('3')) {
                // 挿入は 親→子 の順 (親が存在している必要があるため)
                $this->saveToO($em, $csvDir, 'dtb_delivery');      // 親
                $this->saveToO($em, $csvDir, 'dtb_delivery_time'); // 子
                $this->saveToO($em, $csvDir, 'dtb_delivery_fee');

                $this->saveToO($em, $csvDir, 'dtb_payment');
                $this->saveToO($em, $csvDir, 'dtb_order');
                $this->saveToO($em, $csvDir, 'dtb_mail_history');
            } else {
                // 2.x 系 (dtb_deliv / dtb_delivtime) も同様に子→親削除 + 親→子挿入
                $this->saveToO($em, $csvDir, 'dtb_deliv', 'dtb_delivery');          // 親
                $this->saveToO($em, $csvDir, 'dtb_delivtime', 'dtb_delivery_time'); // 子
                $this->saveToO($em, $csvDir, 'dtb_delivfee', 'dtb_delivery_fee');

                $this->saveToO($em, $csvDir, 'dtb_payment');
                $this->saveToO($em, $csvDir, 'dtb_order');
                $this->saveToO($em, $csvDir, 'dtb_mail_history', 'dtb_mail_history');
            }

            // fixme dtb_delivery_time のあとにやらなければダメ
            $this->saveToO($em, $csvDir, 'dtb_shipping');

            if (!isset($this->product_class_id)) {
                sleep(5);
            }
            // todo 商品別税率設定
            $this->saveToO($em, $csvDir, 'dtb_tax_rule', null, true); // 税率0にしている場合がある

            // todo ダウンロード販売の処理
            if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                $this->saveToO($em, $csvDir, 'dtb_order_detail', 'dtb_order_item', true);
            } else {
                // v4
                $this->saveToO($em, $csvDir, 'dtb_order_item');
                $this->saveToO($em, $csvDir, 'dtb_order_pdf');
                $this->saveToO($em, $csvDir, 'dtb_payment_option');
            }

            if (!empty($this->order_item)) {
                $this->saveOrderItem($em);
            }

            if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                // 支払いは基本移行しない
                $em->exec('DELETE FROM dtb_payment_option');
            }

            if ($platform == 'mysql') {
                $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
            } else {
                $this->dataMigrationService->setIdSeq($em, 'mtb_device_type');
                $this->dataMigrationService->setIdSeq($em, 'dtb_order');
                $this->dataMigrationService->setIdSeq($em, 'dtb_order_item');
                $this->dataMigrationService->setIdSeq($em, 'dtb_shipping');
                $this->dataMigrationService->setIdSeq($em, 'dtb_payment');
                $this->dataMigrationService->setIdSeq($em, 'dtb_delivery');
                $this->dataMigrationService->setIdSeq($em, 'dtb_delivery_fee');
                $this->dataMigrationService->setIdSeq($em, 'dtb_delivery_time');
                $this->dataMigrationService->setIdSeq($em, 'dtb_tax_rule');
                $this->dataMigrationService->setIdSeq($em, 'dtb_mail_history');
            }

            // イレギュラー対応
            $em->exec('UPDATE dtb_order SET order_status_id = NULL WHERE order_status_id not in (select id from mtb_order_status)');

            $em->commit();

            $this->addSuccess('受注データを登録しました。', 'admin');
        } else {
            $this->addDanger('受注データが見つかりませんでした', 'admin');
        }
    }

    private function saveToO($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
        // 通常: リセット (UPSERT 対象は saveOrder で upsertMaster 呼び出し済のためここに来ない想定)
        $this->dataMigrationService->resetTable($em, $tableName);
        $creatorFallbackApplied = 0;

        if (file_exists($tmpDir . $csvName . '.csv') == false) {
            // 無視する
            //$this->addDanger($csvName.'.csv が見つかりませんでした' , 'admin');
            return;
        }
        if (filesize($tmpDir . $csvName . '.csv') == 0) {
            // 無視する
            $this->addWarning($csvName . '.csv のデータがありません。', 'admin');

            return;
        }

        if (($handle = fopen($tmpDir . $csvName . '.csv', 'r')) !== false) {
            // 文字コード問題が起きる可能性が高いので後で調整が必要になると思う
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            $columns = $em->getSchemaManager()->listTableColumns($tableName);
            $listTableColumns = [];
            foreach ($columns as $column) {
                $listTableColumns[] = $column->getName();
            }

            $builder = new BulkInsertQuery($em, $tableName);
            $builder->setColumns($listTableColumns);

            $batchSize = 20;

            while (($row = fgetcsv($handle)) !== false) {
                $value = [];

                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                // PostgreSQL対応: 数値フィールドの空文字をNULLに変換
                $data = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $data);

                // order_ の文字を除去
                foreach ($data as $k => $v) {
                    if ($tableName == 'dtb_order') {
                        $data[str_replace('order_', '', $k)] = $v;
                    } elseif ($tableName == 'dtb_shipping') {
                        $data[str_replace('shipping_', '', $k)] = $v;
                    }
                }

                // 3の差を埋める
                if ($this->dataMigrationService->isVersion('3')) {
                    if (isset($data['delivery_id'])) {
                        $data['deliv_id'] = $data['delivery_id'];
                    }
                    if ('dtb_order' === $tableName && isset($data['delivery_fee_total'])) {
                        $data['deliv_fee'] = $data['delivery_fee_total'];
                    }
                }

                // Schemaにあわせた配列を作成する
                foreach ($listTableColumns as $column) {
                    if ($this->dataMigrationService->isVersion('4.0/4.1') == true) {
                        if ($column == 'use_point') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'creator_id') {
                            $cid = isset($data[$column]) && $data[$column] !== '' ? (int)$data[$column] : 0;
                            if ($cid > 0 && $this->memberIdSet !== null && isset($this->memberIdSet[$cid]) && !isset($this->missingCreatorIds[$cid])) {
                                $value[$column] = $cid;
                            } else {
                                if ($cid > 0 && $this->memberIdSet !== null && !isset($this->memberIdSet[$cid])) {
                                    $creatorFallbackApplied++;
                                }
                                $value[$column] = 1;
                            }
                        } elseif ($column == 'tax_adjust') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'tax') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'tax_rule') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'tax_rate') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'fee') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : date('Y:m:d H:i:s');
                        } elseif ($column == 'payment_date' || $column == 'order_date' || $column == 'shipping_date') {
                            $value[$column] = (!empty($data[$column])) ? $data[$column] : null;
                        } elseif ($column == 'add_point') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'visible') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'quantity') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 0;
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    } else {
                        if ($column == 'id' && $tableName == 'dtb_payment') {
                            $value[$column] = $data['payment_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_delivery') {
                            $value[$column] = $data['deliv_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_delivery_fee') {
                            $value[$column] = $i; // todo
                        } elseif ($column == 'id' && $tableName == 'dtb_delivery_time') {
                            // deliv_idとtime_idで複合主キーだったのが、idのみの主キーとなったため、連番で付与する.
                            $value[$column] = $i;

                            // dtb_order.deliv_idとdtb_shipping.time_idでお届け時間を特定するため、ここで保持しておく.
                            $this->delivery_time[$data['deliv_id']][$data['time_id']] = $i;
                        } elseif ($column == 'order_status_id') {
                            // 退会が追加された
                            $value[$column] = ($data['del_flg'] == 1) ? '3' : $data['status'];

                            // 4系に存在しないstatusなので
                            if ($data['status'] == 2) {
                                $value[$column] = 4;
                            }
                            if ($data['status'] == '') {
                                $value[$column] = 3;
                            }
                        } elseif ($column == 'message' || $column == 'note') {
                            $value[$column] = empty($data[$column]) ? null : mb_substr($data[$column], 0, 4000);
                        } elseif ($column == 'postal_code') {
                            $value[$column] = mb_substr(mb_convert_kana($data['zip01'] . $data['zip02'], 'a'), 0, 8);
                            if (empty($value[$column])) {
                                $value[$column] = null;
                            }
                        } elseif ($column == 'phone_number') {
                            $value[$column] = mb_substr(mb_convert_kana($data['tel01'] . $data['tel02'] . $data['tel03'], 'a'), 0, 14); //14文字制限
                            if (empty($value[$column])) {
                                $value[$column] = null;
                            }
                        } elseif ($column == 'sex_id') {
                            $value[$column] = empty($data['sex']) ? null : $data['sex'];
                        } elseif ($column == 'job_id') {
                            $value[$column] = empty($data['job']) ? null : $data['job']; // 0が入っている場合あり?
                        } elseif ($column == 'pref_id') {
                            $value[$column] = empty($data['pref']) ? null : $data['pref'];
                        } elseif ($column == 'delivery_fee_total') {
                            $value[$column] = empty($data['deliv_fee']) ? 0 : $data['deliv_fee'];

                            // --> shipping
                        } elseif ($column == 'delivery_date') {
                            $value[$column] = empty($data['date']) ? null : $data['date'];
                        } elseif ($column == 'shipping_date') {
                            $value[$column] = empty($data['commit_date']) ? null : self::convertTz($data['commit_date']);
                        } elseif ($column == 'visible' /*&& $tableName == 'dtb_payment'*/) {
                            $value[$column] = 0;

                            // --> deliv
                        } elseif ($column == 'sale_type_id') {
                            $productTypeId = isset($data['product_type_id']) ? (int)$data['product_type_id'] : 1;
                            if ($this->downloadProductTypeId && $productTypeId === $this->downloadProductTypeId) {
                                $value[$column] = 222; // ECCUBE2Downloads の販売種別ID
                            } else {
                                $value[$column] = $productTypeId;
                            }
                        } elseif ($column == 'description') {
                            $value[$column] = isset($data['remark']) ? $data['remark'] : null;
                        } elseif ($column == 'delivery_id') {
                            $value[$column] = isset($data['deliv_id']) ? $data['deliv_id'] : null;
                        } elseif ($column == 'delivery_time') {
                            if (isset($data['deliv_time'])) {
                                $value[$column] = $data['deliv_time'];
                            } elseif (isset($data['delivery_time']) && strlen($data['delivery_time']) > 0) {
                                $value[$column] = $data['delivery_time'];
                            } else {
                                $value[$column] = null;
                            }
                        } elseif ($column == 'fee') {
                            $value[$column] = !empty($data['fee']) ? $data['fee'] : 0;
                            // --> payment
                        } elseif ($column == 'fixed') {
                            $value[$column] = 1;
                        } elseif ($column == 'rule_max') {
                            if ($this->dataMigrationService->isVersion('3')) {
                                $value[$column] = isset($data['rule_max']) && strlen($data['rule_max'] > 0 ? $data['rule_max'] : null);
                            } else {
                                // 2.13
                                $value[$column] = !empty($data['upper_rule']) ? $data['upper_rule'] : null;
                            }
                        } elseif ($column == 'rule_min') {
                            if ($this->dataMigrationService->isVersion('3')) {
                                $value[$column] = isset($data['rule_min']) && strlen($data['rule_min'] > 0 ? $data['rule_min'] : null);
                            } else {
                                // 2.13
                                $value[$column] = !empty($data['rule_max']) ? $data['rule_max'] : (!empty($data['rule']) ? $data['rule'] : null);
                            }
                            // --> dtb_order_item
                        } elseif ($column == 'class_category_name1') {
                            $value[$column] = isset($data['classcategory_name1']) && strlen($data['classcategory_name1']) > 0 ? $data['classcategory_name1'] : null;
                        } elseif ($column == 'class_category_name2') {
                            $value[$column] = isset($data['classcategory_name2']) && strlen($data['classcategory_name2']) > 0 ? $data['classcategory_name2'] : null;
                        } elseif ($column == 'name01' || $column == 'name02') {
                            $value[$column] = empty($data[$column]) ? 'Not null violation' : $data[$column];
                        } elseif ($column == 'sort_no' && $tableName == 'dtb_shipping') {
                            $value[$column] = $data['id'];
                        } elseif ($column == 'sort_no') {
                            $value[$column] = isset($data['rank']) ? $data['rank'] : 0;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = (isset($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : date('Y-m-d H:i:s');
                        } elseif ($column == 'payment_date') {
                            $value[$column] = (!empty($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : null;
                        } elseif ($column == 'creator_id') {
                            $cid = isset($data[$column]) && $data[$column] !== '' ? (int)$data[$column] : 0;
                            if ($cid > 0 && $this->memberIdSet !== null && isset($this->memberIdSet[$cid]) && !isset($this->missingCreatorIds[$cid])) {
                                $value[$column] = $cid;
                            } else {
                                if ($cid > 0 && $this->memberIdSet !== null && !isset($this->memberIdSet[$cid])) {
                                    $creatorFallbackApplied++;
                                }
                                $value[$column] = 1;
                            }
                        } elseif ($column == 'charge' || $column == 'use_point' || $column == 'add_point' || $column == 'discount' || $column == 'total' || $column == 'subtotal' || $column == 'tax' || $column == 'payment_total') {
                            $value[$column] = !empty($data[$column]) ? (int) $data[$column] : 0;
                        } elseif ($column == 'tax_adjust') {
                            $value['tax_adjust'] = 0; // 0固定
                        } elseif ($column == 'discriminator_type') {
                            $search = ['dtb_', 'mtb_', '_'];
                            $value[$column] = str_replace($search, '', $tableName);
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    }
                }

                // 別テーブルからのデータなど
                switch ($tableName) {
                    case 'dtb_order':
                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            // 配送ID
                            if (isset($data['deliv_id'])) {
                                $this->delivery_id[$data['id']] = $data['deliv_id'];
                            }
                            $value['order_no'] = $data['id'];
                            $value['order_date'] = self::convertTz($data['create_date']);
                            $value['currency_code'] = 'JPY'; // とりあえず固定

                            // 3は delivery_fee_total
                            if (isset($data['deliv_fee']) && $data['deliv_fee'] > 0) {
                                $this->order_item[$data['id']]['deliv_fee'] = [
                                    'price' => $data['deliv_fee'],
                                    'order_date' => $value['order_date'],
                                ];
                            }
                            if ($data['charge'] > 0) {
                                $this->order_item[$data['id']]['charge'] = [
                                    'price' => $data['charge'],
                                    'order_date' => $value['order_date'],
                                ];
                            }
                            if ($data['discount'] > 0) {
                                $this->order_item[$data['id']]['discount'] = [
                                    'price' => $data['discount'],
                                    'order_date' => $value['order_date'],
                                ];
                            }
                            // todo 3はプラグイン
                            if (isset($data['use_point']) && $data['use_point'] > 0) {
                                $this->order_item[$data['id']]['use_point'] = [
                                    'price' => $data['use_point'],
                                    'order_date' => $value['order_date'],
                                ];
                            }
                        }

                        // shippingに紐付けるデータを保持
                        $this->shipping_order[$data['id']] = $data;

                        break;

                    case 'dtb_shipping':
                        $value['id'] = $i;
                        $this->shipping_id[$data['order_id']][$data['shipping_id']] = $i;

                        if ($this->dataMigrationService->isVersion('3')) {
                            if (isset($data['delivery_id']) & strlen($data['delivery_id']) > 0) {
                                $value['delivery_id'] = $data['delivery_id'];
                            } else {
                                $value['delivery_id'] = null;
                            }
                            if (isset($data['time_id']) && strlen($data['time_id']) > 0) {
                                if (!empty($this->delivery_time)
                                    && isset($this->delivery_time[$data['delivery_id']])
                                    && isset($this->delivery_time[$data['delivery_id']][$data['time_id']])) {
                                    $value['time_id'] = $this->delivery_time[$data['delivery_id']][$data['time_id']];
                                }
                            }
                        } else {
                            $value['delivery_id'] = !empty($this->delivery_id[$value['order_id']]) ? $this->delivery_id[$value['order_id']] : null;
                            $value['delivery_time'] = empty($data['time']) ? null : $data['time'];
                            if (isset($data['time_id']) && strlen($data['time_id']) > 0) {
                                if (!empty($this->delivery_time)
                                    && isset($this->delivery_time[$value['delivery_id']])
                                    && isset($this->delivery_time[$value['delivery_id']][$data['time_id']])) {
                                    $value['time_id'] = $this->delivery_time[$value['delivery_id']][$data['time_id']];
                                }
                            }
                            // dtb_shipping.shipping_commit_dateが空の場合は、dtb_order.commit_dateを使用
                            if (!empty($data['shipping_commit_date'])) {
                                $value['shipping_date'] = $data['shipping_commit_date'];
                            } elseif (!empty($this->shipping_order[$data['order_id']]['commit_date'])) {
                                $value['shipping_date'] = $this->shipping_order[$data['order_id']]['commit_date'];
                            }
                        }

                        break;

                    case 'dtb_tax_rule':
                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            $value['id'] = $data['tax_rule_id'];
                            $value['apply_date'] = self::convertTz($data['apply_date']);
                            $value['rounding_type_id'] = $data['calc_rule'];
                            $value['tax_adjust'] = 0;
                        }

                        if (isset($data['pref_id']) && $data['pref_id'] === '0') {
                            $value['pref_id'] = null;
                        }
                        if (isset($data['country_id']) && $data['country_id'] === '0') {
                            $value['country_id'] = null;
                        }
                        if (
                            isset($data['product_id']) && $data['product_id'] === '0'
                            && isset($data['product_class_id']) && $data['product_class_id'] === '0'
                        ) {
                            $value['product_id'] = null;
                            $value['product_class_id'] = null;
                        }

                        // 3系対応
                        if (!$value['pref_id']) {
                            $value['pref_id'] = null;
                        }
                        if (!$value['country_id']) {
                            $value['country_id'] = null;
                        }
                        if (!$value['product_id']) {
                            $value['product_id'] = null;
                        }
                        if (!$value['product_class_id']) {
                            $value['product_class_id'] = null;
                        }

                        // 基本税率を保持しておく(送料等の明細を作成するタイミングで利用する)
                        if ($value['product_id'] === null && $value['product_class_id'] === null) {
                            $this->tax_rule[$value['apply_date']] = [
                                'rounding_type_id' => $value['rounding_type_id'],
                                'tax_rate' => $data['tax_rate'],
                                'apply_date' => $value['apply_date'],
                            ];
                        }
                        krsort($this->tax_rule);
                        break;

                    case 'dtb_order_item':
                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            if (isset($data['order_detail_id'])) {
                                $value['id'] = $data['order_detail_id'];
                            } else {
                                $value['id'] = $i; // 2.4.4
                            }
                            // dtb_order_detail.tax_ruleははdtb_tax_rule.calc_ruleの値
                            $value['rounding_type_id'] = isset($data['tax_rule'])
                                ? $data['tax_rule']
                                : $this->baseinfo['tax_rule'];

                            $value['tax_type_id'] = 1;
                            $value['tax_display_type_id'] = 1;

                            // 4.0.3でtax_rule_idはdeprecated.
                            $value['tax_rule_id'] = null;

                            // 2.4.4, 2.11, 2.12
                            if (isset($this->baseinfo) && !empty($this->baseinfo)) {
                                $value['tax_rate'] = $data['tax_rate'] = $this->baseinfo['tax'];
                                $data['point_rate'] = $this->baseinfo['point_rate'];
                            }

                            // 2.4.4
                            if ($this->dataMigrationService->isVersion('2.4.4')) {
                                $value['product_class_id'] = $this->product_class_id[$data['product_id']][$data['classcategory_id1']][$data['classcategory_id2']];
                            }

                            if (isset($data['price']) && isset($data['tax_rate'])) {
                                if ($value['rounding_type_id'] == 2) {
                                    $round = 'floor';
                                } elseif ($value['rounding_type_id'] == 3) {
                                    $round = 'ceil';
                                } else {
                                    $round = 'round';
                                }
                                // Warning: A non-numeric value encountered
                                $value['tax'] = $round((int)$data['price'] * (int)$data['tax_rate'] / 100);
                            } else {
                                $value['tax'] = 0;
                            }

                            $value['order_item_type_id'] = 1; // 商品で固定する
                            $value['currency_code'] = 'JPY'; // とりあえず固定

                            if ($this->dataMigrationService->isVersion('3')) {
                                // 1行目だけを移行する
                                $value['shipping_id'] = array_values($this->shipping_id[$data['order_id']])[0];
                            } else {
                                if (isset($this->shipping_id[$data['order_id']][0])) {
                                    $value['shipping_id'] = $this->shipping_id[$data['order_id']][0];
                                } else {
                                    $value['shipping_id'] = null; // ダウンロード販売
                                }
                            }
                        }
                        break;

                    case 'dtb_mail_history':
                        if ($this->dataMigrationService->isVersion('4.0/4.1') == false) {
                            $value['id'] = $data['send_id'];
                            $value['order_id'] = $data['order_id'];
                            $value['send_date'] = self::convertTz($data['send_date']);
                            $value['mail_subject'] = $data['subject'];
                            $value['mail_body'] = $data['mail_body'];
                        }

                        break;

                    case 'dtb_payment':
                        $value['method_class'] = 'Eccube\Service\Payment\Method\Cash';
                        break;
                }

                // PostgreSQL対応: 最終チェックで数値フィールドの空文字をNULLに変換
                $value = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $value);

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

            return $i; // indexを返す
        }
    }

    private function saveOrderItem($em)
    {
        $tableName = 'dtb_order_item';
        $columns = $em->getSchemaManager()->listTableColumns($tableName);

        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();

            if ($column->getName() == 'tax_adjust') {
                $data[$column->getName()] = 0; // 4.0.3 以降に対する対応
            } else {
                $data[$column->getName()] = null;
            }
        }

        $builder = new BulkInsertQuery($em, $tableName);
        $builder->setColumns($listTableColumns);

        $i = $em->fetchOne('SELECT max(id) + 1  FROM ' . $tableName);
        $batchSize = 20;
        foreach ($this->order_item as $order_id => $type) {
            foreach ($type as $key => $value) {
                $tax_rule = $this->getTaxRule($value['order_date']);
                $data['tax_rate'] = $tax_rule['tax_rate'];
                $data['rounding_type_id'] = $tax_rule['rounding_type_id'];
                $data['shipping_id'] = null;

                switch ($key) {
                    case 'deliv_fee':
                        $data['order_item_type_id'] = 2;
                        $data['product_name'] = '送料';
                        $data['price'] = $value['price'];
                        $data['tax_type_id'] = 1; // 課税
                        $data['tax_display_type_id'] = 2; // 税込表示
                        if (isset($this->shipping_id[$order_id][0])) {
                            $data['shipping_id'] = $this->shipping_id[$order_id][0];
                        }
                        break;
                    case 'charge':
                        $data['order_item_type_id'] = 3;
                        $data['product_name'] = '手数料';
                        $data['price'] = $value['price'];
                        $data['tax_type_id'] = 1; // 課税
                        $data['tax_display_type_id'] = 2; // 税込表示
                        break;
                    case 'discount':
                        $data['order_item_type_id'] = 4;
                        $data['product_name'] = '割引';
                        $data['price'] = $value['price'] * -1;
                        $data['tax_type_id'] = 1; // 課税
                        $data['tax_display_type_id'] = 2; // 税込表示
                        break;
                    case 'use_point':
                        $data['order_item_type_id'] = 6;
                        $data['product_name'] = 'ポイント';
                        $data['price'] = $value['price'] * -1; // use_pointはポイント数のため、正確な値はだせない.ここでは1pt1円として登録する.
                        $data['tax_type_id'] = 2;   // 不課税
                        $data['tax_display_type_id'] = 2; // 税込表示
                        $data['tax_rate'] = 0;
                        break;
                }

                if ($data['rounding_type_id'] == 2) {
                    $round = 'floor';
                } elseif ($data['rounding_type_id'] == 3) {
                    $round = 'ceil';
                } else {
                    $round = 'round';
                }
                $data['tax'] = $round($data['price'] * $data['tax_rate'] / 100) * 1;
                //$data['tax_adjust'] = 0; // 4.0.2でエラーになる
                $data['quantity'] = 1;
                $data['id'] = $i;
                $data['order_id'] = $order_id;
                $data['currency_code'] = 'JPY';
                $data['discriminator_type'] = 'orderitem';

                $builder->setValues($data);
                // 20件に1回SQLを発行してメモリを開放する。
                if (($i % $batchSize) === 0) {
                    $builder->execute();
                }
                $i++;
            }
        }
        if (count($builder->getValues()) > 0) {
            $builder->execute();
            sleep(1);
        }
    }
    private function fix24baseinfo($em, $tmpDir)
    {
        if (!file_exists($tmpDir . 'dtb_baseinfo.csv')) {
            return;
        }

        if (($handle = fopen($tmpDir . 'dtb_baseinfo.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            $add_value = [];
            while (($row = fgetcsv($handle)) !== false) {
                // 1行目をkeyとした配列を作る
                $this->baseinfo = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                $value['tax_rule_id'] = 1;
                $value['calc_rule'] = $this->baseinfo['tax_rule'];
                $value['tax_rate'] = $this->baseinfo['tax'];
                $value['apply_date'] = $value['create_date'] = $value['update_date'] = '1997-04-01 00:00:00';

                $add_value[0] = $value;
            }
            fclose($handle);

            $fpcsv = fopen($tmpDir . 'dtb_tax_rule.csv', 'a');
            foreach ($add_value as $row) {
                if ($row === reset($add_value)) {
                    // 最初
                    fputcsv($fpcsv, array_keys($row));
                }
                fputcsv($fpcsv, array_values($row));
            }
            fclose($fpcsv);
        }
    }


    private function fixPlgPoint($em, $tmpDir)
    {
        if (!file_exists($tmpDir . 'plg_point_customer.csv')) {
            return;
        }

        if (($handle = fopen($tmpDir . 'plg_point_customer.csv', 'r')) !== false) {
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));
            $keySize = count($key);

            $add_value = [];
            while (($row = fgetcsv($handle)) !== false) {
                // 1行目をkeyとした配列を作る
                $value = $this->dataMigrationService->convertNULL(array_combine($key, $row));
                $add_value[$value["customer_id"]] = $value;
            }
            fclose($handle);

            // 顧客IDをキーにして、dtb_customerにポイントを追加する
            $this->customer_point = $add_value;
        }
    }


    // タイムゾーンの変換
    private function convertTz($datetime)
    {
        $date = new \DateTime($datetime, new \DateTimeZone($this->eccubeConfig->get('timezone')));
        $date->setTimezone(new \DateTimeZone('UTC'));

        return $date->format($this->em->getDatabasePlatform()->getDateTimeTzFormatString());
    }

    private function getTaxRule($order_date)
    {
        foreach ($this->tax_rule as $apply_date => $value) {
            if ($apply_date < $order_date) {
                return $value;
            }
        }

        return array_values($this->tax_rule)[0];
    }

    private function fix4x($em, $tmpDir, $csvName)
    {
        // csvNameからテーブル名を取得

        if (filesize($tmpDir . $csvName) == 0) {
            // 無視する
            return;
        }

        $tableName = str_replace('.csv', '', $csvName);
        $columns = $em->getSchemaManager()->listTableColumns($tableName);

        if ($columns == false) {
            return;
        }
        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }

        $platform = $this->dataMigrationService->begin($em);

        // PostgreSQLではUPSERTを使うため、resetTableは不要
        // MySQLは従来通りresetTable()を使用
        if ($platform !== 'postgresql') {
            $this->dataMigrationService->resetTable($em, $tableName);
        }

        // PostgreSQLの場合、UPSERT用のプライマリキーを取得
        $primaryKeys = [];
        if ($platform === 'postgresql') {
            $schemaManager = $em->getSchemaManager();
            $table = $schemaManager->introspectTable($tableName);
            if ($table->hasPrimaryKey()) {
                $primaryKeys = $table->getPrimaryKey()->getColumns();
            }
        }

        $builder = new BulkInsertQuery($em, $tableName);
        $builder->setColumns($listTableColumns);

        $batchSize = 20;

        if (($handle = fopen($tmpDir . $csvName, 'r')) !== false) {
            // 文字コード問題が起きる可能性が高いので後で調整が必要になると思う
            $key = fgetcsv($handle);
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));

            $i = 1;
            while (($row = fgetcsv($handle)) !== false) {

                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));
                // Schemaにあわせた配列を作成する
                $value = [];
                foreach ($columns as $column) {

                    $columnName = $column->getName();

                    // 特定カラムの処理
                    if ($columnName == 'two_factor_auth_enabled' && ($tableName == 'dtb_member' || $tableName == 'dtb_customer')) {
                        // 4.0系には存在しないカラム。デフォルト値として0（無効）を設定
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : 0;
                    } elseif ($columnName == 'two_factor_auth_key' && ($tableName == 'dtb_member' || $tableName == 'dtb_customer')) {
                        // 4.0系には存在しないカラム。NULLを設定
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : null;
                    } elseif ($columnName == 'work' && $tableName == 'dtb_member') {
                        // 4.0系ではwork_idというカラム名
                        $value[$columnName] = isset($data['work_id']) && $data['work_id'] !== '' ? $data['work_id'] : null;
                    } elseif ($columnName == 'authority' && $tableName == 'dtb_member') {
                        // 4.0系ではauthority_idというカラム名
                        $value[$columnName] = isset($data['authority_id']) && $data['authority_id'] !== '' ? $data['authority_id'] : null;
                    } elseif ($columnName == 'create_date' || $columnName == 'update_date') {
                        // create_date/update_dateは、空または'0000-00-00 00:00:00'の場合は現在時刻を設定
                        $value[$columnName] = (isset($data[$columnName]) && $data[$columnName] !== '' && $data[$columnName] != '0000-00-00 00:00:00') ? $data[$columnName] : date('Y-m-d H:i:s');
                    } elseif ($columnName == 'login_date' || $columnName == 'first_buy_date' || $columnName == 'last_buy_date' || $columnName == 'payment_date') {
                        // タイムスタンプ型カラムで、NULL許可の場合は、空または'0000-00-00 00:00:00'の場合はnullを設定
                        $value[$columnName] = (isset($data[$columnName]) && $data[$columnName] !== '' && $data[$columnName] != '0000-00-00 00:00:00') ? $data[$columnName] : null;
                    } elseif ($columnName == 'sex_id' || $columnName == 'job_id' || $columnName == 'country_id' || $columnName == 'pref_id') {
                        // 外部キー制約があるカラムは、空の場合nullを設定（0を設定すると外部キー違反になる）
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : null;
                    } elseif ($columnName == 'discriminator_type') {
                        // discriminator_typeは、テーブル名から生成
                        $search = ['dtb_', 'mtb_', '_'];
                        $value[$columnName] = str_replace($search, '', $tableName);
                    } elseif ($column->getNotNull()) {
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : 0;
                    } else {
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : null;
                    }
                }

                if ($platform === 'postgresql' && !empty($primaryKeys)) {
                    // PostgreSQLはUPSERTで行ごとに処理
                    try {
                        $cols = array_map(fn($c) => '"' . $c . '"', array_keys($value));
                        $placeholders = array_fill(0, count($value), '?');
                        $updateCols = array_filter(array_keys($value), fn($c) => !in_array($c, $primaryKeys));
                        $updateSet = array_map(fn($c) => '"' . $c . '" = EXCLUDED."' . $c . '"', $updateCols);
                        $conflictCols = array_map(fn($c) => '"' . $c . '"', $primaryKeys);

                        $sql = 'INSERT INTO "' . $tableName . '" (' . implode(', ', $cols) . ') ' .
                               'VALUES (' . implode(', ', $placeholders) . ') ' .
                               'ON CONFLICT (' . implode(', ', $conflictCols) . ') ' .
                               'DO UPDATE SET ' . implode(', ', $updateSet);

                        $em->executeStatement($sql, array_values($value));
                    } catch (\Exception $e) {
                        $this->addDanger($e->getMessage(), 'admin');
                        $em->rollback();
                        return;
                    }
                } else {
                    // MySQLはバッチINSERT
                    $builder->setValues($value);

                    if (($i % $batchSize) === 0) {
                        try {
                            $builder->execute();
                            $this->addSuccess($tableName, 'admin');
                        } catch (\Exception $e) {
                            $this->addDanger($e->getMessage(), 'admin');
                            $em->rollback();
                            return;
                        }
                    }
                }

                $i++;
            }

            if ($platform !== 'postgresql' && count($builder->getValues()) > 0) {
                try {
                    $builder->execute();
                    $this->addSuccess($tableName, 'admin');
                } catch (\Exception $e) {
                    $this->addDanger($e->getMessage(), 'admin');
                    $em->rollback();
                    return;
                }
            }
            $em->commit();

            fclose($handle);

            return $i; // indexを返す
        }
    }
}
