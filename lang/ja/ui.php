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
