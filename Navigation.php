<?php
/** Reply keyboards carry labels only; actions remain bound to the recipient on the server. */
trait PlatformNavigation {
    private function replyNavigation(): bool {return (bool)$this->settings['reply_navigation'];}
    private function navigationRows(int $id): array {
        $saved=$this->one('SELECT payload FROM platform_navigation WHERE user_id=? AND expires>?',[$id,time()]);
        $payload=$saved?json_decode($saved['payload'],true):[];return $payload['base_rows']??$payload['rows']??[];
    }
    private function menuMarkup(int $id,array $rows): string {
        if(!$this->replyNavigation())return $this->buttons($rows);
        $map=[];$keyboard=[];$normalized=[];
        foreach($rows as $row){$keys=[];$normal=[];foreach($row as $b){
            $label=trim($b[0]);if($label==='')continue;$base=mb_substr($label,0,110);$label=$base;$n=1;
            while(isset($map[$label]))$label=$base.' · '.(++$n);
            $map[$label]=$b[1];$keys[]=['text'=>$label];$normal[]=[$label,$b[1]];
        }if($keys){$keyboard[]=$keys;$normalized[]=$normal;}}
        $payload=json_encode(['map'=>$map,'rows'=>$normalized],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->query('INSERT INTO platform_navigation(user_id,payload,expires) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires=VALUES(expires)',[$id,$payload,time()+86400]);
        return json_encode($keyboard?['keyboard'=>$keyboard,'resize_keyboard'=>true,'is_persistent'=>true,'one_time_keyboard'=>false]:['remove_keyboard'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private function menuSend(int $id,string $text,array $rows=[],bool $protected=false): bool {
        $data=['chat_id'=>$id,'text'=>str_replace('\\n',"\n",$text),'parse_mode'=>'HTML','link_preview_options'=>json_encode(['is_disabled'=>true])];
        if($rows)$data['reply_markup']=$this->menuMarkup($id,$rows);
        if($protected)$data['protect_content']='true';
        $this->callbackContext=null;$r=jijibot('sendMessage',$data);return (bool)($r->ok??false);
    }
    private function navigationInput(int $id,object $msg,string $text): bool {
        if(!$this->replyNavigation()||isset($msg->forward_origin)||isset($msg->forward_date))return false;
        $saved=$this->one('SELECT payload FROM platform_navigation WHERE user_id=? AND expires>?',[$id,time()]);
        $map=$saved?(json_decode($saved['payload'],true)['map']??[]):[];
        if(!array_key_exists($text,$map))return false;$action=$map[$text];
        if(is_array($action)){
            $channel=$this->channelConfig();$joinLink=isset($action['url'])&&$channel&&$action['url']===$channel['join_url'];
            if(!$joinLink&&!$this->membershipGate($id,true))return true;
            if(isset($action['url']))$this->menuSend($id,$this->e($action['url']));
            elseif(isset($action['copy_text']['text']))$this->menuSend($id,$this->e($action['copy_text']['text']));
            return true;
        }
        $this->action($id,$action);return true;
    }
}
