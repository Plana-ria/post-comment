<?php
declare(strict_types=1);

namespace PostComment;

use RuntimeException;

/** Fetches only the exact fixed-repository release assets selected by ReleaseChecker. */
final class ReleaseDownloader
{
    public static function download(array $release, string $directory): array
    {
        $version=(string)($release['version']??'');
        $tag='v'.$version;
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version) ||
            ($release['url']??'')!=='https://github.com/Plana-ria/post-comment/releases/download/'.$tag.'/post-comment-portable.zip' ||
            ($release['signature_url']??'')!=='https://github.com/Plana-ria/post-comment/releases/download/'.$tag.'/post-comment-portable.zip.sig' ||
            !preg_match('/^sha256:[a-f0-9]{64}$/D',(string)($release['digest']??''))) {
            throw new RuntimeException('信頼できるリリース情報がありません。');
        }
        if (!is_dir($directory) || is_link($directory) || !is_writable($directory)) {
            throw new RuntimeException('ダウンロード用の非公開領域がありません。');
        }
        if (ini_get('allow_url_fopen')==='0') {
            throw new RuntimeException('サーバーがリリースのダウンロードに対応していません。');
        }
        $token=bin2hex(random_bytes(12));
        $zip=$directory.'/download-'.$token.'.zip';
        $sig=$directory.'/download-'.$token.'.sig';
        try {
            self::fetch((string)$release['url'],$zip,50*1024*1024);
            if (!hash_equals(substr($release['digest'],7),hash_file('sha256',$zip))) {
                throw new RuntimeException('GitHubに登録されたZIPのSHA-256と一致しません。');
            }
            self::fetch((string)$release['signature_url'],$sig,4096);
            return [$zip,$sig];
        } catch (\Throwable $e) {
            @unlink($zip);
            @unlink($sig);
            throw $e;
        }
    }

    private static function fetch(string $url, string $path, int $limit): void
    {
        $ctx=stream_context_create([
            'http'=>[
                'timeout'=>20,'follow_location'=>1,'max_redirects'=>5,
                'header'=>"User-Agent: post-comment-updater\r\nAccept: application/octet-stream\r\n",
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $source=@fopen($url,'rb',false,$ctx);
        if (!$source) throw new RuntimeException('GitHubからリリースを取得できません。');
        $target=@fopen($path,'xb');
        if (!$target) {
            fclose($source);
            throw new RuntimeException('リリースを保存できません。');
        }
        $size=0;
        try {
            while (!feof($source)) {
                $chunk=fread($source,65536);
                if ($chunk===false) throw new RuntimeException('リリースの受信が失敗しました。');
                if ($chunk==='') break;
                $size+=strlen($chunk);
                if ($size>$limit || fwrite($target,$chunk)!==strlen($chunk)) {
                    throw new RuntimeException('リリースの大きさまたは保存先を確認してください。');
                }
            }
            if ($size===0) throw new RuntimeException('空のリリースファイルです。');
        } finally {
            fclose($source);
            fclose($target);
            @chmod($path,0600);
        }
    }
}
