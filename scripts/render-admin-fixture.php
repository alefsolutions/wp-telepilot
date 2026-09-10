<?php
// Generates a local, non-production admin snapshot using the isolated test database.
require dirname( __DIR__ ) . '/tests/bootstrap.php';
$id = wp_insert_user( array( 'user_login' => 'visual_qa_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $id );
ob_start();
( new Telepilot_Settings_Page() )->render_page();
$body = ob_get_clean();
$logo = 'data:image/png;base64,' . base64_encode( file_get_contents( TELEPILOT_PATH . 'assets/images/telepilot-logo.png' ) );
$body = str_replace( esc_url( TELEPILOT_URL . 'assets/images/telepilot-logo.png' ), $logo, $body );
$css = file_get_contents( ABSPATH . 'wp-includes/css/buttons.css' ) . file_get_contents( ABSPATH . 'wp-admin/css/forms.css' ) . file_get_contents( TELEPILOT_PATH . 'assets/css/admin.css' );
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Telepilot isolated admin QA</title><style>body{margin:0;padding:20px;font:13px sans-serif;background:#f0f0f1}input,select,button{font:inherit}' . $css . '</style></head><body>' . $body . '<script>' . file_get_contents( TELEPILOT_PATH . 'assets/js/admin.js' ) . '</script></body></html>';
file_put_contents( dirname( __DIR__ ) . '/.test-env/admin-qa.html', $html );
echo "Generated isolated .test-env/admin-qa.html\n";
