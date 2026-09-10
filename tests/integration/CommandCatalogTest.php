<?php
class CommandCatalogTest extends Telepilot_TestCase {
    public function test_all_read_command_groups_for_standard_and_custom_roles() {
        add_role( 'telepilot_custom_author', 'Custom author', array( 'read' => true, 'edit_posts' => true ) );
        $service = new Telepilot_Telegram_Service();
        $property = new ReflectionProperty( $service, 'command_router' );
        if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
        $router = $property->getValue( $service );
        $commands = array( '/menu', '/help', '/site', '/settings', '/notifications list', '/comments', '/comments all', '/comments search fixture', '/posts', '/posts drafts', '/posts trash', '/posts search fixture', '/pages', '/pages drafts', '/pages search fixture', '/media', '/media search fixture', '/users', '/users search fixture', '/plugins', '/plugins search fixture', '/categories', '/categories search fixture', '/tags', '/tags search fixture' );
        try {
            foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'telepilot_custom_author' ) as $role ) {
                $actor = $this->actor( $role );
                foreach ( $commands as $command ) {
                    $result = $router->route( array( 'message' => array( 'text' => $command ) ), $actor );
                    $this->assertIsArray( $result, $role . ': ' . $command );
                    $this->assertStringNotContainsString( 'internal error', $result['message'] ?? '', $role . ': ' . $command );
                }
                $menu = wp_json_encode( $router->get_home_keyboard( $actor ) );
                if ( in_array( $role, array( 'author', 'contributor', 'subscriber', 'telepilot_custom_author' ), true ) ) { $this->assertStringNotContainsString( '/pages', str_replace( '\\/', '/', $menu ) ); }
            }
        } finally { remove_role( 'telepilot_custom_author' ); }
    }
}
