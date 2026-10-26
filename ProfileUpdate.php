<?php
/** Profile onboarding, explicit consent and chat controls. No transcript-reading routes. */
trait PlatformProfileUpdate
{
    private function readyProfile(array $a): bool {return (bool)$a['name_set'] && in_array($a['gender'],['female','male'],true);}
    private function onboarding(int $id): void {
        $a=$this->account($id);
        if(!$a['name_set']) {
            $this->query("UPDATE platform_accounts SET state='onboard:name',available=0 WHERE id=?",[$id]);
            $this->send($id,$this->t($id,'v3.name.prompt'),[[[$this->t($id,'language.button'),'language']]]);return;
        }
        if(!$a['gender']) {$this->genderPage($id,true);return;}
        $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->returnPanel($id);
    }
    private function onboardingAction(int $id,string $action): bool {
        if(preg_match('/^genderpick:(female|male):([a-f0-9]{16})$/',$action,$m)) {$this->selectGender($id,$m[1],$m[2]);return true;}
        return false;
    }
    private function genderPage(int $id,bool $initial=false): void {
        if($this->active($id)||$this->pendingBooking($id))throw new RuntimeException('Edit your profile after your session');
        $a=$this->account($id);$initial=$a['gender']==='';
        if(!$initial && (int)$a['gender_changes']>=2) {$this->send($id,$this->t($id,'v3.gender.locked'),[[[$this->t($id,'ui.back'),'profile']]]);return;}
        $this->query("UPDATE platform_ui SET used=1 WHERE actor=? AND kind='gender'",[$id]);
        $token=$this->token($id,'gender',['old'=>$a['gender'],'changes'=>(int)$a['gender_changes']],1800);
        $rows=[];foreach(['female','male'] as $gender)$rows[]=[[$this->t($id,'v3.gender.'.$gender),'genderpick:'.$gender.':'.$token]];
        if(!$initial)$rows[]=[[$this->t($id,'cancel.button'),'profile']];
        $this->send($id,$this->t($id,$initial?'v3.gender.initial':'v3.gender.choose',['remaining'=>max(0,2-(int)$a['gender_changes'])]),$rows);
    }
    private function selectGender(int $id,string $gender,string $token): void {
        if($this->active($id)||$this->pendingBooking($id))throw new RuntimeException('Edit your profile after your session');
        if(!in_array($gender,['female','male'],true))throw new RuntimeException('Invalid gender');
        $draft=$this->uiToken($id,$token,'gender');$a=$this->account($id);
        if(!$a['name_set']||$a['gender']!==$draft['data']['old']||(int)$a['gender_changes']!==$draft['data']['changes'])throw new RuntimeException('This action expired. Open the panel again.');
        $initial=$a['gender']==='';$change=!$initial&&$a['gender']!==$gender;
        if($change&&(int)$a['gender_changes']>=2)throw new RuntimeException('Gender change limit reached');
        $this->db->begin_transaction();
        try {
            if(!$this->query('UPDATE platform_ui SET used=1 WHERE token=? AND used=0',[$token])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
            $this->query("UPDATE platform_accounts SET gender=?,gender_changes=gender_changes+?,state='' WHERE id=?",[$gender,(int)$change,$id]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        if($initial){$this->returnPanel($id);}else $this->profile($id);
    }
    private function mmProfile(int $id): array {
        $a=$this->account($id);
        $this->query('INSERT IGNORE INTO platform_mm_profiles(user_id,title,bio) VALUES(?,?,?)',[$id,$this->provider($a)?$this->content($id,$a['role'].'_title'):$this->content($id,'mister_title'),'']);
        return $this->one('SELECT * FROM platform_mm_profiles WHERE user_id=?',[$id]);
    }
    private function mmProfilePage(int $id): void {
        if(!$this->provider($this->account($id)))throw new RuntimeException('Provider account required');
        $p=$this->mmProfile($id);
        $this->send($id,$this->t($id,'v3.mm.profile',['name'=>$this->e($p['title']),'bio'=>$this->e($this->emptyText($id,$this->short($p['bio']??'',160))),
            'location'=>$this->e($this->locationText($id,$p)),'interests'=>$this->e($this->emptyText($id,$this->choiceText($id,$id,'mm_interests'))),'preferences'=>$this->e($this->emptyText($id,$this->choiceText($id,$id,'offered')))]),[
            [[$this->t($id,'field.title'),'mmfield:title'],[$this->t($id,'field.bio'),'mmfield:bio']],
            [[$this->t($id,'v3.province'),'loc:mm:p:0'],[$this->t($id,'v3.city'),'loc:mm:c:0']],
            [[$this->t($id,'field.interests'),'choose:mm_interests']],$this->back($id)
        ]);
    }
    private function personalDetails(int $id): string {
        $a=$this->account($id);
        return $this->t($id,'v3.profile.details',['gender'=>$a['gender']!==''?$this->t($id,'v3.gender.'.$a['gender']):$this->t($id,'ui.empty'),'remaining'=>max(0,2-(int)$a['gender_changes']),
            'phone'=>$this->e($a['phone']?:$this->t($id,'ui.empty')),'location'=>$this->e($this->locationText($id,$a))]);
    }
    private function locationData(): array {
        static $data=null;
        if($data===null) {
            $provinces=json_decode(file_get_contents(dirname(__DIR__).'/data/provinces.json'),true,512,JSON_THROW_ON_ERROR);
            $cities=json_decode(file_get_contents(dirname(__DIR__).'/data/cities.json'),true,512,JSON_THROW_ON_ERROR);
            $data=['provinces'=>array_column($provinces,null,'id'),'cities'=>array_column($cities,null,'id')];
        }return $data;
    }
    private function locationText(int $id,array $profile): string {
        $data=$this->locationData();$province=$data['provinces'][$profile['province_id']]??null;$city=$data['cities'][$profile['city_id']]??null;
        if(!$province)return $this->t($id,'ui.empty');
        return $province['name'].($city&&$city['province_id']==$province['id']?' · '.$city['name']:' · '.$this->t($id,'v3.city.required'));
    }
    private function locationOwner(int $id,string $scope,bool $editing=true): array {
        if(!in_array($scope,['normal','mm'],true))throw new RuntimeException('Unknown profile field');
        if($editing&&($this->active($id)||$this->pendingBooking($id)))throw new RuntimeException('Edit your profile after your session');
        $a=$this->account($id);if($scope==='mm'&&!$this->provider($a))throw new RuntimeException('Provider account required');
        return $scope==='mm'?$this->mmProfile($id):$a;
    }
    private function locationPage(int $id,string $scope,string $kind,int $requested=0,string $search=''): void {
        $p=$this->locationOwner($id,$scope);$data=$this->locationData();
        if($kind==='c'&&!$p['province_id']){$this->send($id,$this->t($id,'v3.province.first'),[[[$this->t($id,'v3.province'),'loc:'.$scope.':p:0']]]);return;}
        $items=$kind==='p'?array_values($data['provinces']):array_values(array_filter($data['cities'],fn($c)=>$c['province_id']==$p['province_id']));
        if($search!=='')$items=array_values(array_filter($items,fn($i)=>str_contains($this->normalizePlace($i['name']),$this->normalizePlace($search))));
        usort($items,fn($a,$b)=>strcmp($a['name'],$b['name']));[$page,$pages]=$this->paging(count($items),$requested,10);
        // A draft binds every city button to its province and rejects stale buttons after a change.
        $token=$this->token($id,'location',['scope'=>$scope,'kind'=>$kind,'province'=>(int)$p['province_id'],'search'=>$search],1800);
        $rows=[];foreach(array_chunk(array_slice($items,$page*10,10),2) as $pair){$r=[];foreach($pair as $item)$r[]=[$item['name'],'locpick:'.$token.':'.$item['id']];$rows[]=$r;}
        $rows=array_merge($rows,$this->pageNav($id,'locpage:'.$token.':',$page,$pages));
        if($kind==='c')$rows[]=[[$this->t($id,'v3.city.search'),'locsearch:'.$scope],[$this->t($id,'v3.province'),'loc:'.$scope.':p:0']];
        $rows[]=[[$this->t($id,'ui.back'),$scope==='mm'?'mmprofile':'profile']];
        $initial=$scope==='normal'&&$this->settings['onboarding_required']&&!$this->account($id)['onboarding_done'];
        $text=$this->t($id,$initial?($kind==='p'?'setup.province':'setup.city'):($kind==='p'?'v3.province.choose':'v3.city.choose'),['province'=>$data['provinces'][$p['province_id']]['name']??''])."\n".$this->pageHeading($id,$page,$pages,count($items));
        if(!$items)$text.="\n".$this->t($id,'ui.no.records');$this->send($id,$text,$rows);
    }
    private function normalizePlace(string $text): string {return str_replace(['ي','ك','‌',' '],['ی','ک','',''],trim($text));}
    private function pickLocation(int $id,string $token,int $value): void {
        $draft=$this->uiToken($id,$token,'location');$d=$draft['data'];$p=$this->locationOwner($id,$d['scope']);$data=$this->locationData();
        if((int)$p['province_id']!==$d['province'])throw new RuntimeException('This action expired. Open the panel again.');
        if($d['kind']==='p') {
            if(!isset($data['provinces'][$value]))throw new RuntimeException('Invalid location');
        }else {
            $city=$data['cities'][$value]??null;
            if(!$city||!$p['province_id']||$city['province_id']!=$p['province_id'])throw new RuntimeException('Invalid location');
        }
        $table=$d['scope']==='mm'?'platform_mm_profiles':'platform_accounts';$key=$d['scope']==='mm'?'user_id':'id';
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);if($this->provider($this->account($id)))$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$id]);
        $this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$token]);
        if($d['kind']==='p') {
            if($p['province_id']!=$value)$this->query("UPDATE $table SET province_id=?,city_id=0 WHERE $key=?",[$value,$id]);
            $this->locationPage($id,$d['scope'],'c');return;
        }
        $this->query("UPDATE $table SET city_id=? WHERE $key=?",[$value,$id]);
        if($d['scope']==='mm')$this->mmProfilePage($id);elseif(!$this->welcomeGate($id))$this->profile($id);
    }
    private function removeKeyboard(int $id): void {
        if($this->replyNavigation())$this->query('DELETE FROM platform_navigation WHERE user_id=?',[$id]);
        jijibot('sendMessage',['chat_id'=>$id,'text'=>$this->t($id,'v3.keyboard.cleared'),'reply_markup'=>json_encode(['remove_keyboard'=>true])]);
    }
    private function phonePage(int $id): void {
        if($this->active($id)||$this->pendingBooking($id))throw new RuntimeException('Edit your profile after your session');
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);$this->query("UPDATE platform_accounts SET state='profile:phone',available=0 WHERE id=?",[$id]);
        $this->callbackContext=null;
        jijibot('sendMessage',['chat_id'=>$id,'text'=>$this->t($id,'v3.phone.prompt'),'reply_markup'=>json_encode([
            'keyboard'=>[[['text'=>$this->t($id,'v3.phone.share'),'request_contact'=>true]],[['text'=>$this->t($id,'cancel.button')]]],
            'resize_keyboard'=>true,'one_time_keyboard'=>true,'input_field_placeholder'=>$this->t($id,'v3.phone.share')],JSON_UNESCAPED_UNICODE)]);
    }
    private function receivePhone(int $id,?object $msg): void {
        if(($msg->text??'')===$this->t($id,'cancel.button')){$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->removeKeyboard($id);$this->profile($id);return;}
        $contact=$msg->contact??null;
        if(!$contact||($contact->user_id??0)!=$id||isset($msg->forward_origin)||isset($msg->forward_from)||isset($msg->forward_date)){$this->send($id,$this->t($id,'v3.phone.own'));return;}
        $phone=preg_replace('/[\s()-]/','',(string)$contact->phone_number);
        if(!preg_match('/^\+?[0-9]{7,15}$/',$phone)){$this->send($id,$this->t($id,'v3.phone.own'));return;}
        $this->query("UPDATE platform_accounts SET phone=?,state='' WHERE id=?",[$phone,$id]);$this->removeKeyboard($id);$this->profile($id);
    }
    private function pendingBooking(int $id): ?array {return $this->one("SELECT * FROM platform_requests WHERE kind='booking' AND status IN ('pending','processing') AND expires>? AND (customer=? OR provider=?) LIMIT 1",[time(),$id,$id]);}
    private function bookingProfile(int $id): array {
        $a=$this->account($id);return ['name'=>$a['title'],'interests'=>$this->choiceText($id,$id,'interests')];
    }
    private function requestBooking(int $id,int $target): void {
        if(!$this->accessEligible($id)||!$this->accessEligible($target))throw new RuntimeException('Access denied');
        $a=$this->account($id);$p=$this->knownAccount($target);$r=$this->settings;
        if($this->activeTendency($id)||$this->activeTendency($target))throw new RuntimeException('End your preference test first');
        if(!$r['paid_enabled'])throw new RuntimeException('Paid connections are paused');
        if($this->provider($a)||!$this->provider($p)||$id===$target)throw new RuntimeException('Choose a provider from a normal account');
        if(!$this->readyProfile($a)||!$this->readyProfile($p)||!$a['accepted']||!$p['accepted']||$a['role']==='banned')throw new RuntimeException('Access denied');
        if(!$p['available']||$p['state']!==''||time()-$p['heartbeat']>$r['online_seconds']||$p['strikes']>=$r['strike_limit'])throw new RuntimeException('Provider is no longer available');
        if($this->active($id)||$this->active($target)||$this->pendingBooking($id)||$this->pendingBooking($target))throw new RuntimeException('One participant is already connected');
        if($a['balance']<$r['initial_coins'])throw new RuntimeException('Insufficient coins');
        $token=bin2hex(random_bytes(8));$this->query('INSERT INTO platform_requests(token,kind,customer,provider,terms,created,expires) VALUES(?,?,?,?,?,?,?)',[$token,'booking',$id,$target,json_encode(['rules'=>$r,'profile'=>$this->bookingProfile($id)],JSON_UNESCAPED_UNICODE),time(),time()+120]);
        $this->query('DELETE FROM platform_queue WHERE user_id IN (?,?)',[$id,$target]);
        $this->callbackContext=null;
        $ok=$this->send($target,$this->t($target,'v3.booking.provider',['name'=>$this->e($a['title']),
            'interests'=>$this->e($this->emptyText($target,$this->choiceText($target,$id,'interests'))),
            'preferences'=>$this->e($this->emptyText($target,$this->choiceText($target,$id,'preferences')))]),[
            [[$this->t($target,'v3.approve'),'rq:yes:'.$token,'success'],[$this->t($target,'v3.decline'),'rq:no:'.$token,'danger']]
        ]);
        if(!$ok){$this->query("UPDATE platform_requests SET status='failed' WHERE token=?",[$token]);throw new RuntimeException('Provider is no longer available');}
        $this->send($id,$this->t($id,'v3.booking.wait',['name'=>$this->e($this->mmProfile($target)['title']),'coins'=>$r['initial_coins'],'minutes'=>$r['initial_minutes']]),[[[$this->t($id,'cancel.button'),'rq:no:'.$token]]]);
    }
    private function expireRequests(): void {
        foreach($this->all("SELECT * FROM platform_requests WHERE status IN ('pending','processing') AND expires<=?",[time()]) as $request) {
            $this->query("UPDATE platform_requests SET status='expired' WHERE token=?",[$request['token']]);
            if($request['status']==='pending')foreach([(int)$request['customer'],(int)$request['provider']] as $id)$this->send($id,$this->t($id,'v3.request.expired'),[$this->back($id)]);
        }
    }
    private function exchangeReview(int $id): void {
        $s=$this->active($id);if(!$s||$s['kind']!=='paid'||$s['customer']!=$id)throw new RuntimeException('Information exchange is available to the customer in a special chat');
        $a=$this->account($id);$mm=$this->mmProfile((int)$s['provider']);
        if(!$a['province_id']||!$a['city_id']){$this->send($id,$this->t($id,'v3.exchange.location'),[[[$this->t($id,'status.button'),'status']]]);return;}
        if(!$mm['province_id']||!$mm['city_id']){$this->send($id,$this->t($id,'v3.exchange.mm.location'),[[[$this->t($id,'status.button'),'status']]]);return;}
        if($this->one("SELECT token FROM platform_requests WHERE kind='exchange' AND session_id=? AND status IN ('pending','processing','accepted')",[$s['id']]))throw new RuntimeException('An information exchange already exists for this chat');
        $coins=$this->settings['info_exchange_coins'];if($a['balance']<$coins)throw new RuntimeException('Insufficient coins');
        $token=$this->token($id,'exchange_review',['session'=>(int)$s['id'],'coins'=>$coins],120);
        $this->send($id,$this->t($id,'v3.exchange.review',['coins'=>$coins,'identity'=>$this->e($this->telegramIdentity($a)),'location'=>$this->e($this->locationText($id,$a))]),[
            [[$this->t($id,'v3.exchange.send'),'sx:'.$token,'primary'],[$this->t($id,'cancel.button'),'status']]
        ]);
    }
    private function submitExchange(int $id,string $token): void {
        $draft=$this->uiToken($id,$token,'exchange_review');$s=$this->active($id);$coins=$draft['data']['coins'];
        if(!$s||$s['kind']!=='paid'||$s['customer']!=$id||$s['id']!=$draft['data']['session']||$this->settings['info_exchange_coins']!=$coins)throw new RuntimeException('This action expired. Open the panel again.');
        if($this->one("SELECT token FROM platform_requests WHERE kind='exchange' AND session_id=? AND status IN ('pending','processing','accepted')",[$s['id']]))throw new RuntimeException('An information exchange already exists for this chat');
        $a=$this->account($id);$p=$this->mmProfile((int)$s['provider']);
        $terms=['coins'=>$coins,'customer'=>$this->exchangeInfo($id,$a),'provider'=>$this->exchangeInfo((int)$s['provider'],$p)];
        $this->query('UPDATE platform_ui SET used=1 WHERE token=? AND used=0',[$token]);
        $request=bin2hex(random_bytes(8));$this->query('INSERT INTO platform_requests(token,kind,customer,provider,session_id,terms,created,expires) VALUES(?,?,?,?,?,?,?,?)',[$request,'exchange',$id,$s['provider'],$s['id'],json_encode($terms,JSON_UNESCAPED_UNICODE),time(),time()+120]);
        $this->callbackContext=null;$provider=(int)$s['provider'];
        $ok=$this->send($provider,$this->t($provider,'v3.exchange.provider',['name'=>$this->e($a['title']),'coins'=>$coins,'identity'=>$this->e($terms['provider']['identity']),'location'=>$this->e($this->locationText($provider,$p))]),[
            [[$this->t($provider,'v3.approve'),'rq:yes:'.$request,'success'],[$this->t($provider,'v3.decline'),'rq:no:'.$request,'danger']]
        ]);
        if(!$ok){$this->query("UPDATE platform_requests SET status='failed' WHERE token=?",[$request]);throw new RuntimeException('Provider is no longer available');}
        $this->send($id,$this->t($id,'v3.exchange.wait'),[[[$this->t($id,'cancel.button'),'rq:no:'.$request]]]);
    }
    private function telegramIdentity(array $a): string {return $a['telegram_username']!==''?'@'.$a['telegram_username']:(string)$a['id'];}
    private function exchangeInfo(int $id,array $profile): array {
        $a=$this->account($id);return ['name'=>$profile['title'],'identity'=>$this->telegramIdentity($a),'province_id'=>(int)$profile['province_id'],'city_id'=>(int)$profile['city_id']];
    }
    private function respondRequest(int $id,string $token,bool $approve): void {
        $request=$this->one("SELECT * FROM platform_requests WHERE token=? AND status='pending' AND expires>?",[$token,time()]);
        if(!$request||($approve?$request['provider']!=$id:!in_array($id,[(int)$request['provider'],(int)$request['customer']],true)))throw new RuntimeException('This action expired. Open the panel again.');
        $customer=(int)$request['customer'];$provider=(int)$request['provider'];$terms=json_decode($request['terms'],true,512,JSON_THROW_ON_ERROR);
        if(!$approve) {
            $this->query("UPDATE platform_requests SET status='declined' WHERE token=? AND status='pending'",[$token]);
            $this->callbackContext=null;foreach([$customer,$provider] as $who)$this->send($who,$this->t($who,'v3.request.declined'),[$this->back($who)]);return;
        }
        if($this->account($customer)['role']==='banned'||!$this->provider($this->account($provider)))throw new RuntimeException('Access denied');
        if($request['kind']==='booking') {
            if(json_encode($terms['rules'])!==json_encode($this->settings)||$terms['profile']!==$this->bookingProfile($customer)){
                $this->query("UPDATE platform_requests SET status='failed' WHERE token=?",[$token]);$this->send($customer,$this->t($customer,'v3.request.failed'),[$this->back($customer)]);
                throw new RuntimeException('Connection terms changed. Reopen the provider profile and review the new price before confirming.');
            }
            $this->query("UPDATE platform_requests SET status='processing' WHERE token=? AND status='pending'",[$token]);
            try {$this->callbackContext=null;$this->connectPaid($customer,$provider,$terms['rules']);$this->query("UPDATE platform_requests SET status='accepted' WHERE token=?",[$token]);}
            catch(Throwable $e){$this->query("UPDATE platform_requests SET status='failed' WHERE token=?",[$token]);$this->send($customer,$this->t($customer,'v3.request.failed'),[$this->back($customer)]);throw $e;}
            return;
        }
        $s=$this->active($customer);if(!$s||$s['kind']!=='paid'||$s['provider']!=$provider||$s['id']!=$request['session_id']||time()>=(int)$s['paid_until'])throw new RuntimeException('This action expired. Open the panel again.');
        // Both parties approve exactly the displayed snapshots; never substitute a normal MM profile.
        $a=$this->account($customer);$p=$this->mmProfile($provider);
        if($terms['customer']!==$this->exchangeInfo($customer,$a)||$terms['provider']!==$this->exchangeInfo($provider,$p))throw new RuntimeException('This action expired. Open the panel again.');
        $this->db->begin_transaction();
        try {
            if(!$this->query("UPDATE platform_requests SET status='accepted' WHERE token=? AND status='pending'",[$token])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
            $this->wallet($customer,-$terms['coins'],'information exchange','exchange:'.$token);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        $this->callbackContext=null;$delivered=true;
        foreach([$customer,$provider] as $who){$peer=$terms[$who===$customer?'provider':'customer'];$delivered=$this->send($who,$this->t($who,'v3.exchange.done',['name'=>$this->e($peer['name']),'identity'=>$this->e($peer['identity']),'location'=>$this->e($this->locationText($who,$peer))]))&&$delivered;}
        if(!$delivered){$this->db->begin_transaction();try{$this->wallet($customer,$terms['coins'],'information exchange refund','exchange-refund:'.$token);$this->query("UPDATE platform_requests SET status='failed' WHERE token=?",[$token]);$this->db->commit();}catch(Throwable $e){$this->db->rollback();throw $e;}$this->send($customer,$this->t($customer,'v3.exchange.refund',['coins'=>$terms['coins']]));}
    }
    private function chatKeyboard(int $id): void {
        if($this->replyNavigation())$this->query('DELETE FROM platform_navigation WHERE user_id=?',[$id]);
        $s=$this->active($id);if(!$s)return;$rows=[[
            ['text'=>$this->t($id,'v3.chat.end')],['text'=>$this->t($id,'v3.chat.status')]],
            [['text'=>$this->t($id,'v3.chat.react')],['text'=>$this->t($id,'v3.chat.report')]]];
        if($s['kind']==='paid'&&$s['customer']==$id)$rows[]=[['text'=>$this->t($id,'v3.chat.exchange')]];
        jijibot('sendMessage',['chat_id'=>$id,'text'=>$this->t($id,'v3.chat.controls'),'reply_markup'=>json_encode(['keyboard'=>$rows,'resize_keyboard'=>true,'is_persistent'=>true,'one_time_keyboard'=>false,'input_field_placeholder'=>$this->t($id,'v3.chat.placeholder')],JSON_UNESCAPED_UNICODE)]);
    }
    private function chatKeyboardInput(int $id,?object $msg,string $text): bool {
        $actions=['end'=>'close','status'=>'status','report'=>'report','exchange'=>'exchange','react'=>'reactionhelp'];$action=null;
        foreach(['en','fa'] as $lang)foreach($actions as $key=>$value)if($text===BotLocale::text($lang,'v3.chat.'.$key))$action=$value;
        $s=$this->active($id);
        if($action!==null) {
            if(!$s){$this->removeKeyboard($id);$this->home($id);return true;}
            if($action==='reactionhelp')$this->send($id,$this->t($id,'v3.reaction.help'));
            elseif($action==='exchange')$this->exchangeReview($id);else $this->action($id,$action);return true;
        }
        if($s&&isset($msg->reply_to_message)&&in_array($text,array_merge(array_values(self::REACTIONS),['❤️','✖️']),true)) {
            $map=$this->reactionMap($id,(int)$msg->reply_to_message->message_id);
            if($map&&$map['target_chat']==$id){$emoji=$text==='❤️'?'❤':($text==='✖️'?'':$text);$this->mirrorReaction($id,$map,$emoji,time(),true);return true;}
        }return false;
    }
    private function encryptChatText(?string $text): ?string {
        if($text===null)return null;$key=file_get_contents(dirname(__DIR__).'/runtime/archive.key');
        if($key===false||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Chat archive is unavailable');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($text,$nonce,$key));
    }
    private function profileUpdateAction(int $id,string $action): bool {
        if($this->onboardingAction($id,$action))return true;
        if(preg_match('/^editother:(\d+):(interests|preferences)$/',$action,$m)){$this->openChoices($id,$m[2],(int)$m[1]);return true;}
        if(preg_match('/^interestclear:(-?\d{1,16}):(normal|mm)$/',$action,$m)){$this->saveInterestText($id,(int)$m[1],$m[2]==='mm','');return true;}
        if($action==='gender'){$this->genderPage($id);return true;}
        if($action==='phone'){$this->phonePage($id);return true;}
        if($action==='phoneclear'){if($this->active($id))throw new RuntimeException('Edit your profile after your session');$this->query("UPDATE platform_accounts SET phone='' WHERE id=?",[$id]);$this->profile($id);return true;}
        if($action==='mmprofile'){$this->mmProfilePage($id);return true;}
        if(preg_match('/^mmfield:(title|bio)$/',$action,$m)){if(!$this->provider($this->account($id)))throw new RuntimeException('Provider account required');$this->prompt($id,'mmfield:'.$m[1],$this->t($id,'profile.prompt',['field'=>$this->t($id,'field.'.$m[1])]));return true;}
        if(preg_match('/^loc:(normal|mm):(p|c):(\d+)$/',$action,$m)){$this->locationPage($id,$m[1],$m[2],(int)$m[3]);return true;}
        if(preg_match('/^locpage:([a-f0-9]{16}):(\d+)$/',$action,$m)){$d=$this->uiToken($id,$m[1],'location')['data'];$this->locationPage($id,$d['scope'],$d['kind'],(int)$m[2],$d['search']);return true;}
        if(preg_match('/^locpick:([a-f0-9]{16}):(\d+)$/',$action,$m)){$this->pickLocation($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^locsearch:(normal|mm)$/',$action,$m)){$this->locationOwner($id,$m[1]);$this->prompt($id,'locsearch:'.$m[1],$this->t($id,'v3.city.search.prompt'));return true;}
        if($action==='exchange'){$this->exchangeReview($id);return true;}
        if(preg_match('/^sx:([a-f0-9]{16})$/',$action,$m)){$this->submitExchange($id,$m[1]);return true;}
        if(preg_match('/^rq:(yes|no):([a-f0-9]{16})$/',$action,$m)){$this->respondRequest($id,$m[2],$m[1]==='yes');return true;}
        return false;
    }
    private function saveInterestText(int $id,int $target,bool $mm,string $text): void {
        if($target!==$id&&!$this->super($id))throw new RuntimeException('Superadmin access required');
        $account=$this->knownAccount($target);
        if($mm&&!$this->provider($account))throw new RuntimeException('Provider account required');
        if($this->active($id)||$this->active($target)||$this->pendingBooking($id)||$this->pendingBooking($target))throw new RuntimeException('Edit your profile after your session');
        if(mb_strlen($text)>300)throw new RuntimeException('Enter 1–300 characters');
        if($text!==''&&$this->moderatedProfile($id,$text))return;
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);
        if($this->provider($account))$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$target]);
        if($mm){$this->mmProfile($target);$this->query('UPDATE platform_mm_profiles SET interests=? WHERE user_id=?',[$text,$target]);}
        else $this->query('UPDATE platform_accounts SET interests=? WHERE id=?',[$text,$target]);
        $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);
        if($target!==$id){$this->audit($id,'profile_edit',$target.' interests');$this->manage($id,$target);}
        elseif($mm)$this->mmProfilePage($id);else $this->profile($id);
    }
    private function profileUpdateInput(int $id,array $a,string $text): bool {
        if(preg_match('/^interesttext:(-?\d{1,16}):(normal|mm)$/',$a['state'],$m)) {
            $text=trim($text);if($text==='')throw new RuntimeException('Enter 1–300 characters');
            $this->saveInterestText($id,(int)$m[1],$m[2]==='mm',$text);return true;
        }
        if(preg_match('/^ui:(?:catalog|label):/',$a['state']))throw new RuntimeException('This action expired. Open the panel again.');
        if(preg_match('/^editother:(\d+):(interests|preferences)$/',$a['state'],$m)){$this->openChoices($id,$m[2],(int)$m[1]);return true;}
        if($a['state']==='onboard:name') {
            if(mb_strlen($text)<1||mb_strlen($text)>80||str_contains($text,"\n"))throw new RuntimeException('Enter 1–80 characters');
            if($this->moderatedProfile($id,$text))return true;
            $this->query("UPDATE platform_accounts SET title=?,name_set=1,state='' WHERE id=?",[$text,$id]);$this->onboarding($id);return true;
        }
        if(preg_match('/^mmfield:(title|bio)$/',$a['state'],$m)) {
            if(!$this->provider($a)||$this->active($id)||$this->pendingBooking($id))throw new RuntimeException('Edit your profile after your session');
            $limit=$m[1]==='title'?80:1000;if(mb_strlen($text)<1||mb_strlen($text)>$limit)throw new RuntimeException('Value is empty or too long');
            if($this->moderatedProfile($id,$text))return true;$this->mmProfile($id);
            $this->query('UPDATE platform_mm_profiles SET '.$m[1].'=? WHERE user_id=?',[$text,$id]);$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->mmProfilePage($id);return true;
        }
        if(preg_match('/^locsearch:(normal|mm)$/',$a['state'],$m)) {if(mb_strlen($text)>60)throw new RuntimeException('Value is empty or too long');$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->locationPage($id,$m[1],'c',0,$text);return true;}
        // Redirect obsolete forms to the current single-field editor.
        if(in_array($a['state'],['edit:interests','edit:preferences'],true)){$this->openChoices($id,substr($a['state'],5));return true;}
        return false;
    }
}
