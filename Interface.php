<?php
/** Private-chat interface: bounded cards, inline navigation, persisted drafts and confirmations. */
trait PlatformInterface
{
    private const CATEGORIES=[
        'quiz'=>['tendency_enabled','tendency_seconds','tendency_title','tendency_title_fa'],
        'normal'=>['normal_enabled','normal_coins','normal_seconds','queue_seconds'],
        'provider'=>['paid_enabled','initial_coins','initial_minutes','interval_coins','interval_minutes','provider_leave_minutes','early_refund_coins','online_seconds'],
        'payments'=>['info_exchange_coins','stars_pack','coins_pack','test_pack_enabled','test_stars','test_coins'],
        'brand'=>['reply_navigation','bot_title','bot_title_fa','miss_title','miss_title_fa','mister_title','mister_title_fa','welcome','welcome_fa','support','support_fa','support_username'],
        'safety'=>['age_required','onboarding_required','response_seconds','silence_seconds','unanswered_limit','strike_limit','moderation_block_phones','moderation_block_links','moderation_block_ids','moderation_block_forwards','moderation_block_contacts']
    ];
    private function uiButton(int $id,string $key,string|array $action,?string $style=null,array $values=[]): array {
        return [$this->t($id,$key,$values),$action,$style];
    }
    private function short(string $text,int $limit=180): string {
        $text=trim(preg_replace('/\s+/u',' ',$text));return mb_strlen($text)>$limit?mb_substr($text,0,$limit-1).'…':$text;
    }
    private function emptyText(int $id,string $text): string {return $text!==''?$text:$this->t($id,'ui.empty');}
    private function supportRow(int $id): array {return [$this->uiButton($id,'ui.support','support')];}
    private function supportPage(int $id,string $back='panelhome'): void {
        $name=$this->settings['support_username'];
        $this->send($id,$this->t($id,'ui.support.body',['username'=>$this->e($name),'user'=>$id,'support'=>$this->e($this->content($id,'support'))]),[
            [$this->uiButton($id,'ui.support.open',['url'=>'https://t.me/'.$name],'primary')],
            [$this->uiButton($id,'ui.support.copy',['copy_text'=>['text'=>'@'.$name]])],[[$this->t($id,'ui.back'),$back]]
        ]);
    }
    private function home(int $id): void {
        $this->setPanel($id,'normal');$a=$this->account($id);$s=$this->active($id);
        $rows=$s?[
            [$this->uiButton($id,'status.button','status','primary'),$this->uiButton($id,'close.button','close','danger')]
        ]:[[$this->uiButton($id,'ui.normal.start','normal','primary',['coins'=>$this->settings['normal_coins']])]];
        $rows[]=[[$this->t($id,'special.chat'),'specialsoon']];
        $rows[]=[[$this->t($id,'quiz.open'),'quiz','primary']];
        $rows[]=[[$this->t($id,'online.roles'),'onlineroles']];
        $rows[]=[[$this->t($id,'profile.button'),'profile'],[$this->t($id,'wallet.button'),'wallet']];
        $rows[]=[[$this->t($id,'anon.open'),'anonlink'],[$this->t($id,'growth.open'),'freecoins']];
        $rows[]=[[$this->t($id,'features.title'),'features']];
        $queue=$this->one('SELECT user_id FROM platform_queue WHERE user_id=?',[$id]);
        if($queue)$rows[]=[[$this->t($id,'normal.cancel'),'cancelqueue','danger']];
        $this->send($id,$this->t($id,'ui.home',['title'=>$this->e($this->content($id,'bot_title')),'welcome'=>$this->e($this->content($id,'welcome')),'balance'=>$a['balance'],'status'=>$this->t($id,$s?'ui.connected':($queue?'ui.waiting':'ui.ready'))]),$rows);
    }
    private function onlineRoles(int $id): void {
        $this->send($id,$this->t($id,'online.body'),[
            [['✨ '.$this->content($id,'miss_title'),'list:miss']],
            [['✨ '.$this->content($id,'mister_title'),'list:mister']],
            [[$this->t($id,'online.submissive'),'onlinerole:submissive']],
            [[$this->t($id,'online.switch'),'onlinerole:switch']],
            [[$this->t($id,'ui.back'),'home']]
        ]);
    }
    private function features(int $id): void {
        $this->send($id,$this->t($id,'features.body'),[
            [[$this->t($id,'options.button'),'features:options']],
            [[$this->t($id,'ui.guide'),'features:guide:0']],
            [[$this->t($id,'ui.support'),'features:support']],
            [[$this->t($id,'rules.menu'),'features:rules']],$this->back($id)
        ]);
    }
    private function options(int $id,string $back='panelhome'): void {
        $panel=$this->account($id)['panel'];
        $rows=[];if(in_array($panel,['normal','mm'],true))$rows[]=[[$this->t($id,'field.interests'),$panel==='mm'?'choose:mm_interests':'choose:interests']];
        $this->send($id,$this->t($id,'options.body',['language'=>$this->t($id,'language.name')]),array_merge($rows,[
            [[$this->t($id,'language.button'),'language']],
            [$this->uiButton($id,'ui.history','history:'.$id.':0'),$this->uiButton($id,'ui.ledger','ledger:'.$id.':0')],
            [[$this->t($id,'ui.back'),$back]]
        ]));
    }
    private function choiceText(int $recipient,int $target,string $scope,bool $legacy=true): string {
        if($scope==='mm_interests')return (string)($this->mmProfile($target)['interests']??'');
        return $scope==='interests'?(string)($this->account($target)['interests']??''):'';
    }
    private function profile(int $id): void {
        $a=$this->account($id);
        if($a['panel']==='mm'&&$this->provider($a)){$this->mmProfilePage($id);return;}
        $values=['title'=>$this->e($this->emptyText($id,$this->short($a['title'],60))),'bio'=>$this->e($this->emptyText($id,$this->short($a['bio']??''))),
            'interests'=>$this->e($this->emptyText($id,$this->choiceText($id,$id,'interests'))),
            'preferences'=>$this->e($this->emptyText($id,$this->short($this->choiceText($id,$id,'preferences'),120)))];
        $this->send($id,$this->t($id,'ui.profile',$values)."\n\n".$this->personalDetails($id)."\n\n".$this->privateOrientation($id)."\n\n".$this->t($id,'age.profile',['age'=>$this->effectiveAge($a)?:$this->t($id,'ui.empty')]),[
            [[$this->t($id,'field.title'),'edit:title'],[$this->t($id,'field.bio'),'edit:bio']],
            [[$this->t($id,'field.interests'),'choose:interests']],
            [[$this->t($id,'quiz.profile'),'quiz'],[$this->t($id,'welcome.review'),'welcome:orientation']],
            [[$this->t($id,'v3.gender.button'),'gender'],[$this->t($id,'v3.phone.button'),'phone']],
            ...($a['phone']!==''?[[[$this->t($id,'v3.phone.clear'),'phoneclear']]]:[]),
            [[$this->t($id,'v3.province'),'loc:normal:p:0'],[$this->t($id,'v3.city'),'loc:normal:c:0']],
            [$this->uiButton($id,'ui.preview','profilepreview'),$this->uiButton($id,'ui.guide','guide:'.$a['panel'].':1')],$this->back($id)
        ]);
    }
    private function profilePreview(int $id,int $target): void {
        $a=$this->account($target);
        $text=$this->t($id,'ui.profile.preview',['title'=>$this->e($this->publicText($id,$a['title'])),'bio'=>$this->e($this->emptyText($id,$this->publicText($id,$a['bio']??''))),
            'interests'=>$this->e($this->emptyText($id,$this->choiceText($id,$target,'interests'))),'preferences'=>$this->e($this->emptyText($id,$this->choiceText($id,$target,'preferences')))]);
        $this->send($id,$text,[[[$this->t($id,'ui.back'),$target===$id?'profile':'manage:'.$target]]]);
    }
    private function studio(int $id): void {
        $a=$this->account($id);if(!$this->provider($a))throw new RuntimeException('Provider account required');
        $this->setPanel($id,'mm');$s=$this->active($id);
        $online=$a['available'] && time()-(int)$a['heartbeat']<=$this->settings['online_seconds'];
        $rows=$s?[[[$this->t($id,'status.button'),'status','primary'],[$this->t($id,'close.button'),'close','danger']]]:
            [[[$this->t($id,$a['available']?'mm.offline':'mm.online'),'online',$a['available']?'danger':'success']]];
        if($a['available']&&!$s)$rows[]=[[$this->t($id,'mm.waiting'),'heartbeat']];
        $rows[]=[[$this->t($id,'profile.button'),'profile'],[$this->t($id,'field.interests'),'choose:mm_interests']];
        $rows[]=[[$this->t($id,'rules.button'),'rules'],$this->uiButton($id,'ui.guide','guide:mm:0')];
        $rows[]=[[$this->t($id,'options.button'),'options'],$this->uiButton($id,'ui.support','support')];
        $this->send($id,$this->t($id,'ui.mm',['availability'=>$this->t($id,$s?'ui.connected':($online?'online':'offline')),'strikes'=>$a['strikes'],
            'interests'=>$this->e($this->emptyText($id,$this->choiceText($id,$id,'mm_interests'))),'minutes'=>max(1,(int)floor($this->settings['online_seconds']/60))]),$rows);
    }
    private function panel(int $id,bool $super): void {
        if($super?!$this->super($id):!$this->admin($id))throw new RuntimeException('Access denied');
        $this->setPanel($id,$super?'superadmin':'admin');
        $stats=$this->one("SELECT (SELECT COUNT(*) FROM platform_accounts) AS users,(SELECT COUNT(*) FROM platform_sessions WHERE status='active') AS sessions,(SELECT COUNT(*) FROM platform_queue) AS waiting");
        $rows=[[$this->uiButton($id,'ui.people','directory:0','primary'),$this->uiButton($id,'ui.search','search')]];
        $rows[]=[[$this->t($id,'campaign.ads'),'campaigns:ad:0'],[$this->t($id,'campaign.broadcast'),'campaigns:broadcast:0']];
        $rows[]=[[$this->t($id,'join.panel'),'channel']];
        if($super) {
            $rows[]=[[$this->t($id,'quiz.settings'),'category:quiz']];
            $rows[]=[[$this->t($id,'ui.category.normal'),'category:normal'],[$this->t($id,'ui.category.provider'),'category:provider']];
            $rows[]=[[$this->t($id,'ui.category.payments'),'category:payments'],[$this->t($id,'ui.category.brand'),'category:brand']];
            $rows[]=[[$this->t($id,'ui.category.safety'),'category:safety'],[$this->t($id,'super.audit'),'activity:audit:0']];
        }
        $rows[]=[[$this->t($id,'moderation.button'),'activity:moderation:0'],$this->uiButton($id,'ui.reports','activity:reports:0')];
        $rows[]=[$this->uiButton($id,'ui.guide','guide:'.($super?'superadmin':'admin').':0'),[$this->t($id,'options.button'),'options']];
        $rows[]=$this->supportRow($id);
        $this->send($id,$this->t($id,$super?'ui.super':'ui.admin',$stats),$rows);
    }
    private function pageNav(int $id,string $route,int $page,int $pages): array {
        $rows=[];$nav=[];
        if($page>0)$nav[]=[$this->t($id,'previous'),$route.($page-1)];
        if($page+1<$pages)$nav[]=[$this->t($id,'next'),$route.($page+1)];
        if($nav)$rows[]=$nav;return $rows;
    }
    private function paging(int $total,int $requested,int $size): array {
        $pages=max(1,(int)ceil($total/$size));return [min(max(0,$requested),$pages-1),$pages];
    }
    private function pageHeading(int $id,int $page,int $pages,int $total): string {return $this->t($id,'ui.page',['page'=>$page+1,'pages'=>$pages,'total'=>$total]);}
    private function directory(int $id,int $requested,string $filter='all'): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');
        if(!in_array($filter,['all','user','miss','mister','banned','admin','superadmin'],true))throw new RuntimeException('Unknown category');
        $where=$filter==='all'?'1=1':'role=?';$args=$filter==='all'?[]:[$filter];
        $total=(int)$this->one('SELECT COUNT(*) AS n FROM platform_accounts WHERE '.$where,$args)['n'];[$page,$pages]=$this->paging($total,$requested,6);
        $users=$this->all('SELECT id,title,role,balance FROM platform_accounts WHERE '.$where.' ORDER BY id LIMIT 6 OFFSET '.($page*6),$args);
        $rows=[];foreach($users as $u)$rows[]=[[$this->short($u['title']?:$this->t($id,'role.'.$u['role']),28).' · '.$this->t($id,'role.'.$u['role']),'manage:'.$u['id']]];
        $rows=array_merge($rows,$this->pageNav($id,'people:'.$filter.':',$page,$pages));
        $rows[]=[[$this->t($id,'ui.filter'),'peoplefilters'],[$this->t($id,'ui.search'),'search']];$rows[]=$this->back($id);
        $this->send($id,$this->t($id,'ui.people.body')."\n".$this->pageHeading($id,$page,$pages,$total).(!$users?"\n".$this->t($id,'ui.no.records'):''),$rows);
    }
    private function knownAccount(int $target): array {
        $a=$this->one('SELECT * FROM platform_accounts WHERE id=?',[$target]);
        if(!$a)throw new RuntimeException('This user has not opened the new bot panel yet');return $a;
    }
    private function effectiveRole(int $target,array $a): string {return $this->super($target)?'superadmin':($this->admin($target)?'admin':$a['role']);}
    private function manage(int $id,int $target): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');$a=$this->knownAccount($target);
        $rows=[[$this->uiButton($id,'ui.give','give:'.$target,'primary'),$this->uiButton($id,'ui.ledger','ledger:'.$target.':0')],
            [$this->uiButton($id,'ui.history','history:'.$target.':0')]];
        if($this->super($id)) {
            $rows[]=[$this->uiButton($id,'ui.role','roles:'.$target),$this->uiButton($id,'ui.edit.profile','otherprofile:'.$target)];
            if($this->provider($a))$rows[]=[$this->uiButton($id,'ui.reset','review:reset:'.$target)];
            $rows[]=[$this->uiButton($id,$a['role']==='banned'?'ui.unban':'ui.ban','review:'.($a['role']==='banned'?'unban':'ban').':'.$target,$a['role']==='banned'?'success':'danger')];
        }
        $rows[]=[[$this->t($id,'ui.back.people'),'directory:0']];
        $this->send($id,$this->t($id,'ui.account',['title'=>$this->e($this->short($a['title']?:$this->t($id,'ui.empty'),60)),'user'=>$target,
            'role'=>$this->t($id,'role.'.$this->effectiveRole($target,$a)),'balance'=>$a['balance'],'strikes'=>$a['strikes'],'status'=>$this->t($id,$a['role']==='banned'?'role.banned':($this->active($target)?'ui.connected':'ui.ready'))]),$rows);
    }
    private function history(int $id,int $target,int $requested,bool $ledger=false): void {
        if($target!==$id&&!$this->admin($id))throw new RuntimeException('Access denied');$a=$this->knownAccount($target);
        $table=$ledger?'platform_ledger':'platform_sessions';$where=$ledger?'user_id=?':'(customer=? OR provider=?)';$args=$ledger?[$target]:[$target,$target];$size=$ledger?6:4;
        $total=(int)$this->one("SELECT COUNT(*) AS n FROM $table WHERE $where",$args)['n'];[$page,$pages]=$this->paging($total,$requested,$size);
        $entries=$this->all("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT $size OFFSET ".($page*$size),$args);
        $text=$this->t($id,$ledger?'ui.ledger.heading':'ui.history.heading',['title'=>$this->e($this->short($a['title'],40))])."\n".$this->pageHeading($id,$page,$pages,$total);
        foreach($entries as $entry) {
            if($ledger)$text.="\n\n".$this->t($id,'ui.ledger.entry',['time'=>gmdate('Y-m-d H:i',(int)$entry['created']),'amount'=>($entry['amount']>0?'+':'').$entry['amount'],'reason'=>$this->e($this->t($id,'ledger.'.$entry['reason']))]);
            else {
                $r=json_decode($entry['rules'],true)?:[];
                $charge=$entry['kind']==='paid'&&$entry['provider']==$target?0:$entry['charged'];
                $refund=$entry['kind']==='paid'&&$entry['provider']==$target?0:$entry['refunded'];
                $text.="\n\n".$this->t($id,'ui.history.entry',['time'=>gmdate('Y-m-d H:i',(int)$entry['started']),'kind'=>$this->t($id,'kind.'.$entry['kind']),
                    'status'=>$this->t($id,'status.'.$entry['status']),'coins'=>$charge,'refund'=>$refund,'reason'=>$entry['reason']?$this->t($id,'reason.'.$entry['reason']):'—']);
            }
        }
        if(!$entries)$text.="\n\n".$this->t($id,'ui.no.records');
        $rows=$this->pageNav($id,($ledger?'ledger:':'history:').$target.':',$page,$pages);
        $rows[]=[[$this->t($id,'ui.back'),$target===$id?'options':'manage:'.$target]];$this->send($id,$text,$rows);
    }
    private function token(int $id,string $kind,array $payload,int $ttl=600): string {
        $token=bin2hex(random_bytes(8));$this->query('INSERT INTO platform_ui(token,actor,kind,payload,expires) VALUES(?,?,?,?,?)',[$token,$id,$kind,json_encode($payload,JSON_UNESCAPED_UNICODE),time()+$ttl]);return $token;
    }
    private function uiToken(int $id,string $token,?string $kind=null): array {
        $row=$this->one('SELECT * FROM platform_ui WHERE token=? AND actor=? AND used=0 AND expires>?',[$token,$id,time()]);
        if(!$row||($kind!==null&&$row['kind']!==$kind))throw new RuntimeException('This action expired. Open the panel again.');
        $row['data']=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);return $row;
    }
    // Old profile buttons lead to the single text field; catalog drafts never apply.
    private function openChoices(int $id,string $scope,?int $target=null): void {
        $target??=$id;
        if($target!==$id&&!$this->super($id))throw new RuntimeException('Superadmin access required');
        $a=$this->knownAccount($target);$mm=in_array($scope,['offered','mm_interests'],true);
        if($mm&&!$this->provider($a))throw new RuntimeException('Provider account required');
        if($this->active($id)||$this->active($target)||$this->pendingBooking($id)||$this->pendingBooking($target))throw new RuntimeException('Edit your profile after your session');
        $scope=$mm?'mm_interests':'interests';
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);
        if($this->provider($a))$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$target]);
        $state='interesttext:'.$target.':'.($mm?'mm':'normal');
        $back=$target!==$id?'manage:'.$target:($mm?'mmprofile':'profile');
        $this->prompt($id,$state,$this->t($id,'interests.prompt',['value'=>$this->e($this->emptyText($id,$this->choiceText($id,$target,$scope)))]),[
            [[$this->t($id,'interests.clear'),'interestclear:'.$target.':'.($mm?'mm':'normal'),'danger'],[$this->t($id,'cancel.button'),$back]]
        ]);
    }
    private function guide(int $id,string $role,int $page,string $back='panelhome'): void {
        if(!in_array($role,['normal','mm','admin','superadmin'],true))throw new RuntimeException('Unknown category');
        if(($role==='mm'&&!$this->provider($this->account($id)))||($role==='admin'&&!$this->admin($id))||($role==='superadmin'&&!$this->super($id)))throw new RuntimeException('Access denied');
        $page=min(2,max(0,$page));$values=$this->settings;
        $values['miss_title']=$this->e($this->content($id,'miss_title'));$values['mister_title']=$this->e($this->content($id,'mister_title'));
        $rows=$this->pageNav($id,$back==='features'?'features:guide:':'guide:'.$role.':',$page,3);
        $rows[]=$this->supportRow($id);$rows[]=[[$this->t($id,'ui.back'),$back]];
        $this->send($id,$this->t($id,'guide.'.$role.'.'.$page,$values)."\n\n".$this->pageHeading($id,$page,3,3),$rows);
    }
    private function normalQuote(): array {return array_intersect_key($this->settings,array_flip(self::CATEGORIES['normal']));}
    private function normalHash(): string {return substr(hash('sha256',json_encode($this->normalQuote())),0,12);}
    private function normalReview(int $id): void {
        if($this->tendencyGuard($id))return;
        if($this->provider($this->account($id)))throw new RuntimeException('Provider profiles cannot enter normal matching');
        if($this->active($id)||$this->pendingBooking($id))throw new RuntimeException('End your current connection first');
        if(!$this->settings['normal_enabled'])throw new RuntimeException('Normal connections are paused');
        $this->send($id,$this->t($id,'ui.normal.review',$this->normalQuote()),[
            [$this->uiButton($id,'ui.normal.join','nj:'.$this->normalHash(),'primary',['coins'=>$this->settings['normal_coins']])],
            [[$this->t($id,'wallet.button'),'wallet'],[$this->t($id,'ui.back'),'home']]
        ]);
    }
    private function category(int $id,string $category): void {
        if(!$this->super($id))throw new RuntimeException('Superadmin access required');
        if(!isset(self::CATEGORIES[$category]))throw new RuntimeException('Unknown category');
        $rows=[];$rendered=[];foreach(self::CATEGORIES[$category] as $key) {
            if(in_array($key,$rendered,true))continue;
            $value=$this->settings[$key];$display=$this->isSwitch($key)?$this->t($id,(int)$value?'ui.enabled':'ui.disabled'):$this->short((string)$value,28);
            if(in_array($key.'_fa',self::CATEGORIES[$category],true)) {
                $rows[]=[[$this->t($id,'setting.'.$key),'setting:'.$key],[$this->t($id,'setting.'.$key.'_fa'),'setting:'.$key.'_fa']];$rendered[]=$key.'_fa';
            }else $rows[]=[[$this->t($id,'setting.'.$key).' · '.$display,'setting:'.$key]];
        }
        $rows[]=$this->back($id);$this->send($id,$this->t($id,'ui.category.body',['category'=>$this->t($id,'ui.category.'.$category)]),$rows);
    }
    private function settingCategory(string $key): string {
        foreach(self::CATEGORIES as $category=>$keys)if(in_array($key,$keys,true))return $category;
        throw new RuntimeException('Unknown setting');
    }
    private function isSwitch(string $key): bool {return in_array($key,['normal_enabled','paid_enabled','test_pack_enabled','tendency_enabled','reply_navigation','onboarding_required','ads_enabled','age_required'],true)||str_starts_with($key,'moderation_block_');}
    private function settingPage(int $id,string $key): void {
        if(!$this->super($id))throw new RuntimeException('Superadmin access required');$category=$this->settingCategory($key);$value=$this->settings[$key];$rows=[];
        if($this->isSwitch($key))$rows[]=[[$this->t($id,'ui.enabled'),'sv:'.$key.':1','success'],[$this->t($id,'ui.disabled'),'sv:'.$key.':0','danger']];
        elseif(is_int(SessionRules::defaults()[$key])) {
            $presets=str_contains($key,'seconds')?[60,120,300,600]:[1,2,5,10,50,100];
            foreach(array_chunk($presets,3) as $chunk)$rows[]=array_map(fn($n)=>[(string)$n,'sv:'.$key.':'.$n],$chunk);
            $rows[]=[[$this->t($id,'ui.custom'),'sc:'.$key]];
        }else $rows[]=[[$this->t($id,'ui.edit.text'),'sc:'.$key,'primary']];
        $rows[]=[[$this->t($id,'ui.back'),'category:'.$category]];
        $this->send($id,$this->t($id,'ui.setting',['setting'=>$this->t($id,'setting.'.$key),'value'=>$this->e($this->short((string)$value,300))]),$rows);
    }
    private function settingReview(int $id,string $key,string $value): void {
        if(!$this->super($id))throw new RuntimeException('Superadmin access required');$category=$this->settingCategory($key);
        if($key==='support_username')$value=ltrim(trim($value),'@');
        if(mb_strlen($value)>2000)throw new RuntimeException('Maximum 2000 characters');
        $next=$this->settings;$next[$key]=is_int(SessionRules::defaults()[$key])?(preg_match('/^\d+$/',$value)?(int)$value:$value):$value;SessionRules::validate($next);
        $this->review($id,['type'=>'setting','key'=>$key,'value'=>$next[$key],'old'=>$this->settings[$key],'back'=>'category:'.$category],
            $this->t($id,'ui.setting.review',['setting'=>$this->t($id,'setting.'.$key),'old'=>$this->e($this->short((string)$this->settings[$key],200)),'value'=>$this->e($this->short((string)$next[$key],200))]));
    }
    private function review(int $id,array $payload,string $text): void {
        $token=$this->token($id,'confirm',$payload,600);$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);
        $danger=in_array($payload['type'],['ban','role','clearbio'],true);
        $this->send($id,$text,[[[$this->t($id,'ui.confirm'),'apply:'.$token,$danger?'danger':'success'],[$this->t($id,'cancel.button'),'dismiss:'.$token]]]);
    }
    private function accountReview(int $id,string $type,int $target,?string $role=null): void {
        if(!$this->super($id))throw new RuntimeException('Superadmin access required');$a=$this->knownAccount($target);
        if(!in_array($type,['ban','unban','reset','role'],true))throw new RuntimeException('Unknown action');
        if($type==='ban'&&$this->super($target))throw new RuntimeException('Cannot suspend a superadmin');
        if($type==='unban'&&$a['role']!=='banned')throw new RuntimeException('This account is not banned');
        if($type==='role') {
            if(!in_array($role,['user','miss','mister','admin','superadmin'],true))throw new RuntimeException('Unknown role');
            if($this->super($target)&&$role!=='superadmin')throw new RuntimeException('Cannot change a protected superadmin');
            if($a['role']==='banned')throw new RuntimeException('Unban this account before changing its role');
            if($this->active($target)||($this->one('SELECT step FROM user WHERE id=?',[$target])['step']??'')==='chat')throw new RuntimeException('End this account’s connection before changing its role');
        }
        $newRole=$type==='unban'?$a['previous_role']:($role??$a['role']);
        $text=$this->t($id,'ui.review.'.$type,['title'=>$this->e($this->short($a['title'],60)),'user'=>$target,'role'=>$this->t($id,'role.'.$newRole)]);
        $this->review($id,['type'=>$type,'target'=>$target,'role'=>$role,'old'=>$a['role'],'back'=>'manage:'.$target],$text);
    }
    private function givePage(int $id,int $target): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');$a=$this->knownAccount($target);
        $rows=[];foreach(array_chunk([5,10,25,50,100,500],3) as $chunk)$rows[]=array_map(fn($n)=>[(string)$n,'gr:'.$target.':'.$n],$chunk);
        $rows[]=[[$this->t($id,'ui.custom'),'gc:'.$target]];$rows[]=[[$this->t($id,'ui.back'),'manage:'.$target]];
        $this->send($id,$this->t($id,'ui.give.body',['title'=>$this->e($this->short($a['title'],60)),'balance'=>$a['balance']]),$rows);
    }
    private function grantReview(int $id,int $target,int $amount): void {
        if(!$this->admin($id))throw new RuntimeException('Access denied');$a=$this->knownAccount($target);
        if($amount<1||$amount>9999999)throw new RuntimeException('Enter an amount from 1 to 9,999,999');
        $this->review($id,['type'=>'grant','target'=>$target,'amount'=>$amount,'back'=>'manage:'.$target],$this->t($id,'ui.grant.review',['title'=>$this->e($this->short($a['title'],60)),'user'=>$target,'coins'=>$amount,'balance'=>(int)$a['balance']+$amount]));
    }
    private function applyReview(int $id,string $token): void {
        $review=$this->uiToken($id,$token,'confirm');$data=$review['data'];$type=$data['type'];
        if($type==='grant') {if(!$this->admin($id))throw new RuntimeException('Access denied');}
        elseif($type==='clearbio') {if((int)$data['target']!==$id)throw new RuntimeException('Access denied');}
        elseif(!$this->super($id))throw new RuntimeException('Superadmin access required');
        $target=(int)($data['target']??0);$a=$target?$this->knownAccount($target):null;
        if(in_array($type,['role','ban','unban','reset'],true)&&$a['role']!==$data['old'])throw new RuntimeException('Account details changed. Reopen this account.');
        if($type==='ban'&&$this->super($target))throw new RuntimeException('Cannot suspend a superadmin');
        if($type==='role') {
            if($this->super($target)&&$data['role']!=='superadmin')throw new RuntimeException('Cannot change a protected superadmin');
            if($this->active($target)||($this->one('SELECT step FROM user WHERE id=?',[$target])['step']??'')==='chat')throw new RuntimeException('End this account’s connection before changing its role');
        }
        $this->db->begin_transaction();
        try {
            $used=$this->query('UPDATE platform_ui SET used=1 WHERE token=? AND actor=? AND used=0 AND expires>?',[$token,$id,time()]);
            if($used->affected_rows!==1)throw new RuntimeException('This action expired. Open the panel again.');
            if($type==='grant') {$this->wallet($target,(int)$data['amount'],'admin grant','grant:ui:'.$token,$id);$this->audit($id,'grant',$target.' '.$data['amount']);}
            elseif($type==='setting') {
                $key=$data['key'];$current=$this->one('SELECT value FROM platform_settings WHERE name=?',[$key])['value']??SessionRules::defaults()[$key];
                if((string)$current!==(string)$data['old'])throw new RuntimeException('Settings changed. Review the new value.');
                $next=$this->settings;$next[$key]=$data['value'];SessionRules::validate($next);
                $this->query('INSERT INTO platform_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$data['value']]);$this->settings=$next;$this->audit($id,'setting',$key.'='.$data['value']);
            }elseif($type==='ban') {
                $this->query("UPDATE platform_accounts SET previous_role=role,role='banned',available=0,state='' WHERE id=?",[$target]);
                $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);$this->audit($id,'suspend',(string)$target);
            }elseif($type==='unban'||$type==='role') {
                $role=$type==='unban'?$a['previous_role']:$data['role'];if(!in_array($role,['user','miss','mister','admin','superadmin'],true))$role='user';
                $panel=match($role){'miss','mister'=>'mm','admin'=>'admin','superadmin'=>'superadmin',default=>'normal'};
                $this->query("UPDATE platform_accounts SET role=?,previous_role=?,available=0,panel=?,state='' WHERE id=?",[$role,$role,$panel,$target]);
                $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);$this->query('DELETE FROM chat WHERE user_id=?',[$target]);$this->query('DELETE FROM chatvip WHERE user_id=?',[$target]);$this->audit($id,$type==='unban'?'unban':'role',$target.' '.$role);
            }elseif($type==='reset') {$this->query('UPDATE platform_accounts SET strikes=0,available=0 WHERE id=?',[$target]);$this->audit($id,'reset_strikes',(string)$target);}
            elseif($type==='clearbio') {
                if($this->active($target)||$this->pendingBooking($target))throw new RuntimeException('Edit your profile after your session');
                $this->query("UPDATE platform_accounts SET bio='',state='' WHERE id=?",[$target]);
            }else throw new RuntimeException('Unknown action');
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        if($type==='ban') {
            $session=$this->active($target);if($session)$this->finish($session,'admin_moderation',$this->failureRefund($session));$this->leaveLegacy($target);
        }
        if(in_array($type,['role','unban'],true))$this->syncCommands($target);
        if($type==='clearbio')$this->profile($id);elseif($type==='setting')$this->category($id,$this->settingCategory($data['key']));else $this->manage($id,$target);
        if($type==='grant')$this->send($target,$this->t($target,'grant.received',['coins'=>$data['amount']]),[[[$this->t($target,'wallet.button'),'wallet']]]);
        if($type==='unban')$this->send($target,$this->t($target,'ui.unbanned'),[[[$this->t($target,'ui.open.panel'),'panelhome']]]);
        if($type==='ban')$this->send($target,$this->t($target,'account.suspended'),[$this->supportRow($target)]);
        if($type==='role')$this->send($target,$this->t($target,'role.received',['role'=>$this->t($target,'role.'.$data['role']),'command'=>match($data['role']){'miss','mister'=>'/mm','admin'=>'/admin','superadmin'=>'/superadmin',default=>'/start'}]),[[[$this->t($target,'ui.open.panel'),'panelhome']]]);
    }
    private function activity(int $id,string $kind,int $requested): void {
        if(!$this->admin($id)||($kind==='audit'&&!$this->super($id)))throw new RuntimeException('Access denied');
        if(!in_array($kind,['audit','moderation','reports'],true))throw new RuntimeException('Unknown category');
        $table=$kind==='moderation'?'platform_moderation':'platform_audit';$where=$kind==='reports'?"action IN ('safety_report','anonymous_report')":'1=1';
        $total=(int)$this->one("SELECT COUNT(*) AS n FROM $table WHERE $where")['n'];[$page,$pages]=$this->paging($total,$requested,6);
        $entries=$this->all("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT 6 OFFSET ".($page*6));
        $text=$this->t($id,'ui.activity.'.$kind)."\n".$this->pageHeading($id,$page,$pages,$total);$rows=[];
        foreach($entries as $entry) {
            $actor=(int)($entry['actor']??$entry['user_id']);$detail=$kind==='moderation'?$this->t($id,'moderation.reason.'.$entry['reason']):$this->t($id,'audit.'.$entry['action']).' · '.$this->auditDetail($id,$entry);
            $text.="\n\n".gmdate('m-d H:i',(int)$entry['created']).' · <code>'.$actor.'</code>'."\n".$this->e($this->short($detail,100));
            $rows[]=[[$this->t($id,'ui.inspect',['user'=>$actor]),'manage:'.$actor]];
        }
        if(!$entries)$text.="\n\n".$this->t($id,'ui.no.records');$rows=array_merge($rows,$this->pageNav($id,'activity:'.$kind.':',$page,$pages));$rows[]=$this->back($id);$this->send($id,$text,$rows);
    }
    private function providerDirectory(int $id,string $role,int $requested=0): void {
        if(!in_array($role,['miss','mister'],true))throw new RuntimeException('Unknown category');if($this->provider($this->account($id)))throw new RuntimeException('Providers cannot book other providers');
        $args=[$role,time()-$this->settings['online_seconds'],$this->settings['strike_limit']];
        $where="a.role=? AND a.available=1 AND a.accepted=1 AND a.name_set=1 AND a.gender IN ('female','male') AND a.state='' AND a.language IN ('en','fa') AND a.heartbeat>=? AND a.strikes<? AND p.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM platform_tendency_active ta WHERE ta.user_id=a.id) AND NOT EXISTS (SELECT 1 FROM platform_requests r WHERE r.kind='booking' AND r.status IN ('pending','processing') AND r.expires>UNIX_TIMESTAMP() AND (r.customer=a.id OR r.provider=a.id))";
        $where.=$this->accessSql("a");
        $total=(int)$this->one("SELECT COUNT(*) AS n FROM platform_accounts a LEFT JOIN platform_participants p ON p.user_id=a.id WHERE $where",$args)['n'];[$page,$pages]=$this->paging($total,$requested,6);
        $providers=$this->all("SELECT a.* FROM platform_accounts a LEFT JOIN platform_participants p ON p.user_id=a.id WHERE $where ORDER BY a.title,a.id LIMIT 6 OFFSET ".($page*6),$args);
        $rows=[];foreach($providers as $provider){$provider=array_replace($provider,array_intersect_key($this->mmProfile((int)$provider['id']),array_flip(['title','bio'])));$rows[]=[[$this->short($this->publicText($id,$provider['title'])?:$this->content($id,$role.'_title'),40),'pv:'.$provider['public_key']]];}
        $rows=array_merge($rows,$this->pageNav($id,'pl:'.$role.':',$page,$pages));$rows[]=[[$this->t($id,'refresh'),'pl:'.$role.':'.$page]];$rows[]=[[$this->t($id,'ui.back'),'onlineroles']];
        $this->send($id,$this->t($id,'provider.list',['title'=>$this->e($this->content($id,$role.'_title')),'description'=>$this->t($id,$providers?'provider.choose':'provider.empty')])."\n".$this->pageHeading($id,$page,$pages,$total),$rows);
    }
    private function providerCard(int $id,string $key,bool $fullBio=false): void {
        if($this->provider($this->account($id)))throw new RuntimeException('Providers cannot book other providers');
        $p=$this->one("SELECT * FROM platform_accounts WHERE public_key=? AND role IN ('miss','mister')",[$key]);if(!$p)throw new RuntimeException('Provider profile unavailable');
        if(!$this->accessEligible((int)$p['id']))throw new RuntimeException('Provider is no longer available');
        $p=array_replace($p,array_intersect_key($this->mmProfile((int)$p['id']),array_flip(['title','bio'])));
        $bio=$this->publicText($id,$p['bio']??'');$text=$this->t($id,'ui.provider',['title'=>$this->e($this->publicText($id,$p['title'])),'bio'=>$this->e($this->emptyText($id,$fullBio?$bio:$this->short($bio))),
            'interests'=>$this->e($this->emptyText($id,$this->choiceText($id,(int)$p['id'],'mm_interests'))),
            'preferences'=>$this->e($this->emptyText($id,$this->short($this->choiceText($id,(int)$p['id'],'offered'),120))),
            'initial_coins'=>$this->settings['initial_coins'],'initial_minutes'=>$this->settings['initial_minutes'],'interval_coins'=>$this->settings['interval_coins'],'interval_minutes'=>$this->settings['interval_minutes']]);
        $hash=substr(hash('sha256',json_encode($this->settings)),0,12);
        $rows=[[[$this->t($id,'provider.pay',['coins'=>$this->settings['initial_coins']]),'pc:'.$key.':'.$hash,'primary']]];
        if(!$fullBio&&mb_strlen($bio)>180)$rows[]=[$this->uiButton($id,'ui.full.bio','pb:'.$key)];
        $rows[]=[[$this->t($id,'rules.button'),'rules'],[$this->t($id,'provider.back'),'list:'.$p['role']]];$this->send($id,$text,$rows);
    }
    private function modernAction(int $id,string $action): bool {
        if($action==='support') {$this->supportPage($id);return true;}
        if($action==='normal') {$this->normalReview($id);return true;}
        if(preg_match('/^nj:([a-f0-9]{12})$/',$action,$m)) {
            if($m[1]!==$this->normalHash())throw new RuntimeException('Connection terms changed. Reopen the provider profile and review the new price before confirming.');
            $this->normal($id);return true;
        }
        if($action==='profilepreview') {$this->profilePreview($id,$id);return true;}
        if($action==='edit:bio') {
            $a=$this->account($id);$this->prompt($id,'edit:bio',$this->t($id,'ui.bio.prompt',['bio'=>$this->e($this->short($a['bio']??'',300))]),[
                [$this->uiButton($id,'ui.bio.clear','clearbio','danger'),[$this->t($id,'cancel.button'),'profile']]
            ]);return true;
        }
        if($action==='clearbio') {$this->review($id,['type'=>'clearbio','target'=>$id,'back'=>'profile'],$this->t($id,'ui.bio.clear.review'));return true;}
        if(preg_match('/^(?:choose|edit):(interests|preferences|offered|mm_interests)$/',$action,$m)) {$this->openChoices($id,$m[1]);return true;}
        if(preg_match('/^co:(-?\d{1,16}):(interests|preferences)$/',$action,$m)) {$this->openChoices($id,$m[2],(int)$m[1]);return true;}
        if(preg_match('/^(?:cp|ct|cs|cc|pref|catalog|ci|ca|ce|cv|cm):/',$action))throw new RuntimeException('This action expired. Open the panel again.');
        if(preg_match('/^guide:(normal|mm|admin|superadmin):(\d+)$/',$action,$m)) {$this->guide($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^(history|ledger):(-?\d{1,16}):(\d+)$/',$action,$m)) {$this->history($id,(int)$m[2],(int)$m[3],$m[1]==='ledger');return true;}
        if(preg_match('/^directory:(\d+)$/',$action,$m)) {$this->directory($id,(int)$m[1]);return true;}
        if(preg_match('/^people:(all|user|miss|mister|admin|superadmin|banned):(\d+)$/',$action,$m)) {$this->directory($id,(int)$m[2],$m[1]);return true;}
        if($action==='peoplefilters') {
            if(!$this->admin($id))throw new RuntimeException('Access denied');$rows=[[[$this->t($id,'ui.all'),'people:all:0']]];
            foreach(array_chunk(['user','miss','mister','admin','superadmin','banned'],2) as $roles)$rows[]=array_map(fn($role)=>[$this->t($id,'role.'.$role),'people:'.$role.':0'],$roles);
            $rows[]=$this->back($id);$this->send($id,$this->t($id,'ui.filter.body'),$rows);return true;
        }
        if($action==='search'||in_array($action,['prompt:lookup','prompt:grant','prompt:role','prompt:reset'],true)) {
            if(!$this->admin($id))throw new RuntimeException('Access denied');$this->prompt($id,'ui:search',$this->t($id,'ui.search.prompt'));return true;
        }
        if(preg_match('/^category:(normal|provider|payments|brand|safety|quiz)$/',$action,$m)) {$this->category($id,$m[1]);return true;}
        if(str_starts_with($action,'setting:')) {$this->settingPage($id,substr($action,8));return true;}
        if(preg_match('/^sv:(\w+):(\d+)$/',$action,$m)) {$this->settingReview($id,$m[1],$m[2]);return true;}
        if(preg_match('/^sc:(\w+)$/',$action,$m)) {
            if(!$this->super($id))throw new RuntimeException('Superadmin access required');$this->settingCategory($m[1]);
            $this->prompt($id,'ui:setting:'.$m[1],$this->t($id,'setting.prompt',['setting'=>$this->t($id,'setting.'.$m[1]),'value'=>$this->e($this->short((string)$this->settings[$m[1]],300))]));return true;
        }
        if(preg_match('/^give:(-?\d{1,16})$/',$action,$m)) {$this->givePage($id,(int)$m[1]);return true;}
        if(preg_match('/^gr:(-?\d{1,16}):(\d+)$/',$action,$m)) {$this->grantReview($id,(int)$m[1],(int)$m[2]);return true;}
        if(preg_match('/^gc:(-?\d{1,16})$/',$action,$m)) {if(!$this->admin($id))throw new RuntimeException('Access denied');$this->knownAccount((int)$m[1]);$this->prompt($id,'ui:grant:'.$m[1],$this->t($id,'ui.amount.prompt'));return true;}
        if(preg_match('/^roles:(-?\d{1,16})$/',$action,$m)) {
            if(!$this->super($id))throw new RuntimeException('Superadmin access required');$a=$this->knownAccount((int)$m[1]);$rows=[];
            foreach(array_chunk(['user','miss','mister','admin','superadmin'],2) as $roles)$rows[]=array_map(fn($role)=>[$this->t($id,'role.'.$role),'rr:'.$m[1].':'.$role],$roles);
            $rows[]=[[$this->t($id,'ui.back'),'manage:'.$m[1]]];$this->send($id,$this->t($id,'ui.roles',['title'=>$this->e($this->short($a['title'],60))]),$rows);return true;
        }
        if(preg_match('/^rr:(-?\d{1,16}):(user|miss|mister|admin|superadmin)$/',$action,$m)) {$this->accountReview($id,'role',(int)$m[1],$m[2]);return true;}
        if(preg_match('/^review:(ban|unban|reset):(-?\d{1,16})$/',$action,$m)) {$this->accountReview($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^suspend:(-?\d{1,16})$/',$action,$m)) {$this->accountReview($id,'ban',(int)$m[1]);return true;}
        if(preg_match('/^otherprofile:(-?\d{1,16})$/',$action,$m)) {
            if(!$this->super($id))throw new RuntimeException('Superadmin access required');$this->knownAccount((int)$m[1]);$rows=[];
            foreach(['title','bio'] as $field)$rows[]=[[$this->t($id,'field.'.$field),'editother:'.$m[1].':'.$field]];
            foreach(['interests'] as $field)$rows[]=[[$this->t($id,'field.'.$field),'co:'.$m[1].':'.$field]];
            $rows[]=[[$this->t($id,'ui.back'),'manage:'.$m[1]]];$this->send($id,$this->t($id,'ui.edit.profile'),$rows);return true;
        }
        if(preg_match('/^apply:([a-f0-9]{16})$/',$action,$m)) {$this->applyReview($id,$m[1]);return true;}
        if(preg_match('/^dismiss:([a-f0-9]{16})$/',$action,$m)) {$review=$this->uiToken($id,$m[1],'confirm');$this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$m[1]]);$this->action($id,$review['data']['back']);return true;}
        if($action==='audit') {$this->activity($id,'audit',0);return true;}
        if(preg_match('/^moderation:(\d+)$/',$action,$m)) {$this->activity($id,'moderation',(int)$m[1]);return true;}
        if(preg_match('/^activity:(audit|moderation|reports):(\d+)$/',$action,$m)) {$this->activity($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^(?:list|pl):(miss|mister)(?::(\d+))?$/',$action,$m)) {$this->providerDirectory($id,$m[1],(int)($m[2]??0));return true;}
        if(preg_match('/^p([vb]):([a-f0-9]{16})$/',$action,$m)) {$this->providerCard($id,$m[2],$m[1]==='b');return true;}
        if(preg_match('/^pc:([a-f0-9]{16}):([a-f0-9]{12})$/',$action,$m)) {
            if($m[2]!==substr(hash('sha256',json_encode($this->settings)),0,12))throw new RuntimeException('Connection terms changed. Reopen the provider profile and review the new price before confirming.');
            $p=$this->one("SELECT id FROM platform_accounts WHERE public_key=? AND role IN ('miss','mister')",[$m[1]]);if(!$p)throw new RuntimeException('Provider profile unavailable');$this->requestBooking($id,(int)$p['id']);return true;
        }
        if(preg_match('/^reactmenu:([a-f0-9]{32})$/',$action,$m)) {
            $map=$this->one('SELECT * FROM platform_messages WHERE token=? AND target_chat=?',[$m[1],$id]);$session=$this->active($id);
            if(!$map||!$session||$session['id']!=$map['session_id'])throw new RuntimeException('This message belongs to an ended connection.');
            $this->callbackContext=null;jijibot('editMessageReplyMarkup',['chat_id'=>$id,'message_id'=>$map['target_message'],'reply_markup'=>$this->buttons([])]);$this->send($id,$this->t($id,'v3.reaction.help'));return true;
        }
        return false;
    }
    private function modernInput(int $id,array $a,string $text): bool {
        if(!str_starts_with($a['state'],'ui:'))return false;
        $text=trim($text);$state=$a['state'];
        $digits=strtr($text,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
        if($state==='ui:search') {
            if(!$this->admin($id))throw new RuntimeException('Access denied');
            if(preg_match('/^[1-9]\d{0,15}$/',$digits)) {$target=(int)$digits;$this->knownAccount($target);$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->manage($id,$target);}
            else {
                if(mb_strlen($text)<2)throw new RuntimeException('Enter a name or numeric Telegram user ID');
                $matches=$this->all('SELECT id,title FROM platform_accounts WHERE title LIKE ? ESCAPE \'!\' ORDER BY id LIMIT 6',['%'.strtr($text,['!'=>'!!','%'=>'!%','_'=>'!_']).'%']);
                $rows=array_map(fn($u)=>[[$this->short($u['title'],40),'manage:'.$u['id']]],$matches);$rows[]=$this->back($id);
                $this->send($id,$this->t($id,'ui.search.results').(!$matches?"\n".$this->t($id,'ui.no.records'):''),$rows);
            }return true;
        }
        if(preg_match('/^ui:grant:(-?\d{1,16})$/',$state,$m)) {
            if(!preg_match('/^\d{1,7}$/',$digits))throw new RuntimeException('Enter an amount from 1 to 9,999,999');$this->grantReview($id,(int)$m[1],(int)$digits);return true;
        }
        if(preg_match('/^ui:setting:(\w+)$/',$state,$m)) {$this->settingReview($id,$m[1],is_int(SessionRules::defaults()[$m[1]]??null)?$digits:$text);return true;}
        throw new RuntimeException('This action expired. Open the panel again.');
    }
}
