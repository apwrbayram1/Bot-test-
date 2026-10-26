<?php
trait PlatformAccess {
    private function accessEligible(int $id): bool {
        $a=$this->account($id);if(!$this->ageEligible($a))return false;
        if($this->admin($id))return true;
        if($this->settings['onboarding_required']&&(!$a['province_id']||!$a['city_id']))return false;
        $c=$this->channelConfig();if(!$c||!$c['enabled'])return true;
        $m=$this->one('SELECT * FROM platform_memberships WHERE user_id=?',[$id]);
        return $m&&(bool)$m['member']&&$m['revision']==$c['revision']&&$m['channel_id']==$c['channel_id']&&(int)$m['next_check']>time();
    }
    private function accessSql(string $alias): string {
        // Staff bypass access gates to keep management available.
        $ids=array_filter(array_map('trim',explode(',',($this->env['SUPERADMIN_IDS']??'').','.($this->env['ADMIN_IDS']??''))),fn($v)=>ctype_digit($v));
        $staff="$alias.role IN ('admin','superadmin')".($ids?" OR $alias.id IN (".implode(',',$ids).')':'');
        $parts=[];
        $age="($alias.age>=18 OR ($alias.age>0 AND $alias.age+FLOOR((UNIX_TIMESTAMP()-$alias.age_set_at)/31557600)>=18))";
        if($this->settings['age_required'])$parts[]=$age;else $parts[]="($alias.age=0 OR $age)";
        if($this->settings['onboarding_required'])$parts[]="($alias.province_id<>0 AND $alias.city_id<>0)";
        $c=$this->channelConfig();if($c&&$c['enabled'])$parts[]="EXISTS (SELECT 1 FROM platform_memberships gm WHERE gm.user_id=$alias.id AND gm.member=1 AND gm.channel_id=".(int)$c['channel_id']." AND gm.revision=".(int)$c['revision']." AND gm.next_check>UNIX_TIMESTAMP())";
        return ' AND '.($this->settings['age_required']?$age:"($alias.age=0 OR $age)").($parts?' AND (('.$staff.') OR ('.implode(' AND ',$parts).'))':'');
    }
    private function channelConfig(): ?array {return $this->one('SELECT * FROM platform_channel_gate WHERE id=1');}
    private function channelMember(object $member): bool {return in_array($member->status??'',['creator','administrator','member'],true)||(($member->status??'')==='restricted'&&($member->is_member??false));}
    private function channelProbe(int $channel,int $bot,int $actor): bool {
        $self=jijibot('getChatMember',['chat_id'=>$channel,'user_id'=>$bot]);
        if(!($self->ok??false)||!in_array($self->result->status??'',['administrator','creator'],true))return false;
        $probe=jijibot('getChatMember',['chat_id'=>$channel,'user_id'=>$actor]);return (bool)($probe->ok??false);
    }
    private function channelPage(int $id): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');$c=$this->channelConfig();
        $this->send($id,$this->t($id,'join.admin',['channel'=>$this->e($c['title']??'—'),'status'=>$this->t($id,($c['enabled']??0)?'ui.enabled':'ui.disabled'),'health'=>$this->e($c['health']??'—')]),[
            [[$this->t($id,'join.configure'),'channel:configure']],
            ...($c?[[[$this->t($id,($c['enabled']?'join.disable':'join.enable')),'channel:toggle']]]:[]),
            [[$this->t($id,'ui.back'),'panelhome']]
        ]);
    }
    private function captureChannel(int $id,object $msg): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');
        $chat=($msg->forward_origin->type??'')==='channel'?($msg->forward_origin->chat??null):($msg->forward_from_chat??null);
        if(!$chat||($chat->type??'')!=='channel'){$this->send($id,$this->t($id,'join.forward.required'));return;}
        $me=jijibot('getMe');$bot=(int)($me->result->id??0);$channel=(int)$chat->id;
        $info=jijibot('getChat',['chat_id'=>$channel]);
        if(!($me->ok??false)||!($info->ok??false)||!$this->channelProbe($channel,$bot,$id)){$this->send($id,$this->t($id,'join.rights'));return;}
        $data=['channel'=>$channel,'bot'=>$bot,'title'=>$info->result->title??$chat->title??(string)$channel];
        $url=!empty($info->result->username)?'https://t.me/'.$info->result->username:($info->result->invite_link??'');
        $token=$this->token($id,'channel',$data+['url'=>$url],1800);
        if(!$url){$this->prompt($id,'channel:url:'.$token,$this->t($id,'join.url.prompt'));return;}
        $this->channelReview($id,$token);
    }
    private function channelReview(int $id,string $token): void {
        $d=$this->uiToken($id,$token,'channel')['data'];
        $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);
        $this->send($id,$this->t($id,'join.review',['channel'=>$this->e($d['title']),'url'=>$this->e($d['url'])]),[
            [[$this->t($id,'join.activate'),'channel:save:'.$token],[$this->t($id,'cancel.button'),'channel']]
        ]);
    }
    private function membershipCheck(int $id,array $c): ?bool {
        $r=jijibot('getChatMember',['chat_id'=>$c['channel_id'],'user_id'=>$id]);
        if(!($r->ok??false))return null;
        $member=$this->channelMember($r->result);$now=time();
        $this->query('INSERT INTO platform_memberships(user_id,channel_id,revision,member,checked,next_check) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),revision=VALUES(revision),member=VALUES(member),checked=VALUES(checked),next_check=VALUES(next_check)',[$id,$c['channel_id'],$c['revision'],(int)$member,$now,$now+86400]);
        if(!$member)$this->revokeMembership($id);
        return $member;
    }
    private function revokeMembership(int $id): void {
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$id]);
        $this->query("UPDATE platform_requests SET status='expired' WHERE status='pending' AND (customer=? OR provider=?)",[$id,$id]);
        $s=$this->active($id);if($s)$this->finish($s,'membership_required',$s['kind']==='paid'?$this->failureRefund($s):0);
    }
    private function membershipGate(int $id,bool $force=false): bool {
        $c=$this->channelConfig();if(!$c||!$c['enabled']||$this->admin($id))return true;
        $m=$this->one('SELECT * FROM platform_memberships WHERE user_id=?',[$id]);
        if(!$force&&$m&&$m['channel_id']==$c['channel_id']&&$m['revision']==$c['revision']&&$m['member']&&time()-(int)$m['checked']<60)return true;
        $member=$this->membershipCheck($id,$c);if($member===true)return true;
        $this->callbackContext=null;
        $this->send($id,$this->t($id,$member===null?'join.unavailable':'join.required',['channel'=>$this->e($c['title']),'url'=>$this->e($c['join_url'])]),[
            [[$this->t($id,'join.open'),['url'=>$c['join_url']]]],
            [[$this->t($id,'join.recheck'),'join:check'],[$this->t($id,'language.button'),'language']]
        ]);return false;
    }
    private function membershipTick(): void {
        $c=$this->channelConfig();if(!$c||!$c['enabled'])return;
        $due=$this->all("SELECT a.id FROM platform_accounts a LEFT JOIN platform_memberships m ON m.user_id=a.id WHERE a.id>0 AND a.role<>'banned' AND (m.user_id IS NULL OR m.next_check<=? OR (m.checked>0 AND (m.revision<>? OR m.channel_id<>?))) ORDER BY COALESCE(m.next_check,0),a.id LIMIT 2",[time(),$c['revision'],$c['channel_id']]);
        foreach($due as $row){$id=(int)$row['id'];if($this->admin($id)){
            $this->query('INSERT INTO platform_memberships(user_id,channel_id,revision,member,checked,next_check) VALUES(?,?,?,1,?,?) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),revision=VALUES(revision),member=1,checked=VALUES(checked),next_check=VALUES(next_check)',[$id,$c['channel_id'],$c['revision'],time(),time()+86400]);continue;
        }
        if($this->membershipCheck($id,$c)===null){
            $this->query('INSERT INTO platform_memberships(user_id,channel_id,revision,member,checked,next_check) VALUES(?,?,?,0,0,?) ON DUPLICATE KEY UPDATE member=IF(channel_id=VALUES(channel_id) AND revision=VALUES(revision),member,0),channel_id=VALUES(channel_id),revision=VALUES(revision),next_check=VALUES(next_check)',[$id,$c['channel_id'],$c['revision'],time()+300]);break;
        }}
    }
    private function accessAction(int $id,string $action): bool {
        if($action==='join:check'){if($this->membershipGate($id,true))$this->action($id,'home');return true;}
        if($action==='channel'){$this->channelPage($id);return true;}
        if($action==='channel:configure'){if(!$this->admin($id))throw new RuntimeException('Access denied');$this->prompt($id,'channel:forward',$this->t($id,'join.forward.prompt'));return true;}
        if($action==='channel:toggle'){
            if(!$this->admin($id))throw new RuntimeException('Access denied');$c=$this->channelConfig();if(!$c)throw new RuntimeException('This action expired. Open the panel again.');
            if(!$c['enabled']&&!$this->channelProbe((int)$c['channel_id'],(int)$c['bot_id'],$id)){$this->send($id,$this->t($id,'join.rights'));return true;}
            $this->query('UPDATE platform_channel_gate SET enabled=?,revision=revision+1,updated=? WHERE id=1',[$c['enabled']?0:1,time()]);$this->audit($id,'channel_toggle',$c['enabled']?'off':'on');$this->channelPage($id);return true;
        }
        if(preg_match('/^channel:save:([a-f0-9]{16})$/',$action,$m)){
            if(!$this->admin($id))throw new RuntimeException('Access denied');$draft=$this->uiToken($id,$m[1],'channel');$d=$draft['data'];
            if(!$this->channelProbe((int)$d['channel'],(int)$d['bot'],$id)){$this->send($id,$this->t($id,'join.rights'));return true;}
            $this->db->begin_transaction();try{
                if(!$this->query('UPDATE platform_ui SET used=1 WHERE token=? AND used=0',[$m[1]])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
                $this->query("INSERT INTO platform_channel_gate(id,channel_id,title,join_url,enabled,bot_id,updated) VALUES(1,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),title=VALUES(title),join_url=VALUES(join_url),enabled=1,bot_id=VALUES(bot_id),revision=revision+1,health='ok',updated=VALUES(updated)",[$d['channel'],$d['title'],$d['url'],$d['bot'],time()]);
                $this->audit($id,'channel_config',(string)$d['channel']);$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}$this->channelPage($id);return true;
        }
        if($action==='welcome:orientation'){$this->orientationPage($id);return true;}
        if(preg_match('/^orientation:(yes|no|blank|retry):([a-f0-9]{16})$/',$action,$m)){
            $a=$this->tendencyOwned($id,$m[2],'completed');$latest=$this->latestOrientationAttempt($id);
            if(!$latest||$latest['token']!==$a['token'])throw new RuntimeException('This action expired. Open the panel again.');
            if($m[1]==='no'){$this->query("UPDATE platform_accounts SET orientation_status='rejected',orientation_attempt=? WHERE id=?",[$a['token'],$id]);$this->orientationPage($id);return true;}
            if($m[1]==='retry'){$this->query("UPDATE platform_accounts SET orientation_status='rejected',orientation_attempt=? WHERE id=?",[$a['token'],$id]);$this->tendencyHome($id);return true;}
            if($m[1]==='blank'){
                if($this->account($id)['orientation_status']!=='rejected')throw new RuntimeException('This action expired. Open the panel again.');$cipher=null;$status='blank';
            }else{$top=$this->orientationTop($a);if(!$top){$this->orientationPage($id);return true;}$cipher=TendencyTest::encrypt(['roles'=>$top]);$status='confirmed';}
            $this->query("UPDATE platform_accounts SET orientation_status=?,orientation_attempt=?,orientation_cipher=?,onboarding_done=1,state='' WHERE id=?",[$status,$a['token'],$cipher,$id]);
            $this->send($id,$this->t($id,'orientation.saved'));$this->returnPanel($id);return true;
        }
        return false;
    }
    private function accessInput(int $id,array $a,object $msg): bool {
        if($a['state']==='channel:forward'){$this->captureChannel($id,$msg);return true;}
        if(preg_match('/^channel:url:([a-f0-9]{16})$/',$a['state'],$m)){
            if(!$this->admin($id))throw new RuntimeException('Access denied');$draft=$this->uiToken($id,$m[1],'channel');$url=trim($msg->text??'');
            if(!preg_match('~^https://t\.me/(?:\+[A-Za-z0-9_-]+|joinchat/[A-Za-z0-9_-]+|[A-Za-z][A-Za-z0-9_]{4,31})$~D',$url)){$this->send($id,$this->t($id,'join.url.prompt'));return true;}
            $data=$draft['data'];$data['url']=$url;$this->query('UPDATE platform_ui SET payload=? WHERE token=?',[json_encode($data,JSON_UNESCAPED_UNICODE),$m[1]]);$this->channelReview($id,$m[1]);return true;
        }return false;
    }
    private function latestOrientationAttempt(int $id): ?array {return $this->one("SELECT * FROM platform_tendency_attempts WHERE user_id=? AND status='completed' ORDER BY completed DESC,id DESC LIMIT 1",[$id]);}
    private function orientationTop(array $attempt): array {
        $p=TendencyTest::decrypt($attempt['payload_cipher']);$roles=[];
        foreach($p['bank']['roles'] as $r){$s=$p['result']['scores'][$r['id']]['score'];if($s!==null)$roles[]=['id'=>$r['id'],'label_en'=>$r['label_en'],'label_fa'=>$r['label_fa'],'score'=>$s];}
        usort($roles,fn($x,$y)=>($y['score']<=>$x['score'])?:strcmp($x['id'],$y['id']));return array_slice($roles,0,3);
    }
    private function orientationPage(int $id): void {
        $a=$this->account($id);$attempt=$this->latestOrientationAttempt($id);
        if(!$attempt){$this->send($id,$this->t($id,'welcome.test'));$this->tendencyHome($id);return;}
        $top=$this->orientationTop($attempt);$text=$this->t($id,'welcome.orientation');
        foreach($top as $r)$text.="\n\n<b>".$this->e($r['label_'.$this->languageFor($id)]).'</b> · '.$r['score'].'%';
        $rejected=$a['orientation_status']==='rejected'&&$a['orientation_attempt']===$attempt['token'];
        $rows=$rejected||!$top?[[[$this->t($id,'welcome.retry'),'orientation:retry:'.$attempt['token']]]]:[[[$this->t($id,'welcome.correct'),'orientation:yes:'.$attempt['token']]], [[$this->t($id,'welcome.incorrect'),'orientation:no:'.$attempt['token']]]];
        if(!$top&&!$rejected)$this->query("UPDATE platform_accounts SET orientation_status='rejected',orientation_attempt=? WHERE id=?",[$attempt['token'],$id]);
        if($rejected||!$top){$text.="\n\n".$this->t($id,!$top?'welcome.insufficient':'welcome.rejected');$rows[]=[[$this->t($id,'welcome.blank'),'orientation:blank:'.$attempt['token']]];}
        $this->tendencySend($id,$text,$rows);
    }
    private function welcomeGate(int $id,?string $action=null): bool {
        $a=$this->account($id);if(!$this->settings['onboarding_required']||$this->admin($id)||!$a['accepted']||!$this->readyProfile($a)||$this->active($id))return false;
        if(!$a['province_id']||!$a['city_id']){
            if($action!==null&&preg_match('/^(?:loc:normal:|locpage:|locpick:|locsearch:normal$)/',$action))return false;
            if($action===null&&str_starts_with($a['state'],'locsearch:normal'))return false;
            $this->locationPage($id,'normal',$a['province_id']?'c':'p');return true;
        }
        // The preference test is optional. No replacement requirement has been configured.
        if(!$a['onboarding_done']){
            $this->query('UPDATE platform_accounts SET onboarding_done=1 WHERE id=?',[$id]);
            if($action===null){$this->returnPanel($id);return true;}
        }
        return false;
    }
    private function privateOrientation(int $id): string {
        $a=$this->account($id);$roles=$a['orientation_cipher']?TendencyTest::decrypt($a['orientation_cipher'])['roles']:[];
        $labels=array_map(fn($r)=>$r['label_'.$this->languageFor($id)].' · '.$r['score'].'%',$roles);
        return $this->t($id,'welcome.profile',['roles'=>$this->e($labels?implode('، ',$labels):$this->t($id,'ui.empty'))]);
    }
}
