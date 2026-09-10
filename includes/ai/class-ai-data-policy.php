<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_AI_Data_Policy {
	public static function clean( $value, $key = '' ) {
		if ( preg_match( '/password|secret|token|credential|cookie|authorization|ip_address|user_ip|reset.*url|editor.*url/i', $key ) ) { return '[redacted]'; }
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $name => $item ) {
				if ( preg_match( '/email/i', (string) $name ) && empty( Telepilot_AI_Settings::get_settings()['ai_include_email_fields'] ) ) { continue; }
				$result[ $name ] = self::clean( $item, (string) $name );
			}
			return $result;
		}
		if ( ! is_string( $value ) ) { return $value; }
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) { return wp_json_encode( self::clean( $decoded ) ); }
		$value = preg_replace( '~https?://[^\s<>"\']*(?:action=(?:rp|resetpass)|key=|token=|telepilot[^\s]*edit)[^\s<>"\']*~i', '[security link removed]', $value );
		$value = preg_replace( '/\bsk-[A-Za-z0-9_-]+|\b\d{5,}:[A-Za-z0-9_-]{20,}|\b(?:\d{1,3}\.){3}\d{1,3}\b|\b[a-f0-9]{24,}\b/i', '[redacted]', $value );
		$value = preg_replace( '/\/(?:link)\s+\S+/i', '/link [redacted]', $value );
		$value = preg_replace( '/\b(?:password|api[_ -]?key|secret|authorization)\s*[:=]\s*\S+/i', '[credential removed]', $value );
		$value = preg_replace_callback( '/(?<![a-f0-9:])(?:[a-f0-9]{0,4}:){2,}[a-f0-9]{0,4}(?![a-f0-9:])/i', static function ( $match ) { return filter_var( $match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[IP removed]' : $match[0]; }, $value );
		// Email consent is limited to explicit supported fields, never incidental text.
		if ( ! preg_match( '/^email$/i', $key ) || empty( Telepilot_AI_Settings::get_settings()['ai_include_email_fields'] ) ) { $value = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $value ); }
		return mb_substr( $value, 0, 12000 );
	}
}
