<?php
/** @group stabilization */
class QueueOrderingTest extends Telepilot_TestCase {
    public function test_genuine_exception_keeps_the_operation_quarantined() {
        $actor=$this->actor(); update_option('telepilot_settings',array('bot_token'=>'fixture'));
        $this->mock_http('api.telegram.org',array('ok'=>true,'result'=>array('message_id'=>993)));
        $service=new Telepilot_Telegram_Service();
        $update=array('update_id'=>310001,'message'=>array('date'=>time(),'text'=>'/posts search fixture','from'=>array('id'=>$actor['telegram_user_id']),'chat'=>array('id'=>$actor['chat_id'],'type'=>'private')));
        $job=$service->handle_update($update)['operation_id'];
        $explode=static function($query) { throw new RuntimeException('Read fixture interrupted'); };
        add_action('pre_get_posts',$explode);
        try { $service->process_jobs(1); }
        finally { remove_action('pre_get_posts',$explode); }
        $this->assertSame('uncertain',Telepilot_Jobs_Repository::get($job)['status']);
        $this->assertSame(array(),$service->process_jobs(1));
    }
    private function enqueue_fixture($chat,$transport='webhook',$options=array()) {
        return Telepilot_Jobs_Repository::enqueue(null,$transport,array(),'/fixture',array('chat_id'=>$chat),$options);
    }

    public function test_active_leases_and_expired_quarantined_jobs_do_not_hide_other_chats() {
        global $wpdb;
        $first=$this->enqueue_fixture('busy');
        $live=Telepilot_Jobs_Repository::claim_pending_jobs()[0];
        for($i=0;$i<105;$i++) { $this->enqueue_fixture('busy'); }
        $quarantined=$this->enqueue_fixture('quarantined');
        $wpdb->update(Telepilot_Jobs_Repository::table_name(),array('status'=>'uncertain'),array('id'=>$quarantined));
        $expired=$this->enqueue_fixture('quarantined','webhook',array('stale_after'=>gmdate('Y-m-d H:i:s',time()-1)));
        $other=$this->enqueue_fixture('free');
        $this->assertSame($other,(int)Telepilot_Jobs_Repository::claim_pending_jobs()[0]['id']);
        $this->assertSame('cancelled',Telepilot_Jobs_Repository::get($expired)['status']);
        $this->assertSame('processing',Telepilot_Jobs_Repository::get($first)['status']);
        $this->assertSame('uncertain',Telepilot_Jobs_Repository::get($quarantined)['status']);
    }

    public function test_arrival_order_overrides_priority_and_fresh_commands_precede_internal_jobs() {
        $this->enqueue_fixture('same','ai_continuation',array('priority'=>1));
        $this->enqueue_fixture('same','notification',array('priority'=>1,'result'=>array('ok'=>true)));
        $this->enqueue_fixture('same','webhook',array('priority'=>1,'result'=>array('ok'=>true)));
        $first=$this->enqueue_fixture('same','webhook',array('priority'=>99));
        $second=$this->enqueue_fixture('same','webhook',array('priority'=>1));
        $claimed=Telepilot_Jobs_Repository::claim_pending_jobs()[0];
        $this->assertSame($first,(int)$claimed['id']);
        Telepilot_Jobs_Repository::mark_complete($first,$claimed['lease_token']);
        $this->assertSame($second,(int)Telepilot_Jobs_Repository::claim_pending_jobs()[0]['id']);
    }

    public function test_claim_races_continue_beyond_first_candidate_batch() {
        global $wpdb;
        for($i=0;$i<101;$i++) { $last=$this->enqueue_fixture('race-'.$i); }
        $races=0;
        $lose=static function($query) use(&$races) {
            if(0===strpos(str_replace('`', '', $query),'INSERT IGNORE INTO '.Telepilot_Jobs_Repository::locks_table()) && $races++<100) { return 'SELECT 1 WHERE 0'; }
            return $query;
        };
        add_filter('query',$lose);
        try { $claimed=Telepilot_Jobs_Repository::claim_pending_jobs(); }
        finally { remove_filter('query',$lose); }
        $this->assertSame($last,(int)$claimed[0]['id']);
        $this->assertSame(101,$races);
    }

    public function test_overlong_searches_are_errors_and_next_commands_still_execute() {
        $actor=$this->actor(); update_option('telepilot_settings',array('bot_token'=>'fixture','rate_limit_per_minute'=>100));
        $this->mock_http('api.telegram.org',array('ok'=>true,'result'=>array('message_id'=>991)));
        $service=new Telepilot_Telegram_Service(); $id=200000;
        foreach(Telepilot_Pagination_Service::resources() as $resource) {
            $update=array('update_id'=>++$id,'message'=>array('message_id'=>$id,'date'=>time(),'text'=>'/'.$resource.' search '.str_repeat('x',1001),'from'=>array('id'=>$actor['telegram_user_id']),'chat'=>array('id'=>$actor['chat_id'],'type'=>'private')));
            $job=$service->handle_update($update)['operation_id']; $service->process_jobs(1);
            $saved=Telepilot_Jobs_Repository::get($job);
            $this->assertSame('complete',$saved['status'],$resource);
            $this->assertFalse(json_decode($saved['outcome'],true)['ok'],$resource);
            $update['update_id']=++$id; $update['message']['text']='/menu';
            $next=$service->handle_update($update)['operation_id']; $service->process_jobs(1);
            $this->assertSame('complete',Telepilot_Jobs_Repository::get($next)['status'],$resource);
        }
    }

    public function test_guided_creation_survives_a_later_high_priority_button() {
        $actor=$this->actor(); update_option('telepilot_settings',array('bot_token'=>'fixture'));
        $this->mock_http('api.telegram.org',array('ok'=>true,'result'=>array('message_id'=>992)));
        $service=new Telepilot_Telegram_Service();
        $message=array('from'=>array('id'=>$actor['telegram_user_id']),'chat'=>array('id'=>$actor['chat_id'],'type'=>'private'),'date'=>time(),'text'=>'/posts new');
        $service->handle_update(array('update_id'=>300001,'message'=>$message));
        $message['text']='Arrival order title'; $service->handle_update(array('update_id'=>300002,'message'=>$message));
        $service->handle_update(array('update_id'=>300003,'callback_query'=>array('id'=>'later','from'=>$message['from'],'message'=>array('chat'=>$message['chat'],'message_id'=>20),'data'=>'/menu')));
        $service->process_jobs(3);
        $this->assertCount(1,get_posts(array('post_status'=>'draft','s'=>'Arrival order title')));
    }
    public function set_up() {
        parent::set_up();
        global $wpdb;
        foreach (array(Telepilot_Jobs_Repository::table_name(), Telepilot_Jobs_Repository::operations_table(), Telepilot_Jobs_Repository::locks_table()) as $table) { $wpdb->query("DELETE FROM $table"); }
    }
    public function test_quarantined_chat_does_not_starve_unrelated_chat_after_100_jobs() {
        global $wpdb;
        $first = Telepilot_Jobs_Repository::enqueue(null, 'webhook', array(), '/menu', array('chat_id'=>'blocked'));
        $wpdb->update(Telepilot_Jobs_Repository::table_name(), array('status'=>'uncertain'), array('id'=>$first));
        for ($i=0;$i<100;$i++) { Telepilot_Jobs_Repository::enqueue(null, 'webhook', array(), '/menu', array('chat_id'=>'blocked')); }
        $other = Telepilot_Jobs_Repository::enqueue(null, 'webhook', array(), '/menu', array('chat_id'=>'other'));
        $claimed = Telepilot_Jobs_Repository::claim_pending_jobs();
        $this->assertNotEmpty($claimed, 'All eligible rows outside the first 100 are invisible to the worker.');
        $this->assertSame($other, (int)$claimed[0]['id']);
    }
    public function test_long_read_query_does_not_quarantine_chat() {
        $actor=$this->actor('author');
        update_option('telepilot_settings',array('bot_token'=>'review-fixture'));
        $this->mock_http('api.telegram.org',array('ok'=>true,'result'=>array('message_id'=>999)));
        $service=new Telepilot_Telegram_Service();
        $update=array('update_id'=>987654,'message'=>array('message_id'=>1,'date'=>time(),'text'=>'/posts search '.str_repeat('x',1001),'from'=>array('id'=>$actor['telegram_user_id']),'chat'=>array('id'=>$actor['chat_id'],'type'=>'private')));
        $accepted=$service->handle_update($update);
        $service->process_jobs(1);
        $job=Telepilot_Jobs_Repository::get($accepted['operation_id']);
        $this->assertNotSame('uncertain',$job['status'],'An ordinary validation error must not permanently quarantine the chat.');
    }
    public function test_enter_chat_executes_before_followup_text() {
        $actor=$this->actor(); $service=new Telepilot_Telegram_Service();
        $update=array('message'=>array('message_id'=>1,'date'=>time(),'text'=>'/chat','from'=>array('id'=>$actor['telegram_user_id']),'chat'=>array('id'=>$actor['chat_id'],'type'=>'private')));
        $update['update_id']=987655; $first=$service->handle_update($update);
        $update['update_id']=987656; $update['message']['text']='List my drafts';
        $service->handle_update($update);
        $claimed=Telepilot_Jobs_Repository::claim_pending_jobs();
        $this->assertSame($first['operation_id'],(int)$claimed[0]['id'],'Followup text overtakes /chat and is processed outside AI mode.');
    }
}
