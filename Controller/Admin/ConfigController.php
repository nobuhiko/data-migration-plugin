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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ConfigController extends AbstractController
{
    /** @var pluginService */
    protected $pluginService;
    protected $dataMigrationService;
    protected $parameterBag;

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

    /**
     * constructor.
     *
     * @param pluginService $pluginService
     */
    public function __construct(
        PluginService $pluginService,
        DataMigrationService $dataMigrationService,
        ParameterBagInterface $parameterBag
    ) {
        $this->pluginService = $pluginService;
        $this->dataMigrationService = $dataMigrationService;
        $this->parameterBag = $parameterBag;
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

            // PostgreSQL対応: 接続とプラットフォーム情報を取得
            $connection = $em; // $emは既にConnectionオブジェクト
            $platform = $connection->getDatabasePlatform()->getName();

            // PostgreSQL対応: トランザクション状態をクリア
            if ($platform === 'postgresql') {
                try {
                    // 既存の失敗したトランザクションをクリア
                    if ($connection->isTransactionActive()) {
                        error_log("PostgreSQL: Rolling back existing transaction");
                        $connection->rollBack();
                    }
                    // 新しいトランザクションを開始
                    $connection->beginTransaction();
                    error_log("PostgreSQL: Started new transaction for migration");
                } catch (\Exception $txError) {
                    error_log("PostgreSQL: Transaction setup error: " . $txError->getMessage());
                }
            }

            // logをオフにしてメモリを減らす
            $this->dataMigrationService->disableLogging($em);

            try {
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

                // $csvDir 内のファイルをすべて読み込む
                $files = scandir($csvDir);
                foreach ($files as $file) {
                    // csvファイルのみ処理
                    if (is_file($csvDir . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'csv') {
                        $this->fix4x($em, $csvDir, $file);
                    }
                }
            } else {
                $customerOrderOnly = $form['customer_order_only']->getData();
                error_log("PostgreSQL Debug: customer_order_only flag = " . ($customerOrderOnly ? 'true' : 'false'));

                if ($customerOrderOnly) {
                    // 会員・受注のみ移行
                    error_log("PostgreSQL Debug: Using customer_order_only mode");
                    $this->saveCustomerAndOrder($em, $csvDir);
                } else {
                    error_log("PostgreSQL Debug: Using full migration mode");
                    // 全データ移行
                    $this->saveCustomer($em, $csvDir);
                    error_log("PostgreSQL Debug: Starting saveProduct");
                    $this->saveProduct($em, $csvDir);
                    error_log("PostgreSQL Debug: Starting saveOrder");
                    $this->saveOrder($em, $csvDir);
                    error_log("PostgreSQL Debug: Completed saveOrder");
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

                // PostgreSQL対応: トランザクションをコミット
                if ($platform === 'postgresql') {
                    try {
                        if ($connection->isTransactionActive()) {
                            $connection->commit();
                            error_log("PostgreSQL: Transaction committed successfully");
                        }
                    } catch (\Exception $commitError) {
                        error_log("PostgreSQL: Transaction commit error: " . $commitError->getMessage());
                        try {
                            if ($connection->isTransactionActive()) {
                                $connection->rollBack();
                            }
                        } catch (\Exception $rollbackError) {
                            error_log("PostgreSQL: Rollback error: " . $rollbackError->getMessage());
                        }
                    }
                }

                // 存在しないルート名を修正
                return $this->redirectToRoute('data_migration43_admin_config');

            } catch (\Exception $migrationError) {
                error_log("PostgreSQL: Migration error occurred: " . $migrationError->getMessage());

                // PostgreSQL対応: エラー時のトランザクションロールバック
                if ($platform === 'postgresql') {
                    try {
                        if ($connection->isTransactionActive()) {
                            $connection->rollBack();
                            error_log("PostgreSQL: Transaction rolled back due to error");
                        }
                    } catch (\Exception $rollbackError) {
                        error_log("PostgreSQL: Error during rollback: " . $rollbackError->getMessage());
                    }
                }

                // エラーメッセージを設定してリダイレクト
                $this->addError('data_migration43.migration.error', 'admin');
                return $this->redirectToRoute('data_migration43_admin_config');
            }
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
        $platform = $this->dataMigrationService->begin($em);
        
        // デバッグ：platform判定を確認
        error_log("PostgreSQL Debug: Platform detected as: '$platform'");
        file_put_contents('/tmp/platform_debug.txt', date('Y-m-d H:i:s') . " - Platform: '$platform'" . PHP_EOL, FILE_APPEND);

        // PostgreSQL用の2フェーズ処理を使用
        if ($platform === 'postgresql') {
            error_log("PostgreSQL Debug: Using two-phase process instead of individual table processing");
            file_put_contents('/tmp/two_phase_process_called.txt', date('Y-m-d H:i:s') . ' - Two-phase process started' . PHP_EOL, FILE_APPEND);
            $this->executePostgreSQLTwoPhaseProcess($em, $csvDir);
            
            // PostgreSQL用処理完了後の後処理
            $this->finalizePostgreSQLProcess($em, $platform);
            return;
        }

        // 従来の処理（MySQL等）
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

        // PostgreSQL用のコミット処理
        try {
            $em->commit();
            $this->addSuccess('会員データ・受注データを登録しました。', 'admin');
        } catch (\Exception $e) {
            error_log('PostgreSQL commit error in saveCustomer: ' . $e->getMessage());
            if ($em->isTransactionActive()) {
                $em->rollback();
            }
            throw $e;
        }
    }

    private function saveCustomer($em, $csvDir)
    {
        // 会員系
        if (file_exists($csvDir . 'dtb_customer.csv') && filesize($csvDir . 'dtb_customer.csv') > 0) {

            $platform = $this->dataMigrationService->begin($em);
            
            // デバッグ：platform判定を確認
            error_log("PostgreSQL Debug: saveCustomer - Platform detected as: '$platform'");
            file_put_contents('/tmp/platform_debug.txt', date('Y-m-d H:i:s') . " - saveCustomer Platform: '$platform'" . PHP_EOL, FILE_APPEND);

            // PostgreSQL用の2フェーズ処理を使用
            if ($platform === 'postgresql') {
                error_log("PostgreSQL Debug: saveCustomer - Using two-phase process");
                file_put_contents('/tmp/two_phase_process_called.txt', date('Y-m-d H:i:s') . ' - saveCustomer two-phase process started' . PHP_EOL, FILE_APPEND);
                $this->executePostgreSQLTwoPhaseProcess($em, $csvDir);
                
                // PostgreSQL用処理完了後の後処理
                $this->finalizePostgreSQLProcess($em, $platform);
                return;
            }

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

            $this->saveToC($em, $csvDir, 'mtb_authority', null, true);
            $this->saveToC($em, $csvDir, 'dtb_member', null, true);

            if ($platform == 'mysql') {
                $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
            } else {
                $this->dataMigrationService->setIdSeq($em, 'dtb_member');
                $this->dataMigrationService->setIdSeq($em, 'dtb_customer');
                $this->dataMigrationService->setIdSeq($em, 'dtb_customer_address');
            }

            // PostgreSQL用のコミット処理
            try {
                $em->commit();
                $this->addSuccess('会員データ登録しました。', 'admin');
            } catch (\Exception $e) {
                error_log('PostgreSQL commit error in saveCustomer (v2): ' . $e->getMessage());
                if ($em->isTransactionActive()) {
                    $em->rollback();
                }
                throw $e;
            }
        } else {
            $this->addDanger('会員データが見つかりませんでした', 'admin');
        }
    }

    private function saveToC($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
        $this->dataMigrationService->resetTable($em, $tableName);

        $fullPath = $tmpDir . $csvName . '.csv';
        error_log("PostgreSQL Debug: saveToC looking for CSV file: $fullPath (table: $tableName)");

        if (file_exists($fullPath) == false) {
            error_log("PostgreSQL Debug: CSV file NOT FOUND: $fullPath");
            // デバッグ：同じディレクトリのファイル一覧を表示
            $files = glob($tmpDir . '*.csv');
            error_log("PostgreSQL Debug: Available CSV files in $tmpDir: " . implode(', ', $files));
            // 無視する
            //$this->addDanger($csvName.'.csv が見つかりませんでした' , 'admin');
            return;
        } else {
            error_log("PostgreSQL Debug: CSV file found successfully: $fullPath");
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
                $columnName = $column->getName();
                if ($tableName === 'dtb_member') {
                    if ($columnName === 'two_factor_auth_key' || $columnName === 'two_factor_auth_enabled') {
                        continue;
                    }
                }
                $listTableColumns[] = $columnName;
            }

            $builder = new BulkInsertQuery($em, $tableName);
            $builder->setColumns($listTableColumns);

            error_log("PostgreSQL Debug: Processing table '$tableName' with columns: " . implode(', ', $listTableColumns));

            $batchSize = 20;
            $rowCount = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowCount++;
                $value = [];

                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));

                // PostgreSQL対応: 数値フィールドの空文字をNULLに変換
                $data = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $data);

                // Schemaにあわせた配列を作成する
                foreach ($listTableColumns as $column) {
                    if ($this->dataMigrationService->isVersion('4.0/4.1') == true) {
                        if ($column == 'buy_times') {
                            $value[$column] = isset($data[$column]) ? $data[$column] : 0;
                        } elseif ($column == 'creator_id') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : date('Y:m:d H:i:s');
                        } elseif ($column == 'login_date' || $column == 'first_buy_date') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        } elseif ($column == 'point') {
                            $value[$column] = empty($data[$column]) ? 0 : (int) $data[$column];
                        } elseif ($allow_zero) {
                            $value[$column] = isset($data[$column]) ? $data[$column] : null;
                        } else {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;
                        }
                    } else {
                        if ($column == 'id' && $tableName == 'dtb_customer') { // fixme
                            $value[$column] = $data['customer_id'];
                        } elseif ($column == 'customer_status_id') {
                            // 退会が追加された
                            $value[$column] = ($data['del_flg'] == 1) ? '3' : $data['status'];
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
                        } elseif ($column == 'work_id') {
                            // 削除されているメンバーは非稼働で登録
                            $value[$column] = ($data['del_flg'] == 1) ? 0 : $data['work'];
                        } elseif ($column == 'authority_id') {
                            $value[$column] = $data['authority'];
                        } elseif ($column == 'email') {
                            // 退会時はランダムな値に更新
                            if ($data['del_flg'] == 1) {
                                $value[$column] = StringUtil::random(60) . '@dummy.dummy';
                            } else {
                                $value[$column] = empty($data[$column]) ? 'Not null violation' : $data[$column];
                            }
                        } elseif ($column == 'password' || $column == 'name01' || $column == 'name02') {
                            $value[$column] = empty($data[$column]) ? 'Not null violation' : $data[$column];
                        } elseif ($column == 'sort_no') {
                            if ($this->dataMigrationService->isVersion('4.0/4.1') == true) {
                                $value[$column] = $data['sort_no'];
                            } else {
                                $value[$column] = $data['rank'];
                            }
                        } elseif ($column == 'create_date' || $column == 'update_date') {
                            $value[$column] = (isset($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : date('Y-m-d H:i:s');
                        } elseif ($column == 'login_date' || $column == 'first_buy_date') {
                            $value[$column] = (!empty($data[$column]) && $data[$column] != '0000-00-00 00:00:00') ? self::convertTz($data[$column]) : null;
                        } elseif ($column == 'secret_key') { // 実験
                            $value[$column] = uniqid('secret_key_' . mt_rand() . '.', true);
                        } elseif ($column == 'point') {

                            if ($this->dataMigrationService->isVersion('3') == true && isset($this->customer_point[$data['customer_id']])) {
                                $value[$column] = $this->customer_point[$data['customer_id']]['plg_point_current'];
                            } else {
                                $value[$column] = empty($data[$column]) ? 0 : (int) $data[$column];
                            }
                        } elseif ($column == 'salt') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : null;  // @see https://github.com/EC-CUBE/data-migration-plugin/issues/38
                        } elseif ($column == 'creator_id') {
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
                        } elseif ($column == 'plg_mailmagazine_flg') {
                            $value[$column] = (!empty($data['mailmaga_flg']) && $data['mailmaga_flg'] != 3) ? 1 : 0; // メルマガプラグイン
                        } elseif ($column == 'id' && $tableName == 'dtb_member') {
                            $value[$column] = $data['member_id'];
                        } elseif ($column == 'id' && $tableName == 'dtb_customer_address') {
                            // カラム名が違うので
                            $value[$column] = $i;
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

                // PostgreSQL対応: 最終チェックで数値フィールドの空文字をNULLに変換
                $value = $this->dataMigrationService->convertDataTypesForPostgreSQL($em, $tableName, $value);

                $builder->setValues($value);

                if (($i % $batchSize) === 0) {
                    try {
                        $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
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
                    error_log("PostgreSQL Debug: Final batch for '$tableName' with " . count($builder->getValues()) . " records");
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                    error_log("PostgreSQL Debug: Final batch successfully executed for '$tableName'");
                } catch (\Exception $e) {
                    error_log("BulkInsertQuery final execute error in saveToC table '$tableName': " . $e->getMessage());
                    error_log("Failed final batch, data count: " . count($builder->getValues()));
                    throw $e;
                }
            }

            fclose($handle);

            error_log("PostgreSQL Debug: Completed importing $rowCount rows from $csvName.csv to table '$tableName'");

            return $i; // indexを返す
        }
    }

    /**
     * PostgreSQL外部キー制約に基づく推奨テーブル処理順序を取得
     */
    private function getOptimalTableOrder($em, $tables)
    {
        $connection = $em;
        $platform = $connection->getDatabasePlatform()->getName();

        if ($platform !== 'postgresql') {
            return $tables; // PostgreSQL以外はそのまま
        }

        try {
            // 外部キー制約情報を取得
            $sql = "
                SELECT DISTINCT
                    tc.table_name AS child_table,
                    ccu.table_name AS parent_table
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                JOIN information_schema.constraint_column_usage ccu
                    ON ccu.constraint_name = tc.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_schema = 'public'
            ";

            $foreignKeys = $connection->fetchAllAssociative($sql);

            // 依存関係マップを構築
            $dependencies = [];
            foreach ($foreignKeys as $fk) {
                $child = $fk['child_table'];
                $parent = $fk['parent_table'];

                // 自己参照は無視
                if ($child !== $parent) {
                    $dependencies[$child][] = $parent;
                }
            }

            // トポロジカルソート
            $sorted = [];
            $processed = [];
            $processing = [];

            $visit = function($table) use (&$visit, &$dependencies, &$sorted, &$processed, &$processing, $tables) {
                if (isset($processed[$table]) || !in_array($table, $tables)) {
                    return;
                }

                if (isset($processing[$table])) {
                    // 循環依存を検出したが、処理を継続
                    return;
                }

                $processing[$table] = true;

                if (isset($dependencies[$table])) {
                    foreach ($dependencies[$table] as $dependency) {
                        $visit($dependency);
                    }
                }

                unset($processing[$table]);
                $processed[$table] = true;
                $sorted[] = $table;
            };

            foreach ($tables as $table) {
                $visit($table);
            }

            // 処理されなかったテーブルを最後に追加
            foreach ($tables as $table) {
                if (!in_array($table, $sorted)) {
                    $sorted[] = $table;
                }
            }

            error_log("PostgreSQL: Optimized table order: " . implode(' -> ', $sorted));
            return $sorted;

        } catch (\Exception $e) {
            error_log("PostgreSQL: Error determining table order, using original: " . $e->getMessage());
            return $tables;
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
            $platform = $this->dataMigrationService->begin($em);

            // 2.11系の処理
            if (file_exists($csvDir . 'dtb_class_combination.csv')) {
                $this->fix211classCombination($em, $platform, $csvDir);
            }

            if ($this->dataMigrationService->isVersion('4.0/4.1')) {
                $this->saveToC($em, $csvDir, 'mtb_product_status', null, true);
                $this->saveToC($em, $csvDir, 'mtb_sale_type', null, true);
                // PostgreSQL dependency order fix: process in correct dependency order
                $this->saveToP($em, $csvDir, 'dtb_product');
                $this->saveToP($em, $csvDir, 'dtb_category'); // Process category before product_category
                $this->saveToO($em, $csvDir, 'dtb_delivery_duration', null, true);
                // Process class tables in dependency order: class_name -> class_category -> product_class
                $this->saveToP($em, $csvDir, 'dtb_class_name');
                $this->saveToP($em, $csvDir, 'dtb_class_category');
                $this->saveToP($em, $csvDir, 'dtb_product_class');
                $this->saveToP($em, $csvDir, 'dtb_product_category');
                $this->saveToP($em, $csvDir, 'dtb_product_stock');
                $this->saveToP($em, $csvDir, 'dtb_product_image');
                // Process tag before product_tag for dependency order
                $this->saveToP($em, $csvDir, 'dtb_tag');
                $this->saveToP($em, $csvDir, 'dtb_product_tag');
                $this->saveToP($em, $csvDir, 'dtb_customer_favorite_product');
            } else if ($this->dataMigrationService->isVersion('3')) {
                // PostgreSQL dependency order fix: process in correct dependency order
                $this->saveToP($em, $csvDir, 'dtb_product');
                $this->saveToP($em, $csvDir, 'dtb_category'); // Process category before product_category
                // Process class tables in dependency order: class_name -> class_category -> product_class
                $this->saveToP($em, $csvDir, 'dtb_class_name');
                $this->saveToP($em, $csvDir, 'dtb_class_category');
                $this->saveToP($em, $csvDir, 'dtb_product_class');
                $this->saveToP($em, $csvDir, 'dtb_product_category');
                $this->saveToP($em, $csvDir, 'dtb_product_stock');
                $this->saveToP($em, $csvDir, 'dtb_product_image');
                // Process tag before product_tag for dependency order
                $this->saveToP($em, $csvDir, 'mtb_tag', 'dtb_tag');
                $this->saveToP($em, $csvDir, 'dtb_product_tag');
                $this->saveToP($em, $csvDir, 'dtb_customer_favorite_product');
            } else {
                // PostgreSQL dependency order fix: process in correct dependency order
                $this->saveToP($em, $csvDir, 'dtb_products', 'dtb_product');
                $this->saveToP($em, $csvDir, 'dtb_category'); // Process category before product_category
                // Process class tables in dependency order: class_name -> class_category -> product_class
                $this->saveToP($em, $csvDir, 'dtb_class', 'dtb_class_name');
                $this->saveToP($em, $csvDir, 'dtb_classcategory', 'dtb_class_category');
                $this->saveToP($em, $csvDir, 'dtb_products_class', 'dtb_product_class');
                $this->saveToP($em, $csvDir, 'dtb_product_categories', 'dtb_product_category');
                // Process tag before product_tag for dependency order
                $this->saveToP($em, $csvDir, 'mtb_status', 'dtb_tag');
                $this->saveToP($em, $csvDir, 'dtb_product_status', 'dtb_product_tag');

                $this->saveToP($em, $csvDir, 'dtb_customer_favorite_products', 'dtb_customer_favorite_product');

                // 在庫
                $this->saveStock($em);
                // 画像
                $this->saveProductImage($em);
            }

            // dtb_category is now processed earlier in correct dependency order
            if (file_exists($csvDir . 'mtb_product_type.csv')) {
                $this->saveToP($em, $csvDir, 'mtb_product_type', 'mtb_sale_type', true);
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
            }

            // PostgreSQL用のコミット処理
            try {
                $em->commit();
                $this->addSuccess('商品データを登録しました。', 'admin');
            } catch (\Exception $e) {
                error_log('PostgreSQL commit error in saveProduct: ' . $e->getMessage());
                if ($em->isTransactionActive()) {
                    $em->rollback();
                }
                throw $e;
            }
        } else {
            $this->addDanger('商品データがが見つかりませんでした', 'admin');
        }
    }

    private function saveToP($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
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
                            $value[$column] = isset($data['product_type_id']) ? $data['product_type_id'] : 1;
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
                            if ($data['del_flg'] == 1) {
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
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                }

                $i++;
            }

            if (count($builder->getValues()) > 0) {
                $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
            }

            fclose($handle);

            return $i; // indexを返す
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

            $tableName = 'dtb_class_combination';
            $builder = new BulkInsertQuery($em, $tableName);
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
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                }
            }
            if (count($builder->getValues()) > 0) {
                $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
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

        // PostgreSQL-aware DELETE with constraint handling
        try {
            $em->exec('DELETE FROM ' . $tableName);
        } catch (\Exception $e) {
            // For PostgreSQL, handle constraint violation by using TRUNCATE with CASCADE
            if ($em->getDatabasePlatform()->getName() === 'postgresql') {
                try {
                    error_log("PostgreSQL: DELETE failed for $tableName, attempting TRUNCATE CASCADE: " . $e->getMessage());
                    $em->exec('TRUNCATE ' . $tableName . ' CASCADE');
                } catch (\Exception $truncateError) {
                    error_log("PostgreSQL: TRUNCATE CASCADE failed for $tableName, skipping table clear: " . $truncateError->getMessage());
                    // If both DELETE and TRUNCATE fail, continue without clearing the table
                    // The constraint handling in executeWithPostgreSQLFallback will handle conflicts during INSERT
                }
            } else {
                // For non-PostgreSQL databases, re-throw the original error
                throw $e;
            }
        }

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
                $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
            }
            $i++;
        }
        if (count($builder->getValues()) > 0) {
            $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
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

        // PostgreSQL-aware DELETE with constraint handling
        try {
            $em->exec('DELETE FROM ' . $tableName);
        } catch (\Exception $e) {
            // For PostgreSQL, handle constraint violation by using TRUNCATE with CASCADE
            if ($em->getDatabasePlatform()->getName() === 'postgresql') {
                try {
                    error_log("PostgreSQL: DELETE failed for $tableName, attempting TRUNCATE CASCADE: " . $e->getMessage());
                    $em->exec('TRUNCATE ' . $tableName . ' CASCADE');
                } catch (\Exception $truncateError) {
                    error_log("PostgreSQL: TRUNCATE CASCADE failed for $tableName, skipping table clear: " . $truncateError->getMessage());
                    // If both DELETE and TRUNCATE fail, continue without clearing the table
                    // The constraint handling in executeWithPostgreSQLFallback will handle conflicts during INSERT
                }
            } else {
                // For non-PostgreSQL databases, re-throw the original error
                throw $e;
            }
        }

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
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                }
                $i++;
            }
        }
        if (count($builder->getValues()) > 0) {
            $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
            sleep(1);
        }
    }



    private function saveOrder($em, $csvDir)
    {
        // 会員系
        if (file_exists($csvDir . 'dtb_order.csv') && filesize($csvDir . 'dtb_order.csv') > 0) {
            $platform = $this->dataMigrationService->begin($em);

            // PostgreSQL: 外部キー制約エラー対策 - dtb_customerの存在確認
            $customerCount = $em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
            error_log("PostgreSQL Debug: Found $customerCount customers before order processing");

            // PostgreSQL: dtb_orderに必要な依存テーブルを先に処理（最適化された順序）
            error_log("PostgreSQL Debug: Processing dtb_order dependencies in optimized order");

            // 1. 重要: 他のトランザクションで処理されている可能性のあるテーブルを再処理
            if ($customerCount == 0) {
                error_log("PostgreSQL Debug: dtb_customer is empty, processing customer data in order transaction");
                $this->saveToC($em, $csvDir, 'dtb_customer');

                // PostgreSQL: 顧客データ処理後の確認
                $customerCountAfter = $em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
                error_log("PostgreSQL Debug: Customer count after processing: $customerCountAfter");
            }

            // 2. 基本マスターテーブル（dtb_orderが参照するもの）
            $this->saveToO($em, $csvDir, 'mtb_device_type', null, true);
            $this->saveToO($em, $csvDir, 'mtb_sex', null, true);
            $this->saveToO($em, $csvDir, 'mtb_job', null, true);
            $this->saveToO($em, $csvDir, 'mtb_pref', null, true);
            $this->saveToO($em, $csvDir, 'mtb_country', null, true);

            // PostgreSQL: マスターテーブル処理後、dtb_customerが削除された場合の再処理
            $customerCountAfterMasters = $em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
            error_log("PostgreSQL Debug: Customer count after master tables processing: $customerCountAfterMasters");
            if ($customerCountAfterMasters == 0) {
                error_log("PostgreSQL Debug: dtb_customer was deleted by CASCADE, re-processing customer data");
                $this->saveToC($em, $csvDir, 'dtb_customer');

                $customerCountFinal = $em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
                error_log("PostgreSQL Debug: Final customer count after re-processing: $customerCountFinal");
            }

            // 3. 決済テーブル（dtb_orderのpayment_idが参照）
            $this->saveToO($em, $csvDir, 'dtb_payment');

            if ($this->dataMigrationService->isVersion('4.0/4.1')) {
                $this->saveToP($em, $csvDir, 'mtb_order_status', null, true);
                $this->saveToP($em, $csvDir, 'mtb_order_status_color', null, true);
                $this->saveToP($em, $csvDir, 'mtb_order_item_type', null, true);
                $this->saveToO($em, $csvDir, 'dtb_delivery');
                $this->saveToO($em, $csvDir, 'dtb_delivery_time');
                $this->saveToO($em, $csvDir, 'dtb_delivery_fee');
            } else if ($this->dataMigrationService->isVersion('3')) {
                $this->saveToO($em, $csvDir, 'dtb_delivery');
                $this->saveToO($em, $csvDir, 'dtb_delivery_time');
                $this->saveToO($em, $csvDir, 'dtb_delivery_fee');
            } else {
                $this->saveToO($em, $csvDir, 'dtb_deliv', 'dtb_delivery');
                $this->saveToO($em, $csvDir, 'dtb_delivtime', 'dtb_delivery_time');
                $this->saveToO($em, $csvDir, 'dtb_delivfee', 'dtb_delivery_fee');
            }

            // 4. 全ての依存テーブル処理後、最終確認してdtb_orderを処理
            error_log("PostgreSQL Debug: Final dependency check before dtb_order processing");

            // PostgreSQL: 依存データの最終確認
            $dependencyCheck = [
                'dtb_customer' => $em->fetchOne('SELECT COUNT(*) FROM dtb_customer'),
                'dtb_payment' => $em->fetchOne('SELECT COUNT(*) FROM dtb_payment'),
                'mtb_device_type' => $em->fetchOne('SELECT COUNT(*) FROM mtb_device_type'),
                'mtb_sex' => $em->fetchOne('SELECT COUNT(*) FROM mtb_sex'),
                'mtb_job' => $em->fetchOne('SELECT COUNT(*) FROM mtb_job')
            ];

            $allDependenciesReady = true;
            foreach ($dependencyCheck as $table => $count) {
                error_log("PostgreSQL Debug: Final check - Table $table has $count records");
                if ($count == 0 && in_array($table, ['dtb_customer', 'dtb_payment'])) {
                    error_log("PostgreSQL Warning: Critical dependency $table is still empty!");
                    $allDependenciesReady = false;
                }
            }

            if ($allDependenciesReady) {
                error_log("PostgreSQL Debug: All dependencies satisfied, processing dtb_order");
            } else {
                error_log("PostgreSQL Warning: Dependencies not fully satisfied, dtb_order may encounter constraints");
            }

            $this->saveToO($em, $csvDir, 'dtb_order');

            // 4. dtb_orderに依存するテーブル
            $this->saveToO($em, $csvDir, 'dtb_shipping');
            if ($this->dataMigrationService->isVersion('4.0/4.1') || $this->dataMigrationService->isVersion('3')) {
                $this->saveToO($em, $csvDir, 'dtb_mail_history');
            } else {
                $this->saveToO($em, $csvDir, 'dtb_mail_history', 'dtb_mail_history');
            }

            if (!isset($this->product_class_id)) {
                sleep(5);
            }
            // todo 商品別税率設定
            $this->saveToO($em, $csvDir, 'dtb_tax_rule', null, true); // 税率0にしている場合がある

            // PostgreSQL: dtb_tax_rule処理後にdtb_product_classが削除された場合の復旧処理
            $productClassCountAfterTax = $em->fetchOne('SELECT COUNT(*) FROM dtb_product_class');
            error_log("PostgreSQL Debug: After dtb_tax_rule - dtb_product_class count: $productClassCountAfterTax");

            if ($productClassCountAfterTax == 0) {
                error_log("PostgreSQL Debug: dtb_product_class was deleted by dtb_tax_rule CASCADE, re-processing product data");

                // 商品関連CSVファイルの確認
                $productCsv = $csvDir . 'dtb_product.csv';
                $productClassCsv = $csvDir . 'dtb_product_class.csv';
                $productClassCsv2 = $csvDir . 'dtb_products_class.csv'; // 古いバージョン用

                error_log("PostgreSQL Debug: Checking product CSV files - dtb_product.csv: " . (file_exists($productCsv) ? "exists" : "missing"));
                error_log("PostgreSQL Debug: Checking product CSV files - dtb_product_class.csv: " . (file_exists($productClassCsv) ? "exists" : "missing"));
                error_log("PostgreSQL Debug: Checking product CSV files - dtb_products_class.csv: " . (file_exists($productClassCsv2) ? "exists" : "missing"));

                // バージョンに応じた復旧処理
                if ($this->dataMigrationService->isVersion('4.0/4.1') || $this->dataMigrationService->isVersion('3')) {
                    if (file_exists($productCsv)) {
                        $this->saveToP($em, $csvDir, 'dtb_product');
                    }
                    if (file_exists($productClassCsv)) {
                        $this->saveToP($em, $csvDir, 'dtb_product_class');
                    }
                } else {
                    // 古いバージョン
                    if (file_exists($csvDir . 'dtb_products.csv')) {
                        $this->saveToP($em, $csvDir, 'dtb_products', 'dtb_product');
                    }
                    if (file_exists($productClassCsv2)) {
                        $this->saveToP($em, $csvDir, 'dtb_products_class', 'dtb_product_class');
                    }
                }

                $productClassCountFinal = $em->fetchOne('SELECT COUNT(*) FROM dtb_product_class');
                $productCountFinal = $em->fetchOne('SELECT COUNT(*) FROM dtb_product');
                error_log("PostgreSQL Debug: After product recovery - dtb_product: $productCountFinal, dtb_product_class: $productClassCountFinal");
            }

            // PostgreSQL: dtb_order_item処理前にdtb_product_classの状態を確認
            $productClassCount = $em->fetchOne('SELECT COUNT(*) FROM dtb_product_class');
            $productClassId10 = $em->fetchOne('SELECT COUNT(*) FROM dtb_product_class WHERE id = 10');
            error_log("PostgreSQL Debug: Before dtb_order_item - dtb_product_class total: $productClassCount, id=10: $productClassId10");

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

            // イレギュラー対応 - PostgreSQL-aware UPDATE with error handling
            error_log("PostgreSQL Debug: Executing order status cleanup");
            try {
                $updateCount = $em->exec('UPDATE dtb_order SET order_status_id = NULL WHERE order_status_id not in (select id from mtb_order_status)');
                error_log("PostgreSQL Debug: Updated $updateCount orders with invalid status");
            } catch (\Exception $e) {
                error_log("PostgreSQL Debug: UPDATE failed for order status cleanup, skipping: " . $e->getMessage());
                // Continue without the update - this is a cleanup operation that's not critical for data migration
                $updateCount = 0;
            }

            // PostgreSQL: 移行後の基本データ復旧処理
            $this->restoreEssentialData($em);

            // PostgreSQL: 全マスタテーブルの存在チェック
            $this->checkAllMasterTables($em);

            // PostgreSQL用のコミット処理
            try {
                error_log("PostgreSQL Debug: Committing saveOrder transaction");
                $em->commit();
                error_log("PostgreSQL Debug: saveOrder transaction committed successfully");

                // コミット後のデータ確認
                $orderCount = $em->fetchOne('SELECT COUNT(*) FROM dtb_order');
                $customerCountFinal = $em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
                error_log("PostgreSQL Debug: Order count after commit: $orderCount");
                error_log("PostgreSQL Debug: Customer count after commit: $customerCountFinal");

                $this->addSuccess('受注データを登録しました。', 'admin');
            } catch (\Exception $e) {
                error_log('PostgreSQL commit error in saveOrder: ' . $e->getMessage());
                if ($em->isTransactionActive()) {
                    $em->rollback();
                }
                throw $e;
            }
        } else {
            $this->addDanger('受注データが見つかりませんでした', 'admin');
        }
    }

    private function saveToO($em, $tmpDir, $csvName, $tableName = null, $allow_zero = false, $i = 1)
    {
        $tableName = ($tableName) ? $tableName : $csvName;
        $this->dataMigrationService->resetTable($em, $tableName);

        $fullPath = $tmpDir . $csvName . '.csv';
        error_log("PostgreSQL Debug: saveToO looking for CSV file: $fullPath (table: $tableName)");

        if (file_exists($fullPath) == false) {
            error_log("PostgreSQL Debug: saveToO CSV file NOT FOUND: $fullPath");
            // 無視する
            //$this->addDanger($csvName.'.csv が見つかりませんでした' , 'admin');
            return;
        } else {
            error_log("PostgreSQL Debug: saveToO CSV file found successfully: $fullPath");
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

            error_log("PostgreSQL Debug: Processing table '$tableName' (saveToO) with columns: " . implode(', ', $listTableColumns));

            $batchSize = 20;
            $rowCount = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowCount++;
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
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
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
                            $value[$column] = isset($data['product_type_id']) ? $data['product_type_id'] : 1;
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
                            $value[$column] = !empty($data[$column]) ? $data[$column] : 1;
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
                                $value['time_id'] = $this->delivery_time[$data['delivery_id']][$data['time_id']];
                            }
                        } else {
                            $value['delivery_id'] = !empty($this->delivery_id[$value['order_id']]) ? $this->delivery_id[$value['order_id']] : null;
                            $value['delivery_time'] = empty($data['time']) ? null : $data['time'];
                            if (isset($data['time_id']) && strlen($data['time_id']) > 0) {
                                if (!empty($this->delivery_time)) {
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
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                }

                $i++;
            }

            if (count($builder->getValues()) > 0) {
                error_log("PostgreSQL Debug: Final batch for '$tableName' (saveToO) with " . count($builder->getValues()) . " records");
                $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                error_log("PostgreSQL Debug: Final batch successfully executed for '$tableName' (saveToO)");
            }

            fclose($handle);

            error_log("PostgreSQL Debug: Completed importing $rowCount rows from $csvName.csv to table '$tableName' (saveToO)");

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
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                }
                $i++;
            }
        }
        if (count($builder->getValues()) > 0) {
            $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
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
        if ($csvName == "dtb_member.csv") {
            // 変更するとログアウトしちゃうので
            return;
        }

        // csvNameからテーブル名を取得
        error_log("PostgreSQL Debug: Processing CSV file: $csvName from directory: $tmpDir");

        if (filesize($tmpDir . $csvName) == 0) {
            error_log("PostgreSQL Debug: CSV file is empty: $csvName");
            // 無視する
            return;
        }

        $tableName = str_replace('.csv', '', $csvName);
        error_log("PostgreSQL Debug: Table name: $tableName");

        // 特別なテーブルの処理をログ出力
        if ($tableName === 'dtb_customer') {
            error_log("PostgreSQL Debug: Processing dtb_customer via fix4x method");
        } elseif ($tableName === 'dtb_order') {
            error_log("PostgreSQL Debug: Processing dtb_order via fix4x method - checking dependencies");

            // PostgreSQL: dtb_orderの依存関係チェック
            try {
                $customerCount = $this->em->fetchOne('SELECT COUNT(*) FROM dtb_customer');
                error_log("PostgreSQL Debug: fix4x dtb_order - Found $customerCount customers before processing");
            } catch (\Exception $e) {
                error_log("PostgreSQL Debug: fix4x dtb_order - Error checking customer count: " . $e->getMessage());
            }
        }

        $columns = $em->getSchemaManager()->listTableColumns($tableName);

        if ($columns == false) {
            error_log("PostgreSQL Debug: No columns found for table: $tableName");
            return;
        }

        error_log("PostgreSQL Debug: Found " . count($columns) . " columns for table: $tableName");
        $listTableColumns = [];
        foreach ($columns as $column) {
            $listTableColumns[] = $column->getName();
        }

        $platform = $this->dataMigrationService->begin($em);
        $this->dataMigrationService->resetTable($em, $tableName);

        $builder = new BulkInsertQuery($em, $tableName);
        $builder->setColumns($listTableColumns);

        $batchSize = 20;

        if (($handle = fopen($tmpDir . $csvName, 'r')) !== false) {
            error_log("PostgreSQL Debug: Successfully opened CSV file: " . $tmpDir . $csvName);
            // 文字コード問題が起きる可能性が高いので後で調整が必要になると思う
            $key = fgetcsv($handle);
            error_log("PostgreSQL Debug: CSV header: " . ($key ? implode(',', $key) : 'NULL'));
            // phpmyadminのcsvに余計なスペースが入っているので取り除く
            $key = array_filter(array_map('trim', $key));

            $i = 1;
            while (($row = fgetcsv($handle)) !== false) {
                if ($i <= 3) { // First 3 rows for debugging
                    error_log("PostgreSQL Debug: Row $i raw data: " . implode(',', $row));
                }

                // 1行目をkeyとした配列を作る
                $data = $this->dataMigrationService->convertNULL(array_combine($key, $row));
                if ($i <= 3) { // First 3 rows for debugging
                    error_log("PostgreSQL Debug: Row $i combined data: " . json_encode($data));
                }
                // Schemaにあわせた配列を作成する
                $value = [];
                foreach ($columns as $column) {

                    $columnName = $column->getName();
                    if ($column->getNotNull()) {
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : 0;
                    } else {
                        $value[$columnName] = isset($data[$columnName]) && $data[$columnName] !== '' ? $data[$columnName] : null;
                    }
                }

                $builder->setValues($value);

                if (($i % $batchSize) === 0) {
                    try {
                        $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                        $this->addSuccess($tableName, 'admin');
                    } catch (\Exception $e) {
                        $this->addDanger($e->getMessage(), 'admin');
                        $em->rollback();
                        return;
                    }
                }

                $i++;
            }

            if (count($builder->getValues()) > 0) {
                try {
                    $this->dataMigrationService->executeWithPostgreSQLFallback($builder, $tableName, $em);
                    $this->addSuccess($tableName, 'admin');
                } catch (\Exception $e) {
                    $this->addDanger($e->getMessage(), 'admin');
                    $em->rollback();
                    return;
                }
            }

            // PostgreSQL用のコミット処理
            try {
                $em->commit();
            } catch (\Exception $e) {
                error_log('PostgreSQL commit error in saveToC/P/O: ' . $e->getMessage());
                if ($em->isTransactionActive()) {
                    $em->rollback();
                }
                throw $e;
            }

            fclose($handle);

            return $i; // indexを返す
        }
    }

    /**
     * PostgreSQL TRUNCATE CASCADE で削除された基本データをCSVから復旧する
     */
    private function restoreEssentialData($em)
    {
        error_log("PostgreSQL Debug: Starting essential data restoration from CSV");
        file_put_contents('/tmp/restore_essential_data_called.txt', date('Y-m-d H:i:s') . ' - restoreEssentialData called' . PHP_EOL, FILE_APPEND);

        // CSVディレクトリのパスを取得（saveOrderメソッドと同じ方法）
        $cacheDir = $this->parameterBag->get('kernel.cache_dir');
        $csvDir = $cacheDir . '/Plugin/' . substr(sha1(__FILE__), 0, 8) . '/';

        try {
            // 1. dtb_base_info の確認・復旧
            $baseInfoCount = $em->fetchOne('SELECT COUNT(*) FROM dtb_base_info');
            error_log("PostgreSQL Debug: dtb_base_info count: $baseInfoCount");

            if ($baseInfoCount == 0) {
                error_log("PostgreSQL Debug: Restoring dtb_base_info from CSV");
                $this->restoreFromCSV($em, $csvDir, 'dtb_base_info');
            }

            // 2. mtb_authority の確認・復旧（移行対象データなのでCSVから復旧）
            $authorityCount = $em->fetchOne('SELECT COUNT(*) FROM mtb_authority');
            if ($authorityCount == 0) {
                error_log("PostgreSQL Debug: Restoring mtb_authority from CSV");
                $this->restoreFromCSV($em, $csvDir, 'mtb_authority');
            }

            // 3. mtb_country の確認・復旧（元のデータを復旧）
            $countryCount = $em->fetchOne('SELECT COUNT(*) FROM mtb_country');
            if ($countryCount == 0) {
                error_log("PostgreSQL Debug: Restoring mtb_country from CSV or default");
                if (!$this->restoreFromCSV($em, $csvDir, 'mtb_country')) {
                    // CSVから復旧できない場合は、EC-CUBEの標準的な国データを復旧
                    error_log("PostgreSQL Debug: Using default country data");
                    $em->exec("INSERT INTO mtb_country (id, name, sort_no, discriminator_type) VALUES (392, '日本', 1, 'country')");
                }
            }

            // 4. mtb_rounding_type の確認・復旧
            $roundingCount = $em->fetchOne('SELECT COUNT(*) FROM mtb_rounding_type');
            if ($roundingCount == 0) {
                error_log("PostgreSQL Debug: Restoring mtb_rounding_type from CSV or default");
                if (!$this->restoreFromCSV($em, $csvDir, 'mtb_rounding_type')) {
                    // CSVから復旧できない場合は標準データを復旧
                    error_log("PostgreSQL Debug: Using default rounding type data");
                    $em->exec("INSERT INTO mtb_rounding_type (id, name, sort_no, discriminator_type) VALUES (1, '四捨五入', 1, 'rounding')");
                    $em->exec("INSERT INTO mtb_rounding_type (id, name, sort_no, discriminator_type) VALUES (2, '切り捨て', 2, 'rounding')");
                    $em->exec("INSERT INTO mtb_rounding_type (id, name, sort_no, discriminator_type) VALUES (3, '切り上げ', 3, 'rounding')");
                }
            }

        } catch (\Exception $e) {
            error_log("PostgreSQL Debug: Essential data restoration error: " . $e->getMessage());
            // エラーが発生してもデータ移行処理は継続
        }

        error_log("PostgreSQL Debug: Essential data restoration completed");
    }

    /**
     * 指定されたテーブルのデータをCSVファイルから復旧する
     */
    private function restoreFromCSV($em, $csvDir, $tableName)
    {
        $csvFile = $csvDir . $tableName . '.csv';

        if (!file_exists($csvFile)) {
            error_log("PostgreSQL Debug: CSV file not found: $csvFile");
            return false;
        }

        try {
            error_log("PostgreSQL Debug: Restoring $tableName from CSV: $csvFile");

            // CSVファイルを読み込み
            if (($handle = fopen($csvFile, 'r')) === false) {
                error_log("PostgreSQL Debug: Cannot open CSV file: $csvFile");
                return false;
            }

            // ヘッダー行を読み込み
            $header = fgetcsv($handle);
            if ($header === false) {
                fclose($handle);
                error_log("PostgreSQL Debug: Cannot read CSV header: $csvFile");
                return false;
            }

            $restoredCount = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) {
                    continue; // スキップ
                }

                $data = array_combine($header, $row);
                $data = $this->dataMigrationService->convertNULL($data);

                // INSERT文を構築
                $columns = implode(', ', array_keys($data));
                $placeholders = ':' . implode(', :', array_keys($data));

                $sql = "INSERT INTO $tableName ($columns) VALUES ($placeholders)";

                try {
                    $stmt = $em->prepare($sql);
                    foreach ($data as $key => $value) {
                        $stmt->bindValue(":$key", $value);
                    }
                    $stmt->execute();
                    $restoredCount++;
                } catch (\Exception $insertError) {
                    error_log("PostgreSQL Debug: Insert error for $tableName: " . $insertError->getMessage());
                    // 個別のINSERTエラーは継続
                }
            }

            fclose($handle);
            error_log("PostgreSQL Debug: Restored $restoredCount records for $tableName from CSV");

            return $restoredCount > 0;

        } catch (\Exception $e) {
            error_log("PostgreSQL Debug: CSV restoration error for $tableName: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 全てのmtb_テーブルのレコード存在チェック
     */
    private function checkAllMasterTables($em)
    {
        error_log("PostgreSQL Debug: Starting master table existence check");

        try {
            // mtb_で始まる全テーブルを取得
            $masterTables = $em->fetchAllAssociative("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name LIKE 'mtb_%'
                ORDER BY table_name
            ");

            $emptyTables = [];
            $totalTables = count($masterTables);
            $emptyCount = 0;

            foreach ($masterTables as $table) {
                $tableName = $table['table_name'];
                $count = $em->fetchOne("SELECT COUNT(*) FROM {$tableName}");

                if ($count == 0) {
                    $emptyTables[] = $tableName;
                    $emptyCount++;
                }

                error_log("PostgreSQL Debug: {$tableName}: {$count} records");
            }

            error_log("PostgreSQL Debug: Master table check summary - Total: {$totalTables}, Empty: {$emptyCount}");

            if ($emptyCount > 0) {
                error_log("PostgreSQL Debug: Empty master tables: " . implode(', ', $emptyTables));
                $this->addDanger("警告: 以下のマスタテーブルが空です: " . implode(', ', $emptyTables), 'admin');
            } else {
                $this->addSuccess("全てのマスタテーブル({$totalTables}個)にデータが存在します", 'admin');
            }

            return ['total' => $totalTables, 'empty' => $emptyCount, 'emptyTables' => $emptyTables];

        } catch (\Exception $e) {
            error_log("PostgreSQL Debug: Master table check error: " . $e->getMessage());
            return ['total' => 0, 'empty' => 0, 'emptyTables' => []];
        }
    }

    /**
     * PostgreSQL向け2フェーズ処理：まず全テーブルをTRUNCATE CASCADE、その後依存関係順でINSERT
     */
    private function executePostgreSQLTwoPhaseProcess($em, $csvDir)
    {
        error_log("PostgreSQL Debug: Starting two-phase process (TRUNCATE then INSERT)");
        
        // Phase 1: TRUNCATE CASCADE all target tables
        $this->truncateAllTargetTables($em);
        
        // Phase 2: INSERT in dependency order
        $this->insertInDependencyOrder($em, $csvDir);
        
        error_log("PostgreSQL Debug: Two-phase process completed");
    }

    /**
     * Phase 1: 対象テーブルを全て TRUNCATE CASCADE でクリア
     */
    private function truncateAllTargetTables($em)
    {
        error_log("PostgreSQL Debug: Phase 1 - TRUNCATE CASCADE all target tables");
        
        // 移行対象テーブルリスト（依存関係を考慮した順序）
        $targetTables = [
            'dtb_customer',
            'dtb_product',
            'dtb_product_class',
            'dtb_product_stock',
            'dtb_product_image',
            'dtb_product_category',
            'dtb_category',
            'dtb_class_category',
            'dtb_class_name',
            'dtb_delivery',
            'dtb_delivery_fee',
            'dtb_delivery_time',
            'dtb_payment',
            'dtb_order',
            'dtb_order_item',
            'dtb_shipping',
            'dtb_mail_history',
            'dtb_tax_rule',
            // 必要なマスターテーブル
            'mtb_authority',
            'dtb_base_info'
        ];
        
        foreach ($targetTables as $table) {
            try {
                error_log("PostgreSQL Debug: TRUNCATE CASCADE {$table}");
                $em->exec("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
            } catch (\Exception $e) {
                error_log("PostgreSQL Debug: TRUNCATE CASCADE failed for {$table}: " . $e->getMessage());
                // 一部のテーブルが存在しない場合は継続
            }
        }
        
        error_log("PostgreSQL Debug: Phase 1 completed - All target tables truncated");
    }

    /**
     * Phase 2: 依存関係順にINSERTを実行
     */
    private function insertInDependencyOrder($em, $csvDir)
    {
        error_log("PostgreSQL Debug: Phase 2 - INSERT in dependency order");
        
        // 1. まず基本データとマスターデータ
        $this->restoreEssentialData($em);
        
        // 2. 顧客データ
        $this->saveToC($em, $csvDir, 'dtb_customer');
        
        // 3. 商品関連（依存関係順）
        $this->saveToC($em, $csvDir, 'dtb_category');
        $this->saveToC($em, $csvDir, 'dtb_class_name');
        $this->saveToC($em, $csvDir, 'dtb_class_category');
        $this->saveToC($em, $csvDir, 'dtb_product');
        $this->saveToC($em, $csvDir, 'dtb_product_class');
        $this->saveToC($em, $csvDir, 'dtb_product_stock');
        $this->saveToC($em, $csvDir, 'dtb_product_image');
        $this->saveToC($em, $csvDir, 'dtb_product_category');
        
        // 4. 配送・支払い関連
        $this->saveToC($em, $csvDir, 'dtb_delivery');
        $this->saveToC($em, $csvDir, 'dtb_delivery_fee');
        $this->saveToC($em, $csvDir, 'dtb_delivery_time');
        $this->saveToC($em, $csvDir, 'dtb_payment');
        
        // 5. 注文関連（最後）
        $this->saveToO($em, $csvDir, 'dtb_order');
        $this->saveToO($em, $csvDir, 'dtb_order_item');
        $this->saveToO($em, $csvDir, 'dtb_shipping');
        
        if ($this->dataMigrationService->isVersion('4.0/4.1') || $this->dataMigrationService->isVersion('3')) {
            $this->saveToO($em, $csvDir, 'dtb_mail_history');
        } else {
            $this->saveToO($em, $csvDir, 'dtb_mail_history', 'dtb_mail_history');
        }
        
        $this->saveToO($em, $csvDir, 'dtb_tax_rule', null, true);
        
        error_log("PostgreSQL Debug: Phase 2 completed - All data inserted in dependency order");
    }

    /**
     * PostgreSQL処理完了後の後処理
     */
    private function finalizePostgreSQLProcess($em, $platform)
    {
        error_log("PostgreSQL Debug: Starting finalization process");
        
        try {
            // ID Sequenceの設定
            $this->dataMigrationService->setIdSeq($em, 'dtb_order');
            $this->dataMigrationService->setIdSeq($em, 'dtb_order_item');
            $this->dataMigrationService->setIdSeq($em, 'dtb_shipping');
            $this->dataMigrationService->setIdSeq($em, 'dtb_payment');
            $this->dataMigrationService->setIdSeq($em, 'dtb_delivery');
            $this->dataMigrationService->setIdSeq($em, 'dtb_delivery_fee');
            $this->dataMigrationService->setIdSeq($em, 'dtb_delivery_time');
            $this->dataMigrationService->setIdSeq($em, 'dtb_tax_rule');
            $this->dataMigrationService->setIdSeq($em, 'dtb_mail_history');
            $this->dataMigrationService->setIdSeq($em, 'dtb_customer');
            $this->dataMigrationService->setIdSeq($em, 'dtb_product');
            $this->dataMigrationService->setIdSeq($em, 'dtb_product_class');
            
            // 注文ステータスのクリーンアップ
            error_log("PostgreSQL Debug: Executing order status cleanup");
            try {
                $updateCount = $em->exec('UPDATE dtb_order SET order_status_id = NULL WHERE order_status_id not in (select id from mtb_order_status)');
                error_log("PostgreSQL Debug: Updated $updateCount orders with invalid status");
            } catch (\Exception $e) {
                error_log("PostgreSQL Debug: UPDATE failed for order status cleanup, skipping: " . $e->getMessage());
            }
            
            // 全マスタテーブルの存在チェック
            $this->checkAllMasterTables($em);
            
            // PostgreSQL用のコミット処理
            try {
                error_log("PostgreSQL Debug: Committing two-phase transaction");
                $em->commit();
                error_log("PostgreSQL Debug: Two-phase transaction committed successfully");
            } catch (\Exception $commitError) {
                error_log("PostgreSQL Debug: Commit failed: " . $commitError->getMessage());
                try {
                    $em->rollBack();
                    error_log("PostgreSQL Debug: Transaction rolled back after commit failure");
                } catch (\Exception $rollbackError) {
                    error_log("PostgreSQL Debug: Rollback also failed: " . $rollbackError->getMessage());
                }
                throw $commitError;
            }
            
            error_log("PostgreSQL Debug: Finalization completed successfully");
            
        } catch (\Exception $e) {
            error_log("PostgreSQL Debug: Finalization error: " . $e->getMessage());
            throw $e;
        }
    }
}
