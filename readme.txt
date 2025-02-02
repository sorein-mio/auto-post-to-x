=== WP to X Auto Post ===
Contributors: sorein
Tags: twitter, x, social media, auto post, social share
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WordPressの投稿を自動的にX（旧Twitter）に投稿するプラグインです。

== Description ==

WP to X Auto Postは、WordPressで記事を公開または更新した際に、自動的にX（旧Twitter）に投稿するプラグインです。

主な機能：
* 記事公開時の自動投稿
* 記事更新時の自動投稿（設定可能）
* カスタムハッシュタグのサポート
* カテゴリーをハッシュタグとして使用可能
* 投稿テンプレートのカスタマイズ
* 更新投稿の間隔設定

== Installation ==

1. プラグインファイルを `/wp-content/plugins/` ディレクトリにアップロードします
2. WordPress管理画面でプラグインを有効化します
3. 設定 > WP to X設定 から、X APIの認証情報を設定します
4. 必要に応じて、投稿設定やハッシュタグ設定をカスタマイズします

== Frequently Asked Questions ==

= APIの認証情報はどこで取得できますか？ =

1. X Developer Portalにアクセスし、開発者アカウントを作成します
2. 新しいアプリケーションを作成し、必要な権限を設定します
3. API KeyやAccess Tokenを取得します

詳しい手順は、プラグイン設定画面の「X API認証情報の取得方法」をご確認ください。

== Changelog ==

= 1.0.0 =
* 初回リリース
* 基本的な自動投稿機能
* ハッシュタグサポート
* 更新投稿機能 