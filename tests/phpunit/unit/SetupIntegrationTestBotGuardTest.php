<?php

namespace TranslationManager\Tests\Unit;

use MediaWikiUnitTestCase;

/**
 * The integration-test bot script must refuse to run anywhere but a dev environment.
 *
 * Each case runs the script in a child PHP process with MW_INSTALL_PATH pointing at a
 * directory that does not exist. The guard runs before MediaWiki is loaded, so a refusal
 * exits cleanly; if the guard ever let a case through, the child would die on the missing
 * install instead of reaching a wiki, and the assertions would fail.
 *
 * @coversNothing
 */
class SetupIntegrationTestBotGuardTest extends MediaWikiUnitTestCase {

	private const SCRIPT = __DIR__ . '/../../../maintenance/setupIntegrationTestBot.php';

	/**
	 * Run the script with the given MW_ENVIRONMENT (null = unset).
	 *
	 * @param string|null $environment
	 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
	 */
	private function runScript( ?string $environment ): array {
		$env = [
			'PATH' => getenv( 'PATH' ) ?: '/usr/bin:/bin',
			'MW_INSTALL_PATH' => sys_get_temp_dir() . '/tm-guard-test-no-such-install-' . getmypid(),
		];
		if ( $environment !== null ) {
			$env['MW_ENVIRONMENT'] = $environment;
		}
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open -- the guard exits the process, so it runs in a child
		$proc = proc_open(
			[ PHP_BINARY, '-n', self::SCRIPT ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			$env
		);
		$this->assertIsResource( $proc, 'could not start a PHP child process' );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return [ proc_close( $proc ), $stdout, $stderr ];
	}

	public static function provideNonDevelopmentEnvironments(): array {
		return [
			'production' => [ 'production' ],
			'staging' => [ 'staging' ],
			'unset' => [ null ],
			'empty' => [ '' ],
			'wrong case' => [ 'Development' ],
			'short form' => [ 'dev' ],
		];
	}

	/**
	 * @dataProvider provideNonDevelopmentEnvironments
	 */
	public function testRefusesOutsideDevelopment( ?string $environment ): void {
		[ $code, $stdout, $stderr ] = $this->runScript( $environment );
		$this->assertSame( 1, $code, "stderr: $stderr" );
		$this->assertStringStartsWith( 'Refusing to run:', $stderr );
		$this->assertSame( '', $stdout );
	}

	/**
	 * Control: with MW_ENVIRONMENT=development the guard lets the script through, so it goes
	 * on to load MediaWiki and fails on the missing install. This shows the refusals above
	 * come from the guard and not from some other early failure.
	 */
	public function testDevelopmentPassesTheGuard(): void {
		[ $code, , $stderr ] = $this->runScript( 'development' );
		$this->assertNotSame( 0, $code );
		$this->assertNotSame( 1, $code, "stderr: $stderr" );
		$this->assertStringNotContainsString( 'Refusing to run', $stderr );
	}
}
