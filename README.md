# データ移行プラグイン for EC-CUBE4

EC-CUBE 2~4.1 のバックアップデータを利用して、4.3 系へのデータ移行を行うプラグイン

- https://www.ec-cube.net/products/detail.php?product_id=2091
- https://www.ec-cube.net/products/detail.php?product_id=2479
- https://www.ec-cube.net/products/detail.php?product_id=2931

## :sunny: 移行出来るデータ

### 会員データ

- dtb_customer
- dtb_customer_address
- mtb_sex
- mtb_job

### 管理者データ

- dtb_member
- mtb_authority

### 商品データ

- dtb_product
- dtb_product_class
- dtb_class_category
- dtb_class_name
- dtb_product_image
- mtb_sale_type

### カテゴリデータ

- dtb_category
- dtb_product_category

### 受注データ

- dtb_order
- dtb_shipping
- dtb_order_item

### 支払い方法

- dtb_payment ※データは移行するが非表示設定

### 配送方法

- dtb_delivery ※データは移行するが非表示設定
- dtb_delivery_fee
- dtb_delivery_time

### 税設定

- dtb_tax_rule

### :point_right: 他プラグイン連携

- 会員データ メールマガジン送付について [メルマガ管理プラグイン](https://www.ec-cube.net/products/detail.php?product_id=1760)
- 会員データ ポイント [ポイントプラグイン](https://www.ec-cube.net/products/detail.php?product_id=1101)

## :cloud: 移行出来ないデータ

### カート

- dtb_cart
- dtb_cart_item

### 決済と配送の紐づけ

- dtb_payment_option

### 決済モジュール

## :exclamation: 注意点 1

- アップロードファイルの最大容量は PHP の設定に依存します。(memory_limit, post_max_size, upload_max_filesize)
- PostgreSQL の場合は、super user 権限が必要になります
- 古い EC-CUBE からの移行の場合、`eccube_password_hash_algos: SHA256`を変更する必要があります
- PostgreSQL の場合は、super user 権限が必要になります
- プラグイン内で composer を使用しているため、オーナーズストア経由のインストールが必要になります
- 新規に使う支払い方法と配送方法を設定する必要があります
- 複数配送は移行できません
- ダウンロード商品の受注データは移行できません
- ダウンロード商品も移行できません
- 支払方法は、受注との紐づけのため移行されますが、有効化して利用することはできません。新規に作成することを推奨します。
- ポイントの移行は、EC-CUBE2 系のみ対応しています。3 系は今後対応予定です。
- ポイントは、受注時点の 1pt あたりの金額が判別できないため、1pt ＝ 1 円として計算しています。
- パラメータ設定で`POINT_VALUE`を変更している場合、正確な金額を移行することができません。
- 存在しない受注ステータスを利用している受注は、受注ステータスが null の状態で移行されます。受注検索画面では表示されないため、移行後に適切な受注ステータスを紐付ける必要があります。

## :exclamation: 注意点 2

4.0,4.1 から 4.3 への移行は**すべてのデータ**を移行します。

## License

[LGPL](LICENSE)
