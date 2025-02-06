<?php
/*
Plugin Name: Auto Post to X
Description: WordPressの投稿を自動的にXに投稿するプラグイン
Version: 1.0.0
Author: sorein
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
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
    
    // ハッシュタグ関連の設定を追加
    add_option('wp_to_x_default_hashtags', '');  // デフォルトハッシュタグ
    add_option('wp_to_x_use_category_hashtags', '1');  // カテゴリーをハッシュタグとして使用
    add_option('wp_to_x_max_hashtags', '3');  // 最大ハッシュタグ数
}

// フックの登録を修正
add_action('transition_post_status', 'handle_post_status_transition', 10, 3);

// 新しい関数を修正
function handle_post_status_transition($new_status, $old_status, $post) {
    // 投稿タイプが'post'でない場合は処理しない
    if ($post->post_type !== 'post') {
        return;
    }

    // 同じ投稿の処理中フラグをチェック
    $post_processing = get_transient('wp_to_x_processing_post_' . $post->ID);
    if ($post_processing) {
        error_log('WP to X Auto Post: この投稿は処理中です - Post ID: ' . $post->ID);
        return;
    }

    // グローバルな処理中フラグをチェック
    $global_processing = get_transient('wp_to_x_global_processing');
    if ($global_processing) {
        error_log('WP to X Auto Post: 他の投稿を処理中のため、スキップします');
        return;
    }

    try {
        // 処理中フラグを設定（1分間）
        set_transient('wp_to_x_processing_post_' . $post->ID, true, 60);
        set_transient('wp_to_x_global_processing', true, 60);

        error_log('WP to X Auto Post: ステータス遷移 - Post ID: ' . $post->ID . ', Old: ' . $old_status . ', New: ' . $new_status);

        // APIキーを取得
        $api_key = get_option('wp_to_x_api_key');
        $api_secret = get_option('wp_to_x_api_secret');
        $access_token = get_option('wp_to_x_access_token');
        $access_token_secret = get_option('wp_to_x_access_token_secret');

        if ($old_status !== 'publish' && $new_status === 'publish') {
            // 新規公開の場合
            if (get_post_meta($post->ID, 'posted_to_x', true)) {
                error_log('WP to X Auto Post: 既に投稿済みの記事です。 Post ID: ' . $post->ID);
                return;
            }

            // カスタムハッシュタグの保存処理を先に行う
            if (isset($_POST['wp_to_x_custom_hashtags'])) {
                $hashtags = sanitize_text_field($_POST['wp_to_x_custom_hashtags']);
                update_post_meta($post->ID, 'wp_to_x_custom_hashtags', $hashtags);
                error_log('WP to X Auto Post: カスタムハッシュタグを事前保存 - Post ID: ' . $post->ID . ', Tags: ' . $hashtags);
                
                // 保存後に少し待機して確実にデータベースに反映させる
                usleep(500000); // 0.5秒待機
            }

            // 投稿内容を作成
            $post_title = $post->post_title;
            $post_url = get_permalink($post->ID);
            
            // ハッシュタグを生成（保存されたデータを使用）
            $hashtags = wp_to_x_generate_hashtags($post->ID);
            error_log('WP to X Auto Post: 生成されたハッシュタグ: ' . print_r($hashtags, true));
            
            $tweet_text = $post_title . ' ' . $post_url;
            if (!empty($hashtags)) {
                $tweet_text .= ' ' . $hashtags;
            }

            error_log('WP to X Auto Post: 最終的な投稿内容: ' . $tweet_text);

            // Xに投稿
            $result = post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret);
            
            if ($result === true) {
                add_post_meta($post->ID, 'posted_to_x', true);
                error_log('WP to X Auto Post: 投稿成功 - Post ID: ' . $post->ID);
            } elseif ($result === "RATE_LIMIT") {
                update_option('wp_to_x_rate_limit_notice', 'レート制限に達しました。無料プランの上限は1ヶ月あたり1,500ツイート(17 requests / 24 hours)です。詳細は https://developer.x.com/en/portal/products をご確認ください。');
                return;
            } else {
                error_log('WP to X Auto Post: 投稿失敗');
            }

        } elseif ($old_status === 'publish' && $new_status === 'publish') {
            // 更新の場合
            // 新規投稿から1分以内の場合は更新投稿をスキップ
            $post_date = strtotime($post->post_date);
            if (time() - $post_date < 60) {
                return;
            }

            // 更新時の投稿が無効な場合はスキップ
            if (get_option('wp_to_x_enable_update_post') !== '1') {
                return;
            }

            // 最後の更新からの経過時間をチェック
            $last_update = get_post_meta($post->ID, 'last_x_update', true);
            $update_interval = get_option('wp_to_x_update_interval', 3600);
            if ($last_update && (time() - intval($last_update) < intval($update_interval))) {
                return;
            }

            // テンプレートを使用してメッセージを作成
            $post_title = $post->post_title;
            $post_url = get_permalink($post->ID);
            $template = get_option('wp_to_x_update_template', '記事を更新しました：{title} {url}');
            $tweet_text = str_replace(
                ['{title}', '{url}'],
                [$post_title, $post_url],
                $template
            );

            // ハッシュタグを追加
            $hashtags = wp_to_x_generate_hashtags($post->ID);
            if (!empty($hashtags)) {
                $tweet_text .= ' ' . $hashtags;
            }

            // Xに投稿
            $result = post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret);
            
            if ($result) {
                update_post_meta($post->ID, 'last_x_update', time());
            }
        }

    } catch (Exception $e) {
        error_log('WP to X Auto Post: Exception in handle_post_status_transition - ' . $e->getMessage());
    } finally {
        // 必ずフラグを削除
        delete_transient('wp_to_x_processing_post_' . $post->ID);
        delete_transient('wp_to_x_global_processing');
    }
}

// X APIへの投稿を行う共通関数を修正
function post_to_x_api($tweet_text, $api_key, $api_secret, $access_token, $access_token_secret) {
    require_once(plugin_dir_path(__FILE__) . 'includes/TwitterAPIExchange.php');
    
    try {
        // APIリクエストの間隔を確保（1秒待機）
        sleep(1);

        // 設定を準備
        $settings = array(
            'oauth_access_token' => $access_token,
            'oauth_access_token_secret' => $access_token_secret,
            'consumer_key' => $api_key,
            'consumer_secret' => $api_secret
        );

        $url = 'https://api.twitter.com/2/tweets';
        
        // リクエストデータを配列で準備
        $postfields = array(
            'text' => $tweet_text
        );
        
        // APIリクエストを実行
        $twitter = new TwitterAPIExchange($settings);
        if (!defined('CURL_OPTS')) {
            define('CURL_OPTS', array(
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ));
        }
        $result = $twitter->buildOauth($url, 'POST')
                         ->setPostfields($postfields)
                         ->performRequest();
        
        $response = json_decode($result, true);
        
        // レート制限エラーの場合は、ユーザーに通知して再投稿処理を停止
        if (isset($response['status']) && $response['status'] === 429) {
            error_log('WP to X Auto Post: レート制限に達しました。再投稿処理を停止します。');
            return "RATE_LIMIT";
        }
        
        // レスポンスチェック
        if (isset($response['data']['id'])) {
            return true;
        }
        
        error_log('WP to X Auto Post: 投稿失敗 - レスポンス: ' . print_r($response, true));
        return false;

    } catch (Exception $e) {
        error_log('WP to X Auto Post: Exception - ' . $e->getMessage());
        return false;
    }
}

// 管理画面にメニューを追加
add_action('admin_menu', 'wp_to_x_add_menu');

function wp_to_x_add_menu() {
    add_options_page(
        'Auto Post to X設定',
        'Auto Post to X設定',
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

        // WordPressのタイムゾーンを使用して現在時刻を取得
        $current_time = current_time('Y-m-d H:i:s');
        $test_text = 'これはAuto Post to Xのテスト投稿です。 ' . $current_time;
        
        $result = post_to_x_api($test_text, $api_key, $api_secret, $access_token, $access_token_secret);
        
        if ($result === true) {
            add_settings_error(
                'wp_to_x_messages',
                'wp_to_x_test_success',
                'テスト投稿に成功しました。Xで投稿を確認してください。',
                'updated'
            );
        } elseif ($result === "RATE_LIMIT") {
            add_settings_error(
                'wp_to_x_messages',
                'wp_to_x_test_error',
                'レート制限に達しました。無料プランの上限は1ヶ月あたり1,500ツイート(17 requests / 24 hours)です。詳細は https://developer.x.com/en/portal/products をご確認ください。',
                'error'
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
        update_option('wp_to_x_default_hashtags', sanitize_text_field($_POST['wp_to_x_default_hashtags']));
        update_option('wp_to_x_use_category_hashtags', isset($_POST['wp_to_x_use_category_hashtags']) ? '1' : '0');
        update_option('wp_to_x_max_hashtags', sanitize_text_field($_POST['wp_to_x_max_hashtags']));
        
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
    $default_hashtags = get_option('wp_to_x_default_hashtags');
    $use_category_hashtags = get_option('wp_to_x_use_category_hashtags');
    $max_hashtags = get_option('wp_to_x_max_hashtags');
    
    // HTMLの出力
    ?>
    <div class="wrap">
        <h2>Auto Post to X設定</h2>
        
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
            
            <h3>ハッシュタグ設定</h3>
            <table class="form-table">
                <tr>
                    <th><label for="wp_to_x_default_hashtags">デフォルトハッシュタグ</label></th>
                    <td>
                        <input type="text" id="wp_to_x_default_hashtags" name="wp_to_x_default_hashtags" 
                               value="<?php echo esc_attr($default_hashtags); ?>" class="regular-text">
                        <p class="description">カンマ区切りで入力してください（例：WordPress,blog）</p>
                    </td>
                </tr>
                <tr>
                    <th>カテゴリーハッシュタグ</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_to_x_use_category_hashtags" 
                                   value="1" <?php checked($use_category_hashtags, '1'); ?>>
                            カテゴリーをハッシュタグとして使用する
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wp_to_x_max_hashtags">最大ハッシュタグ数</label></th>
                    <td>
                        <select id="wp_to_x_max_hashtags" name="wp_to_x_max_hashtags">
                            <?php for ($i = 1; $i <= 5; $i++) : ?>
                                <option value="<?php echo $i; ?>" <?php selected($max_hashtags, $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
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
    
    // ハッシュタグ関連の設定を登録
    register_setting('wp_to_x_settings', 'wp_to_x_default_hashtags');
    register_setting('wp_to_x_settings', 'wp_to_x_use_category_hashtags');
    register_setting('wp_to_x_settings', 'wp_to_x_max_hashtags');
}

// メタボックスの表示制御を追加
add_filter('default_hidden_meta_boxes', 'wp_to_x_show_meta_box', 10, 2);
function wp_to_x_show_meta_box($hidden, $screen) {
    if ($screen->base === 'post' && $screen->post_type === 'post') {
        // wp_to_x_hashtagsをhiddenリストから削除
        $hidden = array_diff($hidden, array('wp_to_x_hashtags'));
    }
    return $hidden;
}

// プラグインの初期化時にフックを登録
add_action('init', 'wp_to_x_init');

function wp_to_x_init() {
    // メタボックスを追加
    add_action('add_meta_boxes', 'wp_to_x_add_meta_boxes');
    // 投稿保存時の処理を追加
    add_action('save_post', 'wp_to_x_save_meta_box_data');
}

// メタボックスの追加
function wp_to_x_add_meta_boxes() {
    add_meta_box(
        'wp_to_x_hashtags',           // メタボックスのID
        'X投稿用ハッシュタグ',         // タイトル
        'wp_to_x_hashtags_meta_box',  // コールバック関数
        'post',                       // 投稿タイプ
        'side',                       // 表示位置
        'high'                        // 優先順位
    );
}

// メタボックスの表示内容
function wp_to_x_hashtags_meta_box($post) {
    // nonce フィールドを追加
    wp_nonce_field('wp_to_x_hashtags_nonce', 'wp_to_x_hashtags_nonce');

    // 既存の値を取得
    $custom_hashtags = get_post_meta($post->ID, 'wp_to_x_custom_hashtags', true);
    ?>
    <p>
        <label for="wp_to_x_custom_hashtags">カスタムハッシュタグ:</label><br>
        <input type="text" id="wp_to_x_custom_hashtags" name="wp_to_x_custom_hashtags" 
               value="<?php echo esc_attr($custom_hashtags); ?>" class="widefat">
    </p>
    <p class="description">
        カンマ区切りで入力してください（例：tag1,tag2,tag3）<br>
        # は自動的に付加されます
    </p>
    <?php
}

// メタボックスのデータを保存
function wp_to_x_save_meta_box_data($post_id) {
    // 既存のチェック処理...

    // 処理中フラグをチェック
    if (get_transient('wp_to_x_processing_post_' . $post_id)) {
        error_log('WP to X Auto Post: メタボックス保存をスキップ - Post ID: ' . $post_id);
        return;
    }

    // カスタムハッシュタグの保存
    if (isset($_POST['wp_to_x_custom_hashtags'])) {
        $hashtags = sanitize_text_field($_POST['wp_to_x_custom_hashtags']);
        update_post_meta($post_id, 'wp_to_x_custom_hashtags', $hashtags);
        error_log('WP to X Auto Post: カスタムハッシュタグを保存しました - Post ID: ' . $post_id . ', Tags: ' . $hashtags);
    }
}

// ハッシュタグを生成する関数を修正
function wp_to_x_generate_hashtags($post_id) {
    error_log('WP to X Auto Post: ハッシュタグ生成開始 - Post ID: ' . $post_id);
    
    $hashtags = array();
    $max_hashtags = intval(get_option('wp_to_x_max_hashtags', 3));
    
    // カスタムハッシュタグを最初に追加
    $custom_hashtags = get_post_meta($post_id, 'wp_to_x_custom_hashtags', true);
    error_log('WP to X Auto Post: 取得したカスタムハッシュタグ - ' . $custom_hashtags);
    
    if (!empty($custom_hashtags)) {
        // カンマで分割（スペースは無視）
        $customs = array_map('trim', explode(',', $custom_hashtags));
        foreach ($customs as $tag) {
            if (!empty($tag)) {
                // 特殊文字を除去し、先頭の#があれば削除
                $tag = trim($tag, '#');
                $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag);
                if (!empty($tag)) {
                    $hashtags[] = $tag;
                    error_log('WP to X Auto Post: カスタムハッシュタグ追加 - ' . $tag);
                }
            }
        }
    }
    
    // デフォルトハッシュタグを追加（最大数に達していない場合のみ）
    if (count($hashtags) < $max_hashtags) {
        $default_hashtags = get_option('wp_to_x_default_hashtags', '');
        if (!empty($default_hashtags)) {
            $defaults = array_map('trim', explode(',', $default_hashtags));
            foreach ($defaults as $tag) {
                if (!empty($tag) && count($hashtags) < $max_hashtags) {
                    $hashtags[] = trim($tag, '#');
                    error_log('WP to X Auto Post: デフォルトハッシュタグ追加 - ' . $tag);
                }
            }
        }
    }
    
    // カテゴリーをハッシュタグとして使用（最大数に達していない場合のみ）
    if (count($hashtags) < $max_hashtags && get_option('wp_to_x_use_category_hashtags', '1') === '1') {
        $categories = get_the_category($post_id);
        foreach ($categories as $category) {
            if (count($hashtags) >= $max_hashtags) break;
            $category_name = preg_replace('/[^\p{L}\p{N}_]/u', '', $category->name);
            if (!empty($category_name)) {
                $hashtags[] = $category_name;
                error_log('WP to X Auto Post: カテゴリーハッシュタグ追加 - ' . $category_name);
            }
        }
    }
    
    // 重複を削除し、空の要素を除去
    $hashtags = array_unique(array_filter($hashtags));
    
    // 最大数に制限
    $hashtags = array_slice($hashtags, 0, $max_hashtags);
    
    // ハッシュタグ形式に変換
    $formatted_hashtags = array_map(function($tag) {
        return '#' . $tag;
    }, $hashtags);
    
    $result = implode(' ', $formatted_hashtags);
    error_log('Auto Post to X: 最終ハッシュタグ - ' . $result);
    
    return $result;
}
 
// 管理画面でレート制限の通知を表示する
function wp_to_x_admin_notice() {
    $notice = get_option('wp_to_x_rate_limit_notice', '');
    if (!empty($notice)) {
         echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($notice) . '</p></div>';
         // 削除せず、通知を継続表示
         // delete_option('wp_to_x_rate_limit_notice');
    }
}
add_action('admin_notices', 'wp_to_x_admin_notice');
add_action('network_admin_notices', 'wp_to_x_admin_notice');
 
// 投稿編集画面にレート制限エラーを表示する
function wp_to_x_post_edit_notice() {
    $notice = get_option('wp_to_x_rate_limit_notice', '');
    if (!empty($notice)) {
         echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($notice) . '</p></div>';
         delete_option('wp_to_x_rate_limit_notice');
    }
}
add_action('edit_form_after_title', 'wp_to_x_post_edit_notice');
