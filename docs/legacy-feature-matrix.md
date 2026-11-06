# 旧Post Commentとの機能対応表

対象：2026-11-04に調査した旧PHP版。確定方針により `5dlab_old` は対象外。以下の「対応済み」はコード・基本テスト上の意味であり、本番受入完了を意味しません。

| 旧機能または合意要件 | 新実装 | 状態・留意点 |
|---|---|---|
| サイト管理者ログイン | `public/index.php` | 実装・HTTP基本テスト済み |
| ルーム作成 | `ChatService::createRoom`、管理画面 | 実装・HTTP基本テスト済み |
| ルーム参加者認証 | `ChatService::authenticateUser` | 仕様変更：全ルーム共通個人ID＋自動発行コード |
| ルーム管理者認証 | `administrators` と `admin_room_roles` | 仕様変更：複数管理者・ルーム単位権限 |
| 認証なしでの投稿 | Cookie と `anonymous_sessions` | 実装・HTTP基本テスト済み |
| 標準フォームの氏名・所属・メール・本文 | `FormDefinition::defaults` | 実装。既存全ルームの表示文言は個別確認が必要 |
| 簡易 `guest` フォーム | `FormDefinition::guest` | 実装 |
| フォームのルーム別カスタマイズ | `RoomForms` / `ManagementRoutes` | 実装・投稿時版保持のテスト済み |
| 投稿時刻・投稿本文のDB保存 | `comments` | 実装・基本テスト済み |
| 管理者の約3秒間隔の新着表示 | `admin-live.js` / `admin-updates` | 実装・API権限検証済み。多数接続時の負荷試験未実施 |
| 本人ログDL | `CsvExport::own` | 仕様変更：本人認証に限定、時刻とコメントの2列 |
| 管理者全件CSV | `CsvExport::admin` | 標準CSVヘッダー、guestヘッダーと追加項目に対応 |
| 旧CSVインポート | `LegacyCsvImporter` | 実装・サンプルテスト済み。旧MySQL実データでの検証未実施 |
| 旧ルーム名・基本フォームの取り込み | `LegacyRoomCatalog` | 18ルームのひな形支援。特殊ページの完全再現は未確認 |
| 旧ルーム別パスワード | 廃止 | 意図的に移行対象外 |
| 旧投稿と新ユーザーIDの紐付け | 行わない | 確定したプライバシー方針 |
| 管理者によるユーザーコード再発行 | `ChatService::resetCode` | 新機能、旧コード失効 |
| システム管理者の紛失復旧 | `emergency-recover.php` | 非公開の復旧証明ファイルが必要 |
| 設置・DB初期化 | `install.php` | 実装。共有レンタルサーバー実機検証未実施 |
| バックアップ・復元 | `BackupService` / `RestoreService` | SQLite整合性検証付き。障害注入・大規模復元試験未実施 |
| GitHub Releasesからの更新 | `SignedPackage` / `UpdateService` | 初期実装。署名鍵の本番運用と障害注入試験が必要 |

## 機能的な受入基準

- 認証あり・なし双方で、投稿、再読込、CSV、管理者受信が成立する
- 投稿後にフォームを変更しても過去の回答とフォーム版が保存される
- 参加者CSVに投稿フォームの氏名・所属・メールなどが現れない
- 管理者が担当外ルームの投稿、CSV、新着APIを取得できない
- 旧CSVの時刻、投稿本文、付随フォーム値が失われず重複インポートされない
- 旧ルームの必要な文言・項目が旧ZIPと照合され、欠落があれば記録される
- 非エンジニアがブラウザとFTPだけで設置・バックアップ・復元・移設・更新できる
- 失敗時にDBと稼働コードを安全に復旧できる
