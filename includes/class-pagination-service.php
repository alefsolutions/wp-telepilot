<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Search text stays in short-lived, site/actor-bound storage, never in Telegram callbacks. */
class Telepilot_Pagination_Service {
	public static function resources() { return array( 'posts', 'pages', 'comments', 'media', 'users', 'plugins', 'categories', 'tags' ); }

	public static function issue( $resource, $query ) {
		if ( ! in_array( $resource, self::resources(), true ) || ! is_string( $query ) || mb_strlen( $query ) > 1000 ) { return ''; }
		return ( new Telepilot_Flow_State_Service() )->create_flow( array( 'flow_type' => 'pagination', 'resource' => $resource, 'mode' => 'search', 'query' => $query ), 15 * MINUTE_IN_SECONDS );
	}

	public static function read( $token, $page ) {
		if ( ! is_string( $page ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $page ) ) { return null; }
		$state = ( new Telepilot_Flow_State_Service() )->get_flow( $token );
		if ( ! $state || 'pagination' !== ( $state['flow_type'] ?? '' ) || 'search' !== ( $state['mode'] ?? '' ) || ! in_array( $state['resource'] ?? '', self::resources(), true ) || ! is_string( $state['query'] ?? null ) || mb_strlen( $state['query'] ) > 1000 ) { return null; }
		$state['page'] = (int) $page;
		return $state;
	}

	public static function callback( $token, $page ) { return $token ? 'tp:pagination:' . $token . ':' . (int) $page : ''; }

	public static function search_row( $resource, $query, $page, $total_pages ) {
		$token = self::issue( $resource, $query );
		if ( ! $token ) { return array(); }
		$buttons = array();
		if ( $page > 1 ) { $buttons[] = array( 'text' => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ), 'callback_data' => self::callback( $token, $page - 1 ) ); }
		if ( $page < $total_pages ) { $buttons[] = array( 'text' => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ), 'callback_data' => self::callback( $token, $page + 1 ) ); }
		return $buttons;
	}
}
