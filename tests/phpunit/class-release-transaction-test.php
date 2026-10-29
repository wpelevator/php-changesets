<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Release_Engine;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Release_Transaction;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Release_Transaction_Test extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/php-changesets-transaction-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $this->directory . '/' . $name;
				is_dir( $path ) ? rmdir( $path ) : unlink( $path );
			}
		}
		rmdir( $this->directory );
	}

	public function test_preflight_rejects_changed_content_before_any_write(): void {
		$first = $this->directory . '/first.txt';
		$second = $this->directory . '/second.txt';
		file_put_contents( $first, 'one' );
		file_put_contents( $second, 'different' );
		try {
			Release_Transaction::apply(
				[
					[
						'path' => $first,
						'old' => 'one',
						'new' => 'updated',
					],
					[
						'path' => $second,
						'old' => 'expected',
						'new' => 'updated',
					],
				],
				$this->directory
			);
			self::fail( 'Changed content must reject the transaction.' );
		} catch ( Release_Exception $error ) {
			self::assertStringContainsString( 'changed since planning', $error->getMessage(), 'The diagnostic should identify a stale release plan.' );
		}
		self::assertSame( 'one', file_get_contents( $first ), 'Preflight failure must leave the earlier target untouched.' );
		self::assertSame( 'different', file_get_contents( $second ), 'Preflight failure must leave the stale target untouched.' );
	}

	public function test_recover_refuses_an_unowned_directory(): void {
		$directory = $this->directory . '/.php-changesets-transaction';
		mkdir( $directory );
		file_put_contents( $directory . '/unrelated.txt', 'keep this file' );
		try {
			Release_Transaction::recover( $this->directory );
			self::fail( 'Recovery must refuse a directory without its owner marker.' );
		} catch ( Release_Exception $error ) {
			self::assertStringContainsString( 'not owned', $error->getMessage(), 'The diagnostic should explain why the directory was preserved.' );
		}
		self::assertSame( 'keep this file', file_get_contents( $directory . '/unrelated.txt' ), 'Recovery must leave unrelated files untouched.' );
		unlink( $directory . '/unrelated.txt' );
		rmdir( $directory );
	}

	public function test_interrupted_write_is_recovered_by_cli(): void {
		$first = $this->directory . '/first.txt';
		$second = $this->directory . '/second.txt';
		file_put_contents( $first, 'one' );
		file_put_contents( $second, 'two' );
		$bootstrap = var_export( dirname( __DIR__, 2 ) . '/php/bootstrap.php', true );
		$script = <<<'PHP'
<?php
namespace WPElevator\PHP_Changesets;
function rename( string $from, string $to ): bool {
	$result = \rename( $from, $to );
	if ( str_ends_with( $to, '/first.txt' ) ) {
		exit( 99 );
	}
	return $result;
}
require __BOOTSTRAP__;
$root = $argv[1];
Release_Transaction::apply( [
	[ 'path' => $root . '/first.txt', 'old' => 'one', 'new' => 'updated' ],
	[ 'path' => $root . '/second.txt', 'old' => 'two', 'new' => 'updated' ],
], $root );
PHP;
		file_put_contents( $this->directory . '/interrupt.php', str_replace( '__BOOTSTRAP__', $bootstrap, $script ) );
		$process = proc_open(
			[ PHP_BINARY, $this->directory . '/interrupt.php', $this->directory ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		self::assertIsResource( $process, 'The interruption probe should start.' );
		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 99, proc_close( $process ), 'The probe should terminate immediately after the first replacement.' );
		self::assertSame( 'updated', file_get_contents( $first ), 'The first target should show the interrupted partial release.' );
		self::assertSame( 'two', file_get_contents( $second ), 'The second target should still hold its original content.' );
		self::assertDirectoryExists( $this->directory . '/.php-changesets-transaction', 'The durable journal should remain after interruption.' );
		try {
			( new Release_Engine() )->plan( $this->directory );
			self::fail( 'Planning must stop while interrupted release state remains.' );
		} catch ( Release_Exception $error ) {
			self::assertStringContainsString( 'run changesets recover', $error->getMessage(), 'The planner should direct the maintainer to recovery.' );
		}
		file_put_contents( $this->directory . '/.php-changesets-transaction/restore-0', 'left from an interrupted recovery' );
		$recovery = proc_open(
			[ PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/changesets', 'recover', '--root', $this->directory ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		self::assertIsResource( $recovery, 'The recover command should start.' );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $recovery ), 'The recover command should restore the original files.' );
		self::assertStringContainsString( 'Recovered', $output, 'The CLI should report a recovered transaction.' );
		self::assertSame( 'one', file_get_contents( $first ), 'Recovery should restore the first file.' );
		self::assertSame( 'two', file_get_contents( $second ), 'Recovery should keep the untouched second file.' );
		clearstatcache( true, $this->directory . '/.php-changesets-transaction' );
		self::assertDirectoryDoesNotExist( $this->directory . '/.php-changesets-transaction', 'Recovery should remove the transaction journal.' );
	}
}
