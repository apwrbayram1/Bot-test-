<?php
/** Original, versioned, descriptive preference scoring; no external account dependency. */
final class TendencyTest
{
    public static function bank(string $mode): array {
        if(!in_array($mode,['quick','full'],true))throw new InvalidArgumentException('Invalid test mode');
        $bank=json_decode(file_get_contents(dirname(__DIR__).'/data/tendency-test.json'),true,512,JSON_THROW_ON_ERROR);
        $roleIds=array_column($bank['roles'],'id');$questionIds=array_column($bank['questions'],'id');
        if(count($roleIds)!==count(array_unique($roleIds))||count($questionIds)!==count(array_unique($questionIds))||count($bank['questions'])>200)throw new RuntimeException('Invalid test bank');
        foreach($bank['roles'] as $role)if(!preg_match('/^[a-z_]{1,24}$/',$role['id'])||!is_bool($role['quick'])||!$role['label_en']||!$role['label_fa']||!$role['description_en']||!$role['description_fa'])throw new RuntimeException('Invalid test bank');
        foreach($bank['questions'] as $item)if(!in_array($item['role'],$roleIds,true)||!is_bool($item['reverse'])||!is_int($item['round'])||$item['round']<1)throw new RuntimeException('Invalid test bank');
        $roles=array_values(array_filter($bank['roles'],fn($r)=>$mode==='full'||$r['quick']));$ids=array_column($roles,'id');
        $questions=array_values(array_filter($bank['questions'],fn($q)=>in_array($q['role'],$ids,true)));
        $order=array_flip($ids);usort($questions,fn($a,$b)=>($a['round']<=>$b['round'])?:($order[$a['role']]<=>$order[$b['role']]));
        foreach($questions as $q)if(!$q['text_en']||!$q['text_fa']||!is_numeric($q['weight'])||$q['weight']<=0)throw new RuntimeException('Invalid test bank');
        $version=$bank['version'].'-'.substr(hash('sha256',json_encode([$roles,$questions],JSON_UNESCAPED_UNICODE)),0,12);
        return ['version'=>$version,'mode'=>$mode,'roles'=>$roles,'questions'=>$questions];
    }
    public static function score(array $bank,array $answers): array {
        $totals=[];$answered=0;$values=[];
        foreach($bank['roles'] as $r)$totals[$r['id']]=['sum'=>0,'weight'=>0,'answered'=>0,'total'=>0];
        foreach($bank['questions'] as $i=>$q) {
            $role=&$totals[$q['role']];$role['total']++;
            if(!array_key_exists($i,$answers)||$answers[$i]===null)continue;
            $value=$answers[$i];if(!is_int($value)||$value< -3||$value>3)throw new InvalidArgumentException('Invalid test answer');
            $normalized=($value+3)/6;if($q['reverse'])$normalized=1-$normalized;
            $role['sum']+=$normalized*$q['weight'];$role['weight']+=$q['weight'];$role['answered']++;$answered++;$values[]=$value;
        }unset($role);
        $scores=[];
        foreach($totals as $id=>$r)$scores[$id]=['score'=>$r['answered']>=2?(int)round(100*$r['sum']/$r['weight']):null,'answered'=>$r['answered'],'total'=>$r['total']];
        return ['scores'=>$scores,'answered'=>$answered,'total'=>count($bank['questions']),'straight_line'=>count($values)>=6&&count(array_unique($values))===1];
    }
    public static function encrypt(array $payload): string {
        $key=file_get_contents(dirname(__DIR__).'/runtime/tendency.key');
        if($key===false||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Private test storage is unavailable');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$nonce,$key));
    }
    public static function decrypt(string $cipher): array {
        $bytes=base64_decode($cipher,true);$key=file_get_contents(dirname(__DIR__).'/runtime/tendency.key');
        if($bytes===false||strlen($bytes)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES||$key===false||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Private test storage is unavailable');
        $plain=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);
        if($plain===false)throw new RuntimeException('Private test storage is unavailable');
        return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
    }
}
