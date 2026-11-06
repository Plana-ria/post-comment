<?php
declare(strict_types=1);

namespace PostComment;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Imports CSV files exported by the current pre-redesign room download.php.
 * Imported posts are historical, deliberately not linked to modern user IDs.
 */
final class LegacyCsvImporter
{
    public function __construct(private PDO $db) {}

    public function import(string $roomId, string $path, int $maxRows=100000): array
    {
        if (!is_file($path) || is_link($path) || filesize($path)>20*1024*1024) {
            throw new RuntimeException('CSVファイルを確認してください（20MB以下）。');
        }
        $sourceHash = hash_file('sha256',$path);
        $stream=fopen($path,'rb');
        if ($stream===false) throw new RuntimeException('CSVファイルを開けません。');
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($headers)) throw new RuntimeException('CSVの見出しがありません。');
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
            $headers=array_map(static fn($v)=>strtolower(trim((string)$v)),$headers);
            $keys=array_flip($headers);
            if (!isset($keys['time'])) throw new RuntimeException('time列が見つかりません。');
            $messageCol=null;
            foreach (['messenge','msg','message','comment'] as $candidate) {
                if (isset($keys[$candidate])) { $messageCol=$keys[$candidate]; break; }
            }
            if ($messageCol===null) throw new RuntimeException('コメント本文の列が見つかりません。');
            $guest = isset($keys['name']) && !isset($keys['last_name']);
            if (!$guest && (!isset($keys['last_name']) || !isset($keys['first_name']))) {
                throw new RuntimeException('対応していないCSV形式です。');
            }
            $stmt=$this->db->prepare('SELECT active_form_version_id FROM rooms WHERE id=?');
            $stmt->execute([$roomId]);
            $formVersion=$stmt->fetchColumn();
            if (!$formVersion) throw new RuntimeException('移行先のルームが見つかりません。');
            $insert=$this->db->prepare('INSERT OR IGNORE INTO comments(room_id,user_id,anonymous_session_id,form_version_id,comment_text,submitted_values_json,created_at,legacy_source_id) VALUES (?,NULL,NULL,?,?,?,?,?)');
            $nowJapan=new DateTimeZone('Asia/Tokyo');
            $this->db->beginTransaction();
            $count=0; $ignored=0; $rowNumber=1;
            try {
                while (($row=fgetcsv($stream,0,',','"',''))!==false) {
                    $rowNumber++;
                    if ($row===[null]) continue;
                    if ($rowNumber>$maxRows+1) throw new RuntimeException('CSVの行数が上限を超えています。');
                    if (count($row)!==count($headers)) throw new RuntimeException($rowNumber.'行目の列数が一致しません。');
                    $time=(string)$row[$keys['time']];
                    $date=DateTimeImmutable::createFromFormat('!Y/m/d H:i:s',$time,$nowJapan);
                    if (!$date) $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$time,$nowJapan);
                    if (!$date) throw new RuntimeException($rowNumber.'行目の時刻が不正です。');
                    $comment=(string)$row[$messageCol];
                    if (strlen($comment)>1048576) throw new RuntimeException($rowNumber.'行目のコメントが長すぎます。');
                    $values=[];
                    if ($guest) {
                        $values['name']=(string)$row[$keys['name']];
                    } else {
                        foreach (['last_name','first_name','affiliation','mail'] as $key) {
                            if (isset($keys[$key])) $values[$key==='mail'?'email':$key]=(string)$row[$keys[$key]];
                        }
                    }
                    $values['comment']=$comment;
                    $sourceId='legacy-csv:'.$sourceHash.':'.$rowNumber;
                    $insert->execute([$roomId,$formVersion,$comment,
                        json_encode($values,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                        $sourceId]);
                    if ($insert->rowCount()===1) $count++; else $ignored++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            return ['imported'=>$count,'duplicate'=>$ignored,'guest'=>$guest];
        } finally {
            fclose($stream);
        }
    }
}
