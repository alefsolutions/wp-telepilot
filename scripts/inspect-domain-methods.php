<?php
// Development-only AST inventory used to review domain boundary migrations.
require dirname( __DIR__ ) . '/vendor/autoload.php';
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/includes/class-domain-abilities.php';
$parser = ( new PhpParser\ParserFactory() )->createForNewestSupportedVersion();
$finder = new PhpParser\NodeFinder();
$result = array();
if ( in_array( '--router', $argv, true ) ) {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-command-router.php' );
	foreach ( $finder->findInstanceOf( $parser->parse( $source ), PhpParser\Node\Stmt\ClassMethod::class ) as $method ) {
		$name = (string) $method->name;
		if ( preg_match( '/^handle_(posts|pages|users|plugins)$/', $name ) ) { $result[] = array( 'name' => $name, 'code' => substr( $source, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1 ) ); }
	}
	echo json_encode( $result ); exit;
}
foreach ( Telepilot_Domain_Abilities::domains() as $domain => $definition ) {
	$file = dirname( __DIR__ ) . '/includes/class-' . ( 'terms' === $domain ? 'taxonomies' : $domain ) . '-service.php';
	$source = file_get_contents( $file );
	$methods = $finder->findInstanceOf( $parser->parse( $source ), PhpParser\Node\Stmt\ClassMethod::class );
	foreach ( $methods as $method ) {
		$name = (string) $method->name;
		if ( ! in_array( $name, explode( ',', $definition['reads'] . ',' . $definition['writes'] ), true ) ) { continue; }
		$chunk = substr( $source, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1 );
		$result[] = array( 'domain' => $domain, 'file' => $file, 'name' => $name, 'signature' => substr( $chunk, 0, strpos( $chunk, '{' ) ), 'parameters' => array_map( static function ( $parameter ) { return '$' . $parameter->var->name; }, $method->params ), 'code' => $chunk );
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES );
