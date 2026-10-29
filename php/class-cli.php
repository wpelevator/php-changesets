<?php

namespace WPElevator\PHP_Changesets;

/**
 * Provide the standalone changesets command-line interface.
 */
final class Cli {

	/**
	 * Builder functions that the scaffold being rendered imports.
	 *
	 * @var array<string, true>
	 */
	private array $scaffold_functions = [];

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

			$files = new Project_Files( $options['root'] ?? getcwd() );

			if ( 'init' === $command ) {
				$this->init( $files );
			} elseif ( 'add' === $command ) {
				$this->add( $files, $options );
			} elseif ( 'recover' === $command ) {
				$transaction = new Release_Transaction( $files );
				$recovered = $transaction->recover();
				fwrite( STDOUT, $recovered ? "Recovered the release transaction.\n" : "No release transaction needs recovery.\n" );
			} elseif ( 'status' === $command || 'version' === $command ) {
				$this->release( $files, $command, $options );
			} elseif ( 'tags' === $command ) {
				$this->tags( $files, $options );
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
			'--github-output' => 'github_output',
			'--dry-run' => 'dry_run',
			'--empty' => 'empty',
		];
		$repeatable = [
			'--package' => 'package',
			'--bump' => 'bump',
		];
		$values = [
			'--root' => 'root',
			'--summary' => 'summary',
			'--name' => 'name',
			'--date' => 'date',
			'--prerelease' => 'prerelease',
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
		$allowed = [
			'init' => [ 'root' ],
			'add' => [ 'root', 'bump', 'summary', 'name', 'empty', 'package' ],
			'recover' => [ 'root' ],
			'status' => [ 'root', 'json', 'github_output' ],
			'version' => [ 'root', 'json', 'github_output', 'dry_run', 'date', 'prerelease' ],
			'tags' => [ 'root', 'json', 'github_output' ],
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

	private function init( Project_Files $files ): void {
		$config_loader = new Project_Config( $files );
		$config = $config_loader->load();
		$directory = $files->resolve( $config['changesets'] );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			throw new Release_Exception( 'Could not create changeset directory.' );
		}

		if ( $config_loader->has_config() ) {
			fwrite( STDOUT, "Initialized PHP Changesets.\n" );

			return;
		}

		$file = $files->get_root() . '/changesets.config.php';
		$contents = $this->scaffold( $config );

		if ( strlen( $contents ) !== file_put_contents( $file, $contents, LOCK_EX ) ) {
			throw new Release_Exception( 'Could not write changesets.config.php.' );
		}

		$packages = ( new Package_Discovery( $files ) )->discover( $config );
		$name = 1 === count( $packages ) ? $packages[0]['name'] : $config['changesets'];

		fwrite( STDOUT, "Initialized PHP Changesets for {$name}.\n" );
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
			' * PHP Changesets release configuration scaffolded by changesets init.',
			' */',
			'',
		];
		$settings = [
			"\t'changesets' => " . var_export( $config['changesets'], true ) . ',',
			"\t'tagFormat' => " . var_export( $config['tagFormat'], true ) . ',',
		];

		if ( $config['readmeChangelog'] ) {
			$settings[] = "\t'readmeChangelog' => true,";
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

	private function add( Project_Files $files, array $options ): void {
		$config = ( new Project_Config( $files ) )->load();
		$packages = ( new Package_Discovery( $files ) )->discover( $config );
		$empty = $options['empty'] ?? false;

		$bumps = [];
		$summary = '';

		if ( ! $empty ) {
			$selected = $this->selected_packages( $packages, $options['package'] ?? [] );
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

		$frontmatter = '';

		if ( ! $empty ) {
			$lines = [];

			foreach ( $bumps as $name => $bump ) {
				$lines[] = json_encode( (string) $name, JSON_UNESCAPED_SLASHES ) . ': ' . $bump;
			}

			$frontmatter = implode( "\n", $lines ) . "\n";
		}

		$contents = "---\n" . $frontmatter . "---\n\n" . trim( $summary ) . "\n";
		$written = fwrite( $handle, $contents );
		fclose( $handle );

		if ( false === $written || strlen( $contents ) !== $written ) {
			unlink( $file );

			throw new Release_Exception( 'Could not write changeset.' );
		}

		fwrite( STDOUT, "Created {$file}.\n" );
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
	 * Single-package configurations select their only package. With several
	 * packages, selection repeats the given names, prompts in a terminal, and
	 * throws without one.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param string[] $wanted Selected package names.
	 * @return array<int, array{name: string, path: string, directory: string, group: int}>
	 */
	private function selected_packages( array $packages, array $wanted ): array {
		$by_name = [];

		foreach ( $packages as $package ) {
			$by_name[ $package['name'] ] = $package;
		}

		if ( [] === $wanted && 1 === count( $packages ) ) {
			return $packages;
		}

		if ( [] === $wanted ) {
			if ( ! stream_isatty( STDIN ) ) {
				throw new Release_Exception( sprintf( 'The configuration discovers %d packages; select them with --package NAME.', count( $packages ) ) );
			}

			$wanted = $this->prompt_packages( $packages );
		}

		return $this->collect_packages( $by_name, $wanted );
	}

	/**
	 * Prompt for the affected packages of one changeset in a terminal.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @return string[]
	 */
	private function prompt_packages( array $packages ): array {
		fwrite( STDOUT, "Packages affected by this changeset, comma-separated (empty for all):\n" );

		foreach ( $packages as $package ) {
			fwrite( STDOUT, '  ' . $package['name'] . "\n" );
		}

		$answer = $this->prompt( 'Packages: ' );

		if ( '' === $answer ) {
			return array_column( $packages, 'name' );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $answer ) ), 'strlen' ) );
	}

	/**
	 * Resolve selected package identifiers or paths to discovered packages,
	 * keeping selection order and rejecting unknown or repeated packages.
	 *
	 * @param array<string, array{name: string, path: string, directory: string, group: int}> $by_name
	 * @param string[] $wanted Package identifiers, or their paths under the configured root.
	 * @return array<int, array{name: string, path: string, directory: string, group: int}>
	 */
	private function collect_packages( array $by_name, array $wanted ): array {
		$selected = [];

		foreach ( $wanted as $identifier ) {
			$package = $this->find_package( $by_name, $identifier );

			if ( null === $package ) {
				throw new Release_Exception( "Unknown package: {$identifier}." );
			}

			if ( isset( $selected[ $package['name'] ] ) ) {
				throw new Release_Exception( "Package {$package['name']} is selected more than once." );
			}

			$selected[ $package['name'] ] = $package;
		}

		return array_values( $selected );
	}

	/**
	 * Find a package by its identifier, or by its path under the configured
	 * root like `plugins/alpha/`, as wp-release `findPackage()` does.
	 *
	 * @param array<string, array{name: string, path: string, directory: string, group: int}> $by_name
	 * @return array{name: string, path: string, directory: string, group: int}|null
	 */
	private function find_package( array $by_name, string $identifier ): ?array {
		$path = rtrim( (string) preg_replace( '#^\./#', '', str_replace( '\\', '/', $identifier ) ), '/' );
		$found = $by_name[ $identifier ] ?? null;

		foreach ( $by_name as $package ) {
			if ( null === $found && $package['path'] === $path ) {
				$found = $package;
			}
		}

		return $found;
	}

	private function release( Project_Files $files, string $command, array $options ): void {
		$engine = new Release_Engine( $files );
		$plan = $engine->plan( $options['date'] ?? null, $options['prerelease'] ?? null );
		$dry_run = $options['dry_run'] ?? false;

		if ( 'version' === $command && ! $dry_run ) {
			$engine->apply( $plan );
		}

		$result = [
			'changesets' => $plan['changesets'],
			'releases' => $plan['releases'],
		];

		if ( 'version' === $command && $dry_run ) {
			$result['diff'] = $this->diff( $files->get_root(), $plan['sections'] );
		}

		$this->github_output( $options, $result );

		if ( $options['json'] ?? false ) {
			fwrite( STDOUT, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

			return;
		}

		foreach ( $plan['releases'] as $release ) {
			if ( null === $release['bump'] ) {
				fwrite( STDOUT, "No pending release for {$release['package']} ({$release['currentVersion']}).\n" );
			} else {
				fwrite( STDOUT, "{$release['package']}: {$release['currentVersion']} -> {$release['nextVersion']} ({$release['bump']}); tag {$release['tag']}.\n" );
			}
		}

		if ( 'version' === $command ) {
			fwrite( STDOUT, $result['diff'] ?? "Applied release plan.\n" );
		}
	}

	/**
	 * Print the release tags that the current versions still need, one per
	 * line, so that a workflow can create them after merging a release.
	 */
	private function tags( Project_Files $files, array $options ): void {
		$result = ( new Release_Engine( $files ) )->untagged();

		$this->github_output( $options, $result );

		if ( $options['json'] ?? false ) {
			fwrite( STDOUT, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		} else {
			foreach ( $result['tags'] as $tag ) {
				fwrite( STDOUT, $tag['tag'] . "\n" );
			}
		}
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
			$output = 'result=' . json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";

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
			$process = proc_open(
				[ 'git', 'diff', '--no-index', '--no-ext-diff', '--no-color', '--unified=3', '--', $old_file, $new_file ],
				[
					0 => [ 'pipe', 'r' ],
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes,
				sys_get_temp_dir()
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
		if ( ! stream_isatty( STDIN ) ) {
			throw new Release_Exception( 'Use --bump and --summary without a terminal.' );
		}

		fwrite( STDOUT, $label );

		return trim( (string) fgets( STDIN ) );
	}

	private function help(): string {
		return "Usage: changesets <command> [options]\n\n"
			. "Commands:\n"
			. "  init       Create the changeset directory and scaffold changesets.config.php\n"
			. "  add        Add a changeset (--bump patch|minor|major --summary TEXT, or --empty);\n"
			. "             select packages with --package NAME|PATH, or answer the prompt when several\n"
			. "             exist; repeat --bump after each --package for different bumps\n"
			. "  status     Show the pending release plan (--json)\n"
			. "  version    Preview or apply a release (--dry-run, --json, --date YYYY-MM-DD,\n"
			. "             --prerelease ID)\n"
			. "  tags       List the release tags that the current versions still need (--json),\n"
			. "             for a workflow to create after merging a release\n"
			. "  recover    Restore files after an interrupted release\n\n"
			. "Global option: --root DIRECTORY (default: current directory)\n"
			. "status, version and tags: --github-output sets the result as GitHub Actions step outputs\n";
	}
}
