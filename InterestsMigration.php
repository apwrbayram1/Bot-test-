<?php
/** One-time conversion; retired catalogs remain only as a migration backup. */
final class InterestsMigration {
    public static function run(mysqli $db): void {
        if(!$db->query("SHOW COLUMNS FROM platform_mm_profiles LIKE 'interests'")->num_rows)$db->query('ALTER TABLE platform_mm_profiles ADD COLUMN interests TEXT NULL');
        if($db->query("SELECT name FROM platform_settings WHERE name='free_text_interests_v1'")->num_rows)return;
        $accounts=$db->query('SELECT id,language,role,title,interests,preferences FROM platform_accounts')->fetch_all(MYSQLI_ASSOC);
        $profiles=$db->query('SELECT * FROM platform_mm_profiles')->fetch_all(MYSQLI_ASSOC);
        $choices=$db->query("SELECT s.user_id,s.scope,c.label_en,c.label_fa FROM platform_choices s JOIN platform_catalog c ON c.id=s.option_id WHERE c.enabled=1 ORDER BY c.position,c.id")->fetch_all(MYSQLI_ASSOC);
        $path=dirname(__DIR__).'/runtime/backups/pre-free-text-interests.json';
        if(!is_file($path)){
            if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
            $old=umask(0077);try{if(file_put_contents($path,json_encode(['accounts'=>$accounts,'mm_profiles'=>$profiles,'choices'=>$choices],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not back up interests');}finally{umask($old);}
        }
        $byUser=[];foreach($choices as $c)$byUser[$c['user_id']][$c['scope']][]=$c;
        $mm=array_column($profiles,null,'user_id');
        $join=static function(array $parts): string {return mb_substr(implode('، ',array_values(array_unique(array_filter(array_map('trim',$parts),fn($v)=>$v!=='')))),0,300);};
        $db->begin_transaction();
        try {
            $personal=$db->prepare("UPDATE platform_accounts SET interests=?,preferences='',enabled_preferences='' WHERE id=?");
            $special=$db->prepare('INSERT INTO platform_mm_profiles(user_id,title,bio,interests) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE interests=VALUES(interests)');
            foreach($accounts as $a){
                $id=$a['id'];$field=$a['language']==='fa'?'label_fa':'label_en';
                $parts=[$a['interests']??'',$a['preferences']??''];
                foreach(['interests','preferences'] as $scope)foreach($byUser[$id][$scope]??[] as $c)$parts[]=$c[$field];
                $text=$join($parts);$personal->bind_param('ss',$text,$id);$personal->execute();
                if(isset($mm[$id])||in_array($a['role'],['miss','mister'],true)){
                    $parts=[$mm[$id]['interests']??''];foreach(['mm_interests','offered'] as $scope)foreach($byUser[$id][$scope]??[] as $c)$parts[]=$c[$field];
                    $text=$join($parts);$title=$mm[$id]['title']??$a['title'];$bio=$mm[$id]['bio']??'';
                    $special->bind_param('ssss',$id,$title,$bio,$text);$special->execute();
                }
            }
            $db->query("UPDATE platform_ui SET used=1 WHERE kind IN ('choices','catalog')");
            $db->query("UPDATE platform_accounts SET state='' WHERE state LIKE 'ui:catalog:%' OR state LIKE 'ui:label:%' OR state IN ('edit:interests','edit:preferences','setting:preferences','setting:preferences_fa')");
            // Booking reviews contain the old profile shape. They can be requested again without a debit.
            $db->query("UPDATE platform_requests SET status='expired' WHERE kind='booking' AND status='pending'");
            $db->query("INSERT INTO platform_settings(name,value) VALUES('free_text_interests_v1','1')");
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
    }
}
