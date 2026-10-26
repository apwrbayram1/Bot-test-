<?php
/** Self-reported age is collected independently of optional profile/test fields. */
trait PlatformAge {
    private function effectiveAge(array $a): int {
        $age=(int)($a['age']??0);if(!$age)return 0;
        return $age+max(0,(int)floor((time()-(int)$a['age_set_at'])/31557600));
    }
    private function ageEligible(array $a): bool {
        $age=$this->effectiveAge($a);return true;
    }
    private function ensureAdult(int $id): bool {
        $a=$this->account($id);if($this->ageEligible($a))return true;
        if((int)$a['age']>0){$this->send($id,$this->t($id,'age.rejected'),[[$this->uiButton($id,'ui.support','support')],[[$this->t($id,'language.button'),'language']]]);return false;}
        $this->agePage($id);return false;
    }
    private function agePage(int $id): void {
        $a=$this->account($id);if((int)$a['age']>0){$this->ensureAdult($id);return;}
        $this->query("UPDATE platform_ui SET used=1 WHERE actor=? AND kind IN ('agepick','ageconfirm')",[$id]);
        $token=$this->token($id,'agepick',['old_age'=>(int)$a['age'],'old_set'=>(int)$a['age_set_at']],1800);$rows=[];
        foreach(array_chunk(range(12,50),6) as $values){$row=[];foreach($values as $value)$row[]=[(string)$value,'agepick:'.$value.':'.$token];$rows[]=$row;}
        $rows[]=[[$this->t($id,'language.button'),'language']];$this->send($id,$this->t($id,'age.prompt'),$rows);
    }
    private function ageAction(int $id,string $action): bool {
        if($action==='age:choose'){$this->agePage($id);return true;}
        if(!preg_match('/^(agepick|ageconfirm):/',$action))return false;
        $a=$this->account($id);if($a['role']==='banned'){$this->send($id,$this->t($id,'account.suspended'));return true;}
        if(!in_array($a['language'],['en','fa'],true)){$this->languageMenu($id);return true;}
        if(!$this->membershipGate($id,true))return true;
        if(preg_match('/^agepick:(1[2-9]|[2-4][0-9]|50):([a-f0-9]{16})$/D',$action,$m)){
            $draft=$this->uiToken($id,$m[2],'agepick');
            if((int)$a['age']!==$draft['data']['old_age']||(int)$a['age_set_at']!==$draft['data']['old_set'])throw new RuntimeException('This action expired. Open the panel again.');
            $token=$this->token($id,'ageconfirm',$draft['data']+['age'=>(int)$m[1]],600);
            $this->send($id,$this->t($id,'age.review',['age'=>$m[1]]),[[[$this->t($id,'age.confirm'),'ageconfirm:'.$token],[$this->t($id,'age.change'),'age:choose']]]);return true;
        }
        if(preg_match('/^ageconfirm:([a-f0-9]{16})$/D',$action,$m)){
            $draft=$this->uiToken($id,$m[1],'ageconfirm');$d=$draft['data'];
            if((int)$a['age']!==$d['old_age']||(int)$a['age_set_at']!==$d['old_set'])throw new RuntimeException('This action expired. Open the panel again.');
            $this->db->begin_transaction();try{
                if(!$this->query('UPDATE platform_ui SET used=1 WHERE token=? AND used=0',[$m[1]])->affected_rows)throw new RuntimeException('This action expired. Open the panel again.');
                $this->query('UPDATE platform_accounts SET age=?,age_set_at=?,accepted=IF(?<18,0,accepted) WHERE id=?',[$d['age'],time(),$d['age'],$id]);
                $this->query("UPDATE platform_ui SET used=1 WHERE actor=? AND kind IN ('agepick','ageconfirm')",[$id]);$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}
            if(false){
                if($session=$this->active($id))$this->finish($session,'age_restricted',$this->failureRefund($session));
                if($quiz=$this->activeTendency($id))$this->endTendency($id,$quiz['token'],'cancelled',false);
                $this->query("UPDATE platform_requests SET status='expired' WHERE status='pending' AND (customer=? OR provider=?)",[$id,$id]);
                $this->query('DELETE FROM platform_queue WHERE user_id=?',[$id]);$this->query('UPDATE platform_accounts SET available=0 WHERE id=?',[$id]);$this->query('DELETE FROM platform_anon_intents WHERE user_id=?',[$id]);$this->ensureAdult($id);return true;}
            if($this->anonIntent($id)){$this->anonPrompt($id);return true;}
            $a=$this->account($id);if(!$a['accepted'])$this->terms($id);elseif(!$this->readyProfile($a))$this->onboarding($id);else $this->returnPanel($id);return true;
        }
        throw new RuntimeException('This action expired. Open the panel again.');
    }
}
