# A Batch: Synchronize multiple Google Calendars to one Notion database

複数のGoogleカレンダーの予定を1つのNotionカレンダーに同期するバッチです。Google側のイベントをNotionに追加し、Googleに存在しないイベントを削除します（祝日カレンダーのみのモードでは削除を行いません）。イベント内容の更新には対応していません。

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

# Notion
NOTION_API_TOKEN=notion_api_token
NOTION_DATABASE_ID=notion_database_id
NOTION_DATA_SOURCE_ID=datasource_id-of-your-database   # 省略可（未設定の場合は自動取得）
NOTION_VERSION=2025-09-03

# 同期期間（日数）
SYNC_MAX_DAYS=90
```

- `GOOGLE_CALENDAR_ID_*` は同期したいカレンダー ID を設定します。不要なカレンダーは空にして構いません。
- `GOOGLE_CALENDAR_PATH_TO_JSON` はダウンロードしたサービスアカウントキーを `storage/app/json` に配置した場合の相対パスが `app/json/...` になります。
- `NOTION_DATA_SOURCE_ID` は Notion のデータベース設定画面から取得できます。指定しなくても自動で解決されます。
- `SYNC_MAX_DAYS` は今日から何日先までの予定を同期するかを制御します。
- `SYNC_REPORT_MAIL_TO` を設定すると同期結果のサマリーメールが送信されます。空の場合はメール送信をスキップします。

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
