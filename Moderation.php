<?php
/** Inspect visible text and Telegram entities without storing message contents. */
final class MessageModeration
{
    public static function normalize(string $text): string {
        if(class_exists('Normalizer'))$text=Normalizer::normalize($text,Normalizer::FORM_KC)?:$text;
        $text=preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u',fn($m)=>chr(mb_ord($m[0])-0xFEE0),$text)??$text;
        $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=strtr($text,array_combine(
            preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩０１２３４５６７８９',-1,PREG_SPLIT_NO_EMPTY),
            str_split('012345678901234567890123456789')
        ));
        $text=strtr($text,['＠'=>'@','．'=>'.','。'=>'.','｡'=>'.','：'=>':','／'=>'/','＋'=>'+','‐'=>'-','‑'=>'-']);
        $text=preg_replace('/\p{Cf}/u','',$text)??$text;
        return preg_replace('/\s+/u',' ',$text)??$text;
    }
    public static function text(string $text,array $settings,array $entities=[]): ?string {
        foreach($entities as $entity) {
            $type=is_object($entity)?($entity->type??''):($entity['type']??'');
            if($settings['moderation_block_links'] && in_array($type,['url','text_link','email'],true))return 'links';
            if($settings['moderation_block_phones'] && $type==='phone_number')return 'phones';
            if($settings['moderation_block_ids'] && in_array($type,['mention','text_mention'],true))return 'ids';
        }
        $text=self::normalize($text);
        if($settings['moderation_block_links']) {
            if(preg_match('~\b(?:https?|hxxps?|ftp|tg|mailto|tel)\s*:|\bwww\s*\.|[\p{L}\p{N}._%+\-]+\s*@\s*[\p{L}\p{N}.\-]+\s*\.\s*[\p{L}]{2,}~iu',$text))return 'links';
            $domains=preg_replace('/\s*(?:\[dot\]|\(dot\)|\[\.\]|\s+dot\s+|\s+نقطه\s+)\s*/iu','.',$text)??$text;
            // Compact spaced dots only when followed by a common domain suffix.
            $domains=preg_replace('/\s*\.\s*(?=(?:com|net|org|ir|me|io|co|app|dev|xyz|info|biz|ru|uk|us|de|edu|gov|online|site)\b)/iu','.',$domains)??$domains;
            if(preg_match('/(?<![\p{L}\p{N}_])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,62})\.)+[\p{L}]{2,63}(?![\p{L}\p{N}-])/iu',$domains))return 'links';
            if(preg_match('/(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)/u',$domains))return 'links';
        }
        if($settings['moderation_block_ids']) {
            if(preg_match('/@(?:\s*[\p{L}\p{N}_]){3,}/u',$text))return 'ids';
            if(preg_match('/(?:\b(?:user[\s_-]*id|chat[\s_-]*id|id|username)\b|آیدی|آی\s*دی|شناسه)\s*(?:(?:من|منو|my|is)\s*)*[:=：]?\s*@?[\p{L}\p{N}_][\p{L}\p{N}_.-]{3,}/iu',$text))return 'ids';
            if(preg_match('/(?:\b(?:telegram|instagram|insta|discord|snapchat)\b|تلگرام|اینستاگرام)\s*[:=：]\s*@?[\p{L}\p{N}_][\p{L}\p{N}_.-]{3,}/iu',$text))return 'ids';
            if(preg_match('/(?<![\p{L}\p{N}])\d{6,20}(?![\p{L}\p{N}])/u',$text))return 'ids';
        }
        if($settings['moderation_block_phones']) {
            $numbers=$text;
            $words=['zero'=>'0','one'=>'1','two'=>'2','three'=>'3','four'=>'4','five'=>'5','six'=>'6','seven'=>'7','eight'=>'8','nine'=>'9',
                'صفر'=>'0','یک'=>'1','دو'=>'2','سه'=>'3','چهار'=>'4','پنج'=>'5','شش'=>'6','هفت'=>'7','هشت'=>'8','نه'=>'9'];
            $numbers=preg_replace_callback('/(?<![\p{L}\p{N}])(?:'.implode('|',array_keys($words)).')(?![\p{L}\p{N}])/iu',fn($m)=>$words[mb_strtolower($m[0])]??$m[0],$numbers)??$numbers;
            // Ordinary ISO/slash dates should not be mistaken for telephone numbers.
            $numbers=preg_replace_callback('/\b((?:19|20)\d{2})[-\/]([0-1]?\d)[-\/]([0-3]?\d)\b/u',fn($m)=>checkdate((int)$m[2],(int)$m[3],(int)$m[1])?' ':$m[0],$numbers)??$numbers;
            if(preg_match('/(?<![\p{L}\p{N}])\+?\d(?:[\s().\-–—_\/]*\d){6,15}(?![\p{L}\p{N}])/u',$numbers))return 'phones';
        }
        return null;
    }
    public static function message(object $message,array $settings): ?string {
        if($settings['moderation_block_forwards']) {
            foreach(['forward_origin','forward_from','forward_from_chat','forward_sender_name','forward_date'] as $field)if(isset($message->$field))return 'forwards';
            if($message->is_automatic_forward??false)return 'forwards';
        }
        if($settings['moderation_block_contacts'] && isset($message->contact))return 'contacts';
        $reason=self::text($message->text??'',$settings,$message->entities??[]);
        if($reason)return $reason;
        $reason=self::text($message->caption??'',$settings,$message->caption_entities??[]);
        if($reason)return $reason;
        if(isset($message->document->file_name)) {
            $reason=self::text(pathinfo($message->document->file_name,PATHINFO_FILENAME),$settings);
            if($reason)return $reason;
        }
        foreach(['title','performer'] as $field)if(isset($message->audio->$field)) {
            $reason=self::text($message->audio->$field,$settings);
            if($reason)return $reason;
        }
        return null;
    }
}
