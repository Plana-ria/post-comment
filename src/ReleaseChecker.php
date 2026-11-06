<?php
declare(strict_types=1);

namespace PostComment;

use RuntimeException;

/** Read-only GitHub Releases discovery. Never downloads or executes PHP code. */
final class ReleaseChecker
{
    public static function currentVersion(string $root): string
    {
        $file=$root.'/VERSION';
        return is_file($file) ? trim((string)file_get_contents($file)) : '0.0.0-dev';
    }

    public static function fromApi(array $payload): ?array
    {
        $tag=(string)($payload['tag_name']??'');
        if (!preg_match('/^v([0-9]+)\.([0-9]+)\.([0-9]+)$/',$tag)) return null;
        if (!empty($payload['draft']) || !empty($payload['prerelease'])) return null;
        foreach (($payload['assets']??[]) as $asset) {
            if (!is_array($asset) || ($asset['name']??'')!=='post-comment-portable.zip') continue;
            $uri=(string)($asset['browser_download_url']??'');
            $digest=(string)($asset['digest']??'');
            if (!str_starts_with($uri,'https://github.com/Plana-ria/post-comment/releases/download/'.$tag.'/')
                || !preg_match('/^sha256:[a-f0-9]{64}$/',$digest)) continue;
            return [
                'version'=>substr($tag,1),
                'tag'=>$tag,
                'url'=>$uri,
                'digest'=>$digest,
                'published_at'=>(string)($payload['published_at']??''),
            ];
        }
        return null;
    }

    public static function latest(): ?array
    {
        $url='https://api.github.com/repos/Plana-ria/post-comment/releases/latest';
        $context=stream_context_create([
            'http'=>[
                'method'=>'GET',
                'timeout'=>6,
                'ignore_errors'=>true,
                'header'=>"User-Agent: post-comment-updater\r\nAccept: application/vnd.github+json\r\n",
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        if (!filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('サーバーからGitHubへアクセスできません（allow_url_fopen）。');
        }
        $raw=@file_get_contents($url,false,$context,0,1024*1024+1);
        if ($raw===false || strlen($raw)>1024*1024) {
            throw new RuntimeException('GitHub Releasesの確認に失敗しました。');
        }
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('更新情報の形式が不正です。');
        return self::fromApi($data);
    }
}
