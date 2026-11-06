<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

/**
 * Stream exports without holding all comments in PHP memory.
 * Never accept arbitrary SQL or field names from request parameters.
 */
final class CsvExport
{
    public static function admin(PDO $db, string $roomId, $stream): int
    {
        // First pass collects historical keys while retaining constant-size result sets.
        $stmt = $db->prepare('SELECT submitted_values_json FROM comments WHERE room_id=? ORDER BY id');
        $stmt->execute([$roomId]);
        $fields = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = json_decode($row['submitted_values_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($values)) throw new RuntimeException('Invalid historical answers');
            foreach ($values as $key => $value) {
                if ($key === 'comment') continue; // Already exported in the fixed comment column.
                if (!is_string($key)) continue;
                $fields[$key] = true;
            }
        }
        $allKeys=array_keys($fields);
        $standard=count(array_intersect($allKeys,['last_name','first_name','affiliation','email','mail']))>0;
        $guest=!$standard && in_array('name',$allKeys,true);
        $reserved=$standard ? ['last_name','first_name','affiliation','email','mail'] : ($guest?['name']:[]);
        $extras=array_values(array_diff($allKeys,$reserved));
        $base=$standard ? ['last_name','first_name','affiliation','mail'] : ($guest?['name']:[]);
        $headers=array_merge(['time'],$base,['messenge'],$extras);
        self::start($stream,$headers);
        $stmt = $db->prepare('SELECT created_at,comment_text,submitted_values_json FROM comments WHERE room_id=? ORDER BY id');
        $stmt->execute([$roomId]);
        $count=0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values=json_decode($row['submitted_values_json'],true,512,JSON_THROW_ON_ERROR);
            $record=[self::legacyTime($row['created_at'])];
            foreach ($base as $key) {
                if ($key==='mail') {
                    $record[]=self::scalar($values['email']??$values['mail']??'');
                } else {
                    $record[]=self::scalar($values[$key]??'');
                }
            }
            $record[]=$row['comment_text'];
            foreach ($extras as $key) $record[]=self::scalar($values[$key]??'');
            self::record($stream,$record);
            ++$count;
        }
        return $count;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private static function legacyTime(string $utc): string
    {
        $date=new \DateTimeImmutable($utc,new \DateTimeZone('UTC'));
        return $date->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s');
    }

    public static function own(PDO $db, string $roomId, ?string $userId, ?string $anonymousId, $stream): int
    {
        if (($userId === null) === ($anonymousId === null)) {
            throw new RuntimeException('Exactly one authenticated identity is required');
        }
        self::start($stream,['time','comment']);
        $column = $userId !== null ? 'user_id' : 'anonymous_session_id';
        $id = $userId ?? $anonymousId;
        $stmt = $db->prepare('SELECT created_at,comment_text FROM comments WHERE room_id=? AND '.$column.'=? ORDER BY id');
        $stmt->execute([$roomId,$id]);
        $count = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            self::record($stream,[$row['created_at'],$row['comment_text']]);
            ++$count;
        }
        return $count;
    }

    private static function start($stream, array $header): void
    {
        if (!is_resource($stream)) throw new RuntimeException('CSV output stream unavailable');
        fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,$header);
    }

    private static function record($stream, array $values): void
    {
        $escaped = array_map(static function ($value): string {
            $cell = (string)$value;
            // Prevent formulas from executing in Excel/LibreOffice CSV viewers.
            return preg_match('/^[\x00-\x20]*[=+\-@\t\r]/u',$cell) ? "'".$cell : $cell;
        },$values);
        fputcsv($stream,$escaped);
    }
}
