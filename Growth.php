<?php
/** Welcome credit and last-valid-invite attribution, with transactional, unique ledger credits. */
trait PlatformGrowth {
    private function completeReferralProfile(array $a): bool {
        return $this->ageEligible($a)&&$a['role']!=='banned'&&$a['accepted']&&$this->readyProfile($a)&&in_array($a['language'],['en','fa'],true)&&$a['province_id']&&$a['city_id'];
    }
    private function growthTouch(int $id): array {
        $a=$this->account($id);$completed=$this->completeReferralProfile($a)?time():0;
        $this->query('INSERT IGNORE INTO platform_growth(user_id,completed) VALUES(?,?)',[$id,$completed]);
        return $this->one('SELECT * FROM platform_growth WHERE user_id=?',[$id]);
    }
    private function welcomeCredit(int $id): void {
        if($id<=0||$this->account($id)['role']==='banned')return;$this->growthTouch($id);
        $this->db->begin_transaction();try{
            $g=$this->one('SELECT * FROM platform_growth WHERE user_id=? FOR UPDATE',[$id]);
            if(!$g['welcome_claimed']){
                $this->wallet($id,10,'welcome gift','welcome:'.$id);
                $this->query('UPDATE platform_growth SET welcome_claimed=1,started=? WHERE user_id=?',[time(),$id]);
            }$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    private function recordReferral(int $id,string $token): void {
        $g=$this->growthTouch($id);if($g['completed']||$g['referral_paid']||$this->completeReferralProfile($this->account($id)))return;
        $r=$this->one('SELECT user_id FROM platform_growth WHERE referral_token=?',[$token]);
        if(!$r||$r['user_id']==$id||!$this->completeReferralProfile($this->account((int)$r['user_id'])))return;
        $this->query('UPDATE platform_growth SET inviter_id=?,attributed=? WHERE user_id=? AND completed=0 AND referral_paid=0',[$r['user_id'],time(),$id]);
    }
    private function settleReferral(int $id): void {
        $a=$this->account($id);if(!$this->completeReferralProfile($a)||!$this->accessEligible($id))return;
        $this->growthTouch($id);$rewarded=null;
        $this->db->begin_transaction();try{
            $g=$this->one('SELECT * FROM platform_growth WHERE user_id=? FOR UPDATE',[$id]);
            if(!$g['completed']){
                $inviter=(int)($g['inviter_id']??0);$valid=$inviter>0&&$inviter!==$id&&$this->completeReferralProfile($this->account($inviter));
                $this->query('UPDATE platform_growth SET completed=?,referral_paid=?,inviter_reward=?,invitee_reward=? WHERE user_id=?',[time(),(int)$valid,$valid?10:0,$valid?10:0,$id]);
                if($valid){
                    $this->wallet($inviter,10,'referral reward','referral:inviter:'.$id);
                    $this->wallet($id,10,'invitee reward','referral:invitee:'.$id);$rewarded=$inviter;
                }
            }$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        if($rewarded!==null){$this->send($id,$this->t($id,'growth.invitee.reward'));$this->send($rewarded,$this->t($rewarded,'growth.inviter.reward'));}
    }
    private function botDeepLink(string $parameter): string {
        $name=ltrim(trim((string)($this->env['BOT_USERNAME']??'')),'@');
        if(!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$name)){
            $me=jijibot('getMe');$name=$me->result->username??'';
            if(!($me->ok??false)||!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$name))throw new RuntimeException('This action expired. Open the panel again.');
        }
        return 'https://t.me/'.$name.'?start='.$parameter;
    }
    private function growthMaintenance(): void {
        foreach($this->all("SELECT g.user_id FROM platform_growth g JOIN platform_accounts a ON a.id=g.user_id WHERE g.completed=0 AND a.accepted=1 AND a.name_set=1 AND a.gender IN ('female','male') AND a.province_id<>0 AND a.city_id<>0 AND a.role<>'banned'".$this->accessSql('a')." LIMIT 10") as $row)$this->settleReferral((int)$row['user_id']);
        $this->query("UPDATE platform_anon_messages SET status='uncertain' WHERE status='sending' AND created<?",[time()-180]);
        $this->query('DELETE FROM platform_anon_intents WHERE expires<?',[time()]);
    }
    private function freeCoins(int $id): void {
        $this->growthTouch($id);$a=$this->account($id);
        $stats=$this->one('SELECT COUNT(*) total,COALESCE(SUM(inviter_reward),0) coins FROM platform_growth WHERE inviter_id=? AND referral_paid=1',[$id]);
        $g=$this->one('SELECT * FROM platform_growth WHERE user_id=?',[$id]);$rows=[];
        $text=$this->t($id,'growth.body',['invites'=>$stats['total'],'coins'=>$stats['coins'],'welcome'=>$this->t($id,$g['welcome_claimed']?'growth.claimed':'growth.unclaimed')]);
        if($this->completeReferralProfile($a)){
            if(!$g['referral_token']){$token=bin2hex(random_bytes(12));$this->query('UPDATE platform_growth SET referral_token=? WHERE user_id=? AND referral_token IS NULL',[$token,$id]);$g=$this->one('SELECT * FROM platform_growth WHERE user_id=?',[$id]);}
            $url=$this->botDeepLink('r_'.$g['referral_token']);$text.="\n\n".$this->t($id,'growth.link',['url'=>$this->e($url)]);
            $rows[]=[[$this->t($id,'growth.copy'),['copy_text'=>['text'=>$url]]]];
        }else{$text.="\n\n".$this->t($id,'growth.complete');$rows[]=[[$this->t($id,'profile.button'),'profile']];}
        $rows[]=[[$this->t($id,'ui.back'),'home']];$this->send($id,$text,$rows);
    }
}
