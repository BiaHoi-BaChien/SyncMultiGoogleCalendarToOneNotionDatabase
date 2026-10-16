# A Batch: Synchronize multiple Google Calendars to one Notion database

複数のGoogleカレンダーの予定を1つのNotionカレンダーに同期するバッチです。Google側のイベントをNotionに追加し、Googleに存在しないイベントを削除します（祝日カレンダーのみのモードでは削除を行いません）。イベント内容の更新には対応していません。

- 同期で新しく登録した件数とイベント概要を、メールおよび Slack Incoming Webhook（任意設定）でレポートできます。
- Notion Data Source ID を `.env` に設定しなくても、API から自動取得してキャッシュします。
- Googleの全ページ取得が完了してからNotionの同期を開始します。取得失敗や不正なページ情報があった場合は同期を中止します。
- Googleのイベント一覧とNotionの予定一覧は、同期・参加判定・通知に必要な項目だけを取得します。Notionは一覧取得の前にスキーマを1回読み取り、`Name`・`Date`・`ジャンル`・`googleCalendarId`のプロパティIDを解決して全ページに適用します。必須項目のIDを取得できない場合は同期を中止します。
- 件名・場所・メモが2000文字を超える場合、その項目は上限超過を示す代替文になります。原文はGoogleカレンダーで確認してください。文字数はUTF-16単位で安全側に数えるため、一部の絵文字などは2文字として扱います。

## Notionカレンダーにイベントが同期された画面イメージ

![3つのカレンダー（個人用、仕事用、日本の祝日）から1つのNotionカレンダーにイベントを同期したイメージ。](https://user-images.githubusercontent.com/93363437/143379424-49ca91f5-3a07-484b-8007-a0ee3d1082e4.png)

## Requirements

- Google Calendar API の利用設定（サービスアカウントキーを取得できること）
- Notion API のインテグレーション設定
- PHP 8.x / Composer

## Installation

### 1. Google Calendar API の準備

Google Cloud でサービスアカウントを作成し、Google Calendar API を有効化して対象カレンダー（個人用・仕事用・学校用・祝日など）にアクセスできるよう権限を設定します。サービスアカウントキー（JSON）をダウンロードしてください。

> Google Calendar API の準備については [LIGさんの記事](https://liginc.co.jp/472637) が参考になります。

### 2. Notion インテグレーションの準備

Notion で新しいインテグレーションを作成し、同期対象のデータベースと共有します。

> Notion API のセットアップは [tektektech さんの記事](https://tektektech.com/notion-api/#Notionintegration) が参考になります。

Notion データベース側では次のプロパティを作成しておきます。

| プロパティ名 | 種別 | 説明 |
| --- | --- | --- |
| `ジャンル` | マルチセレクト | カレンダー種別（生活、仕事、学校、祝日 など） |
| `googleCalendarId` | テキスト | Google のイベント ID を保存 |
| `メモ`、`Location` など | 任意 | 任意の補足情報 |

> Notion の新しい API (data source) を利用するため、対象データベースの **Data Source ID** を取得しておくと後述の `.env` で指定できます。未指定の場合は API 経由で自動取得します。

### 3. ソースコードの取得

```bash
git clone https://github.com/BiaHoi-BaChien/SyncMultiGoogleCalendarToOneNotionDatabase.git
cd SyncMultiGoogleCalendarToOneNotionDatabase
```

### 4. 依存パッケージのインストール

```bash
composer install --optimize-autoloader --no-dev
```

### 5. 環境変数の設定

`.env` を作成し、以下の値を設定します。`.env.example` をコピーして編集すると便利です。

```bash
cp .env.example .env
```

主要な環境変数は以下のとおりです。

```dotenv
# Google Calendar
GOOGLE_CALENDAR_ID_PERSONAL=your_personal_calendar_id
GOOGLE_CALENDAR_ID_BUSINESS=your_work_calendar_id
GOOGLE_CALENDAR_ID_SCHOOL=your_school_calendar_id
GOOGLE_CALENDAR_ID_HOLIDAY=japanese__ja@holiday.calendar.google.com
GOOGLE_CALENDAR_PATH_TO_JSON=app/json/your-service-account.json

GOOGLE_CALENDAR_LABEL_PERSONAL=生活
GOOGLE_CALENDAR_LABEL_BUSINESS=仕事
GOOGLE_CALENDAR_LABEL_SCHOOL=学校
GOOGLE_CALENDAR_LABEL_HOLIDAY=祝日

SYNC_REPORT_MAIL_TO=notify@example.com

# Slack (任意)
SLACK_BOT=false
SLACK_WEBHOOK_URL=

# Notion
NOTION_API_TOKEN=notion_api_token
NOTION_DATABASE_ID=notion_database_id
NOTION_DATA_SOURCE_ID=datasource_id-of-your-database   # 省略可（未設定の場合は自動取得）
NOTION_VERSION=2026-03-11

# 同期期間（日数）
SYNC_MAX_DAYS=90
```

- `GOOGLE_CALENDAR_ID_*` は同期したいカレンダー ID を設定します。不要なカレンダーは空にして構いません。
- `GOOGLE_CALENDAR_PATH_TO_JSON` はダウンロードしたサービスアカウントキーを `storage/app/json` に配置した場合の相対パスが `app/json/...` になります。
- `NOTION_DATA_SOURCE_ID` は Notion のデータベース設定画面から取得できます。指定しなくても自動で解決されます。
- `SYNC_MAX_DAYS` は今日から何日先までの予定を同期するかを制御します。
- `SYNC_REPORT_MAIL_TO` を設定すると同期結果のサマリーメールが送信されます。空の場合はメール送信をスキップします。
- SMTPは暗号化が必須です。`MAIL_ENCRYPTION=ssl` は接続時からTLS（通常465番）、`MAIL_ENCRYPTION=tls` はSTARTTLS（通常587番）を使います。465番は接続時からTLSを使います。TLSを確立できないサーバーでは認証情報・メール本文を送信せず失敗します。
- Slack 通知を利用したい場合は `SLACK_BOT=true` とし、NotifyFromHostinger アプリで発行した Incoming Webhook URL を `SLACK_WEBHOOK_URL` に設定してください。URL が未設定の場合は通知を送信しません。

タイムゾーンを変更したい場合は `.env` の `TIMEZONE` を編集してください（未設定時のデフォルトは `Asia/Ho_Chi_Minh`）。

### 6. Google サービスアカウントキーの配置

ダウンロードした JSON キーを `storage/app/json/` に配置します。

```
storage/
└── app/
    └── json/
        └── your-service-account.json
```

### 7. バッチの実行

コマンドは artisan から実行します。

```bash
php artisan command:gcal-sync-notion
```

引数に `holiday` を指定すると祝日カレンダーのみを同期し、Notion からの削除処理は行いません。

```bash
php artisan command:gcal-sync-notion holiday
```

## 同期結果の通知

同期で新規に登録したイベントがある場合、下記の方法でレポートを送信します。

- **メール通知**: `SYNC_REPORT_MAIL_TO` に送信先を設定すると、同期件数と各イベントの概要をテキストメールで送信します。
- **Slack 通知**: `SLACK_BOT=true` にすると、Incoming Webhook に紐づく送信先へ同じ内容を投稿します。その後メール送信に失敗した場合は、同じ送信先へ別メッセージでエラー概要を投稿します。

Slack 通知を利用するには、開発者向けのアプリ設定から Incoming Webhook URL を取得します。

1. [Slack の Your Apps](https://api.slack.com/apps) を開きます。
2. **NotifyFromHostinger** を選択します。
3. 左メニューの **Incoming Webhooks** を開きます。
4. **Webhook URLs for Your Workspace** で対象の送信先を確認し、**Copy** で URL をコピーします。

アプリが一覧に表示されない場合は、アプリの作成者に確認してください。

取得した Webhook URL を使い、実行環境の `.env` に次の値を設定してください。設定画面の URL 自体は送信には使えません。

```dotenv
SLACK_BOT=true
SLACK_WEBHOOK_URL=
```

`SLACK_WEBHOOK_URL` にコピーした URL を設定してください。実際の Webhook URL は秘密情報なので、Git やログに残さず `.env` で管理してください。既存の `SLACK_BOT` は引き続き通知の有効・無効を切り替えるフラグとして使用します。従来の `SLACK_BOT_TOKEN` と `SLACK_DM_USER_IDS` は不要です。移行時に `SLACK_WEBHOOK_URL` を追加しないと Slack 通知はスキップされます。

送信先・投稿名・アイコンは Slack アプリ側の設定に従います。Webhook の応答にはメッセージ ID が含まれないため、エラー通知を元の投稿のスレッドには追加しません。詳しくは [Slack の Incoming Webhooks 仕様](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/)を参照してください。

Webhook の失敗や不正な応答はバッチのエラーとして扱います。その場合、従来と同様に後続のメール通知は実行されません。通知の前に完了した同期は取り消されません。

設定キャッシュを使用している環境では、`.env` の変更後に `php artisan config:clear` を実行してください。デプロイには `.env` が含まれないため、Hostinger 側にも設定が必要です。

### 8. スケジュール設定（任意）

cron などで定期実行する場合は次のように設定します。

```bash
* * * * * /usr/bin/php artisan schedule:run
```

## License

The source code is licensed MIT.

## Author

* @BiaHoi-BaChien
* E-mail : sugi@clb-biahoi.net
