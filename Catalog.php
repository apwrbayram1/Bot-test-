<?php
/** Stable bilingual option identities, seeded once without overwriting owner edits. */
final class ProfileCatalog
{
    public static function seed(mysqli $db): void {
        if($db->query("SELECT value FROM platform_settings WHERE name='catalog_seeded'")->num_rows)return;
        $saved=[];foreach($db->query('SELECT * FROM platform_settings')->fetch_all(MYSQLI_ASSOC) as $row)$saved[$row['name']]=$row['value'];
        $defaults=SessionRules::defaults();
        $en=array_map('trim',explode(',',$saved['preferences']??$defaults['preferences']));
        $fa=array_map('trim',explode(',',$saved['preferences_fa']??$defaults['preferences_fa']));
        $items=['interests'=>[['Music','موسیقی'],['Books','کتاب'],['Movies','فیلم'],['Gaming','بازی'],['Travel','سفر'],['Art','هنر'],['Science','علم'],['Sports','ورزش']], 'preferences'=>[]];
        foreach($en as $i=>$label)if($label!=='')$items['preferences'][]=[$label,$fa[$i]??$label];
        $db->begin_transaction();
        try {
            $insert=$db->prepare('INSERT IGNORE INTO platform_catalog(id,kind,label_en,label_fa,position) VALUES(?,?,?,?,?)');
            foreach($items as $kind=>$rows)foreach($rows as $i=>[$english,$persian]) {
                $key=substr(hash('sha256',$kind.':'.$english),0,12);$position=$i*10;
                $insert->bind_param('ssssi',$key,$kind,$english,$persian,$position);$insert->execute();
            }
            $choices=$db->prepare('INSERT IGNORE INTO platform_choices(user_id,scope,option_id) VALUES(?,?,?)');
            $catalog=$db->query('SELECT * FROM platform_catalog')->fetch_all(MYSQLI_ASSOC);
            foreach($db->query('SELECT id,interests,preferences,enabled_preferences FROM platform_accounts')->fetch_all(MYSQLI_ASSOC) as $account) {
                foreach(['interests','preferences','offered'] as $scope) {
                    $labels=$scope==='offered'?(json_decode($account['enabled_preferences']?:'[]',true)?:[]):preg_split('/[,،\n]+/u',$account[$scope]??'');
                    $labels=array_map('trim',$labels);$kind=$scope==='offered'?'preferences':$scope;
                    foreach($catalog as $option)if($option['kind']===$kind && (in_array($option['label_en'],$labels,true)||in_array($option['label_fa'],$labels,true))) {
                        $uid=$account['id'];$key=$option['id'];$choices->bind_param('sss',$uid,$scope,$key);$choices->execute();
                    }
                }
            }
            $db->query("INSERT INTO platform_settings(name,value) VALUES('catalog_seeded','1')");$db->commit();
        } catch(Throwable $e) {$db->rollback();throw $e;}
    }
}
