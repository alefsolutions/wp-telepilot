<?php
class MCPTest extends Telepilot_TestCase {
    public function test_mcp_is_separate_opt_in_and_never_exports_writes() {
        $mcp = new Telepilot_MCP_Integration_Service();
        update_option( 'telepilot_settings', array( 'ai_enabled' => 1 ) );
        $this->assertSame( array(), $mcp->get_exportable_abilities() );
        $all = array_keys( ( new Telepilot_Ability_Registry() )->get_abilities() );
        update_option( 'telepilot_settings', array( 'mcp_enabled' => 1, 'mcp_allowed_abilities' => $all ) );
        foreach ( $mcp->get_exportable_abilities() as $definition ) { $this->assertSame( 'read', $definition['risk_level'] ); }
        $actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $actor ); $_SERVER['HTTPS'] = 'on';
        try {
            $this->assertTrue( $mcp->transport_permission(), 'MCP must not need Telegram linkage or an OpenAI key.' );
            foreach ( ( new Telepilot_Ability_Registry() )->get_abilities() as $name => $definition ) {
                if ( 'read' !== $definition['risk_level'] ) { $this->assertWPError( $mcp->execute( $name, array() ) ); }
            }
            $result = $mcp->execute( 'telepilot/search-posts', array() );
            $this->assertNotWPError( $result );
            $this->assertWPError( $mcp->execute( 'telepilot/search-posts', array( 'actor_id' => $actor ) ) );
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
            $this->assertWPError( $mcp->transport_permission() );
            wp_set_current_user( $actor ); unset( $_SERVER['HTTPS'] );
            $this->assertWPError( $mcp->transport_permission() );
        } finally { unset( $_SERVER['HTTPS'] ); }
    }

    public function test_actual_adapter_registers_only_private_read_abilities() {
        if ( ! getenv( 'TELEPILOT_TEST_MCP_PLUGIN' ) ) { $this->markTestSkipped( 'Actual adapter integration runs on the current WordPress matrix with the pinned official dependency.' ); }
        $mcp = new Telepilot_MCP_Integration_Service();
        update_option( 'telepilot_settings', array( 'mcp_enabled' => 1, 'mcp_allowed_abilities' => array( 'telepilot/search-posts', 'telepilot/create-draft' ) ) );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_SERVER['HTTPS'] = 'on';
        try {
            rest_get_server();
            $server = \WP\MCP\Core\McpAdapter::instance()->get_server( Telepilot_MCP_Integration_Service::SERVER_ID );
            $this->assertNotNull( $server );
            $tools = $server->get_tools();
            $this->assertArrayHasKey( 'telepilot-search-posts', $tools );
            $this->assertArrayNotHasKey( 'telepilot-create-draft', $tools );
            $ability = wp_get_ability( 'telepilot/search-posts' );
            $this->assertFalse( $ability->get_meta()['public'] );
            $this->assertFalse( $ability->get_meta()['show_in_rest'] );
            $this->assertFalse( $ability->get_meta()['mcp']['public'] );
            $result = $ability->execute( array( 'status' => 'draft' ) );
            $this->assertNotWPError( $result );
            $initialize = $this->rpc( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'telepilot-fixture', 'version' => '1' ) ) );
            $this->assertSame( 200, $initialize->get_status(), wp_json_encode( $initialize->get_data() ) );
            $this->assertArrayHasKey( 'result', $initialize->get_data() );
            $headers = $initialize->get_headers();
            $session_id = $headers['Mcp-Session-Id'] ?? $headers['mcp-session-id'] ?? '';
            $this->assertNotEmpty( $session_id );
            $list = $this->rpc( 'tools/list', array(), $session_id );
            $this->assertSame( array( 'telepilot-search-posts' ), array_column( $list->get_data()['result']['tools'], 'name' ) );
            $read = $this->rpc( 'tools/call', array( 'name' => 'telepilot-search-posts', 'arguments' => array( 'status' => 'draft' ) ), $session_id );
            $this->assertArrayHasKey( 'result', $read->get_data() );
            $this->assertEmpty( $read->get_data()['result']['isError'] ?? false, wp_json_encode( $read->get_data() ) );
            $write = $this->rpc( 'tools/call', array( 'name' => 'telepilot-create-draft', 'arguments' => array( 'title' => 'MCP cannot write' ) ), $session_id );
            $this->assertTrue( isset( $write->get_data()['error'] ) || ! empty( $write->get_data()['result']['isError'] ) );
            wp_set_current_user( 0 );
            $this->assertContains( $this->rpc( 'tools/list', array(), $session_id )->get_status(), array( 401, 403 ) );
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
            $stolen = $this->rpc( 'tools/list', array(), $session_id );
            $this->assertNotSame( 200, $stolen->get_status(), 'An MCP session must belong to the authenticated WordPress actor.' );
            update_option( 'telepilot_settings', array( 'mcp_enabled' => 0 ) );
            $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
            $this->assertWPError( $ability->execute( array() ) );
        } finally { unset( $_SERVER['HTTPS'] ); }
    }

    private function rpc( $method, $params, $session = '' ) {
        $request = new WP_REST_Request( 'POST', '/wp-telepilot-mcp/v1/server' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_header( 'Accept', 'application/json, text/event-stream' );
        if ( $session ) { $request->set_header( 'Mcp-Session-Id', $session ); $request->set_header( 'MCP-Protocol-Version', '2025-11-25' ); }
        $request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object) array() ) ) );
        $response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
        $response->set_data( json_decode( wp_json_encode( $response->get_data() ), true ) );
        return $response;
    }
}
