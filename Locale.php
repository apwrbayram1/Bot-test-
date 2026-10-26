<?php
/** Explicit message catalogs; profile text and configured content are never auto-translated. */
final class BotLocale
{
    private static array $catalogs=[];
    public static function catalog(string $language): array {
        $language=$language==='fa'?'fa':'en';
        return self::$catalogs[$language]??=json_decode(file_get_contents(dirname(__DIR__).'/locales/'.$language.'.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public static function text(string $language,string $key,array $values=[]): string {
        $catalog=self::catalog($language);
        if (!isset($catalog[$key])) throw new LogicException('Missing translation: '.$key);
        $replacements=[];foreach($values as $name=>$value)$replacements['{'.$name.'}']=(string)$value;
        return strtr($catalog[$key],$replacements);
    }
    public static function error(string $language,string $message): string {
        $catalog=self::catalog($language);
        if(isset($catalog['error.'.$message]))return $catalog['error.'.$message];
        if(preg_match('/^Enter 1–(\d+) characters$/u',$message,$m))return self::text($language,'error.length',['limit'=>$m[1]]);
        if(preg_match('/^You can end this session after minute (\d+)\./',$message,$m))return self::text($language,'error.leave',['minutes'=>$m[1]]);
        if(preg_match('/^Invalid value for (\w+)$/',$message,$m))return self::text($language,'error.setting',['setting'=>$catalog['setting.'.$m[1]]??$m[1]]);
        return self::text($language,'error.generic');
    }
}
