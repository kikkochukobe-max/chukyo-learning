<?php
// unit_key → 表示名・ツールURL の台帳（mypage.php / retry.php が共用）
// 新しい単元にDB連携を組み込んだら、ここに1行追加する
return [
    'math_js3_heihokon' => [
        'title' => '平方根マスター',
        'sub'   => '数学・中3',
        'url'   => '/learning/math/math_js3_heihokonmaster.html',
    ],
    'math_js3_nijihoteishiki' => [
        'title' => '二次方程式マスター',
        'sub'   => '数学・中3',
        'url'   => '/learning/math/math_js3_nijihoteishiki.html',
    ],
    // 生成関数からの復元ではなく retry_queue.replay_json の再表示で解き直す
    // （migrate_retry_replay.sql / 詳細はツール内の initRetry のコメント）
    'math_js3_aichi_daimon1' => [
        'title' => '愛知県公立入試 大問1 マーク演習',
        'sub'   => '数学・中3',
        'url'   => '/learning/math/math_js3_aichi_daimon1.html',
        // 解き直しに replay_json が必須の単元（retry.php がボタンの出し分けに使う）
        'replay' => true,
    ],
    // 解き直しは question_params の {m:タイプ, lv:レベル, s:種} で同じ問題を作り直す（CLAUDE.md 2d）
    // レベルは question_key を増やさず params に持つ＝カルテはタイプ単位（11行）で読める
    'math_hs_suuretsu' => [
        'title' => '数列完全マスター',
        'sub'   => '数学・高校（数学B 数列）',
        'url'   => '/learning/math/math_hs_suuretsu.html',
    ],
    // 解き直しは question_params の {m:サブモード, s:種} で同じ問題を作り直す（CLAUDE.md 2d）
    'math_es5_baisu_yakusu' => [
        'title' => '倍数・約数マスター',
        'sub'   => '算数・小5',
        'url'   => '/learning/math/math_es5_baisu_yakusu.html',
    ],
    // 解き直しは question_params の {m:モード, s:種} で同じ問題を作り直す（CLAUDE.md 2d）
    'math_es5_tsuubun_kagen' => [
        'title' => '通分マスター（分数のたし算・ひき算）',
        'sub'   => '算数・小5',
        'url'   => '/learning/math/math_es5_tsuubun_kagen.html',
    ],
    'math_es5_yakubun' => [
        'title' => '約分練習ドリル',
        'sub'   => '算数・小5',
        'url'   => '/learning/math/math_es5_yakubun.html',
    ],
    'math_es4_warizan_hissan' => [
        'title' => 'わり算のひっ算マスター',
        'sub'   => '算数・小4',
        'url'   => '/learning/math/math_es4_warizan_hissan.html',
    ],
    'japanese_js1_kaeriten' => [
        'title' => '漢文 返り点ドリル',
        'sub'   => '国語・中1',
        'url'   => '/learning/japanese/japanese_js1_kaeriten.html',
    ],
    'japanese_goi_crossword' => [
        'title' => 'ことばのマスで語彙力マス',
        'sub'   => '国語・小学〜中学（語彙クロスワード）',
        'url'   => '/learning/japanese/japanese_esjs_goi_crossword.html',
    ],
    'math_js1_seihu' => [
        'title' => '正負の計算マスター',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_seihukeisanmaster.html',
    ],
    'math_js1_seihunomahojin' => [
        'title' => '魔法陣道場（正負の数）',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_seihunomahojin.html',
    ],
    'math_js1_seihunohugohantei' => [
        'title' => '符号判定クイズ',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_seihunohugohantei.html',
    ],
    'math_js1_zettaichi' => [
        'title' => '絶対値 練習道場',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_absolutevalue.html',
    ],
    'math_js1_kariheikin' => [
        'title' => '仮平均マスター',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_kariheikin.html',
    ],
    'math_es6_mojishiki' => [
        'title' => '文字式マスター',
        'sub'   => '算数・小6',
        'url'   => '/learning/math/math_es6_mojishiki.html',
    ],
    'math_js1_mojishiki_keisan' => [
        'title' => '文字式の計算マスター',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_mojishiki_keisan.html',
    ],
    'math_js1_houteishiki_master' => [
        'title' => '方程式マスター',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_houteishiki_master.html',
    ],
    'math_js1_houteishiki_riyou' => [
        'title' => '方程式マスター 文章題編',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_houteishiki_riyou.html',
    ],
    'math_js1_hyomenseki_taiseki' => [
        'title' => '立体マスター（表面積・体積）',
        'sub'   => '数学・中1',
        'url'   => '/learning/math/math_js1_hyomenseki_taiseki.html',
    ],
    'math_es2_all' => [
        'title' => '小2算数まるごとパック',
        'sub'   => '算数・小2',
        'url'   => '/learning/math/math_es2_all.html',
    ],
    'math_es3_all' => [
        'title' => '小3算数まるごとパック',
        'sub'   => '算数・小3',
        'url'   => '/learning/math/math_es3_all.html',
    ],
    'math_es3_warizanwakete' => [
        'title' => 'わり算れんしゅう（分けて計算）',
        'sub'   => '算数・小3',
        'url'   => '/learning/math/math_es3_warizanwakete.html',
    ],
    'math_es3_tokei' => [
        'title' => 'とけいマスター',
        'sub'   => '算数・小3',
        'url'   => '/learning/math/math_es3_tokei.html',
    ],
    // 解き直しは question_params の {g:単元, s:種} で同じ問題を作り直す
    // （小2・小3のまるごとパックと同じ種方式 = CLAUDE.md 2d）
    'math_es4_all' => [
        'title' => '小4算数まるごとパック',
        'sub'   => '算数・小4',
        'url'   => '/learning/math/math_es4_all.html',
    ],
    'math_es_hyakumasu' => [
        'title' => '100マス たし算れんしゅう',
        'sub'   => '算数・小学生',
        'url'   => '/learning/math/math_es_hyakumasu.html',
    ],
    'math_es6_keisan_dousuru' => [
        'title' => '計算どぅする？',
        'sub'   => '算数・小6',
        'url'   => '/learning/math/math_es6_keisan_dousuru.html',
    ],
    'math_es6_en_menseki' => [
        'title' => '円の面積マスター',
        'sub'   => '算数・小6',
        'url'   => '/learning/math/math_es6_en_menseki.html',
    ],
    'math_js2_keisan' => [
        'title' => '計算完璧マスター',
        'sub'   => '数学・中2',
        'url'   => '/learning/math/math_js2_keisan.html',
    ],
    'math_js2_renritsu_riyou' => [
        'title' => '連立方程式マスター 文章題編',
        'sub'   => '数学・中2',
        'url'   => '/learning/math/math_js2_renritsu_riyou.html',
    ],
    // 解き直しは question_params の {m:モード, s:種} で同じ問題を作り直す
    // （連立の文章題編と同じ画面構成だが、乱数を種方式にしてある = CLAUDE.md 2d）
    'math_js3_nijihoteishiki_riyou' => [
        'title' => '二次方程式マスター 文章題編',
        'sub'   => '数学・中3',
        'url'   => '/learning/math/math_js3_nijihoteishiki_riyou.html',
    ],
    // 解き直しは question_params の {m:モード, s:種} で同じ問題を作り直す
    // （生成関数が50個以上あるので replay_json は使わない。詳細はツール内の initRetry）
    'math_js2_ichijikansu' => [
        'title' => '一次関数マスター',
        'sub'   => '数学・中2',
        'url'   => '/learning/math/math_js2_ichijikansu.html',
    ],
    'allgrade_romaji' => [
        'title' => 'ローマ字マスター',
        'sub'   => 'ローマ字・五十音・英単語(おまけ)',
        'url'   => '/learning/allgrade/romaji_master.html',
    ],
    'social_es4_todofuken' => [
        'title' => '都道府県・県庁所在地マスター',
        'sub'   => '社会・小4',
        'url'   => '/learning/social/social_es4_todofuken.html',
    ],
    'english_js_eitango' => [
        'title' => '英単語練習',
        'sub'   => '英語・小学〜中3',
        'url'   => '/learning/english/english_js_eitango.html',
    ],
    'english_hs_target' => [
        'title' => 'TARGET 1900+',
        'sub'   => '英語・高校',
        'url'   => '/learning/english/english_hs_target.html',
    ],
    'english_js_grammar' => [
        'title' => '中学英文法',
        'sub'   => '英語・中1〜中3',
        'url'   => '/learning/english/english_js_grammar_app.html',
    ],
    'social_js_jisa' => [
        'title' => '時差計算練習',
        'sub'   => '社会・中学',
        'url'   => '/learning/social/social_js_jisa.html',
    ],
    'science_js1_busshitsu' => [
        'title' => '身のまわりの物質マスター',
        'sub'   => '理科・中1',
        'url'   => '/learning/science/science_js1_busshitsu.html',
    ],
    'science_js1_hikari_oto_chikara' => [
        'title' => '光・音・力マスター',
        'sub'   => '理科・中1',
        'url'   => '/learning/science/science_js1_hikari_oto_chikara.html',
    ],
    'science_js1_daichi' => [
        'title' => '大地の変化マスター',
        'sub'   => '理科・中1',
        'url'   => '/learning/science/science_js1_daichi.html',
    ],
    'science_js1_seibutsu' => [
        'title' => '生物の観察と分類マスター',
        'sub'   => '理科・中1',
        'url'   => '/learning/science/science_js1_seibutsu.html',
    ],
    'science_js2_seibutsu' => [
        'title' => '生物の体のつくりとはたらきマスター',
        'sub'   => '理科・中2',
        'url'   => '/learning/science/science_js2_seibutsu.html',
    ],
];
