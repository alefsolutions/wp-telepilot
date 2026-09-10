<?php
abstract class Telepilot_TestCase extends WP_UnitTestCase {
    protected function actor( $role = 'administrator' ) {
        $id = self::factory()->user->create( array( 'role' => $role ) );
        update_user_meta( $id, Telepilot_User_Linking_Service::META_TELEGRAM_ID, (string) ( 100000 + $id ) );
        update_user_meta( $id, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, (string) ( 100000 + $id ) );
        return array( 'wp_user' => get_user_by( 'id', $id ), 'telegram_user_id' => (string) ( 100000 + $id ), 'chat_id' => (string) ( 100000 + $id ), 'chat_type' => 'private' );
    }

    protected function invoke( $object, $method, ...$args ) {
        $reflection = new ReflectionMethod( $object, $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $reflection->setAccessible( true );
        }
        return $reflection->invokeArgs( $object, $args );
    }

    protected function mock_http( $host, $body, $status = 200 ) {
        add_filter( 'pre_http_request', static function ( $response, $args, $url ) use ( $host, $body, $status ) {
            if ( parse_url( $url, PHP_URL_HOST ) !== $host ) {
                return $response;
            }
            return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'Fixture' ), 'cookies' => array(), 'filename' => null );
        }, PHP_INT_MAX, 3 );
    }
}
