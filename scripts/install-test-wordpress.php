<?php
// Downloads only into the dedicated, ignored test environment. Never touches a live site.
$version = $argv[1] ?? '6.6.2';
if ( ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $version ) ) {
    throw new RuntimeException( 'Provide an exact WordPress version.' );
}
$directory = dirname( __DIR__ ) . '/.test-env';
if ( ! is_dir( $directory ) ) {
    mkdir( $directory, 0700, true );
}
$archive = $directory . '/wordpress-' . $version . '.zip';
if ( ! is_file( $archive ) ) {
    $curl = curl_init( 'https://wordpress.org/wordpress-' . $version . '.zip' );
    curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => true ) );
    $data = curl_exec( $curl );
    if ( false === $data ) {
        throw new RuntimeException( curl_error( $curl ) );
    }
    file_put_contents( $archive, $data );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $archive ) ) {
    throw new RuntimeException( 'Cannot open WordPress archive.' );
}
$zip->extractTo( $directory );
$zip->close();
echo "Isolated WordPress $version installed.\n";
