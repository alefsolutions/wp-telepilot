<?php
require_once dirname( __DIR__, 2 ) . '/scripts/check-plugin-report.php';

class PluginCheckGateTest extends Telepilot_TestCase {
    private function policy() {
        return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/scripts/plugin-check-policy.json' ), true );
    }

    public function test_exact_reviewed_occurrences_pass_with_reasons() {
        $policy = $this->policy();
        $accepted = telepilot_validate_plugin_report( $policy['warnings'], dirname( TELEPILOT_FILE ), $policy );
        $this->assertCount( 12, $accepted );
        foreach ( $accepted as $row ) { $this->assertNotEmpty( $row['reason'] ); }
    }

    /** @dataProvider report_changes */
    public function test_errors_unexpected_warnings_duplicates_source_changes_and_stale_exceptions_fail( $change ) {
        $policy = $this->policy(); $rows = $policy['warnings'];
        switch ( $change ) {
            case 'error': $rows[0]['type'] = 'ERROR'; break;
            case 'new warning': $rows[0]['code'] = 'unexpected_warning'; break;
            case 'changed message': $rows[0]['message'] .= ' Changed'; break;
            case 'duplicate occurrence': $rows[] = $rows[0]; break;
            case 'missing occurrence': array_pop( $rows ); break;
            case 'changed source': $policy['warnings'][0]['source_sha256'] = str_repeat( '0', 64 ); break;
            case 'outside package': $rows[0]['file'] = '../wp-telepilot.php'; break;
            case 'missing line': unset( $rows[0]['line'] ); break;
            case 'malformed list': $rows = array( 'results' => $rows ); break;
        }
        $this->expectException( RuntimeException::class );
        telepilot_validate_plugin_report( $rows, dirname( TELEPILOT_FILE ), $policy );
    }

    public function report_changes() {
        return array_map( static function ( $change ) { return array( $change ); }, array( 'error', 'new warning', 'changed message', 'duplicate occurrence', 'missing occurrence', 'changed source', 'outside package', 'missing line', 'malformed list' ) );
    }
}
