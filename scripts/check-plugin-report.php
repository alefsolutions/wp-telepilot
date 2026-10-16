<?php
/** Validate the complete Plugin Check JSON against reviewed, occurrence-specific warnings. */

function telepilot_plugin_check_key( $row, $source_hash ) {
    return json_encode( array( $row['file'], $row['type'], $row['code'], $row['message'], $source_hash ), JSON_UNESCAPED_SLASHES );
}

function telepilot_validate_plugin_report( $rows, $plugin_root, $policy ) {
    if ( ! is_array( $rows ) || array_keys( $rows ) !== array_keys( array_values( $rows ) ) ) {
        throw new RuntimeException( 'Plugin Check must return a JSON list.' );
    }
    $root = str_replace( '\\', '/', realpath( $plugin_root ) ?: '' );
    if ( ! $root || ! is_file( $root . '/wp-telepilot.php' ) ) { throw new RuntimeException( 'Packaged plugin directory is missing.' ); }
    $expected = array();
    foreach ( $policy['warnings'] as $exception ) {
        if ( 'WARNING' !== $exception['type'] || empty( $exception['reason'] ) ) { throw new RuntimeException( 'Only explained warnings may be allowlisted.' ); }
        $key = telepilot_plugin_check_key( $exception, $exception['source_sha256'] );
        if ( isset( $expected[ $key ] ) ) { throw new RuntimeException( 'Duplicate warning policy entry.' ); }
        $expected[ $key ] = $exception;
    }
    $accepted = array();
    foreach ( $rows as $row ) {
        foreach ( array( 'file', 'type', 'code', 'message' ) as $field ) {
            if ( ! isset( $row[ $field ] ) || ! is_string( $row[ $field ] ) ) { throw new RuntimeException( 'Malformed finding: missing ' . $field ); }
        }
        if ( ! isset( $row['line'] ) || ! is_numeric( $row['line'] ) || (int) $row['line'] < 0 || (string) (int) $row['line'] !== (string) $row['line'] ) { throw new RuntimeException( 'Malformed finding line.' ); }
        $file = str_replace( '\\', '/', $row['file'] );
        if ( 0 === strpos( $file, $root . '/' ) ) { $file = substr( $file, strlen( $root ) + 1 ); }
        if ( preg_match( '~(^|/)\.\.?(/|$)|^/|:~', $file ) ) { throw new RuntimeException( 'Finding path is outside the package.' ); }
        $resolved = str_replace( '\\', '/', realpath( $root . '/' . $file ) ?: '' );
        if ( 0 !== strpos( $resolved, $root . '/' ) || ! is_file( $resolved ) ) { throw new RuntimeException( 'Finding source is missing or outside the package.' ); }
        $row['file'] = $file;
        $lines = file( $resolved, FILE_IGNORE_NEW_LINES );
        $line = (int) $row['line'];
        if ( $line > count( $lines ) ) { throw new RuntimeException( 'Finding line is outside its source.' ); }
        $source_hash = hash( 'sha256', $line ? trim( $lines[ $line - 1 ] ) : '' );
        $key = telepilot_plugin_check_key( $row, $source_hash );
        if ( 'WARNING' !== $row['type'] || ! isset( $expected[ $key ] ) ) {
            throw new RuntimeException( 'Unreviewed finding: ' . $file . ':' . $line . ' ' . $row['type'] . ' ' . $row['code'] );
        }
        $row['reason'] = $expected[ $key ]['reason'];
        $row['source_sha256'] = $source_hash;
        $accepted[] = $row;
        unset( $expected[ $key ] );
    }
    if ( $expected ) { throw new RuntimeException( 'Stale warning policy: ' . count( $expected ) . ' expected occurrences were not reported. Review the policy instead of ignoring the change.' ); }
    return $accepted;
}

function telepilot_plugin_check_main( $args ) {
    if ( count( $args ) !== 4 ) { throw new RuntimeException( 'Usage: php scripts/check-plugin-report.php report.json packaged-plugin-dir accounted-report.json' ); }
    $policy = json_decode( file_get_contents( __DIR__ . '/plugin-check-policy.json' ), true, 512, JSON_THROW_ON_ERROR );
    if ( getenv( 'TELEPILOT_CHECK_VERSION' ) !== $policy['plugin_check_version'] || ! getenv( 'TELEPILOT_CHECK_WP_VERSION' ) ) { throw new RuntimeException( 'Set TELEPILOT_CHECK_VERSION to the installed checker version and TELEPILOT_CHECK_WP_VERSION to the tested core version.' ); }
    $report_json = preg_replace( '/^\xEF\xBB\xBF/', '', file_get_contents( $args[1] ) );
    $rows = json_decode( $report_json, true, 512, JSON_THROW_ON_ERROR );
    $accepted = telepilot_validate_plugin_report( $rows, $args[2], $policy );
    $inventory_file = dirname( __DIR__ ) . '/.test-env/plugin-check-checks.json';
    $inventory_json = file_get_contents( $inventory_file );
    // PowerShell 5 writes a BOM for UTF8 redirects; accept that on the inventory only.
    $inventory = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $inventory_json ), true, 512, JSON_THROW_ON_ERROR );
    if ( ! is_array( $inventory ) || ! $inventory ) { throw new RuntimeException( 'Capture wp plugin list-checks --format=json before checking the package.' ); }
    $check_slugs = array();
    foreach ( $inventory as $check ) {
        if ( 'stable' === ( $check['stability'] ?? '' ) ) { $check_slugs[] = $check['slug']; }
    }
    sort( $check_slugs );
    if ( $check_slugs !== $policy['enabled_check_slugs'] ) { throw new RuntimeException( 'The installed checker inventory differs from the pinned policy.' ); }
    $source = file_get_contents( rtrim( $args[2], '/\\' ) . '/wp-telepilot.php' );
    preg_match( '/^ \* Version: (\S+)/m', $source, $version );
    $package = dirname( __DIR__ ) . '/dist/wp-telepilot-' . ( $version[1] ?? '' ) . '.zip';
    if ( ! is_file( $package ) ) { throw new RuntimeException( 'Build the release ZIP before checking its extracted contents.' ); }
    // Bind the report to the exact archive, not an independently edited extraction.
    $zip = new ZipArchive();
    if ( true !== $zip->open( $package ) ) { throw new RuntimeException( 'Cannot inspect release ZIP.' ); }
    $archive_files = array();
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        if ( 0 !== strpos( $name, 'wp-telepilot/' ) || preg_match( '~(^|/)\.\.?(/|$)~', $name ) ) { throw new RuntimeException( 'Unexpected release ZIP entry.' ); }
        $relative = substr( $name, strlen( 'wp-telepilot/' ) );
        $file = rtrim( $args[2], '/\\' ) . '/' . $relative;
        if ( ! is_file( $file ) || hash( 'sha256', $zip->getFromIndex( $i ) ) !== hash_file( 'sha256', $file ) ) { throw new RuntimeException( 'Checked package differs from ZIP: ' . $relative ); }
        $archive_files[] = $relative;
    }
    $zip->close();
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $args[2], FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file ) {
        $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( rtrim( $args[2], '/\\' ) ) + 1 ) );
        if ( $file->isLink() || ! in_array( $relative, $archive_files, true ) ) { throw new RuntimeException( 'Unexpected file in checked extraction: ' . $relative ); }
    }
    $process = proc_open( array( 'git', 'rev-parse', 'HEAD' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot identify source commit.' ); }
    fclose( $pipes[0] ); $commit = trim( stream_get_contents( $pipes[1] ) ); fclose( $pipes[1] ); fclose( $pipes[2] );
    if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Cannot identify source commit.' ); }
    $report = array(
        'generated_at_utc' => gmdate( 'c' ), 'plugin' => 'WP Telepilot', 'version' => $version[1],
        'source_commit' => $commit, 'source_note' => 'ZIP SHA256 identifies the checked working-tree build; the commit alone may not include uncommitted changes.',
        'zip_sha256' => hash_file( 'sha256', $package ), 'plugin_check_version' => $policy['plugin_check_version'],
        'wordpress_version' => getenv( 'TELEPILOT_CHECK_WP_VERSION' ), 'php_version' => PHP_VERSION,
        'checks' => $policy['checks'], 'enabled_check_slugs' => $check_slugs,
        'errors' => 0, 'warnings' => count( $accepted ), 'findings' => $accepted,
    );
    if ( false === file_put_contents( $args[3], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL ) ) { throw new RuntimeException( 'Cannot write accounted report.' ); }
    echo 'Plugin Check passed: 0 errors; ' . count( $accepted ) . ' individually reviewed warnings.' . PHP_EOL;
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
    try { telepilot_plugin_check_main( $argv ); }
    catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . PHP_EOL ); exit( 1 ); }
}
