# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / 追加面談購入（Stripe Checkout）/ チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

[http://localhost:8000](http://localhost:8000) にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | [http://localhost:8000](http://localhost:8000) |
| phpMyAdmin（DB 確認） | [http://localhost:8080](http://localhost:8080) |
| Mailpit（メール確認） | [http://localhost:8025](http://localhost:8025) |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## 追加面談購入（Stripe Checkout）・Webhook のローカル動作確認

追加面談購入には Stripe のテスト環境を使用します。Stripe のAPIシークレットキーと Webhook 署名シークレットが未設定の場合、実際の Checkout 決済は利用できません。既存の学習機能や自動テストの実行には Stripe の実キーは不要です。

### 1. Stripe テストキーを設定する

Stripe のテスト環境で APIシークレットキー（`sk_test_` で始まる値）を取得し、ローカルの `.env` に設定します。

```dotenv
STRIPE_SECRET=sk_test_ここにテスト用APIシークレットキー
```

APIシークレットキーは `.env` のみに設定し、Git にコミットしないでください。`.env.example` には空欄の設定項目を残します。

### 2. アプリケーションを起動する

```bash
sail up -d
```

### 3. Stripe CLI で Webhook を転送する

ローカル PC に Stripe CLI をインストールし、別のターミナルで Stripe アカウントにログインします。

```bash
stripe login
stripe listen --forward-to http://localhost:8000/webhooks/stripe
```

`stripe listen` の出力に表示される `whsec_` で始まる署名シークレットを、ローカルの `.env` に設定します。

```dotenv
STRIPE_WEBHOOK_SECRET=whsec_ここにStripe CLIが表示した値
```

環境変数の変更後は Laravel の設定キャッシュをクリアします。

```bash
sail artisan config:clear
```

`stripe listen` は Webhook を受信する間、起動したままにしてください。

### 4. テスト購入を実行する

1. `student@certify-lms.test` でログインします（受講中の受講生）。
2. `/meeting-quota/checkout` を開き、公開中の面談パックを選択します。公開中のパックがない場合は、管理者画面で面談パックを公開してください。
3. Stripe Checkout のテスト決済画面で、Stripe が案内するテストカード（例：`4242 4242 4242 4242`、将来の有効期限、任意の CVC）を使用して支払います。実際のカード番号は入力しないでください。
4. 決済完了後、`/meeting-quota/success` に戻ることを確認します。
5. `stripe listen` のターミナルで `checkout.session.completed` が転送され、Webhook が成功応答を返していることを確認します。
6. 面談回数の残数と履歴を確認し、購入した面談回数が一度だけ加算されていることを確認します。反映はブラウザの完了画面表示ではなく、署名検証済みの Webhook 受信を契機に行われます。

### 5. 失敗・重複・署名不正を確認する

- Stripe Checkout から購入画面へ戻り、キャンセルした場合に面談回数が加算されないことを確認します。
- 同じ決済完了イベントが再送されても、面談回数が重複加算されないことを確認します。
- 署名が無効な Webhook は HTTP 400 で拒否されることを確認します。

署名検証・重複受信・失敗イベントなどの自動テストは、以下で実行できます。

```bash
sail artisan test --filter=MeetingQuotaCheckoutTest
sail artisan test --filter=StripeWebhookTest
```

**注意:** `stripe trigger checkout.session.completed` はテスト用の擬似イベントを生成しますが、このアプリケーションで作成した `payments` レコードと対応しないことがあります。面談回数の加算確認には、手順4のアプリケーションからの実際のテスト購入を使用してください。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Stripe（追加面談購入の Checkout / Webhook）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーすると、外部サービス連携を除く基本機能をローカルで利用できます（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません。
- `STRIPE_SECRET` — Stripe Checkout のセッション作成に使用するAPIシークレットキーです。ローカル動作確認では Stripe のテスト用APIシークレットキーを設定してください。
- `STRIPE_WEBHOOK_SECRET` — `/webhooks/stripe` で受信する Stripe イベントの署名検証に使用します。ローカルでは `stripe listen` が表示する署名シークレットを設定してください。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
