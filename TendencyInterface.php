<?php
trait PlatformTendency
{
    private function activeTendency(int $id): ?array {
        $a=$this->one("SELECT t.* FROM platform_tendency_attempts t JOIN platform_tendency_active a ON a.attempt_token=t.token WHERE a.user_id=? AND t.user_id=? AND t.status='active'",[$id,$id]);
        if($a&&(int)$a['deadline']<=time()){$this->endTendency($id,$a['token'],'expired',false);return null;}return $a;
    }
    private function tendencyOwned(int $id,string $token,?string $status=null): array {
        // Deliberately no admin/superadmin bypass: results and answers belong only to the tester.
        $a=$this->one('SELECT * FROM platform_tendency_attempts WHERE token=? AND user_id=?',[$token,$id]);
        if(!$a||($status!==null&&$a['status']!==$status))throw new RuntimeException('Private test not found');return $a;
    }
    private function tendencyGuard(int $id): bool {
        if(!$this->activeTendency($id))return false;
        $this->send($id,$this->t($id,'quiz.finish.first'),[[[$this->t($id,'quiz.resume'),'quizresume'],[$this->t($id,'quiz.open'),'quiz']]]);return true;
    }
    private function tendencySend(int $id,string $text,array $rows,?array $attempt=null): void {
        if($this->replyNavigation()){$this->menuSend($id,$text,$rows,true);return;}
        $this->callbackContext=null;
        $data=['chat_id'=>$id,'text'=>$text,'parse_mode'=>'HTML','link_preview_options'=>json_encode(['is_disabled'=>true]),'reply_markup'=>$this->buttons($rows)];
        if($attempt&&(int)$attempt['menu_message']) {
            $data['message_id']=(int)$attempt['menu_message'];$response=jijibot('editMessageText',$data);
            if(($response->ok??false)||str_contains($response->description??'','message is not modified'))return;
            unset($data['message_id']);
        }
        $data['protect_content']='true';$response=jijibot('sendMessage',$data);
        if(($response->ok??false)&&$attempt)$this->query('UPDATE platform_tendency_attempts SET menu_message=? WHERE token=? AND user_id=?',[$response->result->message_id,$attempt['token'],$id]);
    }
    private function tendencyHome(int $id): void {
        $a=$this->activeTendency($id);$rows=[];
        if($a){$rows[]=[[$this->t($id,'quiz.resume'),'quizresume','primary']];$rows[]=[[$this->t($id,'quiz.restart'),'quizend:'.$a['token']]];}
        elseif($this->settings['tendency_enabled']) {
            foreach(['quick','full'] as $mode) {
                $bank=TendencyTest::bank($mode);$token=$this->token($id,'quizstart',['mode'=>$mode,'version'=>$bank['version'],'seconds'=>$this->settings['tendency_seconds']],900);
                $rows[]=[[$this->t($id,'quiz.mode.'.$mode,['count'=>count($bank['questions']),'areas'=>count($bank['roles'])]),'qs:'.$token,$mode==='quick'?'primary':'success']];
            }
        }
        $latest=$this->one("SELECT token FROM platform_tendency_attempts WHERE user_id=? AND status='completed' ORDER BY completed DESC,id DESC LIMIT 1",[$id]);
        if($latest)$rows[]=[[$this->t($id,'quiz.last'),'qr:'.$latest['token'].':0'],[$this->t($id,'quiz.history'),'qh:0']];
        $rows[]=[[$this->t($id,'quiz.scoring'),'quizhow'],[$this->t($id,'ui.back'),'home']];
        $text=$this->t($id,'quiz.intro',['title'=>$this->e($this->content($id,'tendency_title')),'time'=>$this->tendencyTime((int)$this->settings['tendency_seconds'])]);
        if(!$this->settings['tendency_enabled'])$text.="\n\n".$this->t($id,'quiz.paused');
        if($a)$text.="\n\n".$this->t($id,'quiz.remaining',['time'=>$this->tendencyTime((int)$a['deadline']-time())]);
        $this->tendencySend($id,$text,$rows);
    }
    private function startTendency(int $id,string $token): void {
        $draft=$this->uiToken($id,$token,'quizstart');$a=$this->account($id);
        if(!$a['accepted']||!$this->readyProfile($a)||$a['role']==='banned')throw new RuntimeException('Access denied');
        if(!$this->settings['tendency_enabled'])throw new RuntimeException('Preference tests are paused');
        if($this->active($id)||$this->pendingBooking($id)||($this->one('SELECT step FROM user WHERE id=?',[$id])['step']??'')==='chat')throw new RuntimeException('End your current connection first');
        if($this->activeTendency($id)){$this->tendencyQuestion($id);return;}
        $bank=TendencyTest::bank($draft['data']['mode']);if($bank['version']!==$draft['data']['version'])throw new RuntimeException('This test changed. Open the test menu again.');
        $payload=['bank'=>$bank,'answers'=>[],'result'=>null];$cipher=TendencyTest::encrypt($payload);$attempt=bin2hex(random_bytes(8));$now=time();
        $this->db->begin_transaction();
        try {
            if($this->query('UPDATE platform_ui SET used=1 WHERE token=? AND actor=? AND used=0 AND expires>?',[$token,$id,$now])->affected_rows!==1)throw new RuntimeException('This action expired. Open the panel again.');
            $this->query('INSERT INTO platform_tendency_attempts(token,user_id,mode,version,started,deadline,payload_cipher) VALUES(?,?,?,?,?,?,?)',[$attempt,$id,$bank['mode'],$bank['version'],$now,$now+(int)$draft['data']['seconds'],$cipher]);
            $this->query('INSERT INTO platform_tendency_active(user_id,attempt_token) VALUES(?,?)',[$id,$attempt]);
            foreach(['platform_queue','chat','chatvip'] as $table)$this->query('DELETE FROM '.$table.' WHERE user_id=?',[$id]);
            $this->query("UPDATE platform_accounts SET available=0,state='' WHERE id=?",[$id]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        $this->tendencyQuestion($id);
    }
    private function tendencyTime(int $seconds): string {$seconds=max(0,$seconds);return sprintf('%02d:%02d',intdiv($seconds,60),$seconds%60);}
    private function tendencyQuestion(int $id): void {
        $a=$this->activeTendency($id);if(!$a){$this->tendencyHome($id);return;}
        $payload=TendencyTest::decrypt($a['payload_cipher']);$questions=$payload['bank']['questions'];$index=(int)$a['question_index'];$q=$questions[$index];$lang=$this->languageFor($id);
        $progress=(int)floor($index/count($questions)*10);$bar=str_repeat('▰',$progress).str_repeat('▱',10-$progress);
        $text=$this->t($id,'quiz.question',['index'=>$index+1,'total'=>count($questions),'bar'=>$bar,'time'=>$this->tendencyTime((int)$a['deadline']-time()),'text'=>$this->e($q['text_'.$lang])]);
        if(array_key_exists($index,$payload['answers'])){$answer=$payload['answers'][$index];$text.="\n\n".$this->t($id,'quiz.previous.answer',['answer'=>$answer===null?$this->t($id,'quiz.skip'):$this->t($id,'quiz.rating.'.($answer+3))]);}
        $route='qa:'.$a['token'].':'.$index.':'.$a['revision'].':';$rows=[];
        foreach([[3,2],[1,0],[-1,-2],[-3]] as $ratings){$row=[];foreach($ratings as $rating)$row[]=[$this->t($id,'quiz.rating.'.($rating+3)),$route.($rating+3)];$rows[]=$row;}
        $rows[]=[[$this->t($id,'quiz.skip'),$route.'s']];$nav=[];
        if($index>0)$nav[]=[$this->t($id,'previous'),'qb:'.$a['token'].':'.$a['revision']];
        $nav[]=[$this->t($id,'quiz.exit'),'quizend:'.$a['token']];$rows[]=$nav;
        $this->tendencySend($id,$text,$rows,$a);
    }
    private function answerTendency(int $id,string $token,int $index,int $revision,?int $answer): void {
        $a=$this->tendencyOwned($id,$token,'active');
        if(time()>=(int)$a['deadline']){$this->endTendency($id,$token,'expired');return;}
        if($this->activeTendency($id)['token']!==$token)throw new RuntimeException('Private test not found');
        if((int)$a['question_index']!==$index||(int)$a['revision']!==$revision){$this->tendencyQuestion($id);return;}
        if($answer!==null&&($answer< -3||$answer>3))throw new RuntimeException('Invalid test answer');
        $payload=TendencyTest::decrypt($a['payload_cipher']);$payload['answers'][$index]=$answer;$next=$index+1;$done=$next>=count($payload['bank']['questions']);
        if($done)$payload['result']=TendencyTest::score($payload['bank'],$payload['answers']);
        $cipher=TendencyTest::encrypt($payload);$this->db->begin_transaction();
        try {
            $written=$this->query('UPDATE platform_tendency_attempts SET question_index=?,revision=revision+1,payload_cipher=?,status=?,completed=? WHERE token=? AND user_id=? AND question_index=? AND revision=? AND status=\'active\' AND deadline>?',[$next,$cipher,$done?'completed':'active',$done?time():0,$token,$id,$index,$revision,time()]);
            if($written->affected_rows!==1)throw new RuntimeException('This action expired. Open the panel again.');
            if($done)$this->query('DELETE FROM platform_tendency_active WHERE user_id=? AND attempt_token=?',[$id,$token]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        if($done){if(!$this->welcomeGate($id))$this->tendencyResult($id,$token,0,true);}else $this->tendencyQuestion($id);
    }
    private function backTendency(int $id,string $token,int $revision): void {
        $a=$this->tendencyOwned($id,$token,'active');if(time()>=(int)$a['deadline']){$this->endTendency($id,$token,'expired');return;}
        if((int)$a['revision']===$revision&&(int)$a['question_index']>0)$this->query('UPDATE platform_tendency_attempts SET question_index=question_index-1,revision=revision+1 WHERE token=? AND user_id=? AND revision=?',[$token,$id,$revision]);
        $this->tendencyQuestion($id);
    }
    private function endTendency(int $id,string $token,string $reason,bool $notify=true): void {
        if(!in_array($reason,['expired','cancelled'],true))throw new RuntimeException('Unknown action');
        $a=$this->tendencyOwned($id,$token);if($a['status']!=='active')return;
        $this->db->begin_transaction();
        try {
            $this->query('UPDATE platform_tendency_attempts SET status=?,payload_cipher=NULL,revision=revision+1 WHERE token=? AND user_id=? AND status=\'active\'',[$reason,$token,$id]);
            $this->query('DELETE FROM platform_tendency_active WHERE user_id=? AND attempt_token=?',[$id,$token]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        if($notify)$this->tendencySend($id,$this->t($id,'quiz.'.$reason),[[[$this->t($id,'quiz.retake'),'quiz'],[$this->t($id,'quiz.history'),'qh:0']]],$a);
    }
    private function tendencyCancelReview(int $id,string $token): void {
        $a=$this->tendencyOwned($id,$token,'active');$review=$this->token($id,'quizcancel',['attempt'=>$token],600);
        $this->tendencySend($id,$this->t($id,'quiz.cancel.review'),[[[$this->t($id,'quiz.cancel.confirm'),'qcancel:'.$review,'danger'],[$this->t($id,'quiz.resume'),'quizresume']]],$a);
    }
    private function tendencyResult(int $id,string $token,int $requested=0,bool $reuse=false): void {
        $a=$this->tendencyOwned($id,$token,'completed');$payload=TendencyTest::decrypt($a['payload_cipher']);$result=$payload['result'];$roles=$payload['bank']['roles'];$lang=$this->languageFor($id);
        usort($roles,fn($x,$y)=>(($result['scores'][$y['id']]['score']??-1)<=>($result['scores'][$x['id']]['score']??-1))?:strcmp($x['id'],$y['id']));
        [$page,$pages]=$this->paging(count($roles),$requested,6);$when=(new DateTimeImmutable('@'.$a['completed']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i');
        $text=$this->t($id,'quiz.result',['title'=>$this->e($this->content($id,'tendency_title')),'when'=>$when,'answered'=>$result['answered'],'total'=>$result['total']]);$rows=[];
        foreach(array_slice($roles,$page*6,6) as $role) {
            $s=$result['scores'][$role['id']];$score=$s['score'];$display=$score===null?$this->t($id,'quiz.insufficient'):$score.'%';
            $bar=$score===null?'':str_repeat('▰',(int)round($score/10)).str_repeat('▱',10-(int)round($score/10));
            $text.="\n\n<b>".$this->e($role['label_'.$lang]).'</b> · '.$display.($bar!==''?"\n".$bar:'');
            $rows[]=[[$role['label_'.$lang],'qd:'.$token.':'.$role['id']]];
        }
        $text.="\n\n".$this->t($id,'quiz.result.note');if($result['straight_line'])$text.="\n\n".$this->t($id,'quiz.straight.line');
        $text.="\n".$this->pageHeading($id,$page,$pages,count($roles));$rows=array_merge($rows,$this->pageNav($id,'qr:'.$token.':',$page,$pages));
        $rows[]=[[$this->t($id,'quiz.retake'),'quiz'],[$this->t($id,'quiz.history'),'qh:0']];
        $rows[]=[[$this->t($id,'quiz.scoring'),'quizhow'],[$this->t($id,'quiz.delete'),'qx:'.$token]];$rows[]=[[$this->t($id,'ui.back'),'profile']];
        $this->tendencySend($id,$text,$rows,$reuse?$a:null);
    }
    private function tendencyDetail(int $id,string $token,string $roleId): void {
        $a=$this->tendencyOwned($id,$token,'completed');$payload=TendencyTest::decrypt($a['payload_cipher']);$role=null;
        foreach($payload['bank']['roles'] as $r)if($r['id']===$roleId)$role=$r;
        if(!$role)throw new RuntimeException('Private test not found');$score=$payload['result']['scores'][$roleId];$lang=$this->languageFor($id);
        $this->tendencySend($id,$this->t($id,'quiz.detail',['role'=>$this->e($role['label_'.$lang]),'description'=>$this->e($role['description_'.$lang]),'score'=>$score['score']===null?$this->t($id,'quiz.insufficient'):$score['score'].'%','count'=>$score['answered'],'total'=>$score['total']]),[[[$this->t($id,'ui.back'),'qr:'.$token.':0']]]);
    }
    private function tendencyHistory(int $id,int $requested=0): void {
        $total=(int)$this->one("SELECT COUNT(*) AS n FROM platform_tendency_attempts WHERE user_id=? AND status='completed'",[$id])['n'];[$page,$pages]=$this->paging($total,$requested,4);
        $entries=$this->all("SELECT token,mode,completed FROM platform_tendency_attempts WHERE user_id=? AND status='completed' ORDER BY completed DESC,id DESC LIMIT 4 OFFSET ".($page*4),[$id]);$rows=[];
        foreach($entries as $entry)$rows[]=[[(new DateTimeImmutable('@'.$entry['completed']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i').' · '.$this->t($id,'quiz.short.'.$entry['mode']),'qr:'.$entry['token'].':0']];
        $rows=array_merge($rows,$this->pageNav($id,'qh:',$page,$pages));$rows[]=[[$this->t($id,'quiz.open'),'quiz'],[$this->t($id,'ui.back'),'profile']];
        $this->tendencySend($id,$this->t($id,'quiz.history.intro')."\n".$this->pageHeading($id,$page,$pages,$total).(!$entries?"\n\n".$this->t($id,'ui.no.records'):''),$rows);
    }
    private function tendencyTick(): void {
        foreach($this->all("SELECT t.token,t.user_id,a.role FROM platform_tendency_attempts t JOIN platform_accounts a ON a.id=t.user_id WHERE t.status='active' AND (t.deadline<=? OR a.role='banned')",[time()]) as $a)$this->endTendency((int)$a['user_id'],$a['token'],$a['role']==='banned'?'cancelled':'expired',$a['role']!=='banned');
    }
    private function tendencyAction(int $id,string $action): bool {
        if($action==='quiz'){$this->tendencyHome($id);return true;}
        if($action==='quizresume'){$this->tendencyQuestion($id);return true;}
        if($action==='quizhow'){$this->tendencySend($id,$this->t($id,'quiz.how'),[[[$this->t($id,'ui.back'),'quiz']]]);return true;}
        if(preg_match('/^qs:([a-f0-9]{16})$/',$action,$m)){$this->startTendency($id,$m[1]);return true;}
        if(preg_match('/^qa:([a-f0-9]{16}):(\d{1,3}):(\d{1,8}):([0-6s])$/',$action,$m)){$this->answerTendency($id,$m[1],(int)$m[2],(int)$m[3],$m[4]==='s'?null:(int)$m[4]-3);return true;}
        if(preg_match('/^qb:([a-f0-9]{16}):(\d{1,8})$/',$action,$m)){$this->backTendency($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^quizend:([a-f0-9]{16})$/',$action,$m)){$this->tendencyCancelReview($id,$m[1]);return true;}
        if(preg_match('/^qcancel:([a-f0-9]{16})$/',$action,$m)){$draft=$this->uiToken($id,$m[1],'quizcancel');$this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$m[1]]);$this->endTendency($id,$draft['data']['attempt'],'cancelled');$this->tendencyHome($id);return true;}
        if(preg_match('/^qr:([a-f0-9]{16}):(\d+)$/',$action,$m)){$this->tendencyResult($id,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^qd:([a-f0-9]{16}):([a-z_]{1,24})$/',$action,$m)){$this->tendencyDetail($id,$m[1],$m[2]);return true;}
        if(preg_match('/^qh:(\d+)$/',$action,$m)){$this->tendencyHistory($id,(int)$m[1]);return true;}
        if(preg_match('/^qx:([a-f0-9]{16})$/',$action,$m)){$this->tendencyOwned($id,$m[1],'completed');$review=$this->token($id,'quizdelete',['attempt'=>$m[1]],600);$this->tendencySend($id,$this->t($id,'quiz.delete.review'),[[[$this->t($id,'quiz.delete'),'qdelete:'.$review,'danger'],[$this->t($id,'cancel.button'),'qr:'.$m[1].':0']]]);return true;}
        if(preg_match('/^qdelete:([a-f0-9]{16})$/',$action,$m)){$draft=$this->uiToken($id,$m[1],'quizdelete');$this->tendencyOwned($id,$draft['data']['attempt'],'completed');$this->query("DELETE FROM platform_tendency_attempts WHERE token=? AND user_id=? AND status='completed'",[$draft['data']['attempt'],$id]);$this->query('UPDATE platform_ui SET used=1 WHERE token=?',[$m[1]]);$this->tendencyHistory($id);return true;}
        return false;
    }
}
