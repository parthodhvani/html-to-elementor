<?php
/**
 * Reproduction + fix verification for the XAMPP/LAMPP LD_LIBRARY_PATH issue.
 *
 * Simulates a PHP process that inherited a poisoned LD_LIBRARY_PATH pointing at
 * bundled libraries incompatible with the system Node binary, then shows that:
 *   - spawning Node with the inherited env FAILS (the reported bug), and
 *   - spawning Node with ChromiumService::child_env() (the fix) SUCCEEDS.
 *
 * Usage: php tests/repro_ldpath.php
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'H2E_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require H2E_PLUGIN_DIR . 'includes/Support/Autoloader.php';
\HtmlToElementor\Support\Autoloader::register();

use HtmlToElementor\Services\ChromiumService;

/**
 * Run `node --version` with the given proc_open environment.
 *
 * @param array<string,string>|null $env Environment (null = inherit).
 * @return array{code:int,out:string}
 */
function run_node( ?array $env ): array {
	$descriptor = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open( 'node --version 2>&1', $descriptor, $pipes, null, $env );
	$out  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array( 'code' => $code, 'out' => trim( (string) $out ) );
}

// 1. Build a directory containing a broken libstdc++.so.6 (simulating an
//    incompatible bundled library) and poison LD_LIBRARY_PATH with it.
$bad_dir = sys_get_temp_dir() . '/h2e-badlib';
@mkdir( $bad_dir );
file_put_contents( $bad_dir . '/libstdc++.so.6', "not a real shared library\n" );
putenv( 'LD_LIBRARY_PATH=' . $bad_dir );
$_ENV['LD_LIBRARY_PATH'] = $bad_dir;

echo "Simulated poisoned LD_LIBRARY_PATH=" . getenv( 'LD_LIBRARY_PATH' ) . "\n\n";

// 2. BEFORE FIX: inherit the parent environment (what proc_open did originally).
$before = run_node( null );
echo "[BEFORE FIX] node with inherited env -> exit {$before['code']}\n";
echo "  output: {$before['out']}\n\n";

// 3. AFTER FIX: use the plugin's real child_env() sanitiser.
$svc        = new ChromiumService();
$reflection = new ReflectionMethod( ChromiumService::class, 'child_env' );
$reflection->setAccessible( true );
$clean_env  = $reflection->invoke( $svc, array( 'node_strip_env' => true, 'node_ld_library_path' => '' ) );

$after = run_node( $clean_env );
echo "[AFTER FIX] node with ChromiumService::child_env() -> exit {$after['code']}\n";
echo "  output: {$after['out']}\n\n";

// 4. Assertions.
$ok = ( 0 !== $before['code'] || false !== stripos( $before['out'], 'libstdc' ) )
	&& ( 0 === $after['code'] && '' !== $after['out'] );

if ( $ok ) {
	echo "RESULT: PASS - bug reproduced before fix, resolved after fix.\n";
	exit( 0 );
}
echo "RESULT: INCONCLUSIVE - before(code={$before['code']}) after(code={$after['code']}).\n";
exit( 1 );
