<?php
declare(strict_types=1);

namespace PostComment;

use RuntimeException;

/**
 * Sanitized room templates transcribed from the supplied 2026-11 old ZIP.
 * Contains no old .env credential values, database credentials or postings.
 */
final class LegacyRoomCatalog
{
    public static function entries(): array
    {
        return [
            ['id'=>'1', 'slug'=>'legacy-room-01', 'name'=>"2025912290", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'2', 'slug'=>'legacy-room-02', 'name'=>"VG講座2026", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'3', 'slug'=>'legacy-room-03', 'name'=>"[2025912288] アート思考とナラティブデザインをベースにしたコミュニケーション論 1", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'4', 'slug'=>'legacy-guest', 'name'=>"guest", 'variant'=>'guest', 'auth_mode'=>'none'],
            ['id'=>'5', 'slug'=>'legacy-room-05', 'name'=>"アートとコミュニケーションA", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'6', 'slug'=>'legacy-room-06', 'name'=>"アートとコミュニケーション「君たちはどう生きるか」宮崎駿と高畑勲の対話を考える", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'7', 'slug'=>'legacy-room-07', 'name'=>"アートとコミュニケーション２", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'8', 'slug'=>'legacy-room-08', 'name'=>"アート思考とナラティブデザインをベースにしたコミュニケーション論 1", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'9', 'slug'=>'legacy-room-09', 'name'=>"アート思考とナラティブデザインをベースにしたコミュニケーション論 1(2026)", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'10', 'slug'=>'legacy-room-10', 'name'=>"ビデオグラファー養成講座1", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'11', 'slug'=>'legacy-room-11', 'name'=>"ビデオグラファー養成講座1Q", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'12', 'slug'=>'legacy-room-12', 'name'=>"ビデオグラファー養成講座1Q(2026)", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'13', 'slug'=>'legacy-room-13', 'name'=>"ベネッセアートサイト直島に作品が設置されるまで", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'14', 'slug'=>'legacy-room-14', 'name'=>"地域文化資源運用実践演習2025", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'15', 'slug'=>'legacy-room-15', 'name'=>"教育実地展開概論（教育と地域創生）2026", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'16', 'slug'=>'legacy-room-16', 'name'=>"産官学連携による文化事業運営演習", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'17', 'slug'=>'legacy-room-17', 'name'=>"産官学連携による文化事業運営演習：企画・広報・評価実践 / CD養成講座26年度特別講義", 'variant'=>'standard', 'auth_mode'=>'required'],
            ['id'=>'18', 'slug'=>'legacy-room-18', 'name'=>"研究基盤EXPO2025_第4回研究基盤協議会シンポジウム_技術と人財の交差点〜産学官連携の可能性を探る〜", 'variant'=>'expo', 'auth_mode'=>'none'],
        ];
    }

    public static function import(ChatService $service, RoomForms $forms, string $adminId, array $selected): array
    {
        $allow = array_fill_keys(array_map('strval',$selected),true);
        $created=0;
        $skipped=0;
        foreach (self::entries() as $entry) {
            if (!isset($allow[$entry['id']])) continue;
            if ($service->roomBySlug($entry['slug'])) {
                $skipped++;
                continue;
            }
            $template = $entry['variant']==='guest' ? 'guest' : 'standard';
            $roomId = $service->createRoom($entry['name'],$entry['slug'],$entry['auth_mode'],$adminId,$template);
            if ($entry['variant']==='expo') {
                $room=$service->roomById($roomId);
                $form=$service->form($room);
                foreach ($form['fields'] as &$field) {
                    if ($field['key']==='affiliation') $field['label']='所属';
                }
                unset($field);
                $forms->publish($roomId,$form,$adminId,$room['active_form_version_id']);
            }
            $created++;
        }
        return ['created'=>$created,'skipped'=>$skipped];
    }
}
