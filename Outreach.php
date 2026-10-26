<?php
/** Telegram-native media references, entity-preserving messages, and bounded delivery work. */
trait PlatformOutreach {
    private function outreachAgeSql(string $alias): string {
        $adult="($alias.age>=18 OR ($alias.age>0 AND $alias.age+FLOOR((UNIX_TIMESTAMP()-$alias.age_set_at)/31557600)>=18))";
        return ' AND '.($this->settings['age_required']?$adult:"(COALESCE($alias.age,0)=0 OR $adult)");
    }
    private function campaign(int $id): array {
        $c=$this->one("SELECT * FROM platform_campaigns WHERE id=? AND status<>'deleted'",[$id]);if(!$c)throw new RuntimeException('This action expired. Open the panel again.');return $c;
    }
    private function outreachHome(int $id,string $kind,int $page=0): void {
        if(!$this->admin($id)||!in_array($kind,['ad','broadcast'],true))throw new RuntimeException('Access denied');
        $total=(int)$this->one("SELECT COUNT(*) n FROM platform_campaigns WHERE kind=? AND status<>'deleted'",[$kind])['n'];[$page,$pages]=$this->paging($total,$page,6);
        $entries=$this->all("SELECT * FROM platform_campaigns WHERE kind=? AND status<>'deleted' ORDER BY id DESC LIMIT 6 OFFSET ".($page*6),[$kind]);$rows=[];
        foreach($entries as $c)$rows[]=[['#'.$c['id'].' · '.$this->t($id,'campaign.state.'.$c['status']).($c['kind']==='ad'?($c['enabled']?' ●':' ○'):''),'campaign:'.$c['id']]];
        $rows=array_merge($rows,$this->pageNav($id,'campaigns:'.$kind.':',$page,$pages));$rows[]=[[$this->t($id,'campaign.new'),'campaign:new:'.$kind]];
        if($kind==='ad'){
            $rows[]=[[$this->t($id,$this->settings['ads_enabled']?'campaign.ads.off':'campaign.ads.on'),'ads:toggle']];
            $rows[]=[[$this->t($id,'campaign.frequency'),'ads:setting:ads_every_events'],[$this->t($id,'campaign.cooldown'),'ads:setting:ads_cooldown_seconds']];
        }
        $rows[]=[[$this->t($id,'ui.back'),'panelhome']];
        $this->send($id,$this->t($id,'campaign.list.'.$kind,['events'=>$this->settings['ads_every_events'],'seconds'=>$this->settings['ads_cooldown_seconds']])."\n".$this->pageHeading($id,$page,$pages,$total),$rows);
    }
    private function captureCampaignPayload(object $msg): ?array {
        if(isset($msg->media_group_id))return null;
        $p=['method'=>'sendMessage','params'=>[],'buttons'=>[]];
        foreach(['animation'=>'sendAnimation','photo'=>'sendPhoto','video'=>'sendVideo','audio'=>'sendAudio','voice'=>'sendVoice','document'=>'sendDocument','sticker'=>'sendSticker','video_note'=>'sendVideoNote'] as $field=>$method){
            if(!isset($msg->$field))continue;$media=$msg->$field;if($field==='photo')$media=end($media);
            if(empty($media->file_id))return null;$p['method']=$method;$p['params'][$field]=$media->file_id;
            if(!in_array($field,['sticker','video_note'],true)){
                if(isset($msg->caption))$p['params']['caption']=$msg->caption;
                if(isset($msg->caption_entities))$p['params']['caption_entities']=json_decode(json_encode($msg->caption_entities),true);
            }return $p;
        }
        if(!isset($msg->text)||trim($msg->text)==='')return null;
        $p['params']['text']=$msg->text;if(isset($msg->entities))$p['params']['entities']=json_decode(json_encode($msg->entities),true);return $p;
    }
    private function campaignSend(int $user,array $payload,bool $preserve=true): object {
        $data=$payload['params'];$data['chat_id']=$user;
        foreach(['entities','caption_entities'] as $field)if(isset($data[$field]))$data[$field]=json_encode($data[$field],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($payload['method']==='sendMessage')$data['link_preview_options']=json_encode(['is_disabled'=>true]);
        if(!empty($payload['buttons'])){
            $rows=$payload['buttons'];$baseRows=null;
            if($this->replyNavigation()&&$preserve){
                $adRows=$rows;$rows=[];
                if($this->active($user)){
                    $s=$this->active($user);$rows[]=[[$this->t($user,'v3.chat.end'),'close'],[$this->t($user,'v3.chat.status'),'status']];
                    $rows[]=[[$this->t($user,'v3.chat.react'),'reactionhelp'],[$this->t($user,'v3.chat.report'),'report']];
                    if($s['kind']==='paid'&&$s['customer']==$user)$rows[]=[[$this->t($user,'v3.chat.exchange'),'exchange']];
                }else $rows=$this->navigationRows($user);
                if(!$rows)$rows=[[[$this->t($user,'back.panel'),'home']]];
                $baseRows=$rows;$rows=array_merge($adRows,$baseRows);
            }
            $data['reply_markup']=$this->menuMarkup($user,$rows);
            if($baseRows!==null){$nav=$this->one('SELECT payload FROM platform_navigation WHERE user_id=?',[$user]);$saved=json_decode($nav['payload'],true);$saved['base_rows']=$baseRows;$this->query('UPDATE platform_navigation SET payload=? WHERE user_id=?',[json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$user]);}
        }
        return jijibot($payload['method'],$data)?:((object)['ok'=>false,'error_code'=>0,'delivery_unknown'=>true]);
    }
    private function campaignPage(int $id,int $key): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');$c=$this->campaign($key);
        $counts=$this->all('SELECT status,COUNT(*) n FROM platform_campaign_deliveries WHERE campaign_id=? GROUP BY status',[$key]);$summary=[];foreach($counts as $count)$summary[]=$this->t($id,'delivery.'.$count['status']).': '.$count['n'];
        $rows=[[[$this->t($id,'campaign.preview'),'campaign:preview:'.$key]]];
        if($c['status']!=='queued'&&$c['status']!=='sending'&&$c['status']!=='completed'&&$c['status']!=='cancelled'){
            $rows[]=[[$this->t($id,'campaign.replace'),'campaign:replace:'.$key],[$this->t($id,'campaign.text'),'campaign:text:'.$key]];
            $rows[]=[[$this->t($id,'campaign.buttons'),'campaign:buttons:'.$key]];
            $rows[]=[[$this->t($id,$c['kind']==='ad'?($c['enabled']?'campaign.pause':'campaign.activate'):'campaign.broadcast.review'),'campaign:publish:'.$key]];
        }
        if(in_array($c['status'],['queued','sending'],true))$rows[]=[[$this->t($id,'campaign.cancel'),'campaign:cancel:'.$key]];
        $rows[]=[[$this->t($id,'campaign.delete'),'campaign:delete:'.$key]];$rows[]=[[$this->t($id,'ui.back'),'campaigns:'.$c['kind'].':0']];
        $this->send($id,$this->t($id,'campaign.card',['id'=>$key,'state'=>$this->t($id,'campaign.state.'.$c['status']),'enabled'=>$this->t($id,$c['enabled']?'ui.enabled':'ui.disabled'),'counts'=>$this->e(implode(' · ',$summary))]),$rows);
    }
    private function campaignEditable(array $c): void {if(in_array($c['status'],['queued','sending','completed','cancelled'],true))throw new RuntimeException('This action expired. Open the panel again.');}
    private function outreachSetting(int $id,string $key,int $value): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');
        if(!in_array($key,['ads_enabled','ads_every_events','ads_cooldown_seconds'],true))throw new RuntimeException('Access denied');
        $r=$this->settings;$r[$key]=$value;SessionRules::validate($r);
        if(($key==='ads_every_events'&&$value>1000)||($key==='ads_cooldown_seconds'&&($value<30||$value>86400)))throw new RuntimeException('Value is empty or too long');
        $this->query('INSERT INTO platform_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);$this->settings=$r;$this->audit($id,'ad_setting',$key.'='.$value);
    }
    private function outreachAction(int $id,string $action): bool {
        if(!preg_match('/^(?:campaigns?:|ads:)/',$action))return false;
        if(!$this->admin($id))throw new RuntimeException('Access denied');
        if(preg_match('/^campaigns:(ad|broadcast):(\d+)$/',$action,$m)){$this->outreachHome($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^campaign:new:(ad|broadcast)$/',$action,$m)){$this->prompt($id,'campaign:capture:'.$m[1],$this->t($id,'campaign.capture'));return true;}
        if($action==='ads:toggle'){$this->outreachSetting($id,'ads_enabled',$this->settings['ads_enabled']?0:1);$this->outreachHome($id,'ad');return true;}
        if(preg_match('/^ads:setting:(ads_every_events|ads_cooldown_seconds)$/',$action,$m)){$this->prompt($id,'ads:setting:'.$m[1],$this->t($id,'campaign.setting',['value'=>$this->settings[$m[1]],'min'=>$m[1]==='ads_every_events'?1:30,'max'=>$m[1]==='ads_every_events'?1000:86400]));return true;}
        if(preg_match('/^campaign:(\d+)$/',$action,$m)){$this->campaignPage($id,(int)$m[1]);return true;}
        if(preg_match('/^campaign:(preview|replace|text|buttons|publish|cancel|delete):(\d+)$/',$action,$m)){
            $key=(int)$m[2];$c=$this->campaign($key);$p=json_decode($c['payload'],true,512,JSON_THROW_ON_ERROR);
            if($m[1]==='preview'){$r=$this->campaignSend($id,$p,false);if(!($r->ok??false))$this->send($id,$this->t($id,'campaign.preview.failed'));
                $this->send($id,$this->t($id,'campaign.preview.note'),array_merge($p['buttons'],[[[$this->t($id,'ui.back'),'campaign:'.$key]]]));return true;}
            if(in_array($m[1],['replace','text','buttons'],true)){
                $this->campaignEditable($c);$this->prompt($id,'campaign:'.$m[1].':'.$key,$this->t($id,'campaign.prompt.'.$m[1]));return true;
            }
            if($m[1]==='publish'&&$c['kind']==='ad'){
                $this->campaignEditable($c);$this->query("UPDATE platform_campaigns SET enabled=?,status='ready',revision=revision+1 WHERE id=?",[$c['enabled']?0:1,$key]);
                if(!$c['enabled'])$this->outreachSetting($id,'ads_enabled',1);
                $this->audit($id,'ad_publish',(string)$key);$this->campaignPage($id,$key);return true;
            }
            if($m[1]==='cancel'){
                $this->query("UPDATE platform_campaigns SET status='cancelled',enabled=0 WHERE id=?",[$key]);$this->query("UPDATE platform_campaign_deliveries SET status='skipped' WHERE campaign_id=? AND status='pending'",[$key]);$this->audit($id,'broadcast_cancel',(string)$key);$this->campaignPage($id,$key);return true;
            }
            if($m[1]==='publish')$this->campaignEditable($c);
            $token=$this->token($id,'campaignreview',['id'=>$key,'revision'=>(int)$c['revision'],'action'=>$m[1]],600);
            $count=(int)$this->one("SELECT COUNT(*) n FROM (SELECT id FROM platform_accounts WHERE id>0 AND role<>'banned' UNION SELECT id FROM user WHERE id>0) audience LEFT JOIN platform_outreach_state o ON o.user_id=audience.id LEFT JOIN platform_accounts a ON a.id=audience.id WHERE COALESCE(o.reachable,1)=1 AND COALESCE(a.role,'user')<>'banned'".$this->outreachAgeSql('a'))['n'];
            $this->send($id,$this->t($id,$m[1]==='delete'?'campaign.delete.review':'campaign.send.review',['count'=>$count]),[[[$this->t($id,'ui.confirm'),'campaign:confirm:'.$token],[$this->t($id,'cancel.button'),'campaign:'.$key]]]);return true;
        }
        if(preg_match('/^campaign:confirm:([a-f0-9]{16})$/',$action,$m)){
            $draft=$this->uiToken($id,$m[1],'campaignreview');$d=$draft['data'];$c=$this->campaign((int)$d['id']);
            if((int)$c['revision']!==$d['revision'])throw new RuntimeException('This action expired. Open the panel again.');
            $this->db->begin_transaction();try{
                if(!$this->query('UPDATE platform_ui SET used=1 WHERE token=? AND used=0',[$m[1]])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
                if($d['action']==='delete'){
                    $this->query("UPDATE platform_campaigns SET status='deleted',enabled=0,revision=revision+1 WHERE id=?",[$c['id']]);$this->query("UPDATE platform_campaign_deliveries SET status='skipped' WHERE campaign_id=? AND status='pending'",[$c['id']]);
                }else{
                    $this->campaignEditable($c);
                    $this->query("UPDATE platform_campaigns SET status='queued',enabled=0,revision=revision+1 WHERE id=?",[$c['id']]);
                    $this->query("INSERT IGNORE INTO platform_campaign_deliveries(campaign_id,user_id) SELECT ?,audience.id FROM (SELECT id FROM platform_accounts WHERE id>0 AND role<>'banned' UNION SELECT id FROM user WHERE id>0) audience LEFT JOIN platform_outreach_state o ON o.user_id=audience.id LEFT JOIN platform_accounts a ON a.id=audience.id WHERE COALESCE(o.reachable,1)=1 AND COALESCE(a.role,'user')<>'banned'".$this->outreachAgeSql('a'),[$c['id']]);
                }
                $this->audit($id,'campaign_'.$d['action'],(string)$c['id']);$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}
            if($d['action']==='delete')$this->outreachHome($id,$c['kind']);else $this->campaignPage($id,(int)$c['id']);return true;
        }
        throw new RuntimeException('This action expired. Open the panel again.');
    }
    private function outreachInput(int $id,array $a,object $msg): bool {
        if(!preg_match('/^(?:campaign:|ads:setting:)/',$a['state']))return false;
        if(!$this->admin($id))throw new RuntimeException('Access denied');$state=$a['state'];
        if(preg_match('/^ads:setting:(ads_every_events|ads_cooldown_seconds)$/',$state,$m)){
            $text=strtr(trim($msg->text??''),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),str_split('0123456789')));
            if(!preg_match('/^\d{1,5}$/D',$text))throw new RuntimeException('Value is empty or too long');$this->outreachSetting($id,$m[1],(int)$text);$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->outreachHome($id,'ad');return true;
        }
        if(preg_match('/^campaign:capture:(ad|broadcast)$/',$state,$m)){
            $payload=$this->captureCampaignPayload($msg);if(!$payload){$this->send($id,$this->t($id,'campaign.unsupported'));return true;}
            $this->query('INSERT INTO platform_campaigns(kind,creator,payload,created) VALUES(?,?,?,?)',[$m[1],$id,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()]);$key=(int)$this->db->insert_id;
            $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->audit($id,'campaign_create',(string)$key);$this->campaignPage($id,$key);return true;
        }
        if(preg_match('/^campaign:(replace|text|buttons):(\d+)$/',$state,$m)){
            $c=$this->campaign((int)$m[2]);$this->campaignEditable($c);$payload=json_decode($c['payload'],true,512,JSON_THROW_ON_ERROR);
            if($m[1]==='replace'){$p=$this->captureCampaignPayload($msg);if(!$p){$this->send($id,$this->t($id,'campaign.unsupported'));return true;}$p['buttons']=$payload['buttons'];$payload=$p;}
            elseif($m[1]==='text'){
                $text=$msg->text??$msg->caption??'';$entities=$msg->entities??$msg->caption_entities??[];
                if(in_array($payload['method'],['sendSticker','sendVideoNote'],true)){$this->send($id,$this->t($id,'campaign.no.caption'));return true;}
                $field=$payload['method']==='sendMessage'?'text':'caption';if(trim($text)===''||mb_strlen($text)>($field==='text'?4096:1024))throw new RuntimeException('Value is empty or too long');
                $payload['params'][$field]=$text;$payload['params'][$field==='text'?'entities':'caption_entities']=json_decode(json_encode($entities),true);
            }else{
                $text=trim($msg->text??'');$rows=[];
                if($text!=='-')foreach(explode("\n",$text) as $line){$parts=array_map('trim',explode('|',$line,2));if(count($parts)!==2||$parts[0]===''||mb_strlen($parts[0])>64||!preg_match('~^https://[^\s<>]+$~uD',$parts[1])||!filter_var($parts[1],FILTER_VALIDATE_URL)){$this->send($id,$this->t($id,'campaign.prompt.buttons'));return true;}$rows[]=[[$parts[0],['url'=>$parts[1]]]];}
                if(count($rows)>6)throw new RuntimeException('Value is empty or too long');$payload['buttons']=$rows;
            }
            $this->query('UPDATE platform_campaigns SET payload=?,enabled=0,revision=revision+1 WHERE id=?',[json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$c['id']]);
            $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->campaignPage($id,(int)$c['id']);return true;
        }return false;
    }
    private function markReachability(int $id,bool $reachable): void {
        $this->query('INSERT INTO platform_outreach_state(user_id,reachable,updated) VALUES(?,?,?) ON DUPLICATE KEY UPDATE reachable=VALUES(reachable),updated=VALUES(updated)',[$id,(int)$reachable,time()]);
    }
    private function maybeAdvertisement(int $id): void {
        if(!$this->settings['ads_enabled']||$this->admin($id))return;
        if((int)($this->one("SELECT value FROM platform_settings WHERE name='outreach_retry_at'")['value']??0)>time())return;
        $a=$this->account($id);
        if(!$this->ageEligible($a)||$a['role']==='banned'||!$a['accepted']||!$this->readyProfile($a)||$a['state']!==''||$this->activeTendency($id)||$this->pendingBooking($id)||($this->settings['onboarding_required']&&!$a['onboarding_done']))return;
        $c=$this->channelConfig();$m=$this->one('SELECT * FROM platform_memberships WHERE user_id=?',[$id]);
        if($c&&$c['enabled']&&(!$m||!$m['member']||$m['revision']!=$c['revision']))return;
        $this->query('INSERT INTO platform_outreach_state(user_id,events,updated) VALUES(?,1,?) ON DUPLICATE KEY UPDATE events=events+1',[$id,time()]);
        $s=$this->one('SELECT * FROM platform_outreach_state WHERE user_id=?',[$id]);
        if(!$s['reachable']||$s['events']<$this->settings['ads_every_events']||time()-(int)$s['last_ad']<$this->settings['ads_cooldown_seconds'])return;
        $ad=$this->one("SELECT * FROM platform_campaigns WHERE kind='ad' AND enabled=1 AND status='ready' ORDER BY (id=?),last_shown,id LIMIT 1",[$s['last_campaign']]);if(!$ad)return;
        $r=$this->campaignSend($id,json_decode($ad['payload'],true,512,JSON_THROW_ON_ERROR));
        if($r->ok??false){$this->query('UPDATE platform_outreach_state SET events=0,last_ad=?,last_campaign=? WHERE user_id=?',[time(),$ad['id'],$id]);$this->query('UPDATE platform_campaigns SET last_shown=? WHERE id=?',[time(),$ad['id']]);}
        elseif($r->delivery_unknown??false)$this->query('UPDATE platform_outreach_state SET events=0,last_ad=?,last_campaign=? WHERE user_id=?',[time(),$ad['id'],$id]);
        elseif(($r->error_code??0)===403)$this->markReachability($id,false);
        elseif(($r->error_code??0)===429)$this->query("INSERT INTO platform_settings(name,value) VALUES('outreach_retry_at',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[time()+max(1,(int)($r->parameters->retry_after??60))]);
        elseif(($r->error_code??0)===400){$this->query('UPDATE platform_campaigns SET enabled=0 WHERE id=?',[$ad['id']]);$this->audit(0,'ad_invalid',(string)$ad['id']);}
    }
    private function broadcastTick(): void {
        // A crash after Telegram accepts a request is ambiguous. Never auto-resend that claim.
        $this->query("UPDATE platform_campaign_deliveries SET status='uncertain' WHERE status='sending' AND claimed<?",[time()-180]);
        $pause=(int)($this->one("SELECT value FROM platform_settings WHERE name='outreach_retry_at'")['value']??0);if($pause>time())return;
        $jobs=$this->all("SELECT d.*,c.payload FROM platform_campaign_deliveries d JOIN platform_campaigns c ON c.id=d.campaign_id WHERE d.status='pending' AND d.retry_at<=? AND c.status IN ('queued','sending') ORDER BY d.id LIMIT 3",[time()]);
        foreach($jobs as $job){
            $id=(int)$job['user_id'];$a=$this->one('SELECT * FROM platform_accounts WHERE id=?',[$id]);$o=$this->one('SELECT reachable FROM platform_outreach_state WHERE user_id=?',[$id]);
            if(!$this->ageEligible($a??['age'=>0])||($a['role']??'')==='banned'||($o&& !$o['reachable'])){$this->query("UPDATE platform_campaign_deliveries SET status='skipped' WHERE id=?",[$job['id']]);continue;}
            $this->query("UPDATE platform_campaign_deliveries SET status='sending',attempts=attempts+1,claimed=? WHERE id=? AND status='pending'",[time(),$job['id']]);$this->query("UPDATE platform_campaigns SET status='sending' WHERE id=?",[$job['campaign_id']]);
            $r=$this->campaignSend($id,json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR));
            if($r->ok??false){$this->query("UPDATE platform_campaign_deliveries SET status='sent',message_id=? WHERE id=?",[$r->result->message_id??0,$job['id']]);continue;}
            if($r->delivery_unknown??false){$this->query("UPDATE platform_campaign_deliveries SET status='uncertain' WHERE id=?",[$job['id']]);continue;}
            $code=(int)($r->error_code??503);$retry=($code===429||$code>=500)&&(int)$job['attempts']<4;
            $wait=$code===429?max(1,(int)($r->parameters->retry_after??60)):min(600,30*(2**(int)$job['attempts']));
            $this->query('UPDATE platform_campaign_deliveries SET status=?,retry_at=?,error_code=? WHERE id=?',[$retry?'pending':'failed',time()+$wait,$code,$job['id']]);
            if($code===403||($code===400&&preg_match('/chat not found|user is deactivated/i',$r->description??'')))$this->markReachability($id,false);
            if($code===429){$this->query("INSERT INTO platform_settings(name,value) VALUES('outreach_retry_at',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[time()+$wait]);break;}
        }
        $this->query("UPDATE platform_campaigns c SET c.status='completed' WHERE c.kind='broadcast' AND c.status IN ('queued','sending') AND NOT EXISTS (SELECT 1 FROM platform_campaign_deliveries d WHERE d.campaign_id=c.id AND d.status IN ('pending','sending'))");
    }
}
