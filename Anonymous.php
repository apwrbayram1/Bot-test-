<?php
/** Free, recipient-bound anonymous inbox; no profile identity is attached to delivered content. */
trait PlatformAnonymous {
    private bool $anonymousUpdate=false;
    private function anonIntent(int $id): ?array {return $this->one('SELECT * FROM platform_anon_intents WHERE user_id=? AND expires>?',[$id,time()]);}
    private function anonBusy(int $id): bool {return (bool)($this->active($id)||$this->activeTendency($id)||$this->pendingBooking($id));}
    private function anonAccess(int $id): bool {
        $a=$this->account($id);
        if($a['role']==='banned'){$this->send($id,$this->t($id,'account.suspended'));return false;}
        if(!in_array($a['language'],['en','fa'],true)){$this->languageMenu($id);return false;}
        if(!$this->membershipGate($id)||!$this->ensureAdult($id))return false;
        if(!$a['accepted']){$this->terms($id);return false;}
        return true;
    }
    private function anonLinkPage(int $id): void {
        $a=$this->account($id);if(!$this->completeReferralProfile($a)){$this->send($id,$this->t($id,'growth.complete'),[[[$this->t($id,'profile.button'),'profile']]]);return;}
        $link=$this->one('SELECT * FROM platform_anon_links WHERE user_id=?',[$id]);
        if(!$link){$token=bin2hex(random_bytes(16));$this->query('INSERT IGNORE INTO platform_anon_links(user_id,token,created) VALUES(?,?,?)',[$id,$token,time()]);$link=$this->one('SELECT * FROM platform_anon_links WHERE user_id=?',[$id]);}
        $url=$this->botDeepLink('a_'.$link['token']);$pending=(int)$this->one("SELECT COUNT(*) n FROM platform_anon_messages WHERE recipient=? AND status='pending'",[$id])['n'];
        $this->send($id,$this->t($id,'anon.link.body',['url'=>$this->e($url),'unread'=>$pending]),[
            [[$this->t($id,'anon.copy'),['copy_text'=>['text'=>$url]]]],
            [[$this->t($id,'anon.rotate'),'anon:rotate']],
            [[$this->t($id,'anon.inbox'),'anon:inbox:0']],
            [[$this->t($id,'anon.unblock'),'anon:unblock']],
            [[$this->t($id,'ui.back'),'home']]
        ]);
    }
    private function anonOpen(int $id,string $token): void {
        $this->anonymousUpdate=true;
        $this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);
        $link=$this->one("SELECT l.* FROM platform_anon_links l JOIN platform_accounts a ON a.id=l.user_id WHERE l.token=? AND a.role<>'banned'",[$token]);
        if(!$link||$link['user_id']==$id){$this->send($id,$this->t($id,'anon.unavailable'),[[[$this->t($id,'back.panel'),'home']]]);return;}
        if((int)$this->account($id)['age']>0&&!$this->ageEligible($this->account($id))){$this->ensureAdult($id);return;}
        if($this->anonBusy($id)){$this->send($id,$this->t($id,'anon.busy'),[[[$this->t($id,'back.panel'),'home']]]);return;}
        if($this->one('SELECT user_id FROM platform_anon_blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?)',[$link['user_id'],$id,$id,$link['user_id']])){$this->send($id,$this->t($id,'anon.unavailable'));return;}
        $recent=(int)$this->one('SELECT COUNT(*) n FROM platform_anon_threads WHERE guest_id=? AND created>?',[$id,time()-60])['n'];
        if($recent>=5){$this->send($id,$this->t($id,'anon.slow'));return;}
        $thread=bin2hex(random_bytes(16));
        $this->query('INSERT INTO platform_anon_threads(token,owner_id,guest_id,created,expires) VALUES(?,?,?,?,?)',[$thread,$link['user_id'],$id,time(),time()+2592000]);
        $this->setAnonIntent($id,$thread,$token);$this->anonPrompt($id);
    }
    private function setAnonIntent(int $id,string $thread,string $link=''): void {
        $this->query('INSERT INTO platform_anon_intents(user_id,thread_token,link_token,expires) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE thread_token=VALUES(thread_token),link_token=VALUES(link_token),expires=VALUES(expires)',[$id,$thread,$link,time()+1800]);
        $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);
        if($this->provider($this->account($id)))$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$id]);
    }
    private function anonPrompt(int $id): void {
        $this->anonymousUpdate=true;if(!$this->anonAccess($id))return;
        $intent=$this->anonIntent($id);if(!$intent){$this->anonInbox($id);return;}
        $this->send($id,$this->t($id,'anon.compose'),[[[$this->t($id,'cancel.button'),'anon:cancel']]]);
    }
    private function anonThread(int $id,string $token): array {
        $t=$this->one('SELECT * FROM platform_anon_threads WHERE token=? AND expires>? AND (owner_id=? OR guest_id=?)',[$token,time(),$id,$id]);
        if(!$t)throw new RuntimeException('This action expired. Open the panel again.');return $t;
    }
    private function anonTicket(int $id,string $token): array {
        $m=$this->one('SELECT * FROM platform_anon_messages WHERE token=? AND recipient=?',[$token,$id]);
        if(!$m)throw new RuntimeException('This action expired. Open the panel again.');return $m;
    }
    private function anonControls(int $id,array $m): array {
        return [[[$this->t($id,'anon.reply'),'anon:reply:'.$m['token']]],
            [[$this->t($id,'anon.block'),'anon:block:'.$m['token']],[$this->t($id,'anon.report'),'anon:report:'.$m['token']]],
            [[$this->t($id,'anon.inbox'),'anon:inbox:0'],[$this->t($id,'back.panel'),'home']]];
    }
    private function anonDeliver(int $id,string $token,bool $explicit=false): bool {
        $m=$this->anonTicket($id,$token);
        if($m['status']==='sent')return true;if($m['status']!=='pending')return false;
        if(!$this->ageEligible($this->account($id)))return false;
        if(!$explicit&&($this->anonBusy($id)||$this->account($id)['state']!==''||$this->anonIntent($id)))return false;
        if($this->one('SELECT user_id FROM platform_anon_blocks WHERE user_id=? AND blocked_id=?',[$id,$m['sender']])){$this->query("UPDATE platform_anon_messages SET status='blocked' WHERE token=?",[$token]);return false;}
        if(!$this->query("UPDATE platform_anon_messages SET status='sending' WHERE token=? AND status='pending'",[$token])->affected_rows)return false;
        $payload=TendencyTest::decrypt($m['payload_cipher']);$payload['buttons']=$this->anonControls($id,$m);$payload['params']['protect_content']='true';
        // A short header distinguishes inbox content from anonymous-chat partner messages.
        $this->menuSend($id,$this->t($id,'anon.received'),[],true);
        $r=$this->campaignSend($id,$payload,false);
        if($r->ok??false){$this->query("UPDATE platform_anon_messages SET status='sent',target_message=? WHERE token=?",[$r->result->message_id??0,$token]);return true;}
        $status=($r->delivery_unknown??false)?'uncertain':'pending';
        $this->query('UPDATE platform_anon_messages SET status=? WHERE token=?',[$status,$token]);
        if(($r->error_code??0)===403)$this->markReachability($id,false);
        return false;
    }
    private function anonSend(int $id,object $msg,array $intent): void {
        $this->anonymousUpdate=true;if(!$this->anonAccess($id))return;
        if($this->anonBusy($id)){$this->send($id,$this->t($id,'anon.busy'));return;}
        $thread=$this->anonThread($id,$intent['thread_token']);$target=(int)($thread['owner_id']==$id?$thread['guest_id']:$thread['owner_id']);
        if($intent['link_token']!==''&&!$this->one('SELECT user_id FROM platform_anon_links WHERE token=? AND user_id=?',[$intent['link_token'],$target])){$this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);$this->send($id,$this->t($id,'anon.unavailable'));return;}
        if($this->account($target)['role']==='banned'||$this->one('SELECT user_id FROM platform_anon_blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?)',[$target,$id,$id,$target])){$this->send($id,$this->t($id,'anon.unavailable'));return;}
        if($existing=$this->one('SELECT token FROM platform_anon_messages WHERE sender=? AND source_message=?',[$id,$msg->message_id])){$this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);$this->send($id,$this->t($id,'anon.sent'));return;}
        $reason=MessageModeration::message($msg,$this->settings);
        if($reason){$this->send($id,$this->t($id,'moderation.blocked',['reason'=>$this->t($id,'moderation.reason.'.$reason)]),[[[$this->t($id,'cancel.button'),'anon:cancel']]]);return;}
        $payload=$this->captureCampaignPayload($msg);if(!$payload){$this->send($id,$this->t($id,'anon.unsupported'));return;}
        $recent=$this->one('SELECT COUNT(*) n,COALESCE(MAX(created),0) last_time FROM platform_anon_messages WHERE sender=? AND created>?',[$id,time()-3600]);
        $pending=(int)$this->one("SELECT COUNT(*) n FROM platform_anon_messages WHERE recipient=? AND status='pending'",[$target])['n'];
        if($recent['n']>=20||time()-(int)$recent['last_time']<5||$pending>=100){$this->send($id,$this->t($id,'anon.slow'));return;}
        $token=bin2hex(random_bytes(16));$cipher=TendencyTest::encrypt($payload);
        $this->query('INSERT INTO platform_anon_messages(token,thread_token,sender,recipient,source_message,payload_cipher,created) VALUES(?,?,?,?,?,?,?)',[$token,$thread['token'],$id,$target,$msg->message_id,$cipher,time()]);
        $this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);
        $delivered=$this->anonDeliver($target,$token);
        if(!$delivered){$this->menuSend($target,$this->t($target,'anon.waiting'),[],true);}
        $this->send($id,$this->t($id,'anon.sent'),[[[$this->t($id,'anon.again'),'anon:again:'.$thread['token']]], [[$this->t($id,'anon.inbox'),'anon:inbox:0']], [[$this->t($id,'back.panel'),'home']]]);
    }
    private function anonInbox(int $id,int $requested=0): void {
        $this->anonymousUpdate=true;if(!$this->anonAccess($id))return;
        if($this->anonBusy($id)){$this->send($id,$this->t($id,'anon.busy'));return;}
        $count=(int)$this->one("SELECT COUNT(*) n FROM platform_anon_messages WHERE recipient=? AND status IN ('pending','sent','uncertain')",[$id])['n'];[$page,$pages]=$this->paging($count,$requested,5);
        $messages=$this->all("SELECT token,status,created FROM platform_anon_messages WHERE recipient=? AND status IN ('pending','sent','uncertain') ORDER BY created DESC,token LIMIT 5 OFFSET ".($page*5),[$id]);$rows=[];
        foreach($messages as $m)$rows[]=[[(($m['status']==='pending')?'📩 ':'💬 ').gmdate('m-d H:i',(int)$m['created']).' · '.substr($m['token'],0,6),'anon:read:'.$m['token']]];
        $rows=array_merge($rows,$this->pageNav($id,'anon:inbox:',$page,$pages));$rows[]=[[$this->t($id,'back.panel'),'home']];
        $this->send($id,$this->t($id,'anon.inbox.body')."\n".$this->pageHeading($id,$page,$pages,$count),$rows);
    }
    private function anonAction(int $id,string $action): bool {
        $intent=$this->anonIntent($id);
        if($intent&&($action==='accept'||str_starts_with($action,'language:')||$action==='join:check')){
            $this->anonymousUpdate=true;
            if($action==='accept')$this->query('UPDATE platform_accounts SET accepted=1 WHERE id=?',[$id]);
            if(str_starts_with($action,'language:')){$lang=substr($action,9);if(!in_array($lang,['en','fa'],true))throw new RuntimeException('Invalid language');$this->query('UPDATE platform_accounts SET language=? WHERE id=?',[$lang,$id]);$this->languages[$id]=$lang;$this->syncCommands($id);}
            $this->anonPrompt($id);return true;
        }
        if(!str_starts_with($action,'anon:'))return false;$this->anonymousUpdate=true;
        if($action==='anon:cancel'){$this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);$this->send($id,$this->t($id,'anon.cancelled'),[[[$this->t($id,'anon.inbox'),'anon:inbox:0']],[[$this->t($id,'back.panel'),'home']]]);return true;}
        if(!$this->anonAccess($id))return true;
        if($action==='anon:rotate'){
            $link=$this->one('SELECT * FROM platform_anon_links WHERE user_id=?',[$id]);if(!$link)throw new RuntimeException('This action expired. Open the panel again.');
            $token=$this->token($id,'anonrotate',['revision'=>(int)$link['revision']],600);
            $this->send($id,$this->t($id,'anon.rotate.review'),[[[$this->t($id,'ui.confirm'),'anon:rotateyes:'.$token],[$this->t($id,'cancel.button'),'anonlink']]]);return true;
        }
        if(preg_match('/^anon:rotateyes:([a-f0-9]{16})$/',$action,$m)){
            $d=$this->uiToken($id,$m[1],'anonrotate');$new=bin2hex(random_bytes(16));
            $this->db->begin_transaction();try{
                if(!$this->query('UPDATE platform_anon_links SET token=?,revision=revision+1,created=? WHERE user_id=? AND revision=?',[$new,time(),$id,$d['data']['revision']])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
                $this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$m[1]]);$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}$this->anonLinkPage($id);return true;
        }
        if(preg_match('/^anon:inbox:(\d+)$/',$action,$m)){$this->anonInbox($id,(int)$m[1]);return true;}
        if(preg_match('/^anon:(read|reply|block|report):([a-f0-9]{32})$/',$action,$m)){
            $ticket=$this->anonTicket($id,$m[2]);if($m[1]==='reply')$this->anonThread($id,$ticket['thread_token']);
            if($m[1]==='block'||$m[1]==='report'){
                $this->query('INSERT IGNORE INTO platform_anon_blocks(user_id,blocked_id,created) VALUES(?,?,?)',[$id,$ticket['sender'],time()]);
                $this->query("UPDATE platform_anon_messages SET status='blocked' WHERE recipient=? AND sender=? AND status='pending'",[$id,$ticket['sender']]);
                if($m[1]==='report')$this->audit($id,'anonymous_report','ticket:'.$ticket['token']);
                $this->send($id,$this->t($id,'anon.blocked'),[[[$this->t($id,'anon.inbox'),'anon:inbox:0']]]);return true;
            }
            if($this->anonBusy($id)){$this->send($id,$this->t($id,'anon.busy'));return true;}
            if($m[1]==='reply'){$this->setAnonIntent($id,$ticket['thread_token']);$this->anonPrompt($id);return true;}
            if($ticket['status']==='pending'){if(!$this->anonDeliver($id,$ticket['token'],true))$this->send($id,$this->t($id,'anon.delivery.retry'),[[[$this->t($id,'anon.inbox'),'anon:inbox:0']]]);}
            else{$payload=TendencyTest::decrypt($ticket['payload_cipher']);$payload['buttons']=$this->anonControls($id,$ticket);$payload['params']['protect_content']='true';$this->campaignSend($id,$payload,false);}return true;
        }
        if(preg_match('/^anon:again:([a-f0-9]{32})$/',$action,$m)){
            if($this->anonBusy($id)){$this->send($id,$this->t($id,'anon.busy'));return true;}$this->anonThread($id,$m[1]);$this->setAnonIntent($id,$m[1]);$this->anonPrompt($id);return true;
        }
        if($action==='anon:unblock'){$token=$this->token($id,'anonunblock',[],600);$this->send($id,$this->t($id,'anon.unblock.review'),[[[$this->t($id,'ui.confirm'),'anon:unblockyes:'.$token],[$this->t($id,'cancel.button'),'anonlink']]]);return true;}
        if(preg_match('/^anon:unblockyes:([a-f0-9]{16})$/',$action,$m)){$this->uiToken($id,$m[1],'anonunblock');$this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$m[1]]);$this->query('DELETE FROM platform_anon_blocks WHERE user_id=?',[$id]);$this->anonLinkPage($id);return true;}
        throw new RuntimeException('This action expired. Open the panel again.');
    }
    private function anonMessageInput(int $id,object $msg,string $text): bool {
        $intent=$this->anonIntent($id);
        if(str_starts_with($text,'/')){
            if($text==='/cancel'&&$intent){$this->anonAction($id,'anon:cancel');return true;}
            if($intent)$this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);return false;
        }
        if(!$intent&&isset($msg->reply_to_message)&&!$this->anonBusy($id)){
            $m=$this->one('SELECT * FROM platform_anon_messages WHERE recipient=? AND target_message=?',[$id,$msg->reply_to_message->message_id]);
            if($m){$this->setAnonIntent($id,$m['thread_token']);$intent=$this->anonIntent($id);}
        }
        if(!$intent)return false;$this->anonSend($id,$msg,$intent);return true;
    }
}
