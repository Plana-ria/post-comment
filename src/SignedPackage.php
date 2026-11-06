<?php
declare(strict_types=1);

namespace PostComment;

use RuntimeException;
use ZipArchive;

/**
 * Verify release ZIP before extracting or running any of its PHP.
 *
 * Package signature: base64 Ed25519 detached signature over the raw 32-byte
 * SHA-256 digest of the entire ZIP. Never trust a digest contained in ZIP.
 */
final class SignedPackage
{
    private const MAX_ZIP = 50 * 1024 * 1024;
    private const MAX_UNPACKED = 180 * 1024 * 1024;
    private const MAX_FILES = 1500;

    public static function inspect(string $archive, string $signatureFile, string $publicKeyHex, string $currentVersion): array
    {
        if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) {
            throw new RuntimeException('更新にはPHPのsodiumとzip拡張機能が必要です。');
        }
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $publicKeyHex)) {
            throw new RuntimeException('管理者が配布署名の公開鍵を設定していません。');
        }
        if (!is_file($archive) || is_link($archive) || filesize($archive) < 1 || filesize($archive) > self::MAX_ZIP) {
            throw new RuntimeException('配布ZIPが不正または大きすぎます。');
        }
        if (!is_file($signatureFile) || is_link($signatureFile) || filesize($signatureFile) > 4096) {
            throw new RuntimeException('署名ファイルを確認してください。');
        }
        $signature = base64_decode(trim((string)file_get_contents($signatureFile)), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('署名ファイルの形式が不正です。');
        }
        $key = hex2bin($publicKeyHex);
        $digest = hash_file('sha256',$archive,true);
        if ($digest === false || $key === false || !sodium_crypto_sign_verify_detached($signature,$digest,$key)) {
            throw new RuntimeException('配布ZIPのデジタル署名を検証できません。');
        }
        $zip=new ZipArchive();
        if ($zip->open($archive,ZipArchive::RDONLY)!==true) {
            throw new RuntimeException('配布ZIPを開けません。');
        }
        try {
            if ($zip->numFiles < 5 || $zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('ZIP内のファイル数が不正です。');
            }
            $entries=[];
            $size=0;
            $required=[
                'post-comment-private/VERSION'=>false,
                'post-comment-private/src/Database.php'=>false,
                'post-comment-private/src/Migrator.php'=>false,
                'public/index.php'=>false,
                'public/bootstrap.php'=>false,
            ];
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);
                if (!is_array($stat)) throw new RuntimeException('ZIP一覧を取得できません。');
                $name=(string)$stat['name'];
                if (str_ends_with($name,'/')) continue;
                if (!self::allowedPath($name) || isset($entries[$name])) {
                    throw new RuntimeException('ZIPに不正なパスが含まれます。');
                }
                if ($zip->getExternalAttributesIndex($i,$opsys,$attributes)) {
                    $unixMode=($attributes>>16)&0170000;
                    if ($unixMode===0120000) {
                        throw new RuntimeException('シンボリックリンクは更新パッケージに含められません。');
                    }
                }
                $filesize=(int)($stat['size']??-1);
                if ($filesize < 0 || $filesize > 16*1024*1024) {
                    throw new RuntimeException('ZIPのファイルサイズ制限を超えています。');
                }
                $size+=$filesize;
                if ($size>self::MAX_UNPACKED) throw new RuntimeException('ZIP展開サイズが上限を超えています。');
                $entries[$name]=$i;
                if (array_key_exists($name,$required)) $required[$name]=true;
            }
            if (in_array(false,$required,true)) throw new RuntimeException('ZIPに必須ファイルがありません。');
            $version=$zip->getFromIndex($entries['post-comment-private/VERSION']);
            if (!is_string($version) || !preg_match('/^([0-9]+)\.([0-9]+)\.([0-9]+)\s*$/D',$version)) {
                throw new RuntimeException('リリースのバージョン形式が不正です。');
            }
            $version=trim($version);
            if (version_compare($version,$currentVersion,'<=')) {
                throw new RuntimeException('新しいバージョンのZIPのみ適用できます。');
            }
            return ['version'=>$version,'entries'=>$entries,'bytes'=>$size,'sha256'=>bin2hex($digest)];
        } finally {
            $zip->close();
        }
    }

    private static function allowedPath(string $name): bool
    {
        if (str_contains($name,'..') || str_contains($name,'//') || str_contains($name,'\\') || str_starts_with($name,'/')) return false;
        if (!preg_match('/^[A-Za-z0-9_\.\/-]+$/D',$name)) return false;
        if (str_starts_with($name,'post-comment-private/')) {
            $local=substr($name,strlen('post-comment-private/'));
            if (in_array($local,['README.md','VERSION','config.example.php'],true)) return true;
            return preg_match('#^(src|database|docs)/[A-Za-z0-9_./-]+\.(php|sql|md|json|txt)$#D',$local)===1;
        }
        if (str_starts_with($name,'public/')) {
            $local=substr($name,strlen('public/'));
            if (in_array($local,['location.php','update.php'],true)) return false;
            return $local==='.htaccess' || preg_match('#^[A-Za-z0-9_./-]+\.(php|js|css|svg|txt|html)$#D',$local)===1;
        }
        return false;
    }
}
