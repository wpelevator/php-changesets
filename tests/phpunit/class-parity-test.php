<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Parity_Test extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-parity-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $this->root );
	}

	public function test_status_matches_node_for_shared_changesets(): void {
		$node_cli = realpath( __DIR__ . '/../../../../js/wp-release/src/bin/wp-release.ts' );
		if ( false === $node_cli || 0 !== $this->command( [ 'node', '--version' ] )['exit'] ) {
			$this->markTestSkipped( 'Node and the wp-release source CLI are required for cross-tool parity.' );
		}
		$this->success( [ 'git', 'init', '-q' ] );
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"0.2.7\"}\n" );
		$this->success( [ 'git', 'add', 'composer.json' ] );
		$this->success( [ 'git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-qm', 'Initial' ] );
		mkdir( $this->root . '/.changeset' );
		file_put_contents( $this->root . '/.changeset/z-fix.md', "---\nexample/library: patch\n---\n\nFix a defect.\n" );
		file_put_contents( $this->root . '/.changeset/a-feature.md', "---\n\"example/library\": minor\n---\n\nAdd a feature.\n" );
		file_put_contents( $this->root . '/.changeset/empty.md', "---\n---\n" );
		$php = json_decode( $this->success( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/changesets', 'status', '--root', $this->root, '--json' ] ), true );
		$node = json_decode( $this->success( [ 'node', $node_cli, 'changeset-status', '--since', 'HEAD', '--json' ] ), true );
		self::assertIsArray( $php, 'PHP status should return JSON.' );
		self::assertIsArray( $node, 'Node status should return JSON.' );
		self::assertCount( 1, $node['releases'], 'The shared fixture should plan one Node release.' );
		$release = $node['releases'][0];
		self::assertSame(
			[ $php['project'], $php['currentVersion'], $php['bump'], $php['nextVersion'] ],
			[ $release['package'], $release['currentVersion'], $release['bump'], $release['nextVersion'] ],
			'Both CLIs should select the same project, current version, highest bump, and next version.'
		);
		$php_changesets = array_map( static fn( array $item ): array => [ $item['name'], $item['summary'] ], $php['changesets'] );
		$node_changesets = array_map( static fn( array $item ): array => [ $item['fileName'], $item['summary'] ], $node['changesets'] );
		self::assertSame( $php_changesets, $node_changesets, 'Both CLIs should return changeset file names and summaries in the same order, including empty changesets.' );
	}

	private function success( array $command ): string {
		$result = $this->command( $command );
		self::assertSame( 0, $result['exit'], 'Command should succeed: ' . implode( ' ', $command ) . "\n" . $result['stderr'] );
		return $result['stdout'];
	}

	private function command( array $command ): array {
		$process = proc_open(
			$command,
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes,
			$this->root
		);
		if ( ! is_resource( $process ) ) {
			return [
				'exit' => 1,
				'stdout' => '',
				'stderr' => 'Could not start process.',
			];
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return [
			'exit' => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		];
	}
}
