<?php
declare(strict_types=1);

/**
 * Find the non-public application folder without assuming the host allows
 * changing DocumentRoot. No database/config data may be under the web root.
 */
function postCommentRoot(): string
{
    $public = realpath(__DIR__);
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
    if (!$public || !$documentRoot) {
        throw new RuntimeException('Web公開ディレクトリを検出できません。サーバー設定を確認してください。');
    }
    $candidates = [
        dirname($public),
        dirname($public,2).'/post-comment-private',
        dirname($public).'/post-comment-private',
    ];

    // For uncommon layouts, the operator may place a separate location.php
    // in the public folder containing ONLY the absolute private path string.
    $override=__DIR__.'/location.php';
    if (is_file($override)) {
        $path=require $override;
        if (!is_string($path) || $path==='') {
            throw new RuntimeException('location.phpのパス設定が不正です。');
        }
        array_unshift($candidates,$path);
    }
    foreach ($candidates as $candidate) {
        $root=realpath($candidate);
        if (!$root || !is_file($root.'/src/Database.php') || !is_dir($root.'/database/migrations')) continue;
        if ($root===$documentRoot || str_starts_with($root,$documentRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('設定やSQLiteがWeb公開領域にあります。公開領域外へ移動してください。');
        }
        return $root;
    }
    throw new RuntimeException('非公開アプリ本体が見つかりません。配置とlocation.phpを確認してください。');
}
