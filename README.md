# データ移行プラグイン for EC-CUBE4

EC-CUBE 2.x / 3.x / 4.0 / 4.1 のバックアップデータを利用して、EC-CUBE 4.3 系へデータ移行を行うプラグインです。

- https://www.ec-cube.net/products/detail.php?product_id=2091
- https://www.ec-cube.net/products/detail.php?product_id=2479
- https://www.ec-cube.net/products/detail.php?product_id=2931

## 移行できるデータ

| カテゴリ | テーブル | 備考 |
|---|---|---|
| **会員** | dtb_customer, dtb_customer_address, mtb_sex, mtb_job | |
| **管理者** | dtb_member, mtb_authority | |
| **商品** | dtb_product, dtb_product_class, dtb_class_category, dtb_class_name, dtb_product_image, mtb_sale_type | |
| **カテゴリ** | dtb_category, dtb_product_category | |
| **受注** | dtb_order, dtb_shipping, dtb_order_item | |
| **支払い方法** | dtb_payment | データは移行するが**非表示**設定 |
| **配送方法** | dtb_delivery, dtb_delivery_fee, dtb_delivery_time | データは移行するが**非表示**設定 |
| **税設定** | dtb_tax_rule | |

## 他プラグイン連携

| プラグイン | 内容 |
|---|---|
| [メルマガ管理プラグイン](https://www.ec-cube.net/products/detail.php?product_id=1760) | 会員のメールマガジン送付設定を移行 |
| [ポイントプラグイン](https://www.ec-cube.net/products/detail.php?product_id=1101) | 会員のポイントを移行（2.x のみ対応） |
| [ECCUBE2Downloads](https://www.ec-cube.net/products/detail.php?product_id=2935) | ダウンロード商品の移行に対応（下記参照） |

### ダウンロード商品の移行（ECCUBE2Downloads 連携）

[ECCUBE2Downloads プラグイン](https://www.ec-cube.net/products/detail.php?product_id=2935) がインストールされている場合、2.x のダウンロード商品を移行できます。

- 2.x の `mtb_product_type` から「ダウンロード」販売種別を自動検出
- `dtb_product_class` と `dtb_delivery` の `sale_type_id` を ECCUBE2Downloads の販売種別ID（222）に書き換え
- `down_filename` / `down_realfilename` も自動的に移行

ECCUBE2Downloads がインストールされていない場合、ダウンロード商品は移行されません。

## 移行できないデータ

- カート（dtb_cart, dtb_cart_item）
- 決済と配送の紐づけ（dtb_payment_option）
- 決済モジュール
- 複数配送の受注データ

## 注意事項

### 環境・設定

- アップロードファイルの最大容量は PHP の設定に依存します（`memory_limit`, `post_max_size`, `upload_max_filesize`）
- PostgreSQL の場合は super user 権限が必要です
- プラグイン内で composer を使用しているため、オーナーズストア経由のインストールが必要です

### 移行時の制約

- 古い EC-CUBE からの移行の場合、`eccube_password_hash_algos: SHA256` を変更する必要があります
- 支払い方法は受注との紐づけのため移行されますが、有効化して利用することはできません。新規に作成することを推奨します
- 新規に使う支払い方法と配送方法を設定する必要があります

### ポイントについて

- ポイントは受注時点の 1pt あたりの金額が判別できないため、1pt = 1円として計算しています
- パラメータ設定で `POINT_VALUE` を変更している場合、正確な金額を移行できません

### 受注ステータスについて

- 存在しない受注ステータスを利用している受注は、受注ステータスが null の状態で移行されます
- 受注検索画面では表示されないため、移行後に適切な受注ステータスを紐付ける必要があります

### 4.0 / 4.1 からの移行

4.0 / 4.1 から 4.3 への移行は**すべてのデータ**を移行します。

## License

[LGPL](LICENSE)
