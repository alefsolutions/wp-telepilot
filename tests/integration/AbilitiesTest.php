<?php
class AbilitiesTest extends Telepilot_TestCase {
    public function test_shared_reads_combine_status_search_and_scope() {
        $author = $this->actor( 'author' );
        $own = self::factory()->post->create( array( 'post_author' => $author['wp_user']->ID, 'post_title' => 'Needle draft', 'post_status' => 'draft' ) );
        self::factory()->post->create( array( 'post_title' => 'Needle hidden', 'post_status' => 'draft' ) );
        self::factory()->post->create( array( 'post_author' => $author['wp_user']->ID, 'post_title' => 'Needle live', 'post_status' => 'publish' ) );
        $result = Telepilot_Actor_Access::as_actor( $author, static function () {
            return ( new Telepilot_Ability_Executor() )->execute( 'telepilot/search-posts', array( 'query' => 'Needle', 'status' => 'draft' ) );
        } );
        $this->assertNotWPError( $result );
        $this->assertSame( array( $own ), array_column( $result['data']['items'], 'id' ) );
        $this->assertNotEmpty( $result['operation_id'] );
    }

    public function test_page_details_and_all_comments_are_available() {
        $actor = $this->actor();
        $page = self::factory()->post->create( array( 'post_type' => 'page' ) );
        $post = self::factory()->post->create();
        self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );
        self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '0' ) );
        Telepilot_Actor_Access::as_actor( $actor, function () use ( $page ) {
            $executor = new Telepilot_Ability_Executor();
            $result = $executor->execute( 'telepilot/get-page', array( 'page_id' => $page ) );
            $this->assertNotWPError( $result );
            $this->assertSame( $page, $result['data']['id'] );
            $result = $executor->execute( 'telepilot/list-comments', array( 'status' => 'all' ) );
            $this->assertNotWPError( $result );
            $this->assertCount( 2, $result['data']['items'] );
        } );
    }

    public function test_writes_require_confirmation_and_native_target_permission() {
        $actor = $this->actor( 'author' );
        $foreign = self::factory()->post->create( array( 'post_status' => 'draft' ) );
        Telepilot_Actor_Access::as_actor( $actor, function () use ( $foreign ) {
            $executor = new Telepilot_Ability_Executor();
            $this->assertWPError( $executor->execute( 'telepilot/create-draft', array( 'title' => 'No intent-only write' ) ) );
            $this->assertWPError( $executor->execute( 'telepilot/update-draft', array( 'post_id' => $foreign, 'title' => 'Not mine' ), true ) );
            $this->assertWPError( $executor->execute( 'telepilot/search-posts', array( 'limit' => 999 ) ) );
            $this->assertNotWPError( $executor->execute( 'telepilot/lookup-terms', array( 'taxonomy' => 'category' ) ) );
        }, 'ai' );
        Telepilot_Actor_Access::as_actor( $actor, function () {
            $this->assertWPError( ( new Telepilot_Ability_Executor() )->execute( 'telepilot/create-draft', array( 'title' => 'Never MCP writes' ), true ) );
        }, 'mcp', true );
    }

    public function test_references_are_typed_and_conflicting_ids_are_rejected() {
        $store = new Telepilot_AI_Conversation_Store();
        $store->remember_result( 'fixture', 'list_pages', array( array( 'position' => 1, 'type' => 'page', 'id' => 42 ) ), 'A page' );
        $executor = new Telepilot_AI_Tool_Executor();
        $registry = new Telepilot_Ability_Registry();
        $this->assertWPError( $executor->resolve_arguments( $registry->get_ability( 'telepilot/get-post' ), array( 'reference_number' => 1 ), array( 'session_id' => 'fixture' ) ) );
        $this->assertSame( array( 'page_id' => 42 ), $executor->resolve_arguments( $registry->get_ability( 'telepilot/get-page' ), array( 'reference_number' => 1 ), array( 'session_id' => 'fixture' ) ) );
        $this->assertWPError( $executor->resolve_arguments( $registry->get_ability( 'telepilot/get-page' ), array( 'reference_number' => 1, 'page_id' => 43 ), array( 'session_id' => 'fixture' ) ) );
    }
}
