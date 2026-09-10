<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/wp-telepilot.php' );
preg_match( '/^ \* Version: (\S+)/m', $source, $header );
preg_match( "/define\( 'TELEPILOT_VERSION', '([^']+)' \)/", $source, $constant );
preg_match( '/^Stable tag: (\S+)/m', file_get_contents( $root . '/readme.txt' ), $readme );
$version = $header[1] ?? '';
if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-beta\.\d+)?$/D', $version ) || $version !== ( $constant[1] ?? '' ) || $version !== ( $readme[1] ?? '' ) ) {
    throw new RuntimeException( 'Invalid or inconsistent release metadata.' );
}
if ( ! is_dir( $root . '/dist' ) ) {
    mkdir( $root . '/dist', 0755 );
}
$path = $root . '/dist/wp-telepilot-' . $version . '.zip';
$zip = new ZipArchive();
if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
    throw new RuntimeException( 'Cannot create package.' );
}
$files = array( 'wp-telepilot.php', 'uninstall.php', 'readme.txt', 'LICENSE' );
foreach ( array( 'assets', 'includes', 'languages' ) as $directory ) {
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( $file->isLink() ) {
            throw new RuntimeException( 'Package symlinks are not allowed.' );
        }
        if ( $file->isFile() ) {
            $files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
        }
    }
}
sort( $files );
foreach ( $files as $file ) {
    $zip->addFile( $root . '/' . $file, 'wp-telepilot/' . $file );
}
$zip->close();
$zip->open( $path );
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
    $name = $zip->getNameIndex( $i );
    if ( ! in_array( substr( $name, strlen( 'wp-telepilot/' ) ), $files, true ) || preg_match( '~(?:^|/)(?:docs|tests|vendor|\.github|\.git)(?:/|$)|\.md$~', $name ) ) {
        throw new RuntimeException( 'Unexpected package entry: ' . $name );
    }
}
$zip->close();
echo $path . PHP_EOL;
