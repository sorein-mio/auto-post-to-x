<?php
/*
Plugin Name: WP to X Auto Post
Description: WordPressの投稿を自動的にXに投稿するプラグイン
Version: 1.0
Author: sorein
*/

if (!defined('ABSPATH')) {
    exit;
}

// プラグインが有効化された時の処理
register_activation_hook(__FILE__, 'wp_to_x_activate');

function wp_to_x_activate() {
    // X APIの認証情報を保存するオプションを追加
    add_option('wp_to_x_api_key', '');
    add_option('wp_to_x_api_secret', '');
    add_option('wp_to_x_access_token', '');
    add_option('wp_to_x_access_token_secret', '');
    
    // 更新関連の設定を追加
    add_option('wp_to_x_enable_update_post', '1');  // デフォルトで有効
    add_option('wp_to_x_update_interval', '3600');  // デフォルトで1時間
    add_option('wp_to_x_update_template', '記事を更新しました：{title} {url}');
}

// 投稿が公開された時のフック
add_action('publish_post', 'post_to_x', 10, 2);
add_action('post_updated', 'handle_post_update', 10, 3);

function post_to_x($post_id, $post) {
    try {
        // 新規投稿かどうかをチェック
        if (get_post_meta($post_id, 'posted_to_x', true)) {
            error_log('WP to X Auto Post: 既に投稿済みの記事です。 Post ID: ' . $post_id);
            return;
        }
        
        // 投稿が新規作成されてから一定時間（例：1時間）以上経過している場合は投稿しない
        $post_date = strtotime($post->post_date);
        if (time() - $post_date > 3600) { // 3600秒 = 1時間
            return;
        }

        // APIキーを取得
        $api_key = get_option('wp_to_x_api_key');
        $api_secret = get_option('wp_to_x_api_secret');
        $access_token = get_option('wp_to_x_access_token');
        $access_token_secret = get_option('wp_to_x_access_token_secret');

        // 投稿内容を作成
        $post_title = $post->post_title;
        $post_url = get_permalink($post_id);
        $tweet_text = $post_title . ' ' . $post_url;

        error_log('WP to X Auto Post: 投稿を試みます - ' . $tweet_text);

        // Xに投稿
        $result = post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret);

        if ($result) {
            // 投稿完了をマーク
            add_post_meta($post_id, 'posted_to_x', true);
            error_log('WP to X Auto Post: 投稿成功 - Post ID: ' . $post_id);
        } else {
            error_log('WP to X Auto Post: 投稿失敗 - Post ID: ' . $post_id);
        }

    } catch (Exception $e) {
        error_log('WP to X Auto Post: Exception in post_to_x - ' . $e->getMessage());
    }
}

function handle_post_update($post_id, $post_after, $post_before) {
    // 投稿タイプが'post'でない場合は処理しない
    if ($post_after->post_type !== 'post') {
        return;
    }

    // 下書きから公開への変更を検知
    if ($post_before->post_status !== 'publish' && $post_after->post_status === 'publish') {
        // 新規公開として処理
        post_to_x($post_id, $post_after);
        return;
    }

    // 更新の場合（既に公開済みの記事の更新）
    if ($post_before->post_status === 'publish' && $post_after->post_status === 'publish') {
        // 更新時の投稿が無効な場合はスキップ
        if (get_option('wp_to_x_enable_update_post') !== '1') {
            return;
        }

        // 最後の更新からの経過時間をチェック
        $last_update = get_post_meta($post_id, 'last_x_update', true);
        $update_interval = get_option('wp_to_x_update_interval', 3600);
        if ($last_update && (time() - intval($last_update) < intval($update_interval))) {
            return;
        }

        // X APIの認証情報を取得
        $api_key = get_option('wp_to_x_api_key');
        $api_secret = get_option('wp_to_x_api_secret');
        $access_token = get_option('wp_to_x_access_token');
        $access_token_secret = get_option('wp_to_x_access_token_secret');

        // テンプレートを使用してメッセージを作成
        $post_title = $post_after->post_title;
        $post_url = get_permalink($post_id);
        $template = get_option('wp_to_x_update_template', '記事を更新しました：{title} {url}');
        $tweet_text = str_replace(
            ['{title}', '{url}'],
            [$post_title, $post_url],
            $template
        );

        // Xに投稿
        post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret);

        // 最終更新時刻を記録
        update_post_meta($post_id, 'last_x_update', time());
    }
}

// X APIへの投稿を行う共通関数を修正
function post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret) {
    require_once(plugin_dir_path(__FILE__) . 'includes/TwitterAPIExchange.php');
    
    try {
        error_log('WP to X Auto Post: API呼び出し開始');
        error_log('WP to X Auto Post: 投稿内容 - ' . $tweet_text);
        
        // APIキーの長さと形式をチェック
        error_log('WP to X Auto Post: API Key長: ' . strlen($api_key));
        error_log('WP to X Auto Post: API Secret長: ' . strlen($api_secret));
        error_log('WP to X Auto Post: Access Token長: ' . strlen($access_token));
        error_log('WP to X Auto Post: Access Token Secret長: ' . strlen($access_token_secret));
        
        // APIキーが設定されているかチェック
        if (empty($api_key) || empty($api_secret) || empty($access_token) || empty($access_token_secret)) {
            error_log('WP to X Auto Post: API認証情報が設定されていません。');
            error_log('API Key: ' . ($api_key ? '設定済み' : '未設定'));
            error_log('API Secret: ' . ($api_secret ? '設定済み' : '未設定'));
            error_log('Access Token: ' . ($access_token ? '設定済み' : '未設定'));
            error_log('Access Token Secret: ' . ($access_token_secret ? '設定済み' : '未設定'));
            return false;
        }

        // APIキーの形式チェック（基本的な文字種のチェック）
        if (!preg_match('/^[A-Za-z0-9\-_]+$/', $api_key) || 
            !preg_match('/^[A-Za-z0-9\-_]+$/', $api_secret) || 
            !preg_match('/^[0-9]+-[A-Za-z0-9\-_]+$/', $access_token) || 
            !preg_match('/^[A-Za-z0-9\-_]+$/', $access_token_secret)) {
            error_log('WP to X Auto Post: API認証情報の形式が不正です');
            return false;
        }

        $settings = array(
            'oauth_access_token' => trim($access_token),
            'oauth_access_token_secret' => trim($access_token_secret),
            'consumer_key' => trim($api_key),
            'consumer_secret' => trim($api_secret)
        );

        error_log('WP to X Auto Post: 認証設定完了');
        error_log('WP to X Auto Post: 認証設定内容（一部マスク） - ' . 
                 'API Key: ' . substr($api_key, 0, 4) . '..., ' .
                 'Access Token: ' . substr($access_token, 0, 8) . '...');

        // v1.1 APIエンドポイントを使用
        $url = 'https://api.twitter.com/1.1/statuses/update.json';
        $requestMethod = 'POST';
        
        // POSTデータを配列として準備（v1.1 API用）
        $postfields = array(
            'status' => $tweet_text // エンコードはTwitterAPIExchangeクラスに任せる
        );

        error_log('WP to X Auto Post: リクエスト準備 - URL: ' . $url);
        error_log('WP to X Auto Post: POST データ - ' . json_encode($postfields));

        $twitter = new TwitterAPIExchange($settings);
        
        // OAuth署名を含むリクエストを構築
        $response = $twitter->buildOauth($url, $requestMethod)
                           ->setPostfields($postfields)
                           ->performRequest();

        error_log('WP to X Auto Post: API レスポンス受信');
        error_log('WP to X Auto Post: レスポンス内容 - ' . $response);

        // レスポンスをデコード
        $result = json_decode($response, true);
        
        // エラーチェック（v1.1 APIのレスポンス形式に合わせて修正）
        if (isset($result['errors']) || !isset($result['id_str'])) {
            error_log('WP to X Auto Post: API エラー検出');
            error_log('WP to X Auto Post: エラー詳細 - ' . print_r($result, true));
            return false;
        }

        error_log('WP to X Auto Post: 投稿成功 - Tweet ID: ' . $result['id_str']);
        return true;

    } catch (Exception $e) {
        error_log('WP to X Auto Post: 例外発生');
        error_log('WP to X Auto Post: 例外メッセージ - ' . $e->getMessage());
        error_log('WP to X Auto Post: スタックトレース - ' . $e->getTraceAsString());
        return false;
    }
}

// 管理画面にメニューを追加
add_action('admin_menu', 'wp_to_x_add_menu');

function wp_to_x_add_menu() {
    add_options_page(
        'WP to X設定',
        'WP to X設定',
        'manage_options',
        'wp-to-x-settings',
        'wp_to_x_settings_page'
    );
}

// 設定ページの表示
function wp_to_x_settings_page() {
    // 出力バッファリングを開始
    ob_start();
    
    // テスト投稿の処理
    if (isset($_POST['wp_to_x_test_post'])) {
        check_admin_referer('wp_to_x_test_nonce', '_wpnonce_test');
        
        $api_key = get_option('wp_to_x_api_key');
        $api_secret = get_option('wp_to_x_api_secret');
        $access_token = get_option('wp_to_x_access_token');
        $access_token_secret = get_option('wp_to_x_access_token_secret');

        $test_text = 'これはWP to X Auto Postのテスト投稿です。 ' . date('Y-m-d H:i:s');
        
        $result = post_to_x_api($test_text, $api_key, $api_secret, $access_token, $access_token_secret);
        
        if ($result) {
            add_settings_error(
                'wp_to_x_messages',
                'wp_to_x_test_success',
                'テスト投稿に成功しました。Xで投稿を確認してください。',
                'updated'
            );
        } else {
            add_settings_error(
                'wp_to_x_messages',
                'wp_to_x_test_error',
                'テスト投稿に失敗しました。WordPressのエラーログを確認してください。',
                'error'
            );
        }
    }

    // 設定の保存処理
    if (isset($_POST['wp_to_x_settings_submit'])) {
        check_admin_referer('wp_to_x_settings_nonce');
        
        update_option('wp_to_x_api_key', sanitize_text_field($_POST['wp_to_x_api_key']));
        update_option('wp_to_x_api_secret', sanitize_text_field($_POST['wp_to_x_api_secret']));
        update_option('wp_to_x_access_token', sanitize_text_field($_POST['wp_to_x_access_token']));
        update_option('wp_to_x_access_token_secret', sanitize_text_field($_POST['wp_to_x_access_token_secret']));
        
        update_option('wp_to_x_enable_update_post', isset($_POST['wp_to_x_enable_update_post']) ? '1' : '0');
        update_option('wp_to_x_update_interval', sanitize_text_field($_POST['wp_to_x_update_interval']));
        update_option('wp_to_x_update_template', sanitize_text_field($_POST['wp_to_x_update_template']));
        
        add_settings_error(
            'wp_to_x_messages',
            'wp_to_x_settings_saved',
            '設定を保存しました。',
            'updated'
        );
    }

    // 現在の設定値を取得
    $api_key = get_option('wp_to_x_api_key');
    $api_secret = get_option('wp_to_x_api_secret');
    $access_token = get_option('wp_to_x_access_token');
    $access_token_secret = get_option('wp_to_x_access_token_secret');
    $enable_update_post = get_option('wp_to_x_enable_update_post');
    $update_interval = get_option('wp_to_x_update_interval');
    $update_template = get_option('wp_to_x_update_template');
    
    // HTMLの出力
    ?>
    <div class="wrap">
        <h2>WP to X 設定</h2>
        
        <?php settings_errors('wp_to_x_messages'); ?>
        
        <!-- 折りたたみ式APIガイド -->
        <div class="card" style="max-width: 100%; margin-bottom: 20px;">
            <details>
                <summary style="cursor: pointer; padding: 10px; font-weight: bold;">
                    X（Twitter）API認証情報の取得方法（クリックで展開）
                </summary>
                <div style="padding: 15px;">
                    <ol style="line-height: 1.8;">
                        <li><a href="https://developer.x.com/en/portal/dashboard" target="_blank">X Developer Portal</a>にアクセスし、アカウントでログインします。</li>
                        <li>開発者アカウントの申請を行います：
                            <ul>
                                <li>「Apply for Access」をクリック</li>
                                <li>「Free Access」を選択</li>
                                <li>必要な情報を入力（名前、国、用途など）</li>
                                <li>How will you use the Twitter API?: 「Making a bot」を選択</li>
                                <li>詳細な使用目的：「Posting WordPress blog updates automatically to X」と記載</li>
                            </ul>
                        </li>
                        <li>承認後、アプリを作成します：
                            <ul>
                                <li>「+ Create Project」をクリック</li>
                                <li>プロジェクト名を入力（例：「WordPress Auto Post」）</li>
                                <li>Use Caseで「Making a bot」を選択</li>
                                <li>プロジェクトの説明を入力</li>
                                <li>「Next」をクリック</li>
                            </ul>
                        </li>
                        <li>アプリの設定：
                            <ul>
                                <li>アプリ名を入力（例：「WP to X Auto Post」）</li>
                                <li>「App permissions」で「Read and Write」を選択</li>
                                <li>App infoの各項目を入力：
                                    <ul>
                                        <li>「Callback URI / Redirect URL」（必須）:
                                            <ul>
                                                <li>あなたのWordPressサイトのURL（例：https://your-site.com）</li>
                                                <li>必ずhttpsまたはschemeで始まるURLを入力</li>
                                            </ul>
                                        </li>
                                        <li>「Website URL」（必須）:
                                            <ul>
                                                <li>あなたのWordPressサイトのURL（例：https://your-site.com）</li>
                                                <li>必ずhttpsで始まるURLを入力</li>
                                            </ul>
                                        </li>
                                        <li>「Organization name」（任意）:
                                            <ul>
                                                <li>あなたの組織名または個人名</li>
                                                <li>ユーザーがアプリを認証する際に表示される名前</li>
                                            </ul>
                                        </li>
                                        <li>「Organization URL」（任意）:
                                            <ul>
                                                <li>組織またはあなたのサイトのURL</li>
                                                <li>ユーザーがアプリを認証する際に表示されるリンク</li>
                                                <li>必ずhttpsで始まるURLを入力</li>
                                            </ul>
                                        </li>
                                        <li>「Terms of service」（任意）:
                                            <ul>
                                                <li>利用規約ページのURL</li>
                                                <li>ユーザーがアプリを認証する際に表示される</li>
                                                <li>必ずhttpsで始まるURLを入力</li>
                                            </ul>
                                        </li>
                                        <li>「Privacy policy」（任意）:
                                            <ul>
                                                <li>プライバシーポリシーページのURL</li>
                                                <li>ユーザーがアプリを認証する際に表示される</li>
                                                <li>必ずhttpsで始まるURLを入力</li>
                                            </ul>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        <li>認証情報の取得：
                            <ul>
                                <li>「Keys and tokens」タブを選択</li>
                                <li>「API Key and Secret」の「Generate」をクリック
                                    <ul>
                                        <li>表示されたAPI Key (Client ID)をメモ</li>
                                        <li>表示されたAPI Key Secret (Client Secret)をメモ</li>
                                    </ul>
                                </li>
                                <li>「Access Token and Secret」の「Generate」をクリック
                                    <ul>
                                        <li>表示されたAccess Tokenをメモ</li>
                                        <li>表示されたAccess Token Secretをメモ</li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                    </ol>
                    <p class="description" style="margin-top: 10px;">
                        <strong>重要な注意事項：</strong>
                        <ul>
                            <li>API Key SecretとAccess Token Secretは生成時にしか表示されません。必ず安全な場所に保存してください。</li>
                            <li>Free Access（基本アクセス）の場合、1ヶ月あたり1,500ツイートまでの制限があります。</li>
                            <li>アプリ名は一意である必要があります（他のユーザーと重複しない名前を設定してください）。</li>
                            <li>詳しい手順は<a href="https://developer.x.com/en/docs/authentication/oauth-1-0a" target="_blank">X開発者ドキュメント</a>をご確認ください。</li>
                        </ul>
                    </p>
                </div>
            </details>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('wp_to_x_settings_nonce'); ?>
            <h3>API設定</h3>
            <table class="form-table">
                <tr>
                    <th><label for="wp_to_x_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="wp_to_x_api_key" name="wp_to_x_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_api_secret">API Secret</label></th>
                    <td>
                        <input type="password" id="wp_to_x_api_secret" name="wp_to_x_api_secret" 
                               value="<?php echo esc_attr($api_secret); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_access_token">Access Token</label></th>
                    <td>
                        <input type="text" id="wp_to_x_access_token" name="wp_to_x_access_token" 
                               value="<?php echo esc_attr($access_token); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_access_token_secret">Access Token Secret</label></th>
                    <td>
                        <input type="password" id="wp_to_x_access_token_secret" name="wp_to_x_access_token_secret" 
                               value="<?php echo esc_attr($access_token_secret); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <p class="description">
                            上記のガイドに従ってX開発者ポータルで取得したAPI認証情報を入力してください。<br>
                            各項目は正確に入力してください。入力を間違えると投稿機能が正しく動作しません。
                        </p>
                    </td>
                </tr>
            </table>
            
            <h3>更新投稿の設定</h3>
            <table class="form-table">
                <tr>
                    <th>更新時の投稿</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_to_x_enable_update_post" 
                                   value="1" <?php checked($enable_update_post, '1'); ?>>
                            記事更新時にXへ投稿する
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_update_interval">更新投稿の間隔</label></th>
                    <td>
                        <select id="wp_to_x_update_interval" name="wp_to_x_update_interval">
                            <option value="1800" <?php selected($update_interval, '1800'); ?>>30分</option>
                            <option value="3600" <?php selected($update_interval, '3600'); ?>>1時間</option>
                            <option value="7200" <?php selected($update_interval, '7200'); ?>>2時間</option>
                            <option value="21600" <?php selected($update_interval, '21600'); ?>>6時間</option>
                            <option value="86400" <?php selected($update_interval, '86400'); ?>>24時間</option>
                        </select>
                        <p class="description">同じ記事の更新投稿を制限する時間間隔</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_update_template">更新投稿テンプレート</label></th>
                    <td>
                        <input type="text" id="wp_to_x_update_template" name="wp_to_x_update_template" 
                               value="<?php echo esc_attr($update_template); ?>" class="large-text">
                        <p class="description">
                            利用可能な変数：{title} = 記事タイトル, {url} = 記事URL<br>
                            例：記事を更新しました：{title} {url}
                        </p>
                    </td>
                </tr>
            </table>
            
            <h3>API接続テスト</h3>
            <table class="form-table">
                <tr>
                    <th>テスト投稿</th>
                    <td>
                        <!-- 別フォームとして分離 -->
                        <?php wp_nonce_field('wp_to_x_test_nonce', '_wpnonce_test'); ?>
                        <input type="submit" name="wp_to_x_test_post" class="button" value="テスト投稿を実行">
                        <p class="description">現在の設定でテスト投稿を実行します。</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wp_to_x_settings_submit" class="button-primary" value="設定を保存">
            </p>
        </form>
    </div>
    <?php
    
    // 出力バッファの内容を出力して終了
    echo ob_get_clean();
}

// セキュリティ対策として設定を登録
add_action('admin_init', 'wp_to_x_register_settings');

function wp_to_x_register_settings() {
    register_setting('wp_to_x_settings', 'wp_to_x_api_key');
    register_setting('wp_to_x_settings', 'wp_to_x_api_secret');
    register_setting('wp_to_x_settings', 'wp_to_x_access_token');
    register_setting('wp_to_x_settings', 'wp_to_x_access_token_secret');
    
    // 更新関連の設定を登録
    register_setting('wp_to_x_settings', 'wp_to_x_enable_update_post');
    register_setting('wp_to_x_settings', 'wp_to_x_update_interval');
    register_setting('wp_to_x_settings', 'wp_to_x_update_template');
} 