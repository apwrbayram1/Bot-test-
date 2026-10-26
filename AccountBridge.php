<?php
/** Local backend connection only; no bot menus or authentication secrets. */
final class AccountBridgeGateway
{
    public static function status(): array {
        $socket='unix://'.dirname(__DIR__).'/runtime/account-bridge/bridge.sock';
        $connection=@stream_socket_client($socket,$errno,$error,1);
        if(!$connection)return ['available'=>false,'account_state'=>'offline'];
        try {
            stream_set_timeout($connection,2);
            if(fwrite($connection,"{\"action\":\"status\"}\n")===false)return ['available'=>false,'account_state'=>'offline'];
            $line=fgets($connection,8192);$reply=is_string($line)?json_decode($line,true):null;
            if(!is_array($reply)||!($reply['ok']??false)||!is_array($reply['result']??null))return ['available'=>false,'account_state'=>'offline'];
            // Only non-sensitive connection and policy information can cross into PHP.
            return ['available'=>true]+array_intersect_key($reply['result'],array_flip([
                'account_state','credentials_configured','authorized','connected','target_configured',
                'tests_available','test_seconds','daily_attempts','daily_timezone','retry_after','busy'
            ]));
        }finally{fclose($connection);}
    }
}
