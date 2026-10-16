<?php
/** @group stabilization */
class DataIntegrityTest extends Telepilot_TestCase {
    public function test_queue_erasure_failure_is_retryable_without_losing_identifiers() {
        $actor=$this->actor(); $service=new Telepilot_Telegram_Service();
        $job=$service->queue_notification($actor['chat_id'],'Private notice','read');
        $fail=static function($sql) { return 0===strpos(str_replace('`', '', $sql),'UPDATE '.Telepilot_Jobs_Repository::table_name().' j LEFT JOIN') ? 'UPDATE telepilot_missing_privacy_jobs SET id=0' : $sql; };
        add_filter('query',$fail);
        try {
            $this->assertFalse((new Telepilot_Privacy_Service())->erase_personal_data($actor['wp_user']->user_email)['done']);
            $this->assertSame($actor['chat_id'],get_user_meta($actor['wp_user']->ID,Telepilot_User_Linking_Service::META_TELEGRAM_CHAT,true));
        } finally { remove_filter('query',$fail); }
        $this->assertTrue((new Telepilot_Privacy_Service())->erase_personal_data($actor['wp_user']->user_email)['done']);
        $this->assertSame('',Telepilot_Jobs_Repository::get($job)['payload']);
    }
    public function test_failed_privacy_update_preserves_identifiers_for_retry() {
        $actor=$this->actor();
        Telepilot_Audit_Log_Repository::log(array('action_name'=>'privacy_retry','wp_user_id'=>$actor['wp_user']->ID,'chat_id'=>$actor['chat_id']));
        $fail=static function($sql) { return 0===strpos(str_replace('`', '', $sql),'UPDATE '.Telepilot_Audit_Log_Repository::table_name().' SET wp_user_id=NULL') ? 'UPDATE telepilot_missing_privacy_table SET id=0' : $sql; };
        add_filter('query',$fail);
        try {
            $result=(new Telepilot_Privacy_Service())->erase_personal_data($actor['wp_user']->user_email);
            $this->assertFalse($result['done']);
            $this->assertSame($actor['chat_id'],get_user_meta($actor['wp_user']->ID,Telepilot_User_Linking_Service::META_TELEGRAM_CHAT,true));
        } finally { remove_filter('query',$fail); }
        $this->assertTrue((new Telepilot_Privacy_Service())->erase_personal_data($actor['wp_user']->user_email)['done']);
        $this->assertSame(array(),Telepilot_Audit_Log_Repository::find_personal_logs($actor['wp_user']->ID, $actor['telegram_user_id'],$actor['chat_id']));
    }

    public function test_default_category_reassignment_fails_before_changing_content() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $post=self::factory()->post->create(); $default=(int)get_option('default_category');
        $replacement=self::factory()->category->create();
        wp_set_post_terms($post,array($default),'category');
        $service=new Telepilot_Taxonomies_Service(new Telepilot_Confirmation_Service());
        $this->assertWPError($service->reassign_and_delete_term('category',$default,$replacement));
        $this->assertSame(array($default),wp_get_post_terms($post,'category',array('fields'=>'ids')));
        $this->assertNotNull(get_term($default,'category'));
    }

    public function test_text_writes_preserve_backslashes_across_domains() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $text='Using C:\\Windows\\Temp and "quoted" text';
        $confirmation=new Telepilot_Confirmation_Service();
        $posts=new Telepilot_Posts_Service($confirmation); $post=$posts->create_draft($text)['post'];
        $this->assertSame($text,$posts->update_title($post->ID,$text)['post']->post_title);
        $this->assertSame($text,$posts->update_excerpt($post->ID,$text)['post']->post_excerpt);
        $pages=new Telepilot_Pages_Service($confirmation); $page=$pages->create_page($text)['post'];
        $this->assertSame($text,$page->post_title);
        $this->assertSame($text,$pages->update_title($page->ID,$text)['post']->post_title);
        $users=new Telepilot_Users_Service($confirmation);
        $this->assertSame($text,$users->update_display_name($actor['wp_user']->ID,$text)['user']->display_name);
        $terms=new Telepilot_Taxonomies_Service($confirmation); $term=$terms->create_term('category',$text);
        $this->assertNotWPError($term); $this->assertSame($text,$term->name);
        $this->assertSame($text.' updated',$terms->rename_term('category',$term->term_id,$text.' updated')->name);
        $this->assertSame($text,$terms->update_description('category',$term->term_id,$text)->description);
        $comment=self::factory()->comment->create(array('comment_post_ID'=>$post->ID));
        $reply=(new Telepilot_Comments_Service($confirmation))->reply_to_comment($comment,$text,wp_get_current_user());
        $this->assertSame($text,$reply['reply']->comment_content);
        $link=(new Telepilot_Post_Editor_Service())->create_edit_link($post->ID,wp_get_current_user(),$actor['telegram_user_id']);
        $this->assertNotWPError((new Telepilot_Post_Editor_Service())->save($link['token'],array('post_content'=>$text)));
        $this->assertSame($text,get_post($post->ID)->post_content);
    }
    public function test_erasure_anonymizes_more_than_5000_audit_records() {
        global $wpdb; $actor = $this->actor(); $values = array();
        for($i=0;$i<5001;$i++) { $values[] = $wpdb->prepare('(%s,%d,%s,%s,%s)', gmdate('Y-m-d H:i:s'), $actor['wp_user']->ID, $actor['telegram_user_id'], $actor['chat_id'], 'review'); }
        $wpdb->query('INSERT INTO '.Telepilot_Audit_Log_Repository::table_name().' (created_at,wp_user_id,telegram_user_id,chat_id,action_name) VALUES '.implode(',', $values));
        $result = (new Telepilot_Privacy_Service())->erase_personal_data($actor['wp_user']->user_email);
        $this->assertTrue($result['done']);
        $left = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Telepilot_Audit_Log_Repository::table_name().' WHERE wp_user_id=%d OR chat_id=%s', $actor['wp_user']->ID, $actor['chat_id']));
        $this->assertSame(0,$left,'Erasure returned done with identifying audit records remaining.');
    }
    public function test_default_category_deletion_is_reported_as_failure() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $term = (int)get_option('default_category');
        $this->assertWPError((new Telepilot_Taxonomies_Service(new Telepilot_Confirmation_Service()))->delete_term('category',$term),'WordPress returns 0 for default category but service reports success.');
    }
    public function test_post_title_backslashes_survive_creation() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $title='Using C:\\Windows\\Temp';
        $result=(new Telepilot_Posts_Service(new Telepilot_Confirmation_Service()))->create_draft($title);
        $this->assertNotWPError($result);
        $this->assertSame($title,$result['post']->post_title);
    }
}
