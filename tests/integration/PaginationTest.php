<?php
/** @group stabilization */
class PaginationTest extends Telepilot_TestCase {
    public function test_all_search_resources_use_actor_bound_opaque_controls() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $confirmation=new Telepilot_Confirmation_Service();
        $query=str_repeat('界',30)." quoted ' C:\\Temp";
        foreach(Telepilot_Pagination_Service::resources() as $resource) {
            if(in_array($resource,array('categories','tags'),true)) {
                $object=new Telepilot_Taxonomies_Service($confirmation);
                $row=$this->invoke($object,'build_pagination_row',$resource,array('page'=>1,'total_pages'=>2,'search'=>$query));
            } else {
                $class='Telepilot_'.ucfirst($resource).'_Service';
                $object='media'===$resource ? new $class($confirmation,new Telepilot_Telegram_Client()) : new $class($confirmation);
                $row=$this->invoke($object,'build_pagination_row','search',$query,1,2);
            }
            $this->assertCount(1,$row,$resource);
            $callback=$row[0]['callback_data']; $this->assertLessThanOrEqual(64,strlen($callback));
            $this->assertStringNotContainsString($query,$callback);
            $parsed=(new Telepilot_Command_Grammar())->parse_text($callback);
            $state=Telepilot_Pagination_Service::read($parsed['args'][0],$parsed['args'][1]);
            $this->assertSame($query,$state['query']); $this->assertSame($resource,$state['resource']);
        }
    }

    public function test_pagination_routes_queries_without_reparsing_and_expires() {
        global $wpdb;
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $token=Telepilot_Pagination_Service::issue('posts',"Quoted ' search");
        $callback=Telepilot_Pagination_Service::callback($token,2);
        $this->assertTrue($this->route($callback,$actor)['ok']);
        foreach(array('0','-1','2junk','99999999999999999999') as $page) { $this->assertNull(Telepilot_Pagination_Service::read($token,$page)); }
        $other=$this->actor(); wp_set_current_user($other['wp_user']->ID);
        $this->assertNull(Telepilot_Pagination_Service::read($token,'2'));
        $this->assertFalse($this->route($callback,$other)['ok']);
        wp_set_current_user($actor['wp_user']->ID);
        $wpdb->update(Telepilot_Security_Records::table_name(),array('expires_at'=>gmdate('Y-m-d H:i:s',time()-1)),array('token_hash'=>hash('sha256',$token)));
        $this->assertStringContainsString('Repeat the search',$this->route($callback,$actor)['message']);
        $token=Telepilot_Pagination_Service::issue('posts','revoked'); Telepilot_Actor_Access::revoke($actor['wp_user']->ID);
        $this->assertNull(Telepilot_Pagination_Service::read($token,'2'));
    }

    public function test_callback_byte_limit_is_enforced_at_build_and_delivery() {
        $valid=str_repeat('x',64); $invalid=str_repeat('界',22);
        $rows=array(array(array('text'=>'Valid','callback_data'=>$valid),array('text'=>'Invalid','callback_data'=>$invalid)));
        $this->assertCount(1,Telepilot_Telegram_Response_Builder::keyboard($rows)['inline_keyboard'][0]);
        $bodies=array();
        add_filter('pre_http_request',static function($pre,$args,$url) use(&$bodies) {
            if(parse_url($url,PHP_URL_HOST)==='api.telegram.org') { $bodies[]=$args['body']; return array('headers'=>array(),'response'=>array('code'=>200),'body'=>'{"ok":true,"result":{"message_id":1}}'); }
            return $pre;
        },PHP_INT_MAX,3);
        $client=new Telepilot_Telegram_Client('fixture'); $markup=array('inline_keyboard'=>$rows);
        $client->send_message('1','Saved response',array('reply_markup'=>$markup));
        $client->edit_message_text('1',1,'Saved response',array('reply_markup'=>$markup));
        foreach($bodies as $body) { $sent=json_decode($body['reply_markup'],true); $this->assertCount(1,$sent['inline_keyboard'][0]); $this->assertSame($valid,$sent['inline_keyboard'][0][0]['callback_data']); }
        $log=Telepilot_Audit_Log_Repository::latest_log_by_action('telegram_callback_omitted');
        $this->assertStringNotContainsString($invalid,wp_json_encode($log));
    }
    public function test_search_pagination_stays_within_telegram_callback_limit() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $query=str_repeat('x',50);
        $keyboard=(new Telepilot_Posts_Service(new Telepilot_Confirmation_Service()))->build_list_keyboard(array(),'search',$query,1,2);
        $this->assertStringContainsString('tp:pagination:', wp_json_encode($keyboard));
        foreach($keyboard['inline_keyboard'] as $row) { foreach($row as $button) { if(isset($button['callback_data'])) { $this->assertLessThanOrEqual(64,strlen($button['callback_data']),'Telegram rejects the whole response for oversized callback data.'); } } }
    }
}
