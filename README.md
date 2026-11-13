# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

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

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます。

外部サービス連携を利用する場合は、後述の設定手順に従って `.env` に認証情報を設定してください。

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

`storage:link` は教材画像・プロフィール画像の配信に必要です。

`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

[http://localhost:8000](http://localhost:8000) にアクセスし、下記のログインアカウントでログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |

|---|---|

| アプリケーション | http://localhost:8000 |

| phpMyAdmin（DB 確認） | http://localhost:8080 |

| Mailpit（メール確認） | http://localhost:8025 |

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

本サービス&#x306F;****招待制****&#x3067;す。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

---

# 外部サービス連携の設定

Certify LMS では、以下の外部サービスを利用できます。

| サービス | 用途 | 必須設定 |

|---|---|---|

| Google Calendar | コーチの予定確認・面談予定の登録 | OAuth 認証情報 |

| Gemini API | AI 相談機能 | API キー |

| Stripe | 追加面談購入・決済処理 | API キー・Webhook 署名シークレット |

| Pusher | チャットのリアルタイム配信 | アプリケーション認証情報 |

外部サービスの設定は利用する機能に応じて行ってください。未設定の場合でも、外部連携を必要としない基本機能や自動テストは利用できます。

認証情報は必ず `.env` に設定し、Git にコミットしないでください。

設定変更後は、必要に応じて Laravel の設定キャッシュをクリアしてください。

```bash
sail artisan config:clear
```

## 1. Google Calendar（面談予定連携）

Google Calendar と連携すると、コーチのGoogleカレンダーに登録されている予定を面談予約時に確認し、確定した面談予定をGoogleカレンダーに登録できます。

Google Calendar との連携はコーチ単位で行います。

### 1-1. Google Cloud プロジェクトの作成

1. Google Cloud Console にアクセスします。

2. Google アカウントでログインします。

3. 新しいプロジェクトを作成します。

4. プロジェクト名を設定します（例：Certify LMS）。

### 1-2. Google Calendar API の有効化

1. 作成したプロジェクトを選択します。

2. API とサービスからライブラリを開きます。

3. Google Calendar API を検索します。

4. Google Calendar API を有効にします。

### 1-3. OAuth 同意画面の設定

1. Google Auth Platform の OAuth 同意画面を開きます。

2. アプリケーション名を設定します。

3. ユーザーサポート用メールアドレスを設定します。

4. 対象ユーザーを設定します。

5. 必要な情報を保存します。

テスト公開の場合は、テストユーザーに連携するGoogleアカウントを追加してください。

### 1-4. OAuth クライアント ID の作成

1. Google Auth Platform のクライアント設定を開きます。

2. OAuth クライアントを作成します。

3. アプリケーションの種類は「ウェブアプリケーション」を選択します。

4. 名前を設定します。

5. 承認済みのリダイレクト URI に以下を登録します。

```text
http://localhost:8000/settings/google-calendar/callback
```

6. 作成後、クライアント ID とクライアントシークレットを取得します。

### 1-5. `.env` の設定

取得した認証情報を `.env` に設定します。

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=取得したクライアントID
GOOGLE_CALENDAR_CLIENT_SECRET=取得したクライアントシークレット
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback
```

なお、上記のリダイレクトURIはローカル開発環境用です。本番環境や異なるドメインで利用する場合は、実際のアプリケーションURLに合わせて `GOOGLE_CALENDAR_REDIRECT_URI` を変更し、Google Cloud Console側の承認済みリダイレクトURIにも同じURLを登録してください。

### 1-6. Google Calendar との連携

1. アプリケーションにコーチアカウントでログインします。

2. 設定画面から面談可用枠の設定画面を開きます。

3. Google Calendar 連携を実行します。

4. Google アカウントを選択します。

5. カレンダーへのアクセスを許可します。

6. アプリケーションに戻り、連携完了を確認します。

連携解除は同じ設定画面から行えます。

---

## 2. Gemini API（AI 相談機能）

Gemini API を利用して、受講生向けの AI 相談機能を提供します。

### 2-1. Google AI Studio で API キーを取得する

1. Google AI Studio にアクセスします。

2. Google アカウントでログインします。

3. API キーの管理画面を開きます。

4. API キーを作成します。

5. 発行された API キーをコピーします。

### 2-2. `.env` の設定

取得した API キーを `.env` に設定します。

```dotenv
AI_CHAT_ENABLED=true
GEMINI_API_KEY=取得したAPIキー
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_TIMEOUT=30
AI_CHAT_DAILY_LIMIT=50
AI_CHAT_CONVERSATION_LIMIT=30
```

各設定項目の意味は以下のとおりです。

| 環境変数 | 内容 | デフォルト値 |

|---|---|---|

| AI_CHAT_ENABLED | AI 相談機能の有効・無効 | false |

| GEMINI_API_KEY | Gemini API キー | なし |

| GEMINI_MODEL | 使用する Gemini モデル | gemini-3.5-flash-lite |

| GEMINI_TIMEOUT | API 通信タイムアウト（秒） | 30 |

| AI_CHAT_DAILY_LIMIT | 1日あたりの質問回数上限 | 50 |

| AI_CHAT_CONVERSATION_LIMIT | 会話履歴の取得上限 | 30 |

### 2-3. 設定の反映

```bash
sail artisan config:clear
```

### 2-4. 動作確認

1. 受講中の受講生アカウントでログインします。

2. AI 相談機能を開きます。

3. 質問を送信します。

4. Gemini API から回答が返ってくることを確認します。

AI 相談機能は `AI_CHAT_ENABLED=true` に設定した場合のみ有効になります。

---

## 3. Stripe（追加面談購入・Webhook）

追加面談購入には Stripe のテスト環境を使用します。

Stripe の API シークレットキーと Webhook 署名シークレットが未設定の場合、実際の Checkout 決済は利用できません。

既存の学習機能や自動テストの実行には Stripe の実キーは不要です。

### 3-1. Stripe アカウントの作成

1. Stripe のアカウントを作成します。

2. Stripe ダッシュボードにログインします。

3. テスト環境に切り替えます。

4. 開発者向けの API キー画面を開きます。

5. テスト用 API シークレットキー（`sk_test\_` で始まる値）を取得します。

### 3-2. `.env` の設定

```dotenv
STRIPE_SECRET_KEY=sk_test_ここにテスト用APIシークレットキー
```

API シークレットキーは `.env` のみに設定してください。

### 3-3. Stripe CLI のインストール

Webhook のローカル動作確認には Stripe CLI を使用します。

Stripe CLI をローカル PC にインストールし、ターミナルで以下を実行します。

```bash
stripe login
```

ブラウザで Stripe アカウントとの認証を完了させます。

### 3-4. Webhook の転送設定

別のターミナルで以下を実行します。

```bash
stripe listen --forward-to http://localhost:8000/webhooks/stripe
```

`stripe listen` の出力に表示される `whsec\_` で始まる署名シークレットをコピーします。

`.env` に以下を設定します。

```dotenv
STRIPE_WEBHOOK_SECRET=whsec_ここにStripe CLIが表示した値
```

### 3-5. 設定の反映

```bash
sail artisan config:clear
```

`stripe listen` は Webhook を受信する間、起動したままにしてください。

### 3-6. テスト購入を実行する

1. `student@certify-lms.test` でログインします（受講中の受講生）。

2. `/meeting-quota/checkout` を開き、公開中の面談パックを選択します。公開中のパックがない場合は、管理者画面で面談パックを公開してください。

3. Stripe Checkout のテスト決済画面で、Stripe が案内するテストカード（例：`4242 4242 4242 4242`、将来の有効期限、任意の CVC）を使用して支払います。実際のカード番号は入力しないでください。

4. 決済完了後、`/meeting-quota/success` に戻ることを確認します。

5. `stripe listen` のターミナルで `checkout.session.completed` が転送され、Webhook が成功応答を返していることを確認します。

6. 面談回数の残数と履歴を確認し、購入した面談回数が一度だけ加算されていることを確認します。

反映はブラウザの完了画面表示ではなく、署名検証済みの Webhook 受信を契機に行われます。

### 3-7. 失敗・重複・署名不正を確認する

- Stripe Checkout から購入画面へ戻り、キャンセルした場合に面談回数が加算されないことを確認します。

- 同じ決済完了イベントが再送されても、面談回数が重複加算されないことを確認します。

- 署名が無効な Webhook は HTTP 400 で拒否されることを確認します。

署名検証・重複受信・失敗イベントなどの自動テストは、以下で実行できます。

```bash
sail artisan test --filter=MeetingQuotaCheckoutTest
sail artisan test --filter=StripeWebhookTest
```

****注意：**** `stripe trigger checkout.session.completed` はテスト用の擬似イベントを生成しますが、このアプリケーションで作成した `payments` レコードと対応しないことがあります。面談回数の加算確認には、アプリケーションからの実際のテスト購入を使用してください。

---

## 4. Pusher（チャットのリアルタイム配信）

Pusher は、チャットメッセージを相手の画面にリアルタイムで反映するためのサービスです。

通常のメッセージ送受信自体は Pusher が未設定でも動作しますが、相手画面へのリアルタイム反映は行われません。

### 4-1. Pusher アカウントの作成

1. Pusher のアカウントを作成します。

2. ダッシュボードにログインします。

3. Channels アプリケーションを新規作成します。

4. アプリケーション名を設定します。

5. クラスターを選択します。

6. アプリケーションを作成します。

### 4-2. アプリケーション認証情報の取得

作成した Channels アプリケーションの設定画面から以下を取得します。

- App ID

- Key

- Secret

- Cluster

### 4-3. `.env` の設定

取得した情報を `.env` に設定します。

```dotenv
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=取得したApp ID
PUSHER_APP_KEY=取得したKey
PUSHER_APP_SECRET=取得したSecret
PUSHER_APP_CLUSTER=取得したCluster
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
```

### 4-4. 設定の反映

```bash
sail artisan config:clear
```

フロントエンドの環境変数も変更しているため、ビルドを実行します。

```bash
sail npm run build
```

開発中の場合は以下を実行します。

```bash
sail npm run dev
```

### 4-5. 動作確認

1. 異なるユーザーアカウントでログインできるようにブラウザを2つ用意します。

2. チャット画面を開きます。

3. 一方のユーザーからメッセージを送信します。

4. もう一方のユーザーの画面にメッセージがリアルタイムで反映されることを確認します。

Pusher を利用しない場合は、以下の設定のままでもメッセージの送受信が可能です。

```dotenv
BROADCAST_DRIVER=log
```

---

## テスト

```bash
sail artisan test                 # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
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

- Google Calendar API（コーチの面談予定連携）

- Gemini API（AI 相談機能）

- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーすると、外部サービス連携を除く基本機能をローカルで利用できます。

| 環境変数 | 用途 |

|---|---|

| `GOOGLE_CALENDAR_CLIENT_ID` | Google Calendar OAuth クライアント ID |

| `GOOGLE_CALENDAR_CLIENT_SECRET` | Google Calendar OAuth クライアントシークレット |

| `GOOGLE_CALENDAR_REDIRECT_URI` | Google Calendar OAuth コールバック URL |

| `AI_CHAT_ENABLED` | AI 相談機能の有効・無効 |

| `GEMINI_API_KEY` | Gemini API キー |

| `GEMINI_MODEL` | 使用する Gemini モデル |

| `GEMINI_TIMEOUT` | Gemini API 通信タイムアウト |

| `AI_CHAT_DAILY_LIMIT` | 1日あたりの質問回数上限 |

| `AI_CHAT_CONVERSATION_LIMIT` | 会話履歴の取得上限 |

| `STRIPE_SECRET_KEY` | Stripe Checkout の API シークレットキー |

| `STRIPE_WEBHOOK_SECRET` | Stripe Webhook の署名検証キー |

| `BROADCAST_DRIVER` | イベント配信ドライバー |

| `PUSHER_APP_ID` | Pusher アプリケーション ID |

| `PUSHER_APP_KEY` | Pusher アプリケーションキー |

| `PUSHER_APP_SECRET` | Pusher アプリケーションシークレット |

| `PUSHER_APP_CLUSTER` | Pusher クラスター |

| `PUSHER_HOST` | Pusher ホスト |

| `PUSHER_PORT` | Pusher ポート |

| `PUSHER_SCHEME` | Pusher 通信スキーム |

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
