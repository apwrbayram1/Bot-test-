<?php
require_once __DIR__.'/Rules.php';
require_once __DIR__.'/Locale.php';
require_once __DIR__.'/Moderation.php';
require_once __DIR__.'/Interface.php';
require_once __DIR__.'/ProfileUpdate.php';
require_once __DIR__.'/AccountBridge.php';
require_once __DIR__.'/Tendency.php';
require_once __DIR__.'/TendencyInterface.php';
require_once __DIR__.'/Navigation.php';
require_once __DIR__.'/Access.php';
require_once __DIR__.'/Outreach.php';
require_once __DIR__.'/Growth.php';
require_once __DIR__.'/Anonymous.php';
require_once __DIR__.'/Age.php';
final class Platform
{
    use PlatformInterface;
    use PlatformProfileUpdate;
    use PlatformTendency;
    use PlatformNavigation;
    use PlatformAccess;
    use PlatformOutreach;
    use PlatformGrowth;
    use PlatformAnonymous;
    use PlatformAge;
    private mysqli $db;
    private array $env;
    private array $settings;
    private ?array $callbackContext=null;
    private ?string $requestKey=null;
    private array $languages=[];
    public function __construct(mysqli $db, array $env) {
        $this->db=$db; $this->env=$env;
        $this->settings=SessionRules::defaults();
        foreach ($this->all('SELECT * FROM platform_settings') as $row) $this->settings[$row['name']]=is_int($this->settings[$row['name']]??null)?(int)$row['value']:$row['value'];
    }
    private function query(string $sql, array $args=[]): mysqli_stmt {
        $s=$this->db->prepare($sql);
        if (!$s) throw new RuntimeException('Database prepare failed');
        if ($args) $s->bind_param(str_repeat('s',count($args)), ...$args);
        if (!$s->execute()) throw new RuntimeException('Database write failed');
        return $s;
    }
    private function all(string $sql,array $args=[]): array { return $this->query($sql,$args)->get_result()->fetch_all(MYSQLI_ASSOC); }
    private function one(string $sql,array $args=[]): ?array { return $this->all($sql,$args)[0]??null; }
    private function account(int $id): array {
        $this->query('INSERT IGNORE INTO platform_accounts(id,bio,interests,preferences,enabled_preferences) VALUES(?,\'\',\'\',\'\',\'\')',[$id]);
        $a=$this->one('SELECT * FROM platform_accounts WHERE id=?',[$id]);
        if($a['public_key']==='') {$key=bin2hex(random_bytes(8));$this->query("UPDATE platform_accounts SET public_key=? WHERE id=? AND public_key=''",[$key,$id]);$a['public_key']=$key;}
        return $a;
    }
    private function super(int $id): bool {
        return in_array((string)$id,array_map('trim',explode(',',$this->env['SUPERADMIN_IDS']??'')),true) || ($this->account($id)['role']==='superadmin');
    }
    private function admin(int $id): bool { return $this->super($id) || in_array((string)$id,array_map('trim',explode(',',$this->env['ADMIN_IDS']??'')),true) || $this->account($id)['role']==='admin'; }
    private function provider(array $a): bool { return in_array($a['role'],['miss','mister'],true); }
    private function e(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function buttons(array $rows): string {
        $keyboard=[];
        foreach($rows as $row) {
            $buttons=[];foreach($row as $b) {
                $button=['text'=>$b[0]];
                if(is_array($b[1]))$button=array_merge($button,$b[1]);
                else {$data='p:'.$b[1];if(strlen($data)>64)throw new LogicException('Callback data exceeds Telegram limit');$button['callback_data']=$data;}
                if(!empty($b[2]))$button['style']=$b[2];$buttons[]=$button;
            }if($buttons)$keyboard[]=$buttons;
        }
        return json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private function send(int $id,string $text,array $rows=[]): bool {
        if($this->replyNavigation())return $this->menuSend($id,$text,$rows);
        $data=['chat_id'=>$id,'text'=>str_replace('\\n',"\n",$text),'parse_mode'=>'HTML','link_preview_options'=>json_encode(['is_disabled'=>true]),'reply_markup'=>$this->buttons($rows)];
        if ($this->callbackContext && $this->callbackContext['chat']===$id) {
            $data['message_id']=$this->callbackContext['message'];$this->callbackContext=null;
            $result=jijibot('editMessageText',$data);
            if ($result && ($result->ok || str_contains($result->description??'', 'message is not modified'))) return true;
            unset($data['message_id']);
        }
        $result=jijibot('sendMessage',$data);return (bool)($result->ok??false);
    }
    private function audit(int $actor,string $action,string $detail): void { $this->query('INSERT INTO platform_audit(actor,action,detail,created) VALUES(?,?,?,?)',[$actor,$action,$detail,time()]); }
    private function wallet(int $id,int $amount,string $reason,string $ref,int $actor=0): void {
        $this->query('INSERT INTO platform_ledger(user_id,amount,reason,reference,actor,created) VALUES(?,?,?,?,?,?)',[$id,$amount,$reason,$ref,$actor,time()]);
        $s=$this->query('UPDATE platform_accounts SET balance=balance+? WHERE id=? AND balance+?>=0',[$amount,$id,$amount]);
        if ($s->affected_rows!==1) throw new RuntimeException('Insufficient coins');
    }
    private function active(int $id): ?array {return $this->one('SELECT s.* FROM platform_sessions s JOIN platform_participants p ON p.session_id=s.id WHERE p.user_id=? AND s.status=\'active\'',[$id]);}

    private const REACTIONS=['like'=>'👍','love'=>'❤','laugh'=>'🤣','fire'=>'🔥','sad'=>'😢','dislike'=>'👎'];
    private function reactionMap(int $chat,int $message): ?array {
        return $this->one('SELECT * FROM platform_messages WHERE (target_chat=? AND target_message=?) OR (source_chat=? AND source_message=?)',[$chat,$message,$chat,$message]);
    }
    private function mirrorReaction(int $actor,array $map,string $emoji,int $date,bool $native=false): bool {
        $a=$this->account($actor);$session=$this->active($actor);
        if(!$a['accepted'] || $a['role']==='banned' || !$session || $session['id']!=$map['session_id'] || !$map['target_message']) {
            if(!$native)throw new RuntimeException('This message belongs to an ended connection.');return false;
        }
        $onCopy=$map['target_chat']==$actor;
        if(!$onCopy && $map['source_chat']!=$actor) {
            if(!$native)throw new RuntimeException('React only to a message received from your current partner.');return false;
        }
        $column=$onCopy?'target_reaction':'source_reaction';$dateColumn=$column.'_date';
        if(!$native)$date=max($date,(int)$map[$dateColumn]);
        if($date<(int)$map[$dateColumn])return false;
        if($map[$column]===$emoji) {$this->query("UPDATE platform_messages SET $dateColumn=? WHERE id=?",[$date,$map['id']]);return true;}
        $target=(int)($onCopy?$map['source_chat']:$map['target_chat']);
        $message=(int)($onCopy?$map['source_message']:$map['target_message']);
        $reactions=$emoji!==''?[['type'=>'emoji','emoji'=>$emoji]]:[];
        $result=jijibot('setMessageReaction',['chat_id'=>$target,'message_id'=>$message,'reaction'=>json_encode($reactions,JSON_UNESCAPED_UNICODE)]);
        if(!$result || !$result->ok) {
            // Some messages or emoji cannot receive native reactions. Deliver a reply instead.
            $text=$this->t($target,$emoji!==''?'reaction.partner':'reaction.removed',['emoji'=>$this->e($emoji)]);
            $result=jijibot('sendMessage',['chat_id'=>$target,'text'=>$text,'parse_mode'=>'HTML','reply_parameters'=>json_encode(['message_id'=>$message,'allow_sending_without_reply'=>true])]);
            if(!$result || !$result->ok) {$this->send($actor,$this->t($actor,'reaction.error'));return false;}
        }
        $this->query("UPDATE platform_messages SET $column=?,$dateColumn=? WHERE id=?",[$emoji,$date,$map['id']]);
        return true;
    }
    private function nativeReaction(object $event): void {
        $id=(int)($event->user->id??0);
        if(!$id || ($event->user->is_bot??false) || ($event->chat->type??'')!=='private' || (int)$event->chat->id!==$id)return;
        $map=$this->reactionMap($id,(int)$event->message_id);if(!$map)return;
        $emoji='';
        foreach($event->new_reaction??[] as $reaction)if(($reaction->type??'')==='emoji') {$emoji=$reaction->emoji;break;}
        // Paid/custom reactions are unsupported; do not treat them as a removal.
        if($emoji==='' && count($event->new_reaction??[])>0)return;
        $this->mirrorReaction($id,$map,$emoji,(int)$event->date,true);
    }
    private function blockContent(int $id,string $reason,bool $profile=false): void {
        $session=$this->active($id);$reference='blocked:'.($this->requestKey??bin2hex(random_bytes(16)));
        $entry=$this->query('INSERT IGNORE INTO platform_moderation(user_id,session_id,reason,reference,created) VALUES(?,?,?,?,?)',[$id,$session['id']??null,$reason,$reference,time()]);
        if($entry->affected_rows)$this->audit($id,'moderation_block',$reason);
        $this->callbackContext=null;
        $rows=$session?[[[$this->t($id,'status.button'),'status'],[$this->t($id,'ui.report'),'report','danger']]]:[$this->back($id)];
        $this->send($id,$this->t($id,$profile?'moderation.profile':'moderation.blocked',['reason'=>$this->t($id,'moderation.reason.'.$reason)]),$rows);
    }
    private function publicText(int $recipient,string $text): string {
        return MessageModeration::text($text,$this->settings)!==null?$this->t($recipient,'moderation.hidden'):$text;
    }
    private function moderatedProfile(int $id,string $text): bool {
        $reason=MessageModeration::text($text,$this->settings);
        if(!$reason)return false;
        $this->blockContent($id,$reason,true);return true;
    }

    private function languageFor(int $id): string {
        return $this->languages[$id]??=($this->account($id)['language']==='fa'?'fa':'en');
    }
    private function t(int $id,string $key,array $values=[]): string {
        return BotLocale::text($this->languageFor($id),$key,$values);
    }
    private function content(int $id,string $key,?array $settings=null): string {
        $settings??=$this->settings;
        $localized=$this->languageFor($id)==='fa'?$key.'_fa':$key;
        return (string)($settings[$localized]??$settings[$key]??'');
    }
    private function preferenceLabel(int $id,string $canonical): string {
        $labels=array_map('trim',explode(',',$this->settings['preferences']));
        $index=array_search($canonical,$labels,true);
        if($this->languageFor($id)==='fa' && $index!==false) {
            return array_map('trim',explode(',',$this->settings['preferences_fa']))[$index]??$canonical;
        }
        return $canonical;
    }
    private function setPanel(int $id,string $panel): void {
        $this->query("UPDATE platform_accounts SET panel=?,state='' WHERE id=?",[$panel,$id]);
    }
    private function back(int $id): array {
        return [[$this->t($id,'back.panel'),'panelhome']];
    }
    private function returnPanel(int $id): void {
        if($this->welcomeGate($id))return;
        $a=$this->account($id);
        switch($a['panel']) {
            case 'admin': $this->panel($id,false);break;
            case 'superadmin': $this->panel($id,true);break;
            case 'mm': $this->studio($id);break;
            default: $this->home($id);
        }
    }
    private function auditDetail(int $id,array $entry): string {
        $detail=$entry['detail'];
        if($entry['action']==='moderation_block')return $this->t($id,'moderation.reason.'.$detail);
        if($entry['action']==='setting' && str_contains($detail,'=')) {
            [$key,$value]=explode('=',$detail,2);
            return $this->t($id,'setting.'.$key).' = '.mb_substr($value,0,100);
        }
        if($entry['action']==='role' && preg_match('/^(\d+) (\w+)$/',$detail,$m))return $this->t($id,'audit.role_detail',['user'=>$m[1],'role'=>$this->t($id,'role.'.$m[2])]);
        if($entry['action']==='profile_edit' && preg_match('/^(\d+) (\w+)$/',$detail,$m))return $m[1].' · '.$this->t($id,'field.'.$m[2]);
        if($entry['action']==='safety_report')return preg_match('/Session (\d+)/',$detail,$m)?'#'.$m[1]:'—';
        if($entry['action']==='invalid_payment')return '—';
        return mb_substr($detail,0,160);
    }
    private function languageMenu(int $id): void {
        $this->send($id,"<b>🌐 Language / زبان</b>\n👋 Welcome! Pick a language and let’s get you started.\n👋 خوش اومدی! زبانت رو انتخاب کن تا شروع کنیم.",[[['English','language:en'],['فارسی','language:fa']]]);
    }
    public function syncCommands(int $id): void {
        $commands=['start','mm','admin','superadmin'];
        foreach(['en','fa'] as $language)jijibot('deleteMyCommands',['scope'=>json_encode(['type'=>'chat','chat_id'=>$id]),'language_code'=>$language]);
        jijibot('setMyCommands',[
            'scope'=>json_encode(['type'=>'chat','chat_id'=>$id]),
            'commands'=>json_encode(array_map(fn($command)=>['command'=>$command,'description'=>$this->t($id,'command.'.$command)],$commands),JSON_UNESCAPED_UNICODE)
        ]);
    }
    public function showError(int $id,string $message): void {
        $rows=[$this->back($id)];if($message==='Insufficient coins')array_unshift($rows,[[$this->t($id,'wallet.button'),'wallet','primary']]);$rows[]=$this->supportRow($id);
        $this->send($id,BotLocale::error($this->languageFor($id),$message),$rows);
    }


    private function rulesText(array $s,int $id): string {
        $s['miss_title']=$this->e($this->content($id,'miss_title',$s));$s['mister_title']=$this->e($this->content($id,'mister_title',$s));
        return $this->t($id,'rules.body',$s)."\n\n".$this->t($id,'moderation.rules');
    }


    private function finish(array $session,string $reason,int $refund=0): void {
        $this->db->begin_transaction();
        try {
            $s=$this->one('SELECT * FROM platform_sessions WHERE id=? FOR UPDATE',[$session['id']]);
            if ($s['status']!=='active') { $this->db->rollback(); return; }
            if ($refund>0) {
                $refund=min($refund,(int)$s['charged']);
                $this->wallet((int)$s['customer'],$refund,'session refund','refund:'.$s['id']);
                if($s['kind']==='normal')$this->wallet((int)$s['provider'],$refund,'session refund','refund:'.$s['id'].':peer');
            }
            $this->query('UPDATE platform_sessions SET status=\'closed\',reason=?,refunded=? WHERE id=?',[$reason,$refund,$s['id']]);
            $this->query('DELETE FROM platform_participants WHERE session_id=?',[$s['id']]);
            if ($reason==='no_response') {
                $this->query('UPDATE platform_accounts SET strikes=strikes+1,available=0 WHERE id=?',[$s['provider']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
        $this->query("UPDATE platform_requests SET status='expired' WHERE session_id=? AND status='pending'",[$s['id']]);
        foreach([(int)$s['customer'],(int)$s['provider']] as $recipient) {
            $this->removeKeyboard($recipient);
            $text=$this->t($recipient,'session.ended',['reason'=>$this->t($recipient,'reason.'.$reason)]);
            if($s['kind']==='normal'||$recipient===(int)$s['customer'])$text.="\n".$this->t($recipient,'session.refund',['coins'=>$refund]);
            $this->send($recipient,$text,[$this->back($recipient)]);
        }
    }
    public function tick(bool $maintenance=false): void {
        $this->tendencyTick();$this->expireRequests();$this->cleanQueue();$this->query('DELETE FROM platform_ui WHERE expires<?',[time()-86400]);$this->query('DELETE FROM platform_processed WHERE created<?',[time()-604800]);
        foreach ($this->all('SELECT * FROM platform_sessions WHERE status=\'active\'') as $s) {
            if($this->account((int)$s['customer'])['role']==='banned'||$this->account((int)$s['provider'])['role']==='banned') {$this->finish($s,'admin_moderation',$this->failureRefund($s));continue;}
            if ($s['kind']==='normal') {
                $deadline=(int)$s['paid_until']?:((int)$s['started']+600);
                if(time()>=$deadline)$this->finish($s,'normal_time_ended');
                elseif(!(int)$s['paid_until'])$this->query('UPDATE platform_sessions SET paid_until=? WHERE id=?',[$deadline,$s['id']]);
                continue;
            }
            $r=json_decode($s['rules'],true); $now=time();
            if ($s['pending_since'] && (($s['unanswered'] >= $r['unanswered_limit'] && $now-$s['pending_since'] >= $r['response_seconds']) || $now-$s['pending_since'] >= $r['silence_seconds'])) {
                $this->finish($s,'no_response',SessionRules::unusedRefund($r,(int)$s['started'],(int)$s['paid_until'],(int)$s['charged'],$now)); continue;
            }
            if ($now >= $s['paid_until']) {
                // Never back-charge after downtime: close at the last purchased boundary.
                if ($now-$s['paid_until']>=30 || $this->account((int)$s['customer'])['balance']<$r['interval_coins']) { $this->finish($s,'paid_time_ended');continue; }
                $this->db->begin_transaction();
                try {
                    $this->wallet((int)$s['customer'],-$r['interval_coins'],'session extension','session:'.$s['id'].':'.$s['paid_until']);
                    $this->query('UPDATE platform_sessions SET paid_until=paid_until+?,charged=charged+? WHERE id=?',[$r['interval_minutes']*60,$r['interval_coins'],$s['id']]);
                    $this->db->commit();
                } catch (Throwable $e) {$this->db->rollback();throw $e;}
                $recipient=(int)$s['customer'];
                $this->send($recipient,$this->t($recipient,'session.extended',['minutes'=>$r['interval_minutes'],'coins'=>$r['interval_coins']]),[[[$this->t($recipient,'close.button'),'close']]]);
            }
        }
        if($maintenance){$this->membershipTick();$this->broadcastTick();$this->growthMaintenance();}
    }
    private function connectPaid(int $id,int $target,?array $quote=null): void {
        if(!$this->accessEligible($id)||!$this->accessEligible($target))throw new RuntimeException('Access denied');
        $a=$this->account($id);$p=$this->knownAccount($target);$r=$quote??$this->settings;
        if($this->activeTendency($id)||$this->activeTendency($target))throw new RuntimeException('End your preference test first');
        $p=array_replace($p,array_intersect_key($this->mmProfile($target),array_flip(['title','bio'])));
        if (!$r['paid_enabled']) throw new RuntimeException('Paid connections are paused');
        if ($this->provider($a) || !$this->provider($p) || $id===$target) throw new RuntimeException('Choose a provider from a normal account');
        if (!in_array($p['language'],['en','fa'],true) || !$p['accepted'] || $p['state']!=='' || !$p['available'] || time()-$p['heartbeat']>$r['online_seconds'] || $p['strikes'] >= $r['strike_limit']) throw new RuntimeException('Provider is no longer available');
        if ($this->active($id)||$this->active($target)) throw new RuntimeException('One participant is already connected');
        if (($this->one('SELECT step FROM user WHERE id=?',[$id])['step']??'')==='chat' || ($this->one('SELECT step FROM user WHERE id=?',[$target])['step']??'')==='chat') throw new RuntimeException('Leave your legacy chat first');
        $this->db->begin_transaction();
        try {
            $now=time();
            $this->query('INSERT INTO platform_sessions(customer,provider,kind,started,paid_until,rules,charged) VALUES(?,?,?,?,?,?,?)',[$id,$target,'paid',$now,$now+$r['initial_minutes']*60,json_encode($r),$r['initial_coins']]);
            $sid=$this->db->insert_id;
            $this->wallet($id,-$r['initial_coins'],'session start','session:'.$sid.':start');
            $this->query('INSERT INTO platform_participants(user_id,session_id) VALUES(?,?),(?,?)',[$id,$sid,$target,$sid]);
            $this->query('DELETE FROM platform_queue WHERE user_id IN (?,?)',[$id,$target]);
            $this->query('DELETE FROM chat WHERE user_id IN (?,?)',[$id,$target]);
            $this->query('DELETE FROM chatvip WHERE user_id IN (?,?)',[$id,$target]);
            $this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$target]);
            $this->db->commit();
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
        $customerDelivered=$this->send($id,$this->t($id,'connected.customer',['title'=>$this->e($this->publicText($id,$p['title'])),'coins'=>$r['initial_coins']])."\n\n".$this->t($id,'ui.receipt',$r),[
            [[$this->t($id,'status.button'),'status'],[$this->t($id,'close.button'),'close','danger']],
            [[$this->t($id,'ui.report'),'report','danger'],[$this->t($id,'rules.button'),'rules']]
        ]);
        $providerDelivered=$this->send($target,$this->t($target,'connected.provider',['name'=>$this->e($a['title']),'interests'=>$this->e($this->choiceText($target,$id,'interests')),'preferences'=>$this->e($this->choiceText($target,$id,'preferences'))])."\n\n".$this->t($target,'ui.reply.reminder',$r),[
            [[$this->t($target,'status.button'),'status'],[$this->t($target,'close.button'),'close','danger']],
            [[$this->t($target,'ui.report'),'report','danger'],[$this->t($target,'rules.button'),'rules']]
        ]);
        if(!$customerDelivered||!$providerDelivered)$this->finish($this->active($id),'delivery_failed',$r['initial_coins']);
        else foreach([$id,$target] as $who)$this->chatKeyboard($who);
    }
    private function failureRefund(array $s): int {
        if($s['kind']==='paid')return SessionRules::unusedRefund(json_decode($s['rules'],true),(int)$s['started'],(int)$s['paid_until'],(int)$s['charged'],time());
        $r=json_decode($s['rules'],true)?:[];$seconds=(int)($r['normal_seconds']??600);
        return min((int)$s['charged'],(int)ceil((int)$s['charged']*max(0,(int)$s['paid_until']-time())/$seconds));
    }
    private function cleanQueue(): void {
        foreach($this->all('SELECT q.*,a.role,a.balance,a.accepted,a.language,a.state,a.name_set,a.gender,p.session_id FROM platform_queue q JOIN platform_accounts a ON a.id=q.user_id LEFT JOIN platform_participants p ON p.user_id=q.user_id') as $entry) {
            $rules=json_decode($entry['rules']??'null',true);
            $invalid=!$this->accessEligible((int)$entry['user_id']) || (bool)$this->activeTendency((int)$entry['user_id']) || !$this->readyProfile($entry) || $this->pendingBooking((int)$entry['user_id']) || !$rules || json_encode($rules)!==json_encode($this->normalQuote()) || !$rules['normal_enabled'] || $entry['state']!=='' || !$entry['accepted'] || !in_array($entry['language'],['en','fa'],true) || in_array($entry['role'],['miss','mister','banned'],true) || $entry['session_id'] || $entry['balance']<$rules['normal_coins'];
            $expired=$rules && time()-(int)$entry['joined']>=(int)$rules['queue_seconds'];
            if($invalid||$expired) {
                $this->query('DELETE FROM platform_queue WHERE user_id=?',[$entry['user_id']]);
                if($entry['role']!=='banned'&&!$entry['session_id'])$this->send((int)$entry['user_id'],$this->t((int)$entry['user_id'],$expired?'ui.queue.expired':'ui.queue.changed'),[[[$this->t((int)$entry['user_id'],'ui.open.panel'),'home']]]);
            }
        }
    }
    private function normal(int $id): void {
        if(!$this->accessEligible($id))throw new RuntimeException('Access denied');
        $a=$this->account($id);$r=$this->normalQuote();
        if($this->activeTendency($id))throw new RuntimeException('End your preference test first');
        if(!$r['normal_enabled'])throw new RuntimeException('Normal connections are paused');
        if($this->provider($a))throw new RuntimeException('Provider profiles cannot enter normal matching');
        if(!$this->readyProfile($a)||$a['role']==='banned'||!$a['accepted']||!in_array($a['language'],['en','fa'],true))throw new RuntimeException('Access denied');
        if($this->active($id)||$this->pendingBooking($id))throw new RuntimeException('End your current connection first');
        if($a['balance']<$r['normal_coins'])throw new RuntimeException('Insufficient coins');
        if(($this->one('SELECT step FROM user WHERE id=?',[$id])['step']??'')==='chat')throw new RuntimeException('Leave your legacy connection first');
        $this->cleanQueue();$this->query('DELETE FROM chat WHERE user_id=?',[$id]);$this->query('DELETE FROM chatvip WHERE user_id=?',[$id]);
        $other=$this->one("SELECT q.user_id FROM platform_queue q JOIN platform_accounts a ON a.id=q.user_id LEFT JOIN platform_participants p ON p.user_id=q.user_id LEFT JOIN user u ON u.id=q.user_id WHERE q.user_id<>? AND a.role NOT IN ('miss','mister','banned') AND a.accepted=1 AND a.name_set=1 AND a.gender IN ('female','male') AND a.language IN ('en','fa') AND a.balance>=? AND a.state='' AND p.user_id IS NULL AND COALESCE(u.step,'')<>'chat' ORDER BY q.joined,q.user_id LIMIT 1",[$id,$r['normal_coins']]);
        if(!$other) {
            $this->query('INSERT IGNORE INTO platform_queue(user_id,joined,rules) VALUES(?,?,?)',[$id,time(),json_encode($r)]);
            $this->send($id,$this->t($id,'ui.normal.wait',$r),[[[$this->t($id,'normal.cancel'),'cancelqueue','danger']]]);return;
        }
        $target=(int)$other['user_id'];$this->db->begin_transaction();
        try {
            $now=time();$this->query('INSERT INTO platform_sessions(customer,provider,kind,started,paid_until,rules,charged) VALUES(?,?,?,?,?,?,?)',[$id,$target,'normal',$now,$now+$r['normal_seconds'],json_encode($r),$r['normal_coins']]);$sid=$this->db->insert_id;
            foreach([$id,$target] as $who)$this->wallet($who,-$r['normal_coins'],'normal connection','normal:'.$sid.':'.$who);
            $this->query('INSERT INTO platform_participants(user_id,session_id) VALUES(?,?),(?,?)',[$id,$sid,$target,$sid]);
            $this->query('DELETE FROM platform_queue WHERE user_id IN (?,?)',[$id,$target]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        $delivered=true;
        foreach([$id,$target] as $who) {
            $peer=$who===$id?$target:$id;
            $ok=$this->send($who,$this->t($who,'ui.normal.connected',$r+['interests'=>$this->e($this->emptyText($who,$this->choiceText($who,$peer,'interests')))]),[
                [[$this->t($who,'status.button'),'status'],[$this->t($who,'close.button'),'close','danger']],
                [[$this->t($who,'ui.report'),'report','danger']]
            ]);$delivered=$ok&&$delivered;
        }
        if(!$delivered)$this->finish($this->active($id),'delivery_failed',$r['normal_coins']);
        else foreach([$id,$target] as $who)$this->chatKeyboard($who);
    }

    private function payment(object $update): bool {
        if (isset($update->pre_checkout_query)) {
            $q=$update->pre_checkout_query;
            $o=$this->one('SELECT * FROM platform_orders WHERE payload=?',[$q->invoice_payload]);
            $ok=$o && $o['status']==='pending' && $o['user_id']==$q->from->id && $q->currency==='XTR' && $q->total_amount==$o['stars'] && time()-$o['created']<3600;
            jijibot('answerPreCheckoutQuery',['pre_checkout_query_id'=>$q->id,'ok'=>$ok?'true':'false','error_message'=>$ok?'':$this->t((int)$q->from->id,'payment.invalid')]);return true;
        }
        if (!isset($update->message->successful_payment)) return false;
        $p=$update->message->successful_payment;$id=(int)$update->message->from->id;
        $this->db->begin_transaction();
        try {
            $o=$this->one('SELECT * FROM platform_orders WHERE payload=? FOR UPDATE',[$p->invoice_payload]);
            if (!$o || $o['user_id']!=$id || $p->currency!=='XTR' || $p->total_amount!=$o['stars']) { $this->db->rollback();$this->audit($id,'invalid_payment','Payment did not match order');return true; }
            if ($o['status']==='paid') {$this->db->rollback();return true;}
            $this->account($id);
            $this->wallet($id,(int)$o['coins'],'Stars purchase','stars:'.$p->telegram_payment_charge_id);
            $this->query('UPDATE platform_orders SET status=\'paid\',charge_id=? WHERE payload=?',[$p->telegram_payment_charge_id,$p->invoice_payload]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        $this->send($id,$this->t($id,'payment.done',['coins'=>$o['coins']]),[[[$this->t($id,'wallet.button'),'wallet']]]);return true;
    }
    private function invoice(int $id,bool $test): void {
        $r=$this->settings;
        if ($test && !$r['test_pack_enabled']) throw new RuntimeException('Test offer is disabled');
        $stars=$r[$test?'test_stars':'stars_pack'];$coins=$r[$test?'test_coins':'coins_pack'];
        $payload=bin2hex(random_bytes(20));
        $this->query('INSERT INTO platform_orders(payload,user_id,stars,coins,created) VALUES(?,?,?,?,?)',[$payload,$id,$stars,$coins,time()]);
        $result=jijibot('sendInvoice',['chat_id'=>$id,'title'=>$this->t($id,$test?'invoice.testtitle':'invoice.title',['coins'=>$coins]),'description'=>$this->t($id,'invoice.description'),'payload'=>$payload,'provider_token'=>'','currency'=>'XTR','prices'=>json_encode([['label'=>$this->t($id,'invoice.title',['coins'=>$coins]),'amount'=>$stars]])]);
        if (!$result || !$result->ok) throw new RuntimeException('Telegram could not create the invoice. Try again later.');
    }
    private function prompt(int $id,string $state,string $text,array $rows=[]): void {
        if ($this->active($id)) throw new RuntimeException('End your connection before editing or managing accounts');
        $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);
        if($this->pendingBooking($id))throw new RuntimeException('Edit your profile after your session');
        if($this->provider($this->account($id)))$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$id]);
        $this->query('UPDATE platform_accounts SET state=? WHERE id=?',[$state,$id]);
        $this->send($id,$text,$rows?:[[[$this->t($id,'cancel.button'),'cancel']]]);
    }
    private function input(int $id,array $a,string $text): void {
        if($this->profileUpdateInput($id,$a,$text))return;
        if($this->modernInput($id,$a,$text))return;
        $state=$a['state'];
        if(in_array($state,['lookup','grant','role','reset'],true) || (str_starts_with($state,'setting:') && is_int(SessionRules::defaults()[substr($state,8)]??null))) {
            $text=strtr($text,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
        }
        if(in_array($state,['setting:preferences','setting:preferences_fa'],true))$text=str_replace('،',',',$text);
        if (preg_match('/^editother:([0-9]+):(title|bio|interests|preferences)$/',$state,$m)) {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            if (mb_strlen($text)>($m[2]==='title'?80:($m[2]==='bio'?1000:300)) || trim($text)==='') throw new RuntimeException('Value is empty or too long');
            if($this->moderatedProfile($id,$text))return;
            $this->query('UPDATE platform_accounts SET '.$m[2].'=? WHERE id=?',[$text,$m[1]]);
            $this->query('UPDATE platform_accounts SET state=\'\' WHERE id=?',[$id]);
            $this->audit($id,'profile_edit',$m[1].' '.$m[2]);$this->manage($id,(int)$m[1]);return;
        }
        if (str_starts_with($state,'edit:')) {
            $field=substr($state,5);
            if (!in_array($field,['title','bio','interests','preferences'],true)) throw new RuntimeException('Invalid profile field');
            $limit=$field==='title'?80:($field==='bio'?1000:300);
            if (mb_strlen($text)>$limit || trim($text)==='') throw new RuntimeException("Enter 1–{$limit} characters");
            if($this->moderatedProfile($id,$text))return;
            $this->query("UPDATE platform_accounts SET $field=?,state='' WHERE id=?",[$text,$id]);$this->profile($id);return;
        }
        if (!$this->admin($id)) throw new RuntimeException('Access denied');
        if ($state==='lookup') {
            if (!preg_match('/^[1-9]\d{0,15}$/',$text)) throw new RuntimeException('Enter a numeric Telegram user ID');
            $u=$this->one('SELECT * FROM platform_accounts WHERE id=?',[$text]);
            if (!$u) throw new RuntimeException('This user has not opened the new bot panel yet');
            $rows=$this->all('SELECT amount,reason,created FROM platform_ledger WHERE user_id=? ORDER BY id DESC LIMIT 8',[$text]);
            $ledger=implode("\n",array_map(fn($x)=>($x['amount']>0?'+':'').$x['amount'].' · '.$this->e($this->t($id,'ledger.'.$x['reason'])),$rows));
            $this->send($id,$this->t($id,'lookup.body',[
                'user'=>$text,'role'=>$this->t($id,'role.'.$u['role']),'title'=>$this->e($u['title']),
                'balance'=>$u['balance'],'strikes'=>$u['strikes'],'ledger'=>$ledger
            ]),[$this->back($id)]);
        } elseif ($state==='grant') {
            if (!preg_match('/^([1-9]\d{0,15})\s+([1-9]\d{0,6})$/',$text,$m)) throw new RuntimeException('Use: USER_ID AMOUNT (maximum 9,999,999 coins)');
            $target=(int)$m[1];$this->account($target);$this->db->begin_transaction();
            try {$this->wallet($target,(int)$m[2],'admin grant','grant:'.($this->requestKey??bin2hex(random_bytes(16))),$id);$this->query('UPDATE platform_accounts SET state=\'\' WHERE id=?',[$id]);$this->audit($id,'grant',$text);$this->db->commit();}catch(Throwable $e){$this->db->rollback();throw $e;}
            $this->send($id,$this->t($id,'grant.done',['coins'=>$m[2],'user'=>$target]),[$this->back($id)]);
            $this->send($target,$this->t($target,'grant.received',['coins'=>$m[2]]),[[[$this->t($target,'wallet.button'),'wallet']]]);
        } elseif ($state==='role') {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            if (!preg_match('/^([1-9]\d{0,15})\s+(user|admin|superadmin|miss|mister|banned)$/',$text,$m)) throw new RuntimeException('Use: USER_ID user|admin|superadmin|miss|mister|banned');
            $target=(int)$m[1];$u=$this->account($target);
            if ($this->active($target) || ($this->one('SELECT step FROM user WHERE id=?',[$target])['step']??'')==='chat') throw new RuntimeException('End this account’s connection before changing its role');
            $newPanel=match($m[2]){'miss','mister'=>'mm','admin'=>'admin','superadmin'=>'superadmin',default=>'normal'};
            $this->query("UPDATE platform_accounts SET role=?,available=0,panel=?,state='' WHERE id=?",[$m[2],$newPanel,$target]);
            $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);$this->query('DELETE FROM chat WHERE user_id=?',[$target]);$this->query('DELETE FROM chatvip WHERE user_id=?',[$target]);
            $this->syncCommands($target);$this->audit($id,'role',$text);$this->send($id,$this->t($id,'role.done'),[$this->back($id)]);$this->send($target,$this->t($target,'role.received',['role'=>$this->t($target,'role.'.$m[2]),'command'=>match($m[2]){'miss','mister'=>'/mm','admin'=>'/admin','superadmin'=>'/superadmin',default=>'/start'}]));
        } elseif ($state==='reset') {
            if (!$this->super($id) || !preg_match('/^[1-9]\d{0,15}$/',$text)) throw new RuntimeException('Superadmin: enter a numeric user ID');
            $this->query('UPDATE platform_accounts SET strikes=0,available=0 WHERE id=?',[$text]);$this->audit($id,'reset_strikes',$text);$this->send($id,$this->t($id,'reset.done'),[$this->back($id)]);
        } elseif (str_starts_with($state,'setting:')) {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            $key=substr($state,8);if (!array_key_exists($key,SessionRules::defaults())) throw new RuntimeException('Unknown setting');
            if (mb_strlen($text)>2000) throw new RuntimeException('Maximum 2000 characters');
            $next=$this->settings;$next[$key]=$text;SessionRules::validate($next);
            $this->query('INSERT INTO platform_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$text]);$this->audit($id,'setting',$key.'='.$text);$this->settings=$next;$this->panel($id,true);
        } else throw new RuntimeException('Unknown action. Use /start');
        $this->query('UPDATE platform_accounts SET state=\'\' WHERE id=?',[$id]);
    }
    private function action(int $id,string $action): void {
        if($this->ageAction($id,$action))return;
        if($this->anonAction($id,$action))return;
        $a=$this->account($id);$r=$this->settings;
        if($action==='support') {$this->supportPage($id);return;}
        if($action==='language') {$this->languageMenu($id);return;}
        if(str_starts_with($action,'language:')) {
            $language=substr($action,9);
            if(!in_array($language,['en','fa'],true))throw new RuntimeException('Invalid language');
            $this->query("UPDATE platform_accounts SET language=?,state='' WHERE id=?",[$language,$id]);
            $this->languages[$id]=$language;
            $this->syncCommands($id);
            if($a['role']==='banned' && !$this->super($id)) {$this->send($id,$this->t($id,'account.suspended'));return;}
            if(!$this->membershipGate($id,true)||!$this->ensureAdult($id))return;
            if(!$a['accepted'])$this->terms($id);elseif(!$this->readyProfile($a))$this->onboarding($id);else $this->returnPanel($id);
            return;
        }
        if(!in_array($a['language'],['en','fa'],true)) {$this->languageMenu($id);return;}
        if($a['role']==='banned'&&!$this->super($id)){$this->send($id,$this->t($id,'account.suspended'));return;}
        $safeExit=in_array($action,['close','report','status','cancelqueue'],true)||str_starts_with($action,'rq:no:')||str_starts_with($action,'confirmclose:');
        if(!$safeExit&&(!$this->membershipGate($id,$action!=='join:check')||!$this->ensureAdult($id)))return;
        if($action==='accept') {
            $this->leaveLegacy($id);$this->query('UPDATE platform_accounts SET accepted=1 WHERE id=?',[$id]);
            $this->onboarding($id);return;
        }
        if(!$a['accepted']) {$this->terms($id);return;}
        if(!$this->readyProfile($a)&&!$this->active($id)) {if(!$this->onboardingAction($id,$action))$this->onboarding($id);return;}
        if($this->welcomeGate($id,$action))return;
        if($a['state']==='profile:phone')$this->removeKeyboard($id);
        $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);
        if($this->accessAction($id,$action))return;
        if($this->outreachAction($id,$action))return;
        if($action==='anonlink'){$this->anonLinkPage($id);return;}
        if($action==='freecoins'){$this->freeCoins($id);return;}
        if($action==='onlineroles'){$this->onlineRoles($id);return;}
        if(preg_match('/^onlinerole:(submissive|switch)$/',$action,$m)){$this->send($id,$this->t($id,'online.soon',['role'=>$this->t($id,'online.'.$m[1])]),[[[$this->t($id,'ui.back'),'onlineroles']]]);return;}
        if($action==='features'){$this->features($id);return;}
        if($action==='features:options'){$this->options($id,'features');return;}
        if($action==='features:support'){$this->supportPage($id,'features');return;}
        if($action==='features:rules'){$this->send($id,$this->rulesText($r,$id),[[[$this->t($id,'ui.back'),'features']]]);return;}
        if(preg_match('/^features:guide:([0-2])$/',$action,$m)){$this->guide($id,$this->account($id)['panel'],(int)$m[1],'features');return;}
        if($action==='specialsoon'){$this->send($id,$this->t($id,'special.soon'),[$this->back($id)]);return;}
        if($action==='reactionhelp'){$this->send($id,$this->t($id,'v3.reaction.help'));return;}
        if($this->tendencyAction($id,$action))return;
        if($this->profileUpdateAction($id,$action))return;
        if($this->modernAction($id,$action))return;
        if($action==='report') {$this->report($id);return;}
        if($action==='home') {$this->home($id);return;}
        if($action==='panelhome'||$action==='cancel') {
            $this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->returnPanel($id);return;
        }
        if($action==='options') {$this->options($id);return;}
        if ($action==='profile') {$this->profile($id);return;}
        if (str_starts_with($action,'edit:')) {if (!in_array(substr($action,5),['title','bio','interests','preferences'],true)) throw new RuntimeException('Unknown profile field'); if ($this->active($id)||$this->pendingBooking($id)) throw new RuntimeException('Edit your profile after your session'); $this->prompt($id,$action,$this->t($id,'profile.prompt',['field'=>$this->t($id,'field.'.substr($action,5))]));return;}
        if ($action==='rules') {$this->send($id,$this->rulesText($r,$id),[$this->back($id)]);return;}
        if ($action==='admin'||$action==='super') {$this->panel($id,$action==='super');return;}
        if (str_starts_with($action,'prompt:')) {
            $state=substr($action,7);
            if (!$this->admin($id) || (in_array($state,['role','reset'],true) && !$this->super($id))) throw new RuntimeException('Access denied');
            if(!in_array($state,['lookup','grant','role','reset'],true))throw new RuntimeException('Unknown action');
            $this->prompt($id,$state,$this->t($id,'prompt.'.$state));return;
        }
        if (str_starts_with($action,'setting:')) {
            if (!$this->super($id)) throw new RuntimeException('Access denied');
            $key=substr($action,8);if (!array_key_exists($key,$r)) throw new RuntimeException('Unknown setting');
            $this->prompt($id,$action,$this->t($id,'setting.prompt',['setting'=>$this->t($id,'setting.'.$key),'value'=>$this->e((string)$r[$key])]));return;
        }
        if (str_starts_with($action,'directory:')) {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            $page=max(0,(int)substr($action,10));$offset=$page*10;
            $users=$this->all('SELECT id,title,role FROM platform_accounts ORDER BY id LIMIT 10 OFFSET '.$offset);
            $rows=array_map(fn($u)=>[[$this->t($id,'role.'.$u['role']).' · '.($u['title']?:$u['id']),'manage:'.$u['id']]],$users);
            $nav=[];if($page>0)$nav[]=[$this->t($id,'previous'),'directory:'.($page-1)];if(count($users)===10)$nav[]=[$this->t($id,'next'),'directory:'.($page+1)];if($nav)$rows[]=$nav;
            $rows[]=$this->back($id);$this->send($id,$this->t($id,'directory.body'),$rows);return;
        }
        if (str_starts_with($action,'manage:')) {$this->manage($id,(int)substr($action,7));return;}
        if (preg_match('/^editother:([0-9]+):(title|bio|interests|preferences)$/',$action,$m)) {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            $this->prompt($id,$action,$this->t($id,'profile.other_prompt',['field'=>$this->t($id,'field.'.$m[2]),'user'=>$m[1]]));return;
        }
        if (str_starts_with($action,'suspend:')) {
            if (!$this->super($id)) throw new RuntimeException('Superadmin access required');
            $target=(int)substr($action,8);if($this->super($target))throw new RuntimeException('Cannot suspend a superadmin');
            $session=$this->active($target);
            if ($session) {
                $refund=$this->failureRefund($session);
                $this->finish($session,'admin_moderation',$refund);
            }
            $this->query('UPDATE platform_accounts SET role=\'banned\',available=0 WHERE id=?',[$target]);
            $this->query('DELETE FROM platform_queue WHERE user_id=?',[$target]);
            $this->audit($id,'suspend',(string)$target);$this->manage($id,$target);return;
        }
        if ($action==='audit') {
            if (!$this->super($id)) throw new RuntimeException('Access denied');
            $logs=$this->all('SELECT * FROM platform_audit ORDER BY id DESC LIMIT 15');
            $lines=[];
            foreach($logs as $entry)$lines[]=gmdate('m-d H:i',(int)$entry['created']).' · '.$entry['actor'].' · '.$this->t($id,'audit.'.$entry['action']).' · '.$this->e($this->auditDetail($id,$entry));
            $this->send($id,$this->t($id,'audit.body')."\n".implode("\n",$lines),[$this->back($id)]);return;
        }
        if ($action==='wallet') {
            $rows=[[[$this->t($id,'wallet.pack',['stars'=>$r['stars_pack'],'coins'=>$r['coins_pack']]),'buy']]];
            if($r['test_pack_enabled'])$rows[]=[[$this->t($id,'wallet.testpack',['stars'=>$r['test_stars'],'coins'=>$r['test_coins']]),'buytest']];
            $rows[]=[[$this->t($id,'growth.open'),'freecoins']];
            $rows[]=$this->back($id);
            $this->send($id,$this->t($id,'wallet.body',['balance'=>$a['balance']]),$rows);return;
        }
        if ($action==='buy'||$action==='buytest') {$this->invoice($id,$action==='buytest');return;}
        if ($action==='studio') {$this->studio($id);return;}
        if (in_array($action,['online','heartbeat'],true)) {
            if (!$this->provider($a)) throw new RuntimeException('Provider account required');
            if ($action==='online') {
                if($this->activeTendency($id))throw new RuntimeException('End your preference test first');
                if ($a['strikes'] >= $r['strike_limit']) throw new RuntimeException('Availability suspended. Ask a superadmin to review your response strikes');
                if ($this->active($id)||$this->pendingBooking($id)) throw new RuntimeException('End your current session first');
                $this->query('UPDATE platform_accounts SET available=?,heartbeat=? WHERE id=?',[$a['available']?0:1,time(),$id]);
            } elseif ($action==='heartbeat') {$this->query('UPDATE platform_accounts SET heartbeat=? WHERE id=?',[time(),$id]);}
            $this->studio($id);return;
        }
        if (str_starts_with($action,'list:')) {
            $role=substr($action,5);if (!in_array($role,['miss','mister'],true)) throw new RuntimeException('Unknown category');
            if ($this->provider($a)) throw new RuntimeException('Providers cannot book other providers');
            $providers=$this->all("SELECT a.* FROM platform_accounts a LEFT JOIN platform_participants p ON p.user_id=a.id WHERE a.role=? AND a.available=1 AND a.accepted=1 AND a.language IN ('en','fa') AND a.heartbeat>=? AND a.strikes<? AND p.user_id IS NULL ORDER BY a.title LIMIT 30",[$role,time()-$r['online_seconds'],$r['strike_limit']]);
            $rows=array_map(fn($p)=>[[$this->publicText($id,$p['title'])?:$this->content($id,$role.'_title'),'view:'.$p['id']]],$providers);
            $rows[]=[[$this->t($id,'refresh'),$action]];$rows[]=$this->back($id);
            $this->send($id,$this->t($id,'provider.list',[
                'title'=>$this->e($this->content($id,$role.'_title')),
                'description'=>$this->t($id,$providers?'provider.choose':'provider.empty')
            ]),$rows);return;
        }
        if (str_starts_with($action,'view:')) { $p=$this->knownAccount((int)substr($action,5));$this->providerCard($id,$p['public_key']);return;
        }
        if (str_starts_with($action,'connect:')) {
            $parts=explode(':',$action);
            if(count($parts)!==3 || $parts[2]!==substr(hash('sha256',json_encode($r)),0,12)) throw new RuntimeException('Connection terms changed. Reopen the provider profile and review the new price before confirming.');
            $this->requestBooking($id,(int)$parts[1]);return;
        }
        if(str_starts_with($action,'react:')) {
            $this->callbackContext=null;
            $parts=explode(':',$action);
            if(count($parts)!==3 || ($parts[2]!=='none' && !isset(self::REACTIONS[$parts[2]])))throw new RuntimeException('Unsupported reaction.');
            $map=$this->one('SELECT * FROM platform_messages WHERE token=?',[$parts[1]]);
            if(!$map || $map['target_chat']!=$id)throw new RuntimeException('React only to a message received from your current partner.');
            $emoji=self::REACTIONS[$parts[2]]??'';
            if($map['target_reaction']===$emoji)$emoji='';
            $this->mirrorReaction($id,$map,$emoji,time());return;
        }
        if(str_starts_with($action,'moderation:')) {
            if(!$this->admin($id))throw new RuntimeException('Access denied');
            $page=max(0,(int)substr($action,11));$offset=$page*10;
            $entries=$this->all('SELECT * FROM platform_moderation ORDER BY id DESC LIMIT 10 OFFSET '.$offset);
            $text=$this->t($id,'moderation.log');
            foreach($entries as $entry)$text.="\n".$this->t($id,'moderation.entry',['user'=>$entry['user_id'],'reason'=>$this->t($id,'moderation.reason.'.$entry['reason']),'time'=>gmdate('m-d H:i',(int)$entry['created'])]);
            if(!$entries)$text.="\n".$this->t($id,'moderation.empty');
            $rows=[];$nav=[];if($page>0)$nav[]=[$this->t($id,'previous'),'moderation:'.($page-1)];if(count($entries)===10)$nav[]=[$this->t($id,'next'),'moderation:'.($page+1)];if($nav)$rows[]=$nav;$rows[]=$this->back($id);
            $this->send($id,$text,$rows);return;
        }
        if($action==='typing'||str_starts_with($action,'typing:')) {
            $session=$this->active($id);
            if(!$session)throw new RuntimeException('No active connection');
            if(str_starts_with($action,'typing:') && substr($action,7)!==(string)$session['id'])throw new RuntimeException('This typing button belongs to an earlier connection. Use /typing again.');
            $partner=(int)($session['customer']==$id?$session['provider']:$session['customer']);
            jijibot('sendChatAction',['chat_id'=>$partner,'action'=>'typing']);return;
        }
        if ($action==='normal') {$this->normal($id);return;}
        if ($action==='cancelqueue') {$this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);$this->home($id);return;}
        if ($action==='status'||$action==='close'||str_starts_with($action,'confirmclose:')) {
            $s=$this->active($id);if (!$s) throw new RuntimeException('No active connection');
            if(str_starts_with($action,'confirmclose:') && substr($action,13)!==(string)$s['id']) throw new RuntimeException('This confirmation belongs to an earlier connection. Use /stop again.');
            $rules=json_decode($s['rules'],true);$elapsed=time()-$s['started'];$isProvider=$s['kind']==='paid' && $s['provider']==$id;
            if($action==='status') {
                $remaining=$this->t($id,'session.remaining',['seconds'=>max(0,(int)$s['paid_until']-time())]);
                $this->send($id,$this->t($id,'session.status',['id'=>$s['id'],'minutes'=>(int)floor($elapsed/60),'coins'=>$s['charged'],'remaining'=>$remaining]),[
                    [[$this->t($id,'close.button'),'close','danger']],
                    [[$this->t($id,'ui.report'),'report','danger']],$this->back($id)
                ]);return;
            }
            if ($isProvider && $elapsed < $rules['provider_leave_minutes']*60) throw new RuntimeException('You can end this session after minute '.$rules['provider_leave_minutes'].'. Use /report for safety concerns.');
            $refund=$s['kind']==='paid'?SessionRules::closeRefund($rules,$elapsed,$isProvider?'provider':'customer'):0;
            if($action==='close') {
                $explanation=$isProvider?$this->t($id,'session.close_provider',['coins'=>$refund]):$this->t($id,'session.close_customer');
                $this->send($id,$this->t($id,'session.close',['explanation'=>$explanation]),[
                    [[$this->t($id,'session.confirm'),'confirmclose:'.$s['id']],[$this->t($id,'session.keep'),'status']]
                ]);return;
            }
            $this->finish($s,$isProvider?'provider_left':'user_left',$refund);return;
        }
        throw new RuntimeException('This button is unavailable. Open /start');
    }
    private function report(int $id): void {
        $session=$this->active($id);$this->audit($id,'safety_report',$session?'Session '.$session['id']:'No active session');
        if($session) {
            $refund=$session['kind']==='paid'&&$session['provider']==$id?$this->failureRefund($session):0;
            $this->finish($session,'safety_report',$refund);
            if($session['kind']==='paid')$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$session['provider']]);
        }
        $this->send($id,$this->t($id,'report.done',['support'=>$this->e($this->content($id,'support'))]),[$this->supportRow($id),$this->back($id)]);
    }
    private function leaveLegacy(int $id): void {
        $legacy=$this->one('SELECT step,side FROM user WHERE id=?',[$id]);
        $this->query('DELETE FROM chat WHERE user_id=?',[$id]);$this->query('DELETE FROM chatvip WHERE user_id=?',[$id]);
        if (($legacy['step']??'')==='chat') {
            $peer=(int)$legacy['side'];
            $this->query('UPDATE user SET step=\'none\',side=0 WHERE id=?',[$id]);
            $result=$this->query('UPDATE user SET step=\'none\',side=0 WHERE id=? AND side=?',[$peer,$id]);
            if ($result->affected_rows) $this->send($peer,$this->t($peer,'legacy.ended'));
        }
    }

    private function terms(int $id): void {
        $text=(!$this->account($id)['accepted']?$this->t($id,'setup.terms'):'').$this->t($id,'v3.age');
        $this->send($id,$text,[
            [[$this->t($id,'age.accept'),'accept']],
            [[$this->t($id,'language.button'),'language']]
        ]);
    }

    public function handle(object $update): bool {
        if(isset($update->pre_checkout_query))return $this->processUpdate($update);
        $key=$update->update_id??null;
        if($key!==null&&$this->one('SELECT update_id FROM platform_processed WHERE update_id=?',[$key]))return true;
        $handled=$this->processUpdate($update);
        $actor=(int)($update->message->from->id??$update->callback_query->from->id??0);
        if($handled&&$key!==null)$this->query('INSERT IGNORE INTO platform_processed(update_id,created) VALUES(?,?)',[$key,time()]);
        if($handled&&$actor>0){try{$this->settleReferral($actor);}catch(Throwable $e){error_log('Referral settlement deferred to maintenance.');}}
        if($handled&&$actor>0&&!$this->anonymousUpdate){try{$this->maybeAdvertisement($actor);}catch(Throwable $e){error_log('Advertisement delivery failed; primary update completed.');}}
        return $handled;
    }
    private function processUpdate(object $update): bool {
        $this->callbackContext=null;$this->anonymousUpdate=false;
        $this->requestKey=isset($update->update_id)?(string)$update->update_id:null;
        if(isset($update->message_reaction)) {$this->nativeReaction($update->message_reaction);return true;}
        if(isset($update->message_reaction_count))return true;
        if($this->payment($update))return true;
        $cb=$update->callback_query??null;$msg=$update->message??null;
        $id=(int)($cb->from->id??$msg->from->id??0);
        $type=$cb->message->chat->type??$msg->chat->type??'';
        if(!$id || $type!=='private' || ($msg && ($msg->chat->id??0)!=$id))return false;
        $this->markReachability($id,true);
        $a=$this->account($id);$this->languages[$id]=$a['language']==='fa'?'fa':'en';$text=trim($msg->text??'');
        $command=preg_replace('/@\w+$/','',explode(' ',$text)[0]);
        if($id>0)$this->growthTouch($id);
        $from=$cb->from??$msg->from;
        $this->query('UPDATE platform_accounts SET telegram_username=? WHERE id=?',[$from->username??'',$id]);
        $this->query('UPDATE platform_accounts SET heartbeat=? WHERE id=?',[time(),$id]);
        if($cb) {
            $this->callbackContext=['chat'=>$id,'message'=>(int)$cb->message->message_id];
            if(!($GLOBALS['callback_acknowledged']??false))jijibot('answerCallbackQuery',['callback_query_id'=>$cb->id]);
            $action=str_starts_with($cb->data??'','p:')?substr($cb->data,2):'';
            if($a['role']==='banned' && !$this->super($id) && $action!=='support' && $action!=='language' && !str_starts_with($action,'language:')) {
                $this->send($id,$this->t($id,'account.suspended'));return true;
            }
            if($action==='') {
                if(!in_array($a['language'],['en','fa'],true))$this->languageMenu($id);
                else $this->send($id,$this->t($id,'controls.updated'));
                return true;
            }
            $this->action($id,$action);return true;
        }
        if($msg&&$command==='/start'&&!($msg->from->is_bot??false)){
            $this->welcomeCredit($id);$parameter=trim(substr($text,strlen(explode(' ',$text)[0])));
            if(preg_match('/^r_([a-f0-9]{24})$/D',$parameter,$m))$this->recordReferral($id,$m[1]);
            if(preg_match('/^a_([a-f0-9]{32})$/D',$parameter,$m)){$this->anonOpen($id,$m[1]);return true;}
        }
        if($msg&&$this->navigationInput($id,$msg,$text))return true;
        if($msg&&$this->anonMessageInput($id,$msg,$text))return true;
        if((int)$a['age']>0&&!$this->ageEligible($a)){if($session=$this->active($id))$this->finish($session,'age_restricted',$this->failureRefund($session));$this->ensureAdult($id);return true;}
        // Forwarded commands and blocked contact details must not bypass chat moderation.
        if($this->active($id)) {
            $reason=MessageModeration::message($msg,$this->settings);
            if($reason) {$this->blockContent($id,$reason);return true;}
        }
        // Save the requested panel during onboarding so acceptance returns to that panel.
        $requested=match($command) {
            '/start','/menu'=>'normal','/admin','/panel'=>'admin','/mm','/provider'=>'mm','/superadmin'=>'superadmin',default=>null
        };
        if($requested!==null) {
            $allowed=match($requested){'admin'=>$this->admin($id),'superadmin'=>$this->super($id),'mm'=>$this->provider($a),default=>true};
            if($allowed)$this->setPanel($id,$requested);
        }
        if($command==='/language') {$this->languageMenu($id);return true;}
        if(!in_array($a['language'],['en','fa'],true)) {$this->languageMenu($id);return true;}
        if($command==='/paysupport'||$command==='/support') {$this->supportPage($id);return true;}
        if($a['role']==='banned' && !$this->super($id)) {$this->send($id,$this->t($id,'account.suspended'));return true;}
        if($this->active($id)&&$this->chatKeyboardInput($id,$msg,$text))return true;
        if(!in_array($command,['/stop','/report','/cancel'],true)&&!$this->membershipGate($id,str_starts_with($text,'/')))return true;
        if(!$this->active($id)&&!$this->ensureAdult($id))return true;
        if($command==='/terms') {$this->terms($id);return true;}
        if(!$a['accepted']) {$this->terms($id);return true;}
        if(!$this->readyProfile($a) && !$this->active($id)) {
            if($a['state']==='onboard:name' && $text!=='' && !str_starts_with($text,'/'))$this->profileUpdateInput($id,$a,$text);else $this->onboarding($id);return true;
        }
        if($msg&&!str_starts_with($text,'/')&&($this->accessInput($id,$a,$msg)||$this->outreachInput($id,$a,$msg)))return true;
        if($this->welcomeGate($id,$command==='/test'?'quiz':null))return true;
        if($a['state']==='profile:phone') {
            if($command==='/cancel'||$command==='/start') {$this->query("UPDATE platform_accounts SET state='' WHERE id=?",[$id]);$this->removeKeyboard($id);$this->profile($id);}
            else $this->receivePhone($id,$msg);return true;
        }
        if($this->chatKeyboardInput($id,$msg,$text))return true;
        if($command==='/start'||$command==='/menu') {
            if((int)$a['ui_version']<2) {
                if(!$this->active($id)&&$this->one('SELECT id FROM user WHERE id=?',[$id]))jijibot('sendMessage',['chat_id'=>$id,'text'=>$this->t($id,'controls.updated'),'reply_markup'=>json_encode(['remove_keyboard'=>true])]);
                $this->query('UPDATE platform_accounts SET ui_version=2 WHERE id=?',[$id]);
            }$this->home($id);if($this->active($id))$this->chatKeyboard($id);return true;
        }
        if($command==='/superadmin') {$this->panel($id,true);return true;}
        if($command==='/admin'||$command==='/panel') {$this->panel($id,false);return true;}
        if($command==='/mm'||$command==='/provider') {$this->studio($id);return true;}
        if($command==='/test') {$this->tendencyHome($id);return true;}
        if($command==='/help') {$this->guide($id,$this->account($id)['panel'],0);return true;}
        if($command==='/options') {$this->options($id);return true;}
        if($command==='/wallet') {$this->action($id,'wallet');return true;}
        if($command==='/profile') {$this->profile($id);return true;}
        if($command==='/react') {
            $argument=trim(substr($text,strlen(explode(' ',$text)[0])));
            if(!$argument || !isset($msg->reply_to_message)) {$this->send($id,$this->t($id,'reaction.help'));return true;}
            $map=$this->reactionMap($id,(int)$msg->reply_to_message->message_id);
            if(!$map || $map['target_chat']!=$id)throw new RuntimeException('No relayed message found.');
            $emoji=$argument==='clear'?'':$argument;
            if($emoji==='❤️')$emoji='❤';
            if($emoji!=='' && !in_array($emoji,self::REACTIONS,true))throw new RuntimeException('Unsupported reaction.');
            $this->mirrorReaction($id,$map,$emoji,time());return true;
        }
        if($command==='/typing') {$this->action($id,'typing');return true;}
        if($command==='/stop') {$this->action($id,'close');return true;}
        if($command==='/cancel') {$this->action($id,'cancel');return true;}
        if($command==='/report') {$this->report($id);return true;}
        if($a['state']===''&&!$this->active($id)&&$this->activeTendency($id)){$this->tendencyQuestion($id);return true;}
        $session=$this->active($id);
        if($session && $session['kind']==='normal' && time()>=(int)$session['paid_until']) {$this->finish($session,'normal_time_ended');return true;}
        if($session) {
            if(str_starts_with($text,'/')) {$this->send($id,$this->t($id,'commands.help'));return true;}
            if(!$msg || (!isset($msg->text) && !isset($msg->photo) && !isset($msg->voice) && !isset($msg->video) && !isset($msg->animation) && !isset($msg->sticker) && !isset($msg->document) && !isset($msg->audio) && !isset($msg->video_note))) {
                $this->send($id,$this->t($id,'media.unsupported'));return true;
            }
            $to=(int)($session['customer']==$id?$session['provider']:$session['customer']);
            $existing=$this->one('SELECT * FROM platform_messages WHERE source_chat=? AND source_message=?',[$id,$msg->message_id]);
            if($existing && $existing['target_message'])return true;
            $token=$existing['token']??bin2hex(random_bytes(16));
            if(!$existing)$this->query('INSERT INTO platform_messages(session_id,token,source_chat,source_message,target_chat,created,text_cipher) VALUES(?,?,?,?,?,?,?)',[$session['id'],$token,$id,$msg->message_id,$to,time(),$this->encryptChatText($msg->text??$msg->caption??null)]);
            $chatAction=match(true) {
                isset($msg->photo)=>'upload_photo',isset($msg->video),isset($msg->animation)=>'upload_video',
                isset($msg->voice),isset($msg->audio)=>'upload_voice',isset($msg->video_note)=>'upload_video_note',
                isset($msg->document)=>'upload_document',isset($msg->sticker)=>'choose_sticker',default=>'typing'
            };
            jijibot('sendChatAction',['chat_id'=>$to,'action'=>$chatAction]);
            $result=jijibot('copyMessage',['chat_id'=>$to,'from_chat_id'=>$id,'message_id'=>$msg->message_id,'protect_content'=>'true']);
            if(!$result || !$result->ok) {
                $refund=$this->failureRefund($session);
                $this->finish($session,'delivery_failed',$refund);return true;
            }
            $this->query('UPDATE platform_messages SET target_message=? WHERE token=?',[$result->result->message_id,$token]);
            if($session['kind']==='paid') {
                if($session['customer']==$id)$this->query('UPDATE platform_sessions SET pending_since=IF(pending_since=0,?,pending_since),unanswered=unanswered+1 WHERE id=?',[time(),$session['id']]);
                else $this->query('UPDATE platform_sessions SET pending_since=0,unanswered=0 WHERE id=?',[$session['id']]);
            }return true;
        }
        if($a['state']!=='' && $text!=='') {$this->input($id,$a,$text);return true;}
        $this->returnPanel($id);return true;
    }
}
