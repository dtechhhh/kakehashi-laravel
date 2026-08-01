<?php

return [
    'app_name' => 'Kakehashi',
    'brand_subtitle' => 'インドネシア・日本 キャリアの架け橋',
    'skip_link' => 'メインコンテンツへスキップ',

    'language' => [
        'id' => 'ID',
        'ja' => 'JA',
        'label' => '言語を切り替える',
    ],

    'nav' => [
        'label' => 'メインナビゲーション',
        'home' => 'ホーム',
        'candidates' => '候補者',
        'lookup' => 'マスターデータ',
        'requests' => 'リクエスト',
        'companies' => '企業',
        'users' => 'アカウント管理',
        'audit' => '監査',
    ],

    'common' => [
        'save' => '保存',
        'cancel' => 'キャンセル',
        'back' => '戻る',
        'reload' => '再読み込み',
        'continue' => '続ける',
        'confirm' => '確認',
        'close' => '閉じる',
        'search' => '検索',
        'filter' => '絞り込み',
        'actions' => '操作',
        'view' => '表示',
        'edit' => '編集',
        'delete' => '削除',
        'loading' => '読み込み中…',
        'logout' => 'ログアウト',
        'login' => 'ログイン',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'current_password' => '現在のパスワード',
        'new_password' => '新しいパスワード',
        'confirm_password' => '新しいパスワード（確認）',
    ],

    'auth' => [
        'login' => [
            'title' => 'ログイン',
            'subtitle' => 'メールアドレスとパスワードでログインしてください。',
            'error_invalid' => 'メールアドレスまたはパスワードが正しくありません。',
            'error_inactive' => 'アカウントが無効です。管理者にお問い合わせください。',
            'error_generic' => 'エラーが発生しました。もう一度お試しください。',
        ],
        'lockout' => [
            'title' => '試行回数が多すぎます',
            'description' => 'ログイン失敗が続いたため、アカウントは一時的にロックされました。',
            'retry_in' => ':time 後に再試行できます',
            'back_to_login' => 'ログイン画面に戻る',
        ],
        'password_forced' => [
            'title' => 'パスワードの変更',
            'subtitle' => '続行する前にパスワードを変更してください。',
            'policy' => '12文字以上で、大文字・小文字・数字・記号のうち3種類以上を含む必要があります。',
            'success' => 'パスワードが変更されました。',
            'error_current' => '現在のパスワードが正しくありません。',
            'error_policy' => '新しいパスワードがポリシーを満たしていません。',
        ],
        'enroll' => [
            'title' => '二段階認証の設定',
            'subtitle' => '認証アプリでQRコードを読み取り、6桁のコードを入力して確認してください。',
            'secret_label' => 'または、秘密鍵を手入力',
            'step_scan' => '1. 下のQRコードを読み取ってください',
            'step_confirm' => '2. アプリに表示された6桁のコードを入力',
            'confirm_button' => '確認して有効化',
            'recovery_title' => '復旧コード',
            'recovery_description' => '安全な場所に保管してください。各コードは1回のみ使用できます。',
            'recovery_done' => '復旧コードを保存しました。ホームへ進んでください。',
            'continue_home' => 'ホームへ進む',
            'already_enabled' => '二段階認証はすでに有効です。',
            'error_invalid_code' => 'コードが正しくありません。もう一度お試しください。',
            'error_generic' => '二段階認証の設定中にエラーが発生しました。',
        ],
        'challenge' => [
            'title' => '二段階認証',
            'subtitle' => '認証アプリに表示された6桁のコードを入力してください。',
            'code_label' => '6桁のコード',
            'use_recovery' => '復旧コードを使用',
            'use_code' => 'アプリのコードを使用',
            'recovery_label' => '復旧コード',
            'error_invalid' => 'コードが正しくありません。もう一度お試しください。',
            'error_expired' => 'ログインセッションが期限切れです。もう一度ログインしてください。',
        ],
    ],

    'state' => [
        'loading' => '読み込み中…',
        'empty' => [
            'title' => 'データがありません',
            'description' => '表示できるデータがありません。',
        ],
        'forbidden' => [
            'title' => 'アクセスが拒否されました',
            'description' => 'このページを表示する権限がありません。',
        ],
        'not_found' => [
            'title' => 'ページが見つかりません',
            'description' => 'お探しのページは存在しません。',
        ],
        'session_expired' => [
            'title' => 'セッションが切れました',
            'description' => 'セッションが終了しました。もう一度ログインしてください。',
        ],
        'conflict' => [
            'title' => 'データが他のユーザーにより変更されました',
            'description' => 'データが他のユーザーにより変更されました。再読み込みしてから再度お試しください。',
        ],
    ],

    'date_time_format' => 'Y年m月d日 H:i',

    'home' => [
        'greeting' => 'ようこそ、:name さん',
        'empty_title' => 'ホーム',
        'empty_description' => '上のメニューから操作を選択してください。',
    ],

    'user_menu' => [
        'label' => 'ユーザーメニュー',
        'role' => '役割',
    ],

    'notifications' => [
        'title' => '通知',
        'empty' => '通知はありません。',
        'unread' => '未読通知 :count 件',
        'CANDIDATE_SUBMITTED' => '新しい候補者が審査に提出されました。',
        'CANDIDATE_REVISION_SUBMITTED' => '候補者の修正版が審査に提出されました。',
        'CANDIDATE_APPROVED' => '候補者が承認されました。',
        'CANDIDATE_REJECTED' => '候補者が却下されました。却下理由をご確認ください。',
        'LOOKUP_REQUEST_SUBMITTED' => '新しいルックアップデータのリクエストが提出されました。',
        'COMPANY_REQUESTED' => '新しい企業データのリクエストが提出されました。',
    ],
];
