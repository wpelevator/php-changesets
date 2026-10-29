<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Cli {

	public function run( array $argv ): int {
		try {
			$command = $argv[1] ?? 'help';
			if ( in_array( $command, [ 'help', '--help', '-h' ], true ) ) {
				fwrite( STDOUT, $this->help() );
				return 0;
			}
			$options = $this->options( array_slice( $argv, 2 ) );
			if ( isset( $options['help'] ) ) {
				fwrite( STDOUT, $this->help() );
				return 0;
			}
			$this->validate_options( $command, $options );
			$root = realpath( $options['root'] ?? getcwd() );
			if ( false === $root ) {
				throw new Release_Exception( 'Project root does not exist.' );
			}
			if ( 'init' === $command ) {
				$this->init( $root );
			} elseif ( 'add' === $command ) {
				$this->add( $root, $options );
			} elseif ( 'recover' === $command ) {
				$recovered = Release_Transaction::recover( $root );
				fwrite( STDOUT, $recovered ? "Recovered the release transaction.\n" : "No release transaction needs recovery.\n" );
			} elseif ( 'status' === $command || 'version' === $command ) {
				$this->release( $root, $command, $options );
			} else {
				throw new Release_Exception( "Unknown command: {$command}." );
			}
			return 0;
		} catch ( Release_Exception $error ) {
			fwrite( STDERR, $error->getMessage() . "\n" );
			return 2;
		}
	}

	private function options( array $arguments ): array {
		$options = [];
		$boolean = [
			'--help' => 'help',
			'--json' => 'json',
			'--dry-run' => 'dryRun',
			'--empty' => 'empty',
		];
		$values = [
			'--root' => 'root',
			'--bump' => 'bump',
			'--summary' => 'summary',
			'--name' => 'name',
			'--date' => 'date',
		];
		for ( $index = 0; $index < count( $arguments ); ++$index ) {
			$argument = $arguments[ $index ];
			if ( isset( $boolean[ $argument ] ) ) {
				$options[ $boolean[ $argument ] ] = true;
			} elseif ( isset( $values[ $argument ] ) && isset( $arguments[ $index + 1 ] ) ) {
				$options[ $values[ $argument ] ] = $arguments[ ++$index ];
			} else {
				throw new Release_Exception( "Unknown or incomplete option: {$argument}." );
			}
		}
		return $options;
	}

	private function validate_options( string $command, array $options ): void {
		$allowed = [
			'init' => [ 'root' ],
			'add' => [ 'root', 'bump', 'summary', 'name', 'empty' ],
			'recover' => [ 'root' ],
			'status' => [ 'root', 'json' ],
			'version' => [ 'root', 'json', 'dryRun', 'date' ],
		];
		if ( ! isset( $allowed[ $command ] ) ) {
			throw new Release_Exception( "Unknown command: {$command}." );
		}
		foreach ( array_keys( $options ) as $option ) {
			if ( ! in_array( $option, $allowed[ $command ], true ) ) {
				throw new Release_Exception( "Option {$option} is not valid for {$command}." );
			}
		}
		if ( 'add' === $command && isset( $options['empty'] ) && ( isset( $options['bump'] ) || isset( $options['summary'] ) ) ) {
			throw new Release_Exception( '--empty cannot be combined with --bump or --summary.' );
		}
	}

	private function init( string $root ): void {
		$config = Project_Config::load( $root );
		$directory = Release_Engine::safe_path( $root, $config['changesets'] );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			throw new Release_Exception( 'Could not create changeset directory.' );
		}
		$file = $root . '/.php-changesets.json';
		if ( ! Project_Config::has_config( $root ) ) {
			$json = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$contents = false === $json ? '' : $json . "\n";
			if ( false === $json || strlen( $contents ) !== file_put_contents( $file, $contents, LOCK_EX ) ) {
				throw new Release_Exception( 'Could not write .php-changesets.json.' );
			}
		}
		fwrite( STDOUT, "Initialized PHP Changesets for {$config['name']}.\n" );
	}

	private function add( string $root, array $options ): void {
		$config = Project_Config::load( $root );
		$empty = $options['empty'] ?? false;
		$bump = $empty ? null : ( $options['bump'] ?? $this->prompt( 'Bump (patch, minor, major): ' ) );
		$summary = $empty ? '' : ( $options['summary'] ?? $this->prompt( 'Summary: ' ) );
		if ( ! $empty && ( ! in_array( $bump, [ 'patch', 'minor', 'major' ], true ) || '' === trim( $summary ) ) ) {
			throw new Release_Exception( 'A release changeset needs a patch, minor, or major bump and a summary.' );
		}
		$directory = Release_Engine::safe_path( $root, $config['changesets'] );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			throw new Release_Exception( 'Could not create changeset directory.' );
		}
		$name = $options['name'] ?? bin2hex( random_bytes( 8 ) );
		if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/D', $name ) || 0 === strcasecmp( 'README', $name ) ) {
			throw new Release_Exception( 'Changeset name must contain only letters, numbers, and hyphens and cannot be README.' );
		}
		$file = $directory . '/' . $name . '.md';
		$handle = @fopen( $file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Existing file is a normal validation error.
		if ( false === $handle ) {
			throw new Release_Exception( "Changeset already exists: {$name}.md." );
		}
		$frontmatter = $empty ? '' : json_encode( $config['name'], JSON_UNESCAPED_SLASHES ) . ': ' . $bump . "\n";
		$contents = "---\n" . $frontmatter . "---\n\n" . trim( $summary ) . "\n";
		$written = fwrite( $handle, $contents );
		fclose( $handle );
		if ( false === $written || strlen( $contents ) !== $written ) {
			unlink( $file );
			throw new Release_Exception( 'Could not write changeset.' );
		}
		fwrite( STDOUT, "Created {$file}.\n" );
	}

	private function release( string $root, string $command, array $options ): void {
		$engine = new Release_Engine();
		$plan = $engine->plan( $root, $options['date'] ?? null );
		$dry_run = $options['dryRun'] ?? false;
		if ( 'version' === $command && ! $dry_run ) {
			$engine->apply( $plan, $root );
		}
		$output = $plan;
		unset( $output['edits'] );
		if ( $options['json'] ?? false ) {
			if ( 'version' === $command && $dry_run ) {
				$output['diff'] = $this->diff( $root, $plan['edits'] );
			}
			fwrite( STDOUT, json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
			return;
		}
		if ( null === $plan['bump'] ) {
			fwrite( STDOUT, "No pending release for {$plan['project']} ({$plan['currentVersion']}).\n" );
		} else {
			fwrite( STDOUT, "{$plan['project']}: {$plan['currentVersion']} -> {$plan['nextVersion']} ({$plan['bump']}); tag {$plan['tag']}.\n" );
		}
		if ( 'version' === $command ) {
			fwrite( STDOUT, $dry_run ? $this->diff( $root, $plan['edits'] ) : "Applied release plan.\n" );
		}
	}

	private function diff( string $root, array $edits ): string {
		$output = '';
		foreach ( $edits as $edit ) {
			$relative = ltrim( substr( $edit['path'], strlen( $root ) ), '/' );
			$old_file = null === $edit['old'] ? '/dev/null' : tempnam( sys_get_temp_dir(), 'php-changesets-old-' );
			$new_file = null === $edit['new'] ? '/dev/null' : tempnam( sys_get_temp_dir(), 'php-changesets-new-' );
			if ( false === $old_file || false === $new_file ) {
				if ( false !== $old_file && '/dev/null' !== $old_file ) {
					unlink( $old_file );
				}
				if ( false !== $new_file && '/dev/null' !== $new_file ) {
					unlink( $new_file );
				}
				throw new Release_Exception( 'Could not stage a preview diff.' );
			}
			try {
				if ( '/dev/null' !== $old_file ) {
					if ( strlen( $edit['old'] ) !== file_put_contents( $old_file, $edit['old'] ) ) {
						throw new Release_Exception( 'Could not write a preview input.' );
					}
				}
				if ( '/dev/null' !== $new_file ) {
					if ( strlen( $edit['new'] ) !== file_put_contents( $new_file, $edit['new'] ) ) {
						throw new Release_Exception( 'Could not write a preview input.' );
					}
				}
				$process = proc_open(
					[ 'git', 'diff', '--no-index', '--no-ext-diff', '--no-color', '--unified=3', '--', $old_file, $new_file ],
					[
						0 => [ 'pipe', 'r' ],
						1 => [ 'pipe', 'w' ],
						2 => [ 'pipe', 'w' ],
					],
					$pipes
				);
				if ( ! is_resource( $process ) ) {
					throw new Release_Exception( 'Could not run git diff.' );
				}
				fclose( $pipes[0] );
				$diff = stream_get_contents( $pipes[1] );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				$exit = proc_close( $process );
				if ( 0 !== $exit && 1 !== $exit ) {
					throw new Release_Exception( 'Could not generate preview diff: ' . trim( $error ) );
				}
				$hunk = strpos( $diff, '@@ ' );
				if ( false !== $hunk ) {
					$before = '/dev/null' === $old_file ? '/dev/null' : "a/{$relative}";
					$after = '/dev/null' === $new_file ? '/dev/null' : "b/{$relative}";
					$output .= "--- {$before}\n+++ {$after}\n" . substr( $diff, $hunk );
				}
			} finally {
				if ( '/dev/null' !== $old_file && is_file( $old_file ) ) {
					unlink( $old_file );
				}
				if ( '/dev/null' !== $new_file && is_file( $new_file ) ) {
					unlink( $new_file );
				}
			}
		}
		return $output;
	}

	private function prompt( string $label ): string {
		if ( ! stream_isatty( STDIN ) ) {
			throw new Release_Exception( 'Use --bump and --summary without a terminal.' );
		}
		fwrite( STDOUT, $label );
		return trim( (string) fgets( STDIN ) );
	}

	private function help(): string {
		return "Usage: changesets <command> [options]\n\nCommands:\n  init       Initialize a single-project configuration\n  add        Add a changeset (--bump patch|minor|major --summary TEXT, or --empty)\n  status     Show the pending release plan (--json)\n  version    Preview or apply a release (--dry-run, --json, --date YYYY-MM-DD)\n  recover    Restore files after an interrupted release\n\nGlobal option: --root DIRECTORY (default: current directory)\n";
	}
}
