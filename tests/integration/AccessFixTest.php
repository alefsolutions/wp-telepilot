<?php
/** @group stabilization */
class AccessFixTest extends Telepilot_TestCase {
    public function test_legacy_disabled_account_is_blocked_before_init() {
        global $wpdb;
        $target=$this->actor('editor'); $password='Pre-init-password-123';
        wp_set_password($password,$target['wp_user']->ID);
        add_filter('wp_is_application_passwords_available','__return_true');
        $created=WP_Application_Passwords::create_new_application_password($target['wp_user']->ID,array('name'=>'Pre-init fixture'));
        add_filter('salt',static function($salt,$scheme) { return 'telepilot-auth-worker-fixture-'.$scheme; },10,2);
        $cookie=wp_generate_auth_cookie($target['wp_user']->ID,time()+3600,'auth');
        // Bypass today's meta hook to reconstruct a pre-upgrade disabled account with live sessions.
        $wpdb->insert($wpdb->usermeta,array('user_id'=>$target['wp_user']->ID,'meta_key'=>Telepilot_Users_Service::META_DISABLED,'meta_value'=>'1'));
        $wpdb->query('COMMIT');
        $process=proc_open(array(PHP_BINARY,dirname(__DIR__).'/support/auth-worker.php'),array(array('pipe','r'),array('pipe','w'),array('pipe','w')),$pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0],wp_json_encode(array('user_id'=>$target['wp_user']->ID,'password'=>$password,'application_password'=>$created[0],'cookie'=>$cookie))."\n"); fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0,proc_close($process),$errors);
        $this->assertSame(array('before_init'=>true,'cookie_initially_valid'=>true,'current_user'=>0,'password_denied'=>true,'application_denied'=>true,'cookie_denied'=>true),json_decode($output,true),$errors.$output);
    }
    public function test_reenable_keeps_credentials_but_never_restores_browser_sessions() {
        $target=$this->actor('editor'); $admin=$this->actor();
        $password='Review-password-123'; wp_set_password($password,$target['wp_user']->ID);
        $target['wp_user']=get_user_by('id',$target['wp_user']->ID);
        add_filter('wp_is_application_passwords_available','__return_true');
        add_filter('application_password_is_api_request','__return_true');
        $created=WP_Application_Passwords::create_new_application_password($target['wp_user']->ID,array('name'=>'Reenable fixture'));
        $cookie=wp_generate_auth_cookie($target['wp_user']->ID,time()+3600,'auth');
        wp_set_current_user($admin['wp_user']->ID);
        $users=new Telepilot_Users_Service(new Telepilot_Confirmation_Service());
        $users->disable_user($target['wp_user']->ID);
        $this->assertWPError(wp_authenticate($target['wp_user']->user_login,$password));
        $this->assertSame(0,Telepilot_Users_Service::guard_current_user($target['wp_user']->ID));
        $this->assertWPError(Telepilot_Actor_Access::check($target));
        update_option('telepilot_settings',array('mcp_enabled'=>1,'mcp_allowed_roles'=>array('editor')));
        wp_set_current_user($target['wp_user']->ID); $_SERVER['HTTPS']='on';
        try { $this->assertWPError((new Telepilot_MCP_Integration_Service())->transport_permission()); }
        finally { unset($_SERVER['HTTPS']); }
        wp_set_current_user($admin['wp_user']->ID);
        $users->enable_user($target['wp_user']->ID);
        $this->assertNotWPError(wp_authenticate($target['wp_user']->user_login,$password));
        $this->assertNotWPError(wp_authenticate_application_password(null,$target['wp_user']->user_login,$created[0]));
        $this->assertFalse(wp_validate_auth_cookie($cookie,'auth'));
        $fresh=wp_generate_auth_cookie($target['wp_user']->ID,time()+3600,'auth');
        $this->assertSame($target['wp_user']->ID,wp_validate_auth_cookie($fresh,'auth'));
    }

    public function test_direct_suspension_meta_change_revokes_browser_session() {
        $target=$this->actor(); $cookie=wp_generate_auth_cookie($target['wp_user']->ID,time()+3600,'auth');
        update_user_meta($target['wp_user']->ID,Telepilot_Users_Service::META_DISABLED,1);
        $this->assertFalse(wp_validate_auth_cookie($cookie,'auth'));
        $this->assertNotFalse(has_filter('authenticate',array('Telepilot_Users_Service','guard_authentication')));
    }

    public function test_linking_disabled_blocks_generation_but_does_not_revoke_existing_links() {
        $actor=$this->actor(); $linker=new Telepilot_User_Linking_Service();
        $id=self::factory()->user->create(); $code=$linker->generate_link_code($id);
        $expiry=get_user_meta($id,Telepilot_User_Linking_Service::META_LINK_EXPIRES,true);
        update_option('telepilot_settings',array('linking_enabled'=>0));
        $this->assertWPError($linker->generate_link_code($id));
        $this->assertTrue(Telepilot_Actor_Access::check($actor));
        update_option('telepilot_settings',array('linking_enabled'=>1));
        $this->assertSame($expiry,get_user_meta($id,Telepilot_User_Linking_Service::META_LINK_EXPIRES,true));
        $message=array('from'=>array('id'=>'890'),'chat'=>array('id'=>'890','type'=>'private'));
        $this->assertTrue($linker->consume_link_code($code,$message)['ok']);
        $this->assertFalse($linker->consume_link_code($code,$message)['ok']);
    }

    public function test_term_validation_is_atomic_and_honors_per_term_denial() {
        $actor=$this->actor(); wp_set_current_user($actor['wp_user']->ID);
        $post=self::factory()->post->create(array('post_author'=>$actor['wp_user']->ID));
        $posts=new Telepilot_Posts_Service(new Telepilot_Confirmation_Service());
        foreach(array('category','post_tag') as $taxonomy) {
            $term=wp_insert_term('Assignment fixture '.$taxonomy,$taxonomy)['term_id'];
            $before=wp_get_post_terms($post,$taxonomy,array('fields'=>'ids'));
            $this->assertWPError($posts->assign_terms($post,$taxonomy,array($term,99999999)));
            $this->assertSame($before,wp_get_post_terms($post,$taxonomy,array('fields'=>'ids')));
            $deny=static function($caps,$cap,$id,$args) use($term) { return 'assign_term'===$cap && (int)($args[0]??0)===$term ? array('do_not_allow') : $caps; };
            add_filter('map_meta_cap',$deny,10,4);
            try {
                $this->assertWPError($posts->assign_terms($post,$taxonomy,array($term)));
                if('category'===$taxonomy) { $this->assertWPError($posts->create_draft('Denied creation',array($term))); }
            } finally { remove_filter('map_meta_cap',$deny,10); }
            $object=get_taxonomy($taxonomy); $original=$object->cap->assign_terms; $object->cap->assign_terms='do_not_allow';
            try { $this->assertWPError($posts->assign_terms($post,$taxonomy,array())); }
            finally { $object->cap->assign_terms=$original; }
            $this->assertNotWPError($posts->assign_terms($post,$taxonomy,array($term)));
        }
    }
    public function test_disabled_user_loses_existing_cookie() {
        $admin = $this->actor(); $target = $this->actor('editor');
        $cookie = wp_generate_auth_cookie($target['wp_user']->ID, time()+3600, 'auth');
        $this->assertSame($target['wp_user']->ID, wp_validate_auth_cookie($cookie, 'auth'));
        wp_set_current_user($admin['wp_user']->ID);
        $this->assertNotWPError((new Telepilot_Users_Service(new Telepilot_Confirmation_Service()))->disable_user($target['wp_user']->ID));
        $this->assertFalse(wp_validate_auth_cookie($cookie, 'auth'), 'Disabled account still has a valid browser cookie.');
    }
    public function test_disabled_user_loses_application_password() {
        $admin = $this->actor(); $target = $this->actor('editor');
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        $created = WP_Application_Passwords::create_new_application_password($target['wp_user']->ID, array('name'=>'Review fixture'));
        $this->assertNotWPError($created);
        wp_set_current_user($admin['wp_user']->ID);
        (new Telepilot_Users_Service(new Telepilot_Confirmation_Service()))->disable_user($target['wp_user']->ID);
        $result = wp_authenticate_application_password(null, $target['wp_user']->user_login, $created[0]);
        $this->assertWPError($result, 'Disabled account still authenticates with an application password.');
    }
    public function test_linking_switch_blocks_existing_valid_code() {
        $id = self::factory()->user->create(array('role'=>'administrator'));
        $linker = new Telepilot_User_Linking_Service(); $code = $linker->generate_link_code($id);
        update_option('telepilot_settings', array('linking_enabled'=>0));
        $actor = array('wp_user'=>null,'telegram_user_id'=>'888','chat_id'=>'888','chat_type'=>'private');
        $result = $this->route('/link '.$code, $actor);
        $this->assertFalse($result['ok'], 'Link succeeded while linking_enabled was off.');
    }
    public function test_valid_link_code_after_first_50_users_is_found() {
        $hash = wp_hash_password('OTHER123');
        for($i=0;$i<50;$i++) {
            $id = self::factory()->user->create(array('user_login'=>'aaa_review_'.sprintf('%02d',$i)));
            update_user_meta($id,Telepilot_User_Linking_Service::META_LINK_CODE_HASH,$hash);
            update_user_meta($id,Telepilot_User_Linking_Service::META_LINK_EXPIRES,time()+3600);
        }
        $id = self::factory()->user->create(array('user_login'=>'zzz_review_target'));
        $linker = new Telepilot_User_Linking_Service(); $code = $linker->generate_link_code($id);
        $result = $linker->consume_link_code($code,array('from'=>array('id'=>'889'),'chat'=>array('id'=>'889','type'=>'private')));
        $this->assertTrue($result['ok'],'Valid code outside first 50 users is rejected.');
    }
    public function test_existing_post_taxonomy_assignment_checks_assign_capability() {
        $actor=$this->actor('author'); wp_set_current_user($actor['wp_user']->ID);
        $post=self::factory()->post->create(array('post_author'=>$actor['wp_user']->ID));
        $category=self::factory()->category->create();
        $taxonomy=get_taxonomy('category'); $original=$taxonomy->cap->assign_terms;
        $taxonomy->cap->assign_terms='review_assign_categories';
        try {
            $this->assertFalse(current_user_can($taxonomy->cap->assign_terms));
            $this->assertWPError((new Telepilot_Posts_Service(new Telepilot_Confirmation_Service()))->assign_terms($post,'category',array($category)),'Term assignment succeeds without the taxonomy assign_terms capability.');
        } finally { $taxonomy->cap->assign_terms=$original; }
    }
}
