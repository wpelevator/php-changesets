<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Parity_Test extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-parity-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $this->root );
	}

	public function test_status_matches_node_for_shared_changesets(): void {
		$node_cli = realpath( __DIR__ . '/../../../../js/wp-release/src/bin/wp-release.ts' );

		if ( false === $node_cli || 0 !== $this->command( [ 'node', '--version' ] )['exit'] ) {
			$this->markTestSkipped( 'Node and the wp-release source CLI are required for cross-tool parity.' );
		}

		$this->success( [ 'git', 'init', '-q' ] );
		$this->success( [ 'git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--allow-empty', '-qm', 'Initial' ] );

		mkdir( $this->root . '/packages/alpha', 0777, true );
		mkdir( $this->root . '/packages/beta', 0777, true );
		file_put_contents( $this->root . '/packages/alpha/composer.json', "{\"name\":\"example/alpha\",\"version\":\"0.2.7\"}\n" );
		file_put_contents( $this->root . '/packages/beta/composer.json', "{\"name\":\"example/beta\",\"version\":\"1.0.0\"}\n" );

		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'tagFormat' => '{name}-v{version}',
	'packages' => [
		[
			'glob' => 'packages/*',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\composer_json_version(),
				'to' => [],
			],
		],
	],
] );
PHP
		);
		file_put_contents(
			$this->root . '/wp-release.config.mjs',
			<<<'MJS'
export default {
	tagFormat: '{name}-v{version}',
	packages: [
		{
			glob: 'packages/*',
			idFrom: 'composer.json#name',
			version: {
				from: { kind: 'composer-json', file: 'composer.json', field: 'version' },
				to: [],
			},
		},
	],
};
MJS
		);

		mkdir( $this->root . '/.changeset' );
		file_put_contents( $this->root . '/.changeset/z-fix.md', "---\n\"example/alpha\": patch\n\"example/beta\": patch\n---\n\nFix a defect.\n" );
		file_put_contents( $this->root . '/.changeset/a-feature.md', "---\n\"example/alpha\": minor\n---\n\nAdd a feature.\n" );
		file_put_contents( $this->root . '/.changeset/empty.md', "---\n---\n" );

		$php = json_decode( $this->success( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/changeset', 'status', '--root', $this->root, '--json' ] ), true );
		$node = json_decode( $this->success( [ 'node', $node_cli, 'changeset-status', '--since', 'HEAD', '--json' ] ), true );

		$this->assertIsArray( $php, 'PHP status should return JSON.' );
		$this->assertIsArray( $node, 'Node status should return JSON.' );
		$this->assertCount( 2, $node['releases'], 'The shared fixture should plan one release per package.' );

		$php_releases = array_map(
			static fn( array $release ): array => [
				$release['package'],
				$release['currentVersion'],
				$release['bump'],
				$release['nextVersion'],
			],
			$php['releases']
		);
		$node_releases = array_map(
			static fn( array $release ): array => [
				$release['package'],
				$release['currentVersion'],
				$release['bump'],
				$release['nextVersion'],
			],
			$node['releases']
		);

		$this->assertSame(
			$node_releases,
			$php_releases,
			'Both CLIs should plan the same packages, current versions, highest bumps, and next versions in group order.'
		);

		$php_changesets = array_map(
			static fn( array $item ): array => [ $item['file'], $item['packages'], $item['summary'] ],
			$php['changesets']
		);
		$node_changesets = array_map(
			static fn( array $item ): array => [ $item['fileName'], $item['packages'], $item['summary'] ],
			$node['changesets']
		);

		$this->assertSame(
			$node_changesets,
			$php_changesets,
			'Both CLIs should return changeset file names, package mappings, and summaries in the same order, including empty changesets.'
		);
	}

	private function success( array $command ): string {
		$result = $this->command( $command );

		$this->assertSame( 0, $result['exit'], 'Command should succeed: ' . implode( ' ', $command ) . "\n" . $result['stderr'] );

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
