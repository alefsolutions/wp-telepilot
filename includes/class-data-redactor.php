<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Data_Redactor {
	public static function redact( $value, $key = '' ) {
		if ( in_array( $key, array( 'message', 'caption', 'body' ), true ) ) { return '[redacted]'; }
		if ( preg_match( '/password|passwd|secret|token|authorization|cookie|link_code|reset_url|editor_url|api_key|headers|payload|post_content|content|email|ip_address/i', (string) $key ) ) { return '[redacted]'; }
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $name => $item ) { $value[ $name ] = self::redact( $item, $name ); }
			return $value;
		}
		if ( ! is_string( $value ) ) { return $value; }
		if ( in_array( substr( ltrim( $value ), 0, 1 ), array( '{', '[' ), true ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) { return wp_json_encode( self::redact( $decoded ) ); }
		}
		// Commands retain their domain/action for diagnostics, not arbitrary arguments.
		if ( in_array( $key, array( 'raw_command', 'command', 'text', 'last_update_message' ), true ) ) {
			if ( preg_match( '~^(/[a-z]+)(?:\s+([a-z]+))?~i', $value, $match ) ) {
				return $match[1] . ( '/link' === strtolower( $match[1] ) ? ' [redacted]' : ( isset( $match[2] ) ? ' ' . $match[2] : '' ) );
			}
			if ( 0 !== strpos( $value, 'tp:' ) ) { return '[redacted]'; }
		}
		$value = preg_replace( '~https?://[^\s<>"\']+~i', '[URL redacted]', $value );
		$value = preg_replace( '~(/link\s+)\S+~i', '$1[redacted]', $value );
		$value = preg_replace( '~\b(?:sk-[a-zA-Z0-9_-]+|\d{5,}:[a-zA-Z0-9_-]{20,}|[a-f0-9]{24,})\b~i', '[redacted]', $value );
		$value = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email redacted]', $value );
		$value = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP redacted]', $value );
		return mb_substr( $value, 0, 2000 );
	}
}
