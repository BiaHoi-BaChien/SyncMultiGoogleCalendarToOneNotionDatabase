<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sync Multi Google Calendar to One Notion Database</title>
    <link rel="icon" type="image/svg+xml" href="https://clb-biahoi.net/api/notion_webform/favicon.svg">
    <style>
        :root {
            color-scheme: light dark;
            --bg-light: #f5f5f5;
            --bg-dark: #0f172a;
            --text-light: #1e293b;
            --text-dark: #e2e8f0;
            --accent: #f97316;
            font-family: 'Inter', 'Noto Sans JP', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.12), rgba(14, 165, 233, 0.12));
            color: #1e293b;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(59, 130, 246, 0.2));
                color: var(--text-dark);
            }
        }

        main {
            max-width: 720px;
            padding: 3rem 3.5rem;
            border-radius: 32px;
            backdrop-filter: blur(12px);
            background-color: rgba(255, 255, 255, 0.85);
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
        }

        @media (prefers-color-scheme: dark) {
            main {
                background-color: rgba(15, 23, 42, 0.82);
            }
        }

        h1 {
            font-size: clamp(2rem, 3vw, 2.75rem);
            margin-bottom: 1.25rem;
            line-height: 1.25;
        }

        p {
            margin: 0 0 1.5rem;
            line-height: 1.8;
            font-size: 1.05rem;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 2.25rem;
        }

        a.action {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.6rem;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            letter-spacing: 0.02em;
            background: linear-gradient(135deg, #f97316, #facc15);
            color: #0f172a;
            box-shadow: 0 18px 30px rgba(249, 115, 22, 0.25);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        a.action.secondary {
            background: transparent;
            color: inherit;
            border: 1px solid currentColor;
            box-shadow: none;
        }

        a.action:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 36px rgba(249, 115, 22, 0.32);
        }

        footer {
            margin-top: 2.75rem;
            font-size: 0.95rem;
            opacity: 0.78;
        }
    </style>
</head>
<body>
<main>
    <h1>複数のGoogleカレンダーを1つのNotionデータベースへ</h1>
    <p>
        このバッチアプリケーションは、個人・仕事・学校・祝日など複数のGoogleカレンダー予定をまとめてNotionカレンダーに同期します。
        実行方法や環境変数の設定についてはリポジトリの README を参照してください。
    </p>
    <p>
        同期が完了すると、任意でメールと Slack DM へ登録件数とサマリーを通知できます。
        Notion の Data Source ID を未設定でも API から自動取得してキャッシュします。
    </p>
    <div class="actions">
        <a class="action" href="https://github.com/BiaHoi-BaChien/SyncMultiGoogleCalendarToOneNotionDatabase" target="_blank" rel="noopener">
            GitHub リポジトリを見る
        </a>
        <a class="action secondary" href="https://clb-biahoi.net/api/notion_webform/favicon.svg" target="_blank" rel="noopener">
            favicon.svg を確認
        </a>
    </div>
    <footer>
        &copy; {{ now()->year }} CLB Bia Hoi. All rights reserved.
    </footer>
</main>
</body>
</html>
