<?php

namespace WPElevator\PHP_Changesets;

/**
 * Provide the standalone changeset command-line interface, with the commands
 * and options of the wp-release `changeset-*` commands.
 */
final class Cli {

	/**
	 * Builder functions that the scaffold being rendered imports.
	 *
	 * @var array<string, true>
	 */
	private array $scaffold_functions = [];

	private Git $diff_git;

	private Logger $logger;

	/**
	 * Whether the command may ask in a terminal: never with --json or
	 * --github-output, like wp-release.
	 */
	private bool $interactive = false;

	/**
	 * @param Git|null $diff_git Git runner of the dry-run diffs, in the temporary directory.
	 */
	public function __construct( ?Git $diff_git = null ) {
		$this->diff_git = $diff_git ?? new Git( sys_get_temp_dir() );
		$this->logger = new Logger();
	}

	/**
	 * Run a command and return its exit code, like wp-release: 0 for success,
	 * 1 for an unexpected failure like a failed git command, and 2 for
	 * invalid options, failed release checks or missing changesets.
	 */
	public function run( array $argv ): int {
		$command = $argv[1] ?? 'help';

		if ( in_array( $command, [ 'help', '--help', '-h' ], true ) ) {
			fwrite( STDOUT, $this->help() );

			return 0;
		}

		try {
			$options = $this->options( array_slice( $argv, 2 ) );
			$exit = $this->run_command( $command, $options );
		} catch ( Release_Exception $error ) {
			$this->logger->failure( $error, "changeset {$command} failed" );
			$exit = 2;
		} catch ( \Throwable $error ) {
			$this->logger->failure( $error, "changeset {$command} failed" );
			$exit = 1;
		}

		return $exit;
	}

	private function run_command( string $command, array $options ): int {
		$exit = 0;

		if ( isset( $options['help'] ) ) {
			fwrite( STDOUT, $this->help() );
		} else {
			$this->logger = $this->logger( $options );
			$this->validate_options( $command, $options );
			$this->interactive = ! isset( $options['json'] ) && ! isset( $options['github_output'] ) && stream_isatty( STDIN );

			$files = new Project_Files( $options['root'] ?? getcwd() );
			$config_loader = new Project_Config( $files, $this->config_overrides( $options ) );

			if ( 'init' === $command ) {
				$this->init( $files, $config_loader, $options );
			} elseif ( 'add' === $command ) {
				$this->add( $files, $config_loader, $options );
			} elseif ( 'recover' === $command ) {
				$recovered = ( new Release_Transaction( $files ) )->recover();
				$this->output(
					$options,
					[ 'recovered' => $recovered ],
					$recovered ? 'Recovered the release transaction.' : 'No release transaction needs recovery.'
				);
			} elseif ( 'status' === $command ) {
				$exit = $this->status( new Release_Engine( $files, $config_loader ), $options );
			} elseif ( 'version' === $command ) {
				$this->version( $files, new Release_Engine( $files, $config_loader ), $options );
			} else {
				$this->tag( new Release_Engine( $files, $config_loader ), $options );
			}
		}

		return $exit;
	}

	private function options( array $arguments ): array {
		$options = [];
		$boolean = [
			'--help' => 'help',
			'--json' => 'json',
			'--github-output' => 'github_output',
			'--dry-run' => 'dry_run',
			'--empty' => 'empty',
			'--untagged' => 'untagged',
			'--push' => 'push',
			'--ci' => 'ci',
		];
		$repeatable = [
			'--package' => 'package',
			'--bump' => 'bump',
		];
		$values = [
			'--root' => 'root',
			'--config' => 'config',
			'--tag-format' => 'tag_format',
			'--project-name' => 'project_name',
			'--base-branch' => 'base_branch',
			'--remote' => 'remote',
			'--log-format' => 'log_format',
			'--since' => 'since',
			'--summary' => 'summary',
			'--name' => 'name',
			'--date' => 'date',
			'--prerelease' => 'prerelease',
			'--snapshot' => 'snapshot',
			'--version' => 'version',
			'--release-title' => 'release_title',
		];

		for ( $index = 0; $index < count( $arguments ); ++$index ) {
			$argument = $arguments[ $index ];

			if ( isset( $boolean[ $argument ] ) ) {
				$options[ $boolean[ $argument ] ] = true;
			} elseif ( isset( $repeatable[ $argument ] ) && isset( $arguments[ $index + 1 ] ) ) {
				$options[ $repeatable[ $argument ] ][] = $arguments[ ++$index ];
			} elseif ( isset( $values[ $argument ] ) && isset( $arguments[ $index + 1 ] ) ) {
				$options[ $values[ $argument ] ] = $arguments[ ++$index ];
			} else {
				throw new Release_Exception( "Unknown or incomplete option: {$argument}." );
			}
		}

		return $options;
	}

	private function validate_options( string $command, array $options ): void {
		$global = [ 'root', 'config', 'tag_format', 'project_name', 'base_branch', 'remote', 'log_format', 'ci', 'json', 'github_output' ];
		$allowed = [
			'init' => [],
			'add' => [ 'bump', 'summary', 'name', 'empty', 'package', 'since' ],
			'recover' => [],
			'status' => [ 'since' ],
			'version' => [ 'dry_run', 'date', 'package', 'prerelease', 'snapshot' ],
			'tag' => [ 'dry_run', 'untagged', 'since', 'package', 'version', 'release_title', 'push' ],
		];

		if ( ! isset( $allowed[ $command ] ) ) {
			throw new Release_Exception( "Unknown command: {$command}." );
		}

		foreach ( array_keys( $options ) as $option ) {
			if ( ! in_array( $option, array_merge( $global, $allowed[ $command ] ), true ) ) {
				throw new Release_Exception( 'Option --' . str_replace( '_', '-', $option ) . " is not valid for {$command}." );
			}
		}

		if ( 'add' === $command && isset( $options['empty'] ) && ( isset( $options['bump'] ) || isset( $options['summary'] ) ) ) {
			throw new Release_Exception( '--empty cannot be combined with --bump or --summary.' );
		}
	}

	/**
	 * The logger of the --log-format, or --ci for github, which is also the
	 * default in GitHub Actions.
	 */
	private function logger( array $options ): Logger {
		$default = isset( $options['ci'] ) || 'true' === getenv( 'GITHUB_ACTIONS' ) ? 'github' : 'text';
		$format = $options['log_format'] ?? $default;

		if ( ! in_array( $format, Logger::FORMATS, true ) ) {
			throw new Release_Exception( "Unknown log format: {$format}; expected text or github." );
		}

		$summary = (string) getenv( 'GITHUB_STEP_SUMMARY' );
		$workspace = (string) getenv( 'GITHUB_WORKSPACE' );

		return new Logger( $format, '' === $workspace ? (string) getcwd() : $workspace, '' === $summary ? null : $summary );
	}

	/**
	 * The global options that replace configured settings for one command,
	 * with the --config file relative to the current directory.
	 *
	 * @return array{config?: string, tagFormat?: string, projectName?: string, remote?: string, baseBranch?: string}
	 */
	private function config_overrides( array $options ): array {
		$overrides = [];
		$names = [
			'config' => 'config',
			'tag_format' => 'tagFormat',
			'project_name' => 'projectName',
			'remote' => 'remote',
			'base_branch' => 'baseBranch',
		];

		foreach ( array_intersect_key( $names, $options ) as $option => $name ) {
			$overrides[ $name ] = $options[ $option ];
		}

		if ( isset( $overrides['config'] ) && '/' !== substr( $overrides['config'], 0, 1 ) ) {
			$overrides['config'] = getcwd() . '/' . $overrides['config'];
		}

		return $overrides;
	}

	/**
	 * Print a result as JSON with --json, or the text, and set it as GitHub
	 * Actions step outputs with --github-output.
	 *
	 * @param array<string, mixed> $result
	 */
	private function output( array $options, array $result, string $text ): void {
		$this->github_output( $options, $result );

		if ( $options['json'] ?? false ) {
			fwrite( STDOUT, $this->json( $result, JSON_PRETTY_PRINT ) . "\n" );
		} elseif ( '' !== $text ) {
			$this->logger->info( $text );
		}
	}

	/**
	 * Encode a result, keeping maps that may be empty as JSON objects.
	 *
	 * @param array<string, mixed> $result
	 */
	private function json( array $result, int $flags = 0 ): string {
		if ( isset( $result['changedPackageFiles'] ) ) {
			$result['changedPackageFiles'] = (object) $result['changedPackageFiles'];
		}

		if ( isset( $result['packages'] ) && [] === $result['packages'] && isset( $result['file'] ) ) {
			$result['packages'] = (object) [];
		}

		return (string) json_encode( $result, $flags | JSON_UNESCAPED_SLASHES );
	}

	private function init( Project_Files $files, Project_Config $config_loader, array $options ): void {
		$config = $config_loader->load();
		$directory = $files->resolve( $config['changesets'] );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			throw new Release_Exception( 'Could not create changeset directory.' );
		}

		$created = ! $config_loader->has_config();
		$file = $config_loader->config_file();

		if ( $created ) {
			$contents = $this->scaffold( $config );

			if ( strlen( $contents ) !== file_put_contents( $file, $contents, LOCK_EX ) ) {
				throw new Release_Exception( 'Could not write changesets.config.php.' );
			}
		}

		$packages = array_column( ( new Package_Discovery( $files ) )->discover( $config ), 'name' );
		$name = 1 === count( $packages ) ? $packages[0] : $config['changesets'];

		$this->output(
			$options,
			[
				'config' => $this->relative( $files, $file ),
				'created' => $created,
				'changesets' => $config['changesets'],
				'packages' => $packages,
			],
			$created ? "Initialized PHP Changesets for {$name}." : 'Initialized PHP Changesets.'
		);
	}

	/**
	 * A path relative to the project root, or as it is outside the root.
	 */
	private function relative( Project_Files $files, string $path ): string {
		$root = $files->get_root() . '/';

		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}

	/**
	 * Render an inferred canonical configuration as a project config file
	 * that uses the descriptor builders. A single-group scaffold carries a
	 * commented template that shows how to add more groups.
	 */
	private function scaffold( array $config ): string {
		$this->scaffold_functions = [ 'define_config' => true ];
		$groups = [];

		foreach ( $config['packages'] as $group ) {
			$groups = array_merge( $groups, $this->group_lines( $group ) );
		}

		if ( 1 === count( $config['packages'] ) ) {
			$this->scaffold_functions['composer_json_version'] = true;

			foreach ( $this->group_template_lines() as $line ) {
				$groups[] = "\t" . $line;
			}
		}

		$lines = [
			'<?php',
			'',
			'/**',
			' * PHP Changesets release configuration scaffolded by changeset init.',
			' */',
			'',
		];
		$settings = [
			"\t'changesets' => " . var_export( $config['changesets'], true ) . ',',
			"\t'tagFormat' => " . var_export( $config['tagFormat'], true ) . ',',
		];

		if ( $config['readmeChangelog'] ) {
			$settings[] = "\t'readmeChangelog' => true,";
		} elseif ( $this->has_readme_target( $config ) ) {
			$settings[] = "\t// Add the release notes to the == Changelog == section of the plugin readme:";
			$settings[] = "\t// 'readmeChangelog' => true,";
		}

		foreach ( array_keys( $this->scaffold_functions ) as $function ) {
			$lines[] = 'use function WPElevator\PHP_Changesets\\' . $function . ';';
		}

		return implode(
			"\n",
			array_merge(
				$lines,
				[
					'',
					'return define_config( [',
				],
				$settings,
				[ "\t'packages' => [" ],
				$groups,
				[
					"\t],",
					'] );',
				]
			)
		) . "\n";
	}

	/**
	 * Whether a package group updates a readme Stable tag, so that the
	 * scaffold can offer the opt-in readme changelog.
	 */
	private function has_readme_target( array $config ): bool {
		$kinds = [];

		foreach ( $config['packages'] as $group ) {
			$kinds = array_merge( $kinds, array_column( $group['version']['to'], 'kind' ) );
		}

		return in_array( 'wordpress-stable-tag', $kinds, true );
	}

	/**
	 * Render one package group of the scaffold.
	 *
	 * @param array{glob: string, id: string|null, idFrom: string, tagFormat: string|null, version: array} $group
	 * @return string[]
	 */
	private function group_lines( array $group ): array {
		$lines = [
			"\t\t[",
			"\t\t\t'glob' => " . var_export( $group['glob'], true ) . ',',
		];

		if ( null !== $group['id'] ) {
			$lines[] = "\t\t\t'id' => " . var_export( $group['id'], true ) . ',';
		}

		$lines[] = "\t\t\t'idFrom' => " . var_export( $group['idFrom'], true ) . ',';

		if ( null !== $group['tagFormat'] ) {
			$lines[] = "\t\t\t'tagFormat' => " . var_export( $group['tagFormat'], true ) . ',';
		}

		$lines[] = "\t\t\t'version' => [";
		$lines[] = "\t\t\t\t'from' => " . $this->descriptor_expression( $group['version']['from'] ) . ',';

		if ( [] === $group['version']['to'] ) {
			$lines[] = "\t\t\t\t'to' => [],";
		} else {
			$lines[] = "\t\t\t\t'to' => [";

			foreach ( $group['version']['to'] as $target ) {
				$lines[] = "\t\t\t\t\t" . $this->descriptor_expression( $target ) . ',';
			}

			$lines[] = "\t\t\t\t],";
		}

		$lines[] = "\t\t\t],";
		$lines[] = "\t\t],";

		return $lines;
	}

	/**
	 * Commented template of a second package group, extending the scaffold's
	 * inferred single group.
	 *
	 * @return string[]
	 */
	private function group_template_lines(): array {
		return [
			'// Add further package groups by repeating this template:',
			'// [',
			"// \t'glob' => 'packages/*',",
			"// \t'idFrom' => 'composer.json#name',",
			"// \t'version' => [",
			"// \t\t'from' => composer_json_version(),",
			"// \t\t'to' => [ composer_json_version() ],",
			"// \t],",
			'// ],',
		];
	}

	private function descriptor_expression( array $descriptor ): string {
		$kind = $descriptor['kind'] ?? '';

		if ( 'callback' === $kind ) {
			throw new Release_Exception( 'A callback descriptor cannot be written into an inferred scaffold; use wordpress_plugin_version(), git_tag_version(), or another matching builder.' );
		}

		$builder = [
			'composer-json' => 'composer_json_version',
			'wordpress-plugin-header' => 'wordpress_plugin_version',
			'wordpress-theme-header' => 'wordpress_theme_version',
			'wordpress-stable-tag' => 'wordpress_stable_tag',
			'php-constant' => 'php_constant_version',
			'git-tag' => 'git_tag_version',
		][ $kind ] ?? null;

		if ( null === $builder ) {
			throw new Release_Exception( "Unscaffoldable version descriptor kind: {$kind}." );
		}

		$this->scaffold_functions[ $builder ] = true;
		$args = [];

		if ( 'composer-json' === $kind ) {
			if ( 'composer.json' !== $descriptor['file'] ) {
				$args[] = var_export( $descriptor['file'], true );
			}

			if ( ! $descriptor['ifPresent'] ) {
				$args[] = 'false';
			}
		} elseif ( 'wordpress-stable-tag' === $kind ) {
			if ( isset( $descriptor['file'] ) ) {
				$args[] = var_export( $descriptor['file'], true );
			}

			if ( ! $descriptor['ifPresent'] ) {
				$args[] = 'false';
			}
		} elseif ( 'php-constant' === $kind ) {
			$args[] = var_export( $descriptor['file'], true );
			$args[] = var_export( $descriptor['constant'], true );
		} elseif ( 'git-tag' === $kind ) {
			$args = $this->git_tag_arguments( $descriptor );
		} elseif ( isset( $descriptor['file'] ) ) {
			$args[] = var_export( $descriptor['file'], true );
		}

		return [] === $args ? $builder . '()' : $builder . '( ' . implode( ', ', $args ) . ' )';
	}

	/**
	 * Arguments of a git_tag_version() call, leaving out trailing defaults.
	 *
	 * @return string[]
	 */
	private function git_tag_arguments( array $descriptor ): array {
		$args = [ var_export( $descriptor['pattern'] ?? null, true ) ];

		if ( isset( $descriptor['initialVersion'] ) ) {
			$args[] = var_export( $descriptor['initialVersion'], true );
		}

		return [ 'NULL' ] === $args ? [] : str_replace( 'NULL', 'null', $args );
	}

	private function add( Project_Files $files, Project_Config $config_loader, array $options ): void {
		$config = $config_loader->load();
		$packages = ( new Package_Discovery( $files ) )->discover( $config );
		$empty = $options['empty'] ?? false;

		$bumps = [];
		$summary = '';

		if ( ! $empty ) {
			$engine = new Release_Engine( $files, $config_loader );
			// Like wp-release, a sole package is released without comparing changes,
			// unless --since asks for them.
			$since = $options['since'] ?? ( 1 < count( $packages ) ? $engine->base_ref() : null );
			$changed = null === $since ? null : $engine->status( $since, true )['missingChangesets'];
			$selected = $this->selected_packages( $files, $packages, $options['package'] ?? [], $changed, $since, $options );
			$bumps = $this->package_bumps( $selected, $options['bump'] ?? [] );
			$summary = $options['summary'] ?? $this->prompt( 'Summary: ' );
		}

		if ( ! $empty && '' === trim( $summary ) ) {
			throw new Release_Exception( 'A release changeset needs a summary.' );
		}

		$directory = $files->resolve( $config['changesets'] );

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

		$contents = ( new Changeset_Reader() )->format( $bumps, $summary );
		$written = fwrite( $handle, $contents );
		fclose( $handle );

		if ( false === $written || strlen( $contents ) !== $written ) {
			unlink( $file );

			throw new Release_Exception( 'Could not write changeset.' );
		}

		$this->output(
			$options,
			[
				'file' => $this->relative( $files, $file ),
				'packages' => $bumps,
				'summary' => trim( $summary ),
			],
			"Created {$file}."
		);
	}

	/**
	 * Pair the selected packages with their bumps: one --bump for every
	 * package, one per --package in the same order, or a prompt per package.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $selected
	 * @param string[] $given Bumps from the command line.
	 * @return array<string, string> Package name to bump.
	 */
	private function package_bumps( array $selected, array $given ): array {
		if ( 1 < count( $given ) && count( $given ) !== count( $selected ) ) {
			throw new Release_Exception( 'Pass one --bump for every package, or one --bump after each --package.' );
		}

		$bumps = [];

		foreach ( $selected as $index => $package ) {
			$bump = $given[ $index ] ?? $given[0] ?? $this->prompt( $this->bump_label( $package, count( $selected ) ) );

			if ( ! in_array( $bump, [ 'patch', 'minor', 'major' ], true ) ) {
				throw new Release_Exception( 'A release changeset needs a patch, minor, or major bump for ' . $package['name'] . '.' );
			}

			$bumps[ $package['name'] ] = $bump;
		}

		return $bumps;
	}

	private function bump_label( array $package, int $count ): string {
		if ( 1 === $count ) {
			return 'Bump (patch, minor, major): ';
		}

		return 'Bump for ' . $package['name'] . ' (patch, minor, major): ';
	}

	/**
	 * Select the packages a new changeset releases, rejecting unknown names.
	 *
	 * Given names are used as they are. Without them, a single package is
	 * selected, unless --since compares the changes; with --since, a single
	 * --bump or a run without a terminal selects the changed packages without
	 * a changeset, like wp-release. Otherwise a terminal prompt asks, where
	 * Enter selects the changed packages, or every package without --since.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param string[]      $wanted  Selected package names.
	 * @param string[]|null $changed Changed packages without a changeset since the base ref.
	 * @param string|null   $since   Base ref of the changes, from --since or the config.
	 * @return array<int, array{name: string, path: string, directory: string, group: int}>
	 */
	private function selected_packages( Project_Files $files, array $packages, array $wanted, ?array $changed, ?string $since, array $options ): array {
		if ( [] === $wanted && null === $changed && 1 === count( $packages ) ) {
			$wanted = array_column( $packages, 'name' );
		} elseif ( [] === $wanted && null !== $changed && ( ! $this->interactive || 1 === count( $options['bump'] ?? [] ) ) ) {
			if ( [] === $changed ) {
				throw new Release_Exception( "No package changed since {$since} without a changeset. Pass --package <name> to choose the packages." );
			}

			$wanted = $changed;
		} elseif ( [] === $wanted ) {
			if ( ! $this->interactive ) {
				throw new Release_Exception( sprintf( 'The configuration discovers %d packages; select them with --package NAME, or the changed ones with --since REF.', count( $packages ) ) );
			}

			$wanted = $this->prompt_packages( $packages, $changed );
		}

		if ( [] === $wanted ) {
			throw new Release_Exception( 'Select at least one package, or pass --empty for no release.' );
		}

		$names = ( new Package_Discovery( $files ) )->names( $packages, $wanted );

		return array_map(
			static fn( string $name ): array => array_values( array_filter( $packages, static fn( array $package ): bool => $name === $package['name'] ) )[0],
			$names
		);
	}

	/**
	 * Prompt for the affected packages of one changeset in a terminal,
	 * marking the changed ones without a changeset.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param string[]|null $changed
	 * @return string[]
	 */
	private function prompt_packages( array $packages, ?array $changed ): array {
		fwrite( STDOUT, null === $changed ? "Packages affected by this changeset, comma-separated (empty for all):\n" : "Packages affected by this changeset, comma-separated (empty for the changed ones):\n" );

		foreach ( $packages as $package ) {
			$mark = in_array( $package['name'], $changed ?? [], true ) ? ' (changed)' : '';
			fwrite( STDOUT, '  ' . $package['name'] . $mark . "\n" );
		}

		$answer = $this->prompt( 'Packages: ' );

		if ( '' === $answer ) {
			return $changed ?? array_column( $packages, 'name' );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $answer ) ), 'strlen' ) );
	}

	/**
	 * Show the pending releases and, with --since, fail with exit code 2 for
	 * each changed package without a changeset, like wp-release
	 * `changeset-status`.
	 */
	private function status( Release_Engine $engine, array $options ): int {
		$result = $engine->status( $options['since'] ?? null );
		$rows = [];

		foreach ( $result['changedPackages'] as $name ) {
			$rows[] = '| ' . $name . ' | ' . $this->package_status( $result, $name ) . ' |';
		}

		$this->logger->summary( implode( "\n", array_merge( [ '### Changeset status', '', '| Package | Status |', '| --- | --- |' ], $rows ) ) );
		$this->output( $options, $result, $this->releases_text( $result['releases'] ) );

		foreach ( $result['missingChangesets'] as $name ) {
			$file = $result['changedPackageFiles'][ $name ][0] ?? null;
			$annotation = [ 'title' => 'Missing changeset' ];

			if ( null !== $file ) {
				$annotation['file'] = $engine->absolute_path( $file );
			}

			$this->logger->error( "Changed package \"{$name}\" has no changeset.", $annotation );
		}

		return [] === $result['missingChangesets'] ? 0 : 2;
	}

	private function package_status( array $result, string $name ): string {
		$status = 'no release requested';

		if ( in_array( $name, $result['missingChangesets'], true ) ) {
			$status = 'missing changeset';
		} elseif ( in_array( $name, array_column( array_filter( $result['releases'], static fn( array $release ): bool => null !== $release['bump'] ), 'package' ), true ) ) {
			$status = 'changeset found';
		}

		return $status;
	}

	/**
	 * Preview or apply the pending releases.
	 */
	private function version( Project_Files $files, Release_Engine $engine, array $options ): void {
		$plan = $engine->plan(
			[
				'date' => $options['date'] ?? null,
				'prerelease' => $options['prerelease'] ?? null,
				'snapshot' => $options['snapshot'] ?? null,
				'packages' => $options['package'] ?? [],
			]
		);
		$dry_run = $options['dry_run'] ?? false;

		if ( ! $dry_run ) {
			$engine->apply( $plan );
		}

		$result = [
			'changesets' => $plan['changesets'],
			'releases' => $plan['releases'],
		];

		if ( $dry_run ) {
			$result['diff'] = $this->diff( $files->get_root(), $plan['sections'] );
		}

		$rows = [];

		foreach ( $plan['releases'] as $release ) {
			if ( null !== $release['bump'] ) {
				$rows[] = "| {$release['package']} | {$release['currentVersion']} → {$release['nextVersion']} | {$release['bump']} |";
			}
		}

		$this->logger->summary( implode( "\n", array_merge( [ $dry_run ? '### Release plan (dry run)' : '### Released versions', '', '| Package | Version | Bump |', '| --- | --- | --- |' ], $rows ) ) );
		$this->output( $options, $result, $this->releases_text( $plan['releases'] ) . "\n" . rtrim( $result['diff'] ?? 'Applied release plan.' ) );
	}

	/**
	 * One line per package release, or that it has nothing pending.
	 */
	private function releases_text( array $releases ): string {
		$lines = [];

		foreach ( $releases as $release ) {
			$lines[] = null === $release['bump']
				? "No pending release for {$release['package']} ({$release['currentVersion']})."
				: "{$release['package']}: {$release['currentVersion']} -> {$release['nextVersion']} ({$release['bump']}); tag {$release['tag']}.";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Create the missing release tags, like wp-release `changeset-tag`, or
	 * list them with --dry-run or --untagged.
	 */
	private function tag( Release_Engine $engine, array $options ): void {
		$result = $engine->tag(
			[
				'dryRun' => $options['dry_run'] ?? false,
				'untagged' => $options['untagged'] ?? false,
				'since' => $options['since'] ?? null,
				'packages' => $options['package'] ?? [],
				'version' => $options['version'] ?? null,
				'releaseTitle' => $options['release_title'] ?? null,
				'push' => $options['push'] ?? false,
			]
		);
		$summary = [ '### Release tags', '' ];
		$lines = [];

		foreach ( $result['tags'] as $tag ) {
			$status = $this->tag_status( $tag, $options );
			$previous = isset( $tag['previousTag'] ) ? " after `{$tag['previousTag']}`" : '';
			$summary[] = "- `{$tag['tag']}` ({$tag['title']}){$previous}: {$status}";
			$summary[] = '';
			$summary[] = '  <details><summary>Release notes</summary>';
			$summary[] = '';
			$summary[] = '  ' . str_replace( "\n", "\n  ", $tag['notes'] );
			$summary[] = '';
			$summary[] = '  </details>';
			$lines[] = isset( $options['untagged'] ) ? $tag['tag'] : "{$tag['tag']}: {$status}.";
		}

		$this->logger->summary( [] === $result['tags'] ? "### Release tags\n\nNo release needs a tag." : implode( "\n", $summary ) );
		$this->output( $options, $result, implode( "\n", $lines ) );
	}

	private function tag_status( array $tag, array $options ): string {
		$status = 'exists';

		if ( $tag['created'] ) {
			$status = isset( $options['push'] ) ? 'created and pushed' : 'created';
		} elseif ( ! $tag['exists'] ) {
			$status = 'missing';
		}

		return $status;
	}

	/**
	 * Set a command result as GitHub Actions step outputs with
	 * --github-output, like wp-release: the compact JSON in `result`, and
	 * every top-level string, number or boolean as its own output. Every
	 * value but the JSON, which has no line breaks, uses a random delimiter,
	 * so that a value with line breaks can't set other outputs.
	 *
	 * @param array<string, mixed> $result
	 */
	private function github_output( array $options, array $result ): void {
		$file = (string) getenv( 'GITHUB_OUTPUT' );

		if ( $options['github_output'] ?? false ) {
			if ( '' === $file ) {
				throw new Release_Exception( '--github-output requires the GITHUB_OUTPUT environment variable of GitHub Actions.' );
			}

			$delimiter = 'ghadelimiter_' . bin2hex( random_bytes( 16 ) );
			$output = 'result=' . $this->json( $result ) . "\n";

			foreach ( $result as $name => $value ) {
				if ( is_scalar( $value ) ) {
					$text = is_bool( $value ) ? var_export( $value, true ) : (string) $value;
					$output .= $name . '<<' . $delimiter . "\n" . $text . "\n" . $delimiter . "\n";
				}
			}

			if ( false === file_put_contents( $file, $output, FILE_APPEND | LOCK_EX ) ) {
				throw new Release_Exception( 'Could not write the GitHub Actions step outputs.' );
			}
		}
	}

	/**
	 * Render one combined preview diff with a section per release.
	 *
	 * @param array<int, array{title: string, edits: array<int, array{path: string, old: ?string, new: ?string}>}> $sections
	 */
	private function diff( string $root, array $sections ): string {
		$output = '';

		foreach ( $sections as $section ) {
			if ( [] !== $section['edits'] ) {
				$output .= "\n### {$section['title']}\n\n";

				foreach ( $section['edits'] as $edit ) {
					$output .= $this->diff_edit( $root, $edit );
				}
			}
		}

		return $output;
	}

	/**
	 * Render the planned edits of one release section without changing the project.
	 *
	 * The temporary files are only inputs to git diff. They are removed before
	 * the method returns or throws.
	 */
	private function diff_edit( string $root, array $edit ): string {
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

			// The diff inputs are absolute temporary files and the index comparison
			// needs no repository, so the subprocess runs away from any enclosing
			// git checkout that may be unreadable, like a linked worktree .git file.
			$diff = $this->diff_git->run( [ 'diff', '--no-index', '--no-ext-diff', '--no-color', '--unified=3', '--', $old_file, $new_file ], [ 0, 1 ] );
			$hunk = strpos( $diff, '@@ ' );
			$edit_diff = '';

			if ( false !== $hunk ) {
				$before = '/dev/null' === $old_file ? '/dev/null' : "a/{$relative}";
				$after = '/dev/null' === $new_file ? '/dev/null' : "b/{$relative}";
				$edit_diff = "--- {$before}\n+++ {$after}\n" . substr( $diff, $hunk );
			}
		} finally {
			if ( '/dev/null' !== $old_file && is_file( $old_file ) ) {
				unlink( $old_file );
			}

			if ( '/dev/null' !== $new_file && is_file( $new_file ) ) {
				unlink( $new_file );
			}
		}

		return $edit_diff;
	}

	private function prompt( string $label ): string {
		if ( ! $this->interactive ) {
			throw new Release_Exception( 'Use --bump and --summary without a terminal.' );
		}

		fwrite( STDOUT, $label );

		return trim( (string) fgets( STDIN ) );
	}

	private function help(): string {
		return "Usage: changeset <command> [options]\n\n"
			. "Commands:\n"
			. "  init       Create the changeset directory and scaffold changesets.config.php\n"
			. "  add        Add a changeset: --package NAME|PATH --bump BUMP pairs (repeatable), a\n"
			. "             single --bump for the changed packages, or --empty; --summary TEXT,\n"
			. "             --since REF, --name NAME. Asks in a terminal without them\n"
			. "  status     Show the pending releases; with --since REF, or the configured base\n"
			. "             branch, exit with 2 when a changed package has no changeset\n"
			. "  version    Apply the pending releases: --dry-run, --date YYYY-MM-DD, --package\n"
			. "             NAME|PATH (repeatable), --prerelease ID or --snapshot ID\n"
			. "  tag        Create the missing release tags: --dry-run, --untagged, --since REF,\n"
			. "             --package NAME|PATH (repeatable), --version VERSION,\n"
			. "             --release-title TEMPLATE, --push\n"
			. "  recover    Restore files after an interrupted release\n\n"
			. "Global options:\n"
			. "  --root DIRECTORY        Project root (default: current directory)\n"
			. "  --config PATH           Config file instead of changesets.config.php\n"
			. "  --tag-format FORMAT     Replace the configured tag formats, like {name}@{version}\n"
			. "  --project-name NAME     Identifier of an inferred root package\n"
			. "  --base-branch BRANCH    Branch on the remote that status and add compare with\n"
			. "  --remote NAME           Git remote of the base branch and tag pushes (default: origin)\n"
			. "  --json                  Print the result as JSON\n"
			. "  --github-output         Set the result as GitHub Actions step outputs\n"
			. "  --log-format text|github  Log as text or GitHub Actions annotations and job summaries\n"
			. "  --ci                    Shorthand for --log-format github, the default in GitHub Actions\n\n"
			. "Exit codes: 0 success, 1 unexpected failure like a failed git command, 2 invalid\n"
			. "options, failed release checks or missing changesets.\n";
	}
}
