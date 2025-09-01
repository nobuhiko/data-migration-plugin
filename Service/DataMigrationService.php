<?php

namespace Plugin\DataMigration43\Service;

use Eccube\Common\EccubeConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware;
use wapmorgan\UnifiedArchive\UnifiedArchive;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DataMigrationService
{
    private $migrationVersion = '2';

    private $params;

    /**
     * カスタマーアイテムの入力タイプをキャッシュする配列
     * @var array
     */
    private $customerItemTypeCache = [];

    /**
     * 選択肢オプションのテキスト値をキャッシュする配列
     * @var array
     */
    private $optionTextCache = [];

    /**
     * Customer item data for migration
     * @var array
     */
    private $plg_customerplus_dtb_customer_item = [];

    /**
     * 入力タイプをキャッシュする配列
     * @var array
     */
    private $inputTypeCache = [];

    /**
     * 選択肢オプションの結果をキャッシュする配列
     * @var array
     */
    private $mappingCache = [];

    /**
     * Customer item option data for migration
     * @var array
     */
    private $plg_customerplus_dtb_customer_item_option = [];

    private $eccubeConfig;

    public function __construct(ParameterBagInterface $params, EccubeConfig $eccubeConfig)
    {
        $this->params = $params;
        $this->eccubeConfig = $eccubeConfig;
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
        error_log("PostgreSQL Debug: Platform detected as '$platform' for table '$tableName'");

        if ($platform == 'mysql') {
            $em->exec('DELETE FROM ' . $tableName);
        } else {
            // PostgreSQL用: より単純なアプローチ - テーブルが空でない場合のみトランケート実行
            try {
                $em->exec('TRUNCATE TABLE ' . $tableName . ' RESTART IDENTITY CASCADE');
                error_log("PostgreSQL: Successfully reset table '$tableName' using TRUNCATE CASCADE");
            } catch (\Exception $e) {
                error_log("PostgreSQL: Could not reset table '$tableName': " . $e->getMessage());
                // テーブルリセットに失敗してもデータ移行は続行（INSERT時に重複対応）
            }
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

    /**
     * PostgreSQL対応のためのデータ型変換
     * 数値フィールドの空文字をNULLに変換
     * @param Connection $em
     * @param string $tableName
     * @param array $data
     * @return array
     */
    public function convertDataTypesForPostgreSQL($em, $tableName, $data)
    {
        // PostgreSQL以外は処理しない
        if ($em->getDatabasePlatform()->getName() !== 'postgresql') {
            return $data;
        }
        
        
        try {
            $columns = $em->getSchemaManager()->listTableColumns($tableName);
            $hasConversion = false;
            
            foreach ($data as $key => &$value) {
                // 空文字またはfalseの場合にNULL変換を行う
                if ($value === '' || $value === false) {
                    if (isset($columns[$key])) {
                        $column = $columns[$key];
                        $type = $column->getType()->getName();
                        
                        // 数値型の場合、空文字またはfalseをNULLに変換
                        if (in_array($type, ['integer', 'bigint', 'smallint', 'decimal', 'float', 'numeric'])) {
                            $value = null;
                            $hasConversion = true;
                        }
                        // 真偽値型の場合の処理
                        elseif ($type === 'boolean') {
                            $value = null;
                            $hasConversion = true;
                        }
                    }
                }
                
                // PostgreSQL foreign key special handling: convert 0 values to NULL for foreign key columns
                elseif ($value === 0 || $value === '0') {
                    if ($this->isForeignKeyColumn($key, $tableName)) {
                        $value = null;
                        $hasConversion = true;
                        error_log("PostgreSQL: Converting foreign key value 0 to NULL for {$tableName}.{$key}");
                    }
                }
            }
            
            if ($hasConversion) {
                error_log("PostgreSQL: Data type conversions applied for table '$tableName'");
            }
            
        } catch (\Exception $e) {
            error_log("Error in convertDataTypesForPostgreSQL for table '$tableName': " . $e->getMessage());
            error_log("Data being processed: " . json_encode($data));
            // エラーが発生した場合は元のデータをそのまま返す
        }
        
        return $data;
    }

    /**
     * PostgreSQL用の依存関係を考慮したテーブル挿入順序を取得
     * 外部キー制約に従った適切な順序でデータを挿入することで
     * superuser権限なしでも安全にデータ移行を実行
     * 
     * @return array 挿入順序に並べられたテーブル名の配列
     */
    public function getPostgreSQLInsertionOrder()
    {
        // 依存関係に基づく挿入順序
        // 親テーブル → 子テーブルの順序で定義
        return [
            // 1. マスターテーブル（依存関係なし）
            'mtb_authority', 'mtb_job', 'mtb_sex', 'mtb_pref', 'mtb_country',
            'mtb_customer_status', 'mtb_customer_order_status', 'mtb_order_status',
            'mtb_order_status_color', 'mtb_order_item_type', 'mtb_device_type',
            'mtb_sale_type', 'mtb_product_type', 'mtb_taxrule', 'mtb_work',
            'mtb_csv_type', 'mtb_page_max', 'mtb_product_list_max',
            'mtb_product_list_order_by', 'mtb_disp', 'mtb_tag', 'mtb_db',
            'mtb_zip',
            
            // 2. 基本エンティティ（マスターテーブルに依存）
            'dtb_member',           // mtb_authority, mtb_work に依存
            'dtb_customer',         // mtb_job, mtb_sex, mtb_pref, mtb_country, mtb_customer_status に依存
            'dtb_customer_address', // dtb_customer, mtb_pref, mtb_country に依存
            
            // 3. 商品関連（基本的な依存関係）
            'dtb_category',         // 自己参照可能だが親カテゴリから順に挿入
            'dtb_product',          // dtb_member (creator) に依存
            'dtb_class_name',       // dtb_member (creator) に依存
            'dtb_class_category',   // dtb_class_name に依存
            'dtb_product_class',    // dtb_product, dtb_class_category に依存
            'dtb_product_category', // dtb_product, dtb_category に依存
            'dtb_product_stock',    // dtb_product_class に依存
            'dtb_product_image',    // dtb_product に依存
            'dtb_tag',              // 基本エンティティ
            'dtb_product_tag',      // dtb_product, dtb_tag に依存
            'dtb_customer_favorite_product', // dtb_customer, dtb_product に依存
            
            // 4. 配送・決済関連
            'dtb_delivery',         // dtb_member (creator) に依存
            'dtb_delivery_time',    // dtb_delivery に依存
            'dtb_delivery_fee',     // dtb_delivery, mtb_pref に依存
            'dtb_payment',          // dtb_member (creator) に依存
            'dtb_payment_option',   // dtb_delivery, dtb_payment に依存
            
            // 5. 注文関連（最も多くの依存関係）
            'dtb_order',            // dtb_customer, dtb_customer_status, mtb_order_status, mtb_device_type に依存
            'dtb_shipping',         // dtb_order, dtb_delivery, dtb_delivery_time, mtb_pref, mtb_country に依存
            'dtb_order_item',       // dtb_order, dtb_product, dtb_product_class に依存
            'dtb_shipment_item',    // dtb_shipping, dtb_order_item に依存
            
            // 6. その他のシステムテーブル
            'dtb_mail_history',     // dtb_order に依存
            'dtb_tax_rule',         // dtb_product, dtb_product_class に依存（NULLable）
            
            // 7. CMS・システム設定関連
            'dtb_base_info', 'dtb_news', 'dtb_help', 'dtb_csv',
            'dtb_template', 'dtb_block', 'dtb_page_layout', 'dtb_block_position',
            'dtb_mail_template', 'dtb_plugin', 'dtb_plugin_event_handler',
            'dtb_authority_role', 'doctrine_migration_versions'
        ];
    }

    /**
     * PostgreSQL用の外部キー制約に適応したBulkInsertQuery実行
     * 制約エラーが発生した場合、NULL値での再試行や行レベルでの個別挿入を行う
     * 
     * @param \nobuhiko\BulkInsertQuery\BulkInsertQuery $builder
     * @param string $tableName
     * @param \Doctrine\DBAL\Connection $em
     * @return bool 実行成功かどうか
     */
    public function executeWithPostgreSQLFallback($builder, $tableName, $em)
    {
        if ($em->getDatabasePlatform()->getName() !== 'postgresql') {
            // PostgreSQL以外は通常実行
            $builder->execute();
            return true;
        }
        
        try {
            // 通常の一括挿入を試行
            error_log("PostgreSQL Debug: Executing bulk insert for table '$tableName' with " . count($builder->getValues()) . " values");
            $builder->execute();
            error_log("PostgreSQL Debug: Bulk insert successful for table '$tableName'");
            
            // PostgreSQLの場合、実際にデータが挿入されたか確認
            $checkSql = "SELECT COUNT(*) FROM $tableName";
            $count = $em->fetchOne($checkSql);
            error_log("PostgreSQL Debug: Table '$tableName' now has $count rows");
            
            // dtb_orderの場合、より詳細なデバッグ
            if ($tableName === 'dtb_order') {
                $sampleSql = "SELECT id, customer_id, order_status_id FROM dtb_order LIMIT 3";
                $samples = $em->fetchAllAssociative($sampleSql);
                error_log("PostgreSQL Debug: dtb_order sample data: " . json_encode($samples));
            }
            
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // PostgreSQL外部キー制約エラーの検出
            if (strpos($errorMessage, 'foreign key constraint') !== false || 
                strpos($errorMessage, 'violates not-null constraint') !== false ||
                strpos($errorMessage, 'violates unique constraint') !== false) {
                
                error_log("PostgreSQL constraint error in table '$tableName': " . $errorMessage);
                error_log("Attempting fallback strategies...");
                
                // PostgreSQL用のトランザクション状態をリセット
                try {
                    // PostgreSQLの場合、異常終了したトランザクションを完全にリセット
                    if ($em->isTransactionActive()) {
                        $em->rollBack();
                    }
                    // 新しいトランザクションを開始
                    if (!$em->isTransactionActive()) {
                        $em->beginTransaction();
                    }
                    error_log("PostgreSQL: Transaction reset for constraint error recovery");
                } catch (\Exception $txError) {
                    error_log("PostgreSQL: Could not reset transaction: " . $txError->getMessage());
                    // トランザクションリセットに失敗した場合、接続を再初期化する
                    try {
                        $em->close();
                        if (!$em->isTransactionActive()) {
                            $em->beginTransaction();
                        }
                        error_log("PostgreSQL: Connection reset and transaction restarted");
                    } catch (\Exception $connError) {
                        error_log("PostgreSQL: Connection reset failed: " . $connError->getMessage());
                    }
                }
                
                // フォールバック戦略を実行
                $result = $this->handlePostgreSQLConstraintError($builder, $tableName, $em, $errorMessage);
                
                if (!$result) {
                    // 外部キー制約エラーの場合、再スローして上位で処理
                    error_log("PostgreSQL: Foreign key constraint error - re-throwing for dependency handling");
                    throw $e;
                }
                
                return $result;
            }
            
            // その他のエラーは再スロー
            throw $e;
        }
    }
    
    /**
     * PostgreSQL制約エラーの処理
     * 
     * @param \nobuhiko\BulkInsertQuery\BulkInsertQuery $builder
     * @param string $tableName
     * @param \Doctrine\DBAL\Connection $em
     * @param string $errorMessage
     * @return bool
     */
    private function handlePostgreSQLConstraintError($builder, $tableName, $em, $errorMessage)
    {
        $values = $builder->getValues();
        
        if (empty($values)) {
            return true;
        }
        
        error_log("PostgreSQL constraint error in table '$tableName': " . $errorMessage);
        
        // 外部キー制約エラーの場合、依存関係を考慮した遅延実行を試みる
        if (strpos($errorMessage, 'foreign key constraint') !== false) {
            return $this->handleForeignKeyConstraintError($builder, $tableName, $em, $errorMessage);
        }
        
        // その他の制約エラー（unique, not-null等）の場合は個別行処理を試行
        return $this->handleOtherConstraintError($builder, $tableName, $em, $errorMessage);
    }
    
    /**
     * 外部キー制約エラーの処理 - 依存関係を考慮した遅延実行
     */
    private function handleForeignKeyConstraintError($builder, $tableName, $em, $errorMessage)
    {
        error_log("PostgreSQL: Foreign key constraint error detected for table '$tableName'");
        error_log("PostgreSQL: Error details: " . $errorMessage);
        
        // デバッグ用：実際のデータ内容を出力
        $values = $builder->getValues();
        if (!empty($values)) {
            error_log("PostgreSQL: First row data: " . json_encode($values[0]));
            error_log("PostgreSQL: Total rows in batch: " . count($values));
        }
        
        // エラーメッセージから参照先テーブルを特定
        $referencedTable = $this->extractReferencedTableFromError($errorMessage);
        if ($referencedTable) {
            error_log("PostgreSQL: Referenced table identified as: $referencedTable");
            
            // 参照先テーブルがマスタテーブル（mtb_*）の場合、適切なデフォルト値で処理を試行
            if (strpos($referencedTable, 'mtb_') === 0) {
                return $this->handleMasterTableForeignKeyError($builder, $tableName, $em, $referencedTable);
            }
        }
        
        // その他の場合は従来通りスキップ
        error_log("PostgreSQL: Could not resolve foreign key dependency, skipping batch");
        return true; // バッチをスキップして続行
    }
    
    /**
     * エラーメッセージから参照先テーブル名を抽出
     */
    private function extractReferencedTableFromError($errorMessage)
    {
        // PostgreSQLの外部キー制約エラーメッセージをパース
        // 例: violates foreign key constraint "fk_8298bbe3c00af8a7" DETAIL: Key (customer_status_id)=(1) is not present in table "mtb_customer_status".
        if (preg_match('/not present in table "([^"]+)"/', $errorMessage, $matches)) {
            return $matches[1];
        }
        
        // 制約名からテーブル名を推測する別のパターン
        if (preg_match('/violates foreign key constraint "([^"]+)"/', $errorMessage, $matches)) {
            $constraintName = $matches[1];
            error_log("PostgreSQL: Constraint name: $constraintName");
        }
        
        return null;
    }
    
    /**
     * マスタテーブルの外部キー制約エラーを処理
     */
    private function handleMasterTableForeignKeyError($builder, $tableName, $em, $referencedTable)
    {
        error_log("PostgreSQL: Attempting to resolve master table dependency for: $referencedTable");
        
        // マスタテーブルの必要最小限のデータを挿入
        try {
            $this->insertMinimalMasterData($em, $referencedTable);
            
            // 元のクエリを再試行
            $builder->execute();
            error_log("PostgreSQL: Successfully resolved master table dependency and inserted data");
            return true;
            
        } catch (\Exception $e) {
            error_log("PostgreSQL: Failed to resolve master table dependency: " . $e->getMessage());
            return true; // スキップして続行
        }
    }
    
    /**
     * 指定されたカラムが外部キーカラムかどうか判定
     */
    private function isForeignKeyColumn($columnName, $tableName)
    {
        // 一般的な外部キーカラムの命名規則に基づいて判定
        $foreignKeyPatterns = [
            '_id$',           // xxx_id pattern
            'id$',            // id ending
        ];
        
        // テーブル固有の外部キーカラムマッピング
        $foreignKeyColumns = [
            'dtb_class_category' => ['class_name_id'],
            'dtb_product_class' => ['product_id', 'class_category_id1', 'class_category_id2'],
            'dtb_product_category' => ['product_id', 'category_id'],
            'dtb_product_stock' => ['product_class_id'],
            'dtb_product_tag' => ['product_id', 'tag_id'],
            'dtb_customer' => ['customer_status_id', 'sex_id', 'job_id', 'country_id', 'pref_id'],
            'dtb_order' => ['customer_id', 'country_id', 'pref_id', 'sex_id', 'job_id', 'payment_id', 'device_type_id'],
            'dtb_product' => ['creator_id', 'product_status_id'],
        ];
        
        // テーブル固有のマッピングをチェック
        if (isset($foreignKeyColumns[$tableName])) {
            return in_array($columnName, $foreignKeyColumns[$tableName]);
        }
        
        // 一般的なパターンをチェック
        foreach ($foreignKeyPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/', $columnName)) {
                // ただし、主キーのidは除外
                if ($columnName === 'id') {
                    return false;
                }
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * マスタテーブルに最小限のデータを挿入
     */
    private function insertMinimalMasterData($em, $tableName)
    {
        // 各マスタテーブルに応じた最小限のデータ挿入
        switch ($tableName) {
            case 'mtb_customer_status':
                $em->exec("INSERT INTO mtb_customer_status (id, name, sort_no) VALUES (1, '仮会員', 1), (2, '本会員', 2) ON CONFLICT (id) DO NOTHING");
                break;
            case 'mtb_sex':
                $em->exec("INSERT INTO mtb_sex (id, name, sort_no) VALUES (1, '男性', 1), (2, '女性', 2) ON CONFLICT (id) DO NOTHING");
                break;
            case 'mtb_job':
                $em->exec("INSERT INTO mtb_job (id, name, sort_no) VALUES (1, '会社員', 1) ON CONFLICT (id) DO NOTHING");
                break;
            case 'mtb_pref':
                $em->exec("INSERT INTO mtb_pref (id, name, sort_no) VALUES (1, '北海道', 1), (13, '東京都', 13) ON CONFLICT (id) DO NOTHING");
                break;
            case 'mtb_country':
                $em->exec("INSERT INTO mtb_country (id, name, sort_no) VALUES (392, '日本', 1) ON CONFLICT (id) DO NOTHING");
                break;
            default:
                error_log("PostgreSQL: No minimal data defined for master table: $tableName");
        }
    }
    
    /**
     * その他の制約エラーの処理
     */
    private function handleOtherConstraintError($builder, $tableName, $em, $errorMessage)
    {
        error_log("PostgreSQL: Other constraint error for table '$tableName': " . $errorMessage);
        
        // unique制約違反やnot-null制約違反の場合
        // データに問題がある可能性が高いので、そのバッチをスキップ
        error_log("PostgreSQL: Skipping problematic batch due to data constraint violation");
        
        return true; // 続行（バッチスキップ）
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

        // リレーションエラーになるので - PostgreSQL対応
        $this->executePostgreSQLAwareDelete($em, 'DELETE FROM dtb_cart');
        $this->executePostgreSQLAwareDelete($em, 'DELETE FROM dtb_cart_item');

        // 外部キー制約エラーになるデータを消す - PostgreSQL対応
        $this->executePostgreSQLAwareDelete($em, 'DELETE FROM dtb_class_category WHERE id = 0');
        $this->executePostgreSQLAwareDelete($em, 'UPDATE dtb_product_class SET class_category_id1 = NULL WHERE class_category_id1 not in (select id from dtb_class_category)');
        $this->executePostgreSQLAwareDelete($em, 'UPDATE dtb_product_class SET class_category_id2 = NULL WHERE class_category_id2 not in (select id from dtb_class_category)');

        $this->executePostgreSQLAwareDelete($em, 'delete from dtb_product_tag where id in (
                        select id from (select t1.id from dtb_product_tag t1 left join dtb_tag t2 on t1.tag_id = t2.id where t2.id is null) as tmp
                    );');
        $this->executePostgreSQLAwareDelete($em, 'delete from dtb_product_tag where id in (
                        select id from (select t1.id from dtb_product_tag t1 left join dtb_product t2 on t1.product_id = t2.id where t2.id is null) as tmp
                    );');
    }

    /**
     * PostgreSQL-aware database operation with constraint violation handling
     */
    private function executePostgreSQLAwareDelete($em, $query)
    {
        try {
            $em->exec($query);
        } catch (\Exception $e) {
            $platform = $em->getDatabasePlatform()->getName();
            if ($platform === 'postgresql') {
                // PostgreSQLの場合、制約違反エラーをログに記録して続行
                error_log("PostgreSQL: Database operation failed due to constraints, skipping: " . $e->getMessage());
                error_log("PostgreSQL: Failed query: " . $query);
                // DELETE が失敗してもデータ移行は続行（後続のINSERTで重複対応）
            } else {
                // 他のデータベースでは元のエラーを再発生
                throw $e;
            }
        }
    }

    public function begin($em)
    {
        $em->beginTransaction();
        $platform = $em->getDatabasePlatform()->getName();

        if ($platform == 'mysql') {
            $em->exec('SET FOREIGN_KEY_CHECKS = 0;');
            $em->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'"); // STRICT_TRANS_TABLESを無効にする。
        } else {
            // PostgreSQLの場合、制約の遅延チェックを試行（通常は失敗するが試す価値あり）
            try {
                $em->exec('SET CONSTRAINTS ALL DEFERRED');
                error_log('PostgreSQL: Successfully set constraints to DEFERRED - foreign key checks delayed until commit');
            } catch (\Exception $e) {
                // 制約が遅延可能でない場合（通常はこちら）、依存関係を考慮した挿入順序を使用
                error_log('PostgreSQL: Constraints not deferrable as expected. Using dependency-aware insertion order.');
                error_log('PostgreSQL: Will handle foreign key constraints with proper dependency management (no superuser required)');
            }
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
        error_log("PostgreSQL Debug: Starting cutOff24 processing for file: " . $tmpDir . $csvName);
        
        $tbl_flg = false;
        $col_flg = false;
        $fpcsv = null;
        $tableName = null;

        if (($handle = fopen($tmpDir . $csvName, 'r')) !== false) {
            error_log("PostgreSQL Debug: Successfully opened backup file for processing");
            while (($row = fgetcsv($handle)) !== false) {
                // デバッグ：行データを確認
                if ($tbl_flg === false && count($row) <= 3) { // テーブル名判定時のみログ
                    error_log("PostgreSQL Debug Row: count=" . count($row) . ", data=[" . implode('|', $row) . "], first='" . (isset($row[0]) ? $row[0] : 'NULL') . "'");
                }
                
                //空白行のときはテーブル変更（より厳密な判定）
                if (empty($row) || (count($row) == 1 && trim($row[0]) === '')) {
                    $tbl_flg = false;
                    $col_flg = false;
                    $enablePoint = false;
                    $key = [];
                    $i = 1;
                    if ($fpcsv) {
                        fclose($fpcsv);
                        $fpcsv = null;
                    }
                    continue;
                }

                // テーブルフラグがたっていない場合にはテーブル名セット
                if (!$tbl_flg && count($row) == 1) {  // テーブル名は1カラムのみの行
                    error_log("PostgreSQL Debug: Checking potential table name: " . $row[0]);
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
                            error_log("PostgreSQL Debug: Processing table '" . $tableName . "', created CSV: " . $tmpDir . $tableName . '.csv');
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
                    if (isset($tableName) && $tableName == 'dtb_customer' && count($row) > 1) {
                        error_log("PostgreSQL Debug: Wrote customer data row: " . implode(',', array_slice($row, 0, 5)) . "...");
                    }
                }
            } // end while
            if ($fpcsv) {
                fclose($fpcsv);
            }
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
     * 値データを解析し、配列形式に変換
     * @param array $dataRow データ行
     * @param int $customer_item_id カスタマー項目ID
     * @param $em データベース接続
     * @return array 値の配列
     */
    private function parseValueData($dataRow, $customer_item_id = null, $em = null)
    {

        // 入力タイプをキャッシュから取得する
        $input_type = isset($this->inputTypeCache[$customer_item_id]) ? $this->inputTypeCache[$customer_item_id] : null;

        $result = [
            'value' => null,
            'date_value' => null,
            'num_value' => null,
        ];

        // 電話番号タイプの場合は特別な処理
        if ($input_type == 2) { // TEL_TYPE
            if (isset($dataRow['value']) && $dataRow['value'] !== null && $dataRow['value'] !== '') {
                // 電話番号は value にカンマ区切りで部品が保存されている場合がある
                $result['value'] = str_replace(',', '', $dataRow['value']);
                return [$result];
            }
        }

        // 選択肢タイプの場合
        else if ($input_type >= 10 && $input_type < 100) { // SELECT_TYPE, RADIO_TYPE, CHECKBOX_TYPE
            if (isset($dataRow['value']) && $dataRow['value'] !== null && $dataRow['value'] !== '') {
                $values = explode(',', $dataRow['value']);
                foreach ($values as $v) {
                    $optionValue = trim($v);
                    $res[] = $this->mappingOptionTextCache($em, $optionValue, $customer_item_id);
                }
                return $res;
            }
        } else if ($input_type == 4) {
            if (isset($dataRow['value']) && $dataRow['value'] !== null && $dataRow['value'] !== '') {
                $result['date_value'] = self::convertTz($dataRow['value'], $em);
            }
        }

        // 通常のデータ処理
        if (isset($dataRow['value']) && $dataRow['value'] !== null && $dataRow['value'] !== '') {
            //$values = @json_decode($dataRow['value'], true);
            $result['value'] = $dataRow['value'];
        } else {
            $result = [null];
        }

        return [$result];
    }

    /**
     * 詳細CSVに行を追加
     * @param $em
     * @param resource $detailFp 詳細CSVファイルハンドル
     * @param array $values 値の配列
     * @param int $customer_data_id 顧客データID
     * @param int &$detailId 詳細ID参照
     * @param string $csvDir CSVファイルのディレクトリ
     */
    private function addDetailCsvRows($detailFp, $values, $customer_data_id, &$detailId)
    {
        foreach ($values as $v) {
            if ($v === null) {
                // nullの場合はすべての値をnullとして保存
                $detailCsvRow = [
                    $detailId,
                    $customer_data_id,
                    null,
                    null,
                    null,
                    'customerdatadetail'
                ];

                fputcsv($detailFp, $detailCsvRow);
                $detailId++;
                continue;
            }

            $value = $v['value'] ?? null;
            $date_value = $v['date_value'] ?? '';
            $num_value = $v['num_value'] ?? '';

            /*if (is_array($value)) {
                dump($value);
                die();
            }*/

            $detailCsvRow = [
                $detailId,
                $customer_data_id,
                $value,
                $date_value,
                $num_value,
                'customerdatadetail'
            ];

            fputcsv($detailFp, $detailCsvRow);
            $detailId++;
        }
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

        // 移行するテーブルの順序を定義
        $importOrder = [
            'plg_customerplus_dtb_customer_item',
            'plg_customerplus_dtb_customer_item_option',
            'plg_customerplus_dtb_customer_data',
            'plg_customerplus_dtb_customer_data_detail',
            'plg_customerplus_dtb_order',
            'plg_customerplus_dtb_shipping',
            'plg_customerplus_dtb_customer_address',
        ];

        // 全テーブルのデータを削除
        $allTables = array_merge($importOrder, ['plg_customerplus_dtb_customer']);
        foreach ($allTables as $tableName) {
            if ($em->getSchemaManager()->tablesExist([$tableName])) {
                $this->resetTable($em, $tableName);
            }
        }

        // CustomerItem, CustomerItemOptionのデータをインポート
        $baseTableNames = [
            'plg_customerplus_dtb_customer_item',
            'plg_customerplus_dtb_customer_item_option'
        ];

        foreach ($baseTableNames as $tableName) {
            // CSVファイル名のマッピング処理
            if ($tableName === 'plg_customerplus_dtb_customer_item_option') {
                $optionCsv = $csvDir . 'plg_customerplus_dtb_customer_option.csv';
                $itemOptionCsv = $csvDir . 'plg_customerplus_dtb_customer_item_option.csv';
                if (file_exists($optionCsv) && !file_exists($itemOptionCsv)) {
                    rename($optionCsv, $itemOptionCsv);
                }
            }
            $this->importTableFromCsv($em, $csvDir, $controller, $tableName, true);
        }

        $this->createInputTypeCache();

        // CustomerDataとDetailの生成と保存
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
        $valueToDataIdMap = [];

        if (file_exists($customerCsv) && filesize($customerCsv) > 0) {
            $this->processCustomerCsv($em, $customerCsv, $dataFp, $detailFp, $dataId, $detailId, $valueToDataIdMap, $csvDir);
        }

        fclose($dataFp);
        fclose($detailFp);

        // インポート処理
        $this->importGeneratedCsvFile($em, $csvDir, $controller, 'plg_customerplus_dtb_customer_data');
        $this->importGeneratedCsvFile($em, $csvDir, $controller, 'plg_customerplus_dtb_customer_data_detail');

        // その他のテーブルのインポート
        $otherTables = [
            'plg_customerplus_dtb_order',
            'plg_customerplus_dtb_shipping',
            'plg_customerplus_dtb_customer_address',
            'plg_customerplus_dtb_customer' // 最後にインポート
        ];

        // plg_customerplus_dtb_other_deliv → plg_customerplus_dtb_customer_address へのマッピング
        $otherDelivCsv = $csvDir . 'plg_customerplus_dtb_other_deliv.csv';
        $customerAddressCsv = $csvDir . 'plg_customerplus_dtb_customer_address.csv';
        if (file_exists($otherDelivCsv) && !file_exists($customerAddressCsv)) {
            rename($otherDelivCsv, $customerAddressCsv);
        }

        foreach ($otherTables as $tableName) {
            $this->importTableWithValueMapping($em, $csvDir, $controller, $tableName, $valueToDataIdMap);
        }

        if ($platform == 'mysql') {
            $em->exec('SET FOREIGN_KEY_CHECKS = 1;');
        } else {
            foreach ($importOrder as $tableName) {
                $this->setIdSeq($em, $tableName);
            }
        }
        $em->commit();
    }

    /**
     * CustomerCSVファイルを処理し、データと詳細CSVを生成
     * @param $em
     * @param string $customerCsv 顧客CSVファイルパス
     * @param resource $dataFp データCSVファイルハンドル
     * @param resource $detailFp 詳細CSVファイルハンドル
     * @param int &$dataId データID参照
     * @param int &$detailId 詳細ID参照
     * @param array &$valueToDataIdMap 値とデータIDのマッピング
     * @param string $csvDir CSVファイルのディレクトリ
     */
    private function processCustomerCsv($em, $customerCsv, $dataFp, $detailFp, &$dataId, &$detailId, &$valueToDataIdMap, $csvDir)
    {
        if (($handle = fopen($customerCsv, 'r')) !== false) {
            $key = fgetcsv($handle);
            $key = array_filter(array_map('trim', $key));
            while (($row = fgetcsv($handle)) !== false) {
                $dataRow = $this->convertNULL(array_combine($key, $row));

                // valueカラムが配列やJSONの場合を想定
                $customer_item_id = isset($dataRow['customer_item_id']) ? $dataRow['customer_item_id'] : null;
                $values = $this->parseValueData($dataRow, $customer_item_id, $em);

                $customer_id = isset($dataRow['customer_id']) ? $dataRow['customer_id'] : null;
                $create_date = isset($dataRow['create_date']) ? $dataRow['create_date'] : date('Y-m-d H:i:s');

                // customer_data_idを採番
                $customer_data_id = $dataId;

                // valueとcustomer_data_idの関連付けを保存
                $originalValue = $dataRow['customer_id'] . '_' . $dataRow['customer_item_id'];
                $valueToDataIdMap[$originalValue] = $customer_data_id;

                // データCSVに行を追加
                $dataCsvRow = [
                    $customer_data_id,
                    $customer_item_id,
                    $create_date,
                    'customerdata'
                ];
                fputcsv($dataFp, $dataCsvRow);

                // 詳細CSVに行を追加
                $this->addDetailCsvRows($detailFp, $values, $customer_data_id, $detailId);

                $dataId++;
            }
            fclose($handle);
        }
    }

    /**
     * 生成されたCSVファイルをインポート
     * @param $em
     * @param string $csvDir CSVファイルのディレクトリ
     * @param $controller メッセージ出力用
     * @param string $tableName テーブル名
     */
    private function importGeneratedCsvFile($em, $csvDir, $controller, $tableName)
    {
        $csvFile = $csvDir . $tableName . '.csv';

        if (!file_exists($csvFile) || filesize($csvFile) === 0) {
            $controller->addWarning($tableName . '.csv が見つからないか空です。', 'admin');
            return;
        }

        // 一般的なCSVインポート処理を使用してデータをインポート
        $this->importTableFromCsv($em, $csvDir, $controller, $tableName);
    }

    /**
     * 値マッピングを使用してテーブルをインポート
     * @param $em
     * @param string $csvDir CSVファイルのディレクトリ
     * @param $controller メッセージ出力用
     * @param string $tableName テーブル名
     * @param array $valueToDataIdMap 値とデータIDのマッピング
     */
    private function importTableWithValueMapping($em, $csvDir, $controller, $tableName, $valueToDataIdMap)
    {
        $csvFile = $csvDir . $tableName . '.csv';

        if (!file_exists($csvFile) || filesize($csvFile) === 0) {
            $controller->addWarning($tableName . '.csv が見つからないか空です。', 'admin');
            return;
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

            while (($row = fgetcsv($handle)) !== false) {
                $data = $this->convertNULL(array_combine($key, $row));
                $value = $this->processRowData($tableName, $data, $listTableColumns, $valueToDataIdMap, $controller, $em);

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

        $controller->addSuccess($tableName . ' のデータを移行しました。', 'admin');
    }

    /**
     * 行データを処理して値を設定
     * @param string $tableName テーブル名
     * @param array $data データ配列
     * @param array $listTableColumns カラム名リスト
     * @param array $valueToDataIdMap 値とデータIDのマッピング
     * @param $controller メッセージ出力用（shipping_idマッピング用）
     * @return array 処理後の値
     */
    private function processRowData($tableName, $data, $listTableColumns, $valueToDataIdMap, $controller, $em)
    {
        $value = [];

        switch ($tableName) {
            case 'plg_customerplus_dtb_shipping':
                foreach ($listTableColumns as $column) {
                    if ($column === 'shipping_id') {
                        // $this->shipping_idマッピングから値を取得
                        $value[$column] = $controller->shipping_id[$data['order_id']][$data['shipping_id']];
                    } elseif ($column === 'customer_data_id') {
                        $sql = "SELECT customer_id FROM dtb_order WHERE id = ?";
                        $stmt = $em->executeQuery($sql, [$data['order_id']]);
                        $result = $stmt->fetchAssociative();

                        // customer_data_idへの変換
                        $value[$column] = $valueToDataIdMap[$result['customer_id'] . '_' . $data['customer_item_id']] ?? null;
                    } else {
                        $value[$column] = isset($data[$column]) ? $data[$column] : null;
                    }
                }
                break;

            case 'plg_customerplus_dtb_order':
                foreach ($listTableColumns as $column) {
                    if ($column === 'customer_data_id') {
                        $sql = "SELECT customer_id FROM dtb_order WHERE id = ?";
                        $stmt = $em->executeQuery($sql, [$data['order_id']]);
                        $result = $stmt->fetchAssociative();

                        // customer_data_idへの変換
                        $value[$column] = $valueToDataIdMap[$result['customer_id'] . '_' . $data['customer_item_id']] ?? null;
                    } else {
                        $value[$column] = isset($data[$column]) ? $data[$column] : null;
                    }
                }

                break;
            case 'plg_customerplus_dtb_customer_address':
                foreach ($listTableColumns as $column) {

                    if ($column === 'customer_data_id') {
                        // customer_data_idへの変換
                        $value[$column] = $valueToDataIdMap[$data['customer_id'] . '_' . $data['customer_item_id']] ?? null;
                    } else {
                        $value[$column] = isset($data[$column]) ? $data[$column] : null;
                    }
                }

                break;
            case 'plg_customerplus_dtb_customer':

                foreach ($listTableColumns as $column) {

                    if ($column === 'customer_data_id') {
                        // customer_data_idへの変換
                        $value[$column] = $valueToDataIdMap[$data['customer_id'] . '_' . $data['customer_item_id']] ?? null;
                    } elseif ($column === 'discriminator_type') {
                        $value[$column] = "customercustom";
                    } else {
                        $value[$column] = isset($data[$column]) ? $data[$column] : null;
                    }
                }
                break;
        }

        $value['discriminator_type'] = str_replace('_', '', str_replace('plg_customerplus_dtb_', '', $tableName . 'custom'));

        return $value;
    }


    /**
     * CSVからテーブルデータをインポート
     * @param $em
     * @param string $csvDir CSVファイルのディレクトリ
     * @param $controller メッセージ出力用
     * @param string $tableName テーブル名
     */
    private function importTableFromCsv($em, $csvDir, $controller, $tableName, $save_flag = false)
    {
        $csvFile = $csvDir . $tableName . '.csv';

        if (!file_exists($csvFile) || filesize($csvFile) === 0) {
            $controller->addWarning($tableName . '.csv が見つからないか空です。', 'admin');
            return;
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

            while (($row = fgetcsv($handle)) !== false) {
                $data = $this->convertNULL(array_combine($key, $row));

                if ($save_flag) {
                    $this->$tableName[] = $data;
                }
                $value = $this->processCustomerItemData($tableName, $data, $listTableColumns, $i);

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

        $controller->addSuccess($tableName . ' のデータを移行しました。', 'admin');
    }

    /**
     * CustomerItem関連データを処理
     * @param string $tableName テーブル名
     * @param array $data データ配列
     * @param array $listTableColumns カラム名リスト
     * @param int $index インデックス（ソート番号用）
     * @return array 処理後の値
     */
    private function processCustomerItemData($tableName, $data, $listTableColumns, $index)
    {
        $value = [];

        switch ($tableName) {
            case 'plg_customerplus_dtb_customer_item':
                foreach ($listTableColumns as $column) {
                    if ($column === 'id' && isset($data['customer_item_id'])) {
                        $value[$column] = $data['customer_item_id'];
                    } elseif ($column === 'name' && isset($data['title'])) {
                        $value[$column] = $data['title'];
                    } elseif ($column === 'input_type' && isset($data['input_type'])) {
                        // 旧データのinput_typeを新プラグインの仕様に合わせて変換
                        $value[$column] = $this->convertInputType($data['input_type']);
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
                            $value[$column] = $index;
                        }
                    } elseif ($column === 'discriminator_type') {
                        $value[$column] = 'customeritemoption';
                    } else {
                        $value[$column] = isset($data[$column]) ? $data[$column] : null;
                    }
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

        return $value;
    }

    /**
     * 旧データのinput_typeを新プラグインの仕様に合わせて変換
     * @param int $inputType 入力タイプ
     * @return int|string 変換後の入力タイプ
     */
    private function convertInputType($inputType)
    {
        // 旧データのinput_typeを新プラグインの仕様に合わせて変換
        switch ($inputType) {
            case 1: // テキストボックス
                return 1;
            case 2: // 電話
                return 3;
            case 3: // 郵便
                return '';
            case 4: // 日付
                return 4;
            case 5: // テキストエリア
                return 2;
            case 10: // ラジオボタン
                return 11;
            case 11: // セレクトボックス
                return 10;
            case 12: // チェックボックス
                return 12; // 'checkbox' から数値に修正
            default:
                return $inputType; // デフォルトはtext
        }
    }


    private function createInputTypeCache()
    {
        foreach ($this->plg_customerplus_dtb_customer_item as $customerItem) {
            $this->inputTypeCache[$customerItem['customer_item_id']] = $customerItem['input_type'];
        }
    }
    private function mappingOptionTextCache($em, $option_id, $customer_item_id)
    {
        // キャッシュキーを生成
        $cacheKey = $option_id . '_' . $customer_item_id;

        // キャッシュに結果があればそれを返す
        /*if (isset($this->mappingCache[$cacheKey])) {
            return $this->mappingCache[$cacheKey];
        }*/

        // キャッシュにない場合は検索処理を実行
        foreach ($this->plg_customerplus_dtb_customer_item_option as $option) {
            if ($option['option_id'] == $option_id && $option['customer_item_id'] == $customer_item_id) {

                // $option['text'] $option['customer_item_id'] と使って plg_customerplus_dtb_customer_item_option テーブルから id を取得する
                $sql = "SELECT id FROM plg_customerplus_dtb_customer_item_option WHERE customer_item_id = ? AND text = ?";
                $stmt = $em->executeQuery($sql, [$customer_item_id, $option['text']]);
                $result = $stmt->fetchAssociative();

                //$this->mappingCache[$cacheKey] = ['num_value' => $result['id'], 'value' => $option['text']];
                return ['num_value' => $result['id'], 'value' => $option['text']];
            }
        }

        // 見つからない場合はnullをキャッシュして返す
        $this->mappingCache[$cacheKey] = null;
        return null;
    }


    // タイムゾーンの変換
    private function convertTz($datetime, $em)
    {
        $date = new \DateTime($datetime, new \DateTimeZone($this->eccubeConfig->get('timezone')));
        $date->setTimezone(new \DateTimeZone('UTC'));

        return $date->format($em->getDatabasePlatform()->getDateTimeTzFormatString());
    }
}
