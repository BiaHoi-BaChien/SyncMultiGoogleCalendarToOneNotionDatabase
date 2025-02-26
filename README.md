# A Batch : Synchronize multiple Google Calendars to one Notion database

複数のGoogleカレンダーの予定を1つのNotionカレンダーに同期するバッチ。
現状は追加／削除のみ。更新は対応していません。

# Notionカレンダーにイベントが同期された画面イメージ

![3つのカレンダー（個人用、仕事用、日本の祝日）から1つのNotionカレンダーにイベントを同期したイメージ。](https://user-images.githubusercontent.com/93363437/143379424-49ca91f5-3a07-484b-8007-a0ee3d1082e4.png)

3つのカレンダー（個人用、仕事用、日本の祝日）から1つのNotionカレンダーにイベントを同期したイメージ。

# Installation

## 1. Google Calendar APIの準備をして個人用と仕事用の各カレンダーにAPIからアクセスできるようにする。
![20211125115123 (2)](https://user-images.githubusercontent.com/93363437/143381645-3ab76251-bf87-4fe7-b1d1-43bcdd523df0.png)

※Google Calendar APIの準備はこちらの記事が参考になりました。→ https://liginc.co.jp/472637

## 2. Notionにて新しいインテグレーションの準備をする。
![notion](https://user-images.githubusercontent.com/93363437/143382921-beb2157c-32e0-4de2-be35-e2fbd232fbab.png)

## 3. Notionの「共有」にて新しいインテグレーションがカレンダーを編集できるようにする。
![notion2](https://user-images.githubusercontent.com/93363437/143383010-3a4ac152-6928-44c8-afd2-6faddd541275.png)

※Notion側の準備はこちらの記事が参考になりました。→ https://tektektech.com/notion-api/#Notionintegration

## 4. Notionカレンダーに本バッチが参照する3つのプロパティを準備する。
### 4-1. 「マルチセレクト」でラベル付けしたい文字列を設定します。プロパティ名は「ジャンル」
![prop1](https://user-images.githubusercontent.com/93363437/143386907-06c81349-ba05-4e6f-a899-6b45f924fb0a.png)

### 4-2.「googleCalendarId」という名前の「テキスト」のプロパティ

## 5. Clone Source Code
## 6. 「.env」にその他各種設定をする

```php
GOOGLE_CALENDAR_ID_PERSONAL=Calenar Id for Personal
GOOGLE_CALENDAR_ID_HOLIDAY=japanese__ja@holiday.calendar.google.com
GOOGLE_CALENDAR_ID_BUSINESS=Calenar Id for Business
GOOGLE_CALENDAR_PATH_TO_JSON=app/json/xxxxxxxxx.json

GOOGLE_CALENDAR_LABEL_PERSONAL=生活
GOOGLE_CALENDAR_LABEL_BUSINESS=仕事
GOOGLE_CALENDAR_LABEL_SCHOOL=学校
GOOGLE_CALENDAR_LABEL_HOLIDAY=祝日

NOTION_API_TOKEN=Api Token of Notion
NOTION_DATABASE_ID=Database Id of Notion Calendar
NOTION_UPDATABLE=true

TIMEZONE=Asia/Tokyo
```
個人・仕事用のカレンダーID、
NotionのAPIトークンやNotionカレンダーのDatabase Idを設定します。

## 7. Google Calendar APIを準備したときに作成したアカウントの「サービスアカウントキー」(JSONファイル)を設置する。
```
storage>app>json
```

![json](https://user-images.githubusercontent.com/93363437/143384668-e7fbd910-bd78-4e70-a18b-cf51665d9e60.png)

## 8. Composer intall
```bash
composer install --optimize-autoloader --no-dev
```
## 9. Batchの設定
```bash
* * * * *   /usr/bin/php artisan schedule:run
```

# License
The source code is licensed MIT.

# Author

* @BiaHoi-BaChien
* E-mail : sugi@clb-biahoi.net
