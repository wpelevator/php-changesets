<?php

namespace WPElevator\PHP_Changesets;

/**
 * Find and load the one authoritative release configuration, or infer it.
 */
final class Project_Config {

	/**
	 * The configuration file, relative to the project root.
	 */
	private const CONFIG_PATH = 'changesets.config.php';

	/**
	 * Removed or foreign configuration files that are rejected with a pointer
	 * to the configuration file. The upstream Changesets `.changeset/config.json`
	 * is rejected too, since both tools would consume the same changesets.
	 */
	private const REMOVED_PATHS = [ '.php-changesets.json', '.changeset/config.php', '.changeset/config.json', 'config/changesets.php' ];

	/**
	 * Tag format of an inferred root package, like the wp-release single
	 * project default.
	 */
	private const ROOT_TAG_FORMAT = 'v{version}';

	private const CANONICAL_KEYS = [ 'changesets', 'tagFormat', 'releaseTitle', 'root', 'remote', 'baseBranch', 'changelog', 'readmeChangelog', 'nextVersion', 'changelogEntry', 'packages' ];

	private Project_Files $files;

	private ?string $config_path;

	private ?string $tag_format;

	private ?string $project_name;

	/**
	 * Settings that replace the configured ones for one command.
	 *
	 * @var array{remote?: string, baseBranch?: string}
	 */
	private array $settings;

	/**
	 * @param array{config?: string, tagFormat?: string, projectName?: string, remote?: string, baseBranch?: string} $overrides
	 *     Command-line overrides, like the global options of the CLI: the absolute path of a
	 *     config file to load instead of changesets.config.php (--config), a tag format that
	 *     replaces the configured ones (--tag-format), the identifier of an inferred root
	 *     package (--project-name), and the git remote and base branch (--remote, --base-branch).
	 */
	public function __construct( Project_Files $files, array $overrides = [] ) {
		$this->files = $files;
		$this->config_path = $overrides['config'] ?? null;
		$this->tag_format = $overrides['tagFormat'] ?? null;
		$this->project_name = $overrides['projectName'] ?? null;
		$this->settings = array_intersect_key( $overrides, array_flip( [ 'remote', 'baseBranch' ] ) );
	}

	/**
	 * Whether a configuration file is selected or exists at the project root.
	 */
	public function has_config(): bool {
		$file = $this->config_file();

		return null !== $this->config_path || file_exists( $file ) || is_link( $file );
	}

	/**
	 * The configuration file, selected or at the project root.
	 */
	public function config_file(): string {
		return $this->config_path ?? $this->files->get_root() . '/' . self::CONFIG_PATH;
	}

	/**
	 * Load and validate the configuration, or infer the workspace.
	 *
	 * Without the file, the project is inferred from its Composer
	 * manifest and root WordPress plugin headers as one package group. With
	 * one, the returned array is validated through the same define()
	 * checks that hand-written configurations use.
	 *
	 * @return array{changesets: string, tagFormat: string, releaseTitle: string|callable|null, root: string|null, remote: string, baseBranch: string|null, changelog: string, readmeChangelog: bool, nextVersion: callable|null, changelogEntry: callable|null, packages: array<int, mixed>}
	 */
	public function load(): array {
		$this->reject_removed_configs();

		$config = $this->has_config() ? $this->load_file() : $this->infer();

		if ( [] !== $this->settings ) {
			$config = define_config( array_merge( $config, $this->settings ) );
		}

		return null === $this->tag_format ? $config : $this->with_tag_format( $config, $this->tag_format );
	}

	/**
	 * Replace the tag format of the repository and every group for one
	 * command, like the wp-release global --tag-format.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private function with_tag_format( array $config, string $tag_format ): array {
		if ( 1 !== substr_count( $tag_format, '{version}' ) ) {
			throw new Release_Exception( '--tag-format must contain {version} exactly once.' );
		}

		$config['tagFormat'] = $tag_format;

		foreach ( array_keys( $config['packages'] ) as $index ) {
			$config['packages'][ $index ]['tagFormat'] = null;
		}

		return $config;
	}

	/**
	 * Load and validate the selected or root configuration file.
	 *
	 * @return array<string, mixed>
	 */
	private function load_file(): array {
		$file = $this->config_file();

		if ( ! file_exists( $file ) && ! is_link( $file ) ) {
			throw new Release_Exception( 'PHP Changesets config not found: ' . $file . '.' );
		}

		if ( is_link( $file ) || ! is_file( $file ) ) {
			throw new Release_Exception( 'The PHP Changesets config must be a regular file.' );
		}

		try {
			$config = ( static function ( string $path ) {
				return require $path;
			} )( $file );
		} catch ( \Throwable $error ) {
			throw new Release_Exception( 'Could not load the PHP Changesets config: ' . basename( $file ), 0, $error );
		}

		if ( ! is_array( $config ) ) {
			throw new Release_Exception( 'The PHP Changesets config must return an array.' );
		}

		return $this->assert_canonical( define_config( $config ) );
	}

	/**
	 * Infer one root package group from the project's version sources.
	 *
	 * A second group for `packages/*` is inferred only when the layout is
	 * unambiguous: every matched directory declares a distinct Composer
	 * name and version in its own manifest. Otherwise the scaffold shows
	 * how to extend the single group instead.
	 *
	 * @return array{changesets: string, tagFormat: string, releaseTitle: string|callable|null, root: string|null, remote: string, baseBranch: string|null, changelog: string, readmeChangelog: bool, nextVersion: callable|null, changelogEntry: callable|null, packages: array<int, mixed>}
	 */
	private function infer(): array {
		$manifest = $this->manifest();
		$root = $this->files->get_root();

		$name = $manifest['name'] ?? null;
		$id_from = is_string( $name ) && '' !== trim( $name ) ? 'composer.json#name' : 'path';

		$plugin_files = [];
		$php_files = glob( $root . '/*.php' );

		if ( false === $php_files ) {
			$php_files = [];
		}

		foreach ( $php_files as $file ) {
			$contents = (string) file_get_contents( $file, false, null, 0, 8192 );

			if ( preg_match( '/^\s*(?:(?:\/\/|\/\*|\*)\s*)?Plugin Name\s*:/mi', $contents ) ) {
				$plugin_files[] = basename( $file );
			}
		}

		if ( count( $plugin_files ) > 1 ) {
			throw new Release_Exception( 'Multiple plugin headers found; add a PHP Changesets config.' );
		}

		$has_manifest_version = isset( $manifest['version'] ) && is_string( $manifest['version'] ) && '' !== $manifest['version'];

		if ( $plugin_files && $has_manifest_version ) {
			throw new Release_Exception( 'Multiple version sources found; add a PHP Changesets config.' );
		}

		if ( $plugin_files ) {
			$descriptor = wordpress_plugin_version( $plugin_files[0] );
			$targets = [ $descriptor ];

			if ( is_file( $root . '/readme.txt' ) ) {
				$targets[] = wordpress_stable_tag( 'readme.txt' );
			}

			$version = [
				'from' => $descriptor,
				'to' => $targets,
			];
		} elseif ( $has_manifest_version ) {
			$descriptor = composer_json_version();
			$version = [
				'from' => $descriptor,
				'to' => [ $descriptor ],
			];
		} elseif ( $manifest ) {
			$version = [
				'from' => git_tag_version(),
				'to' => [],
			];
		} else {
			throw new Release_Exception( 'No version source found; add a PHP Changesets config.' );
		}

		$packages = [
			[
				'glob' => '.',
				'id' => $this->root_id( $id_from, $plugin_files ),
				'idFrom' => $id_from,
				'version' => $version,
				'tagFormat' => self::ROOT_TAG_FORMAT,
			],
		];

		$manifest_packages = $this->manifest_packages_group( 'composer.json#name' === $id_from ? $name : null );

		if ( null !== $manifest_packages ) {
			$packages[] = $manifest_packages;
		}

		// Readme changelog updates are opt-in, like in wp-release.
		return define_config( [ 'packages' => $packages ] );
	}

	/**
	 * A fixed identifier for an inferred root package: the --project-name,
	 * or without a Composer name the plugin slug of its main file, so that
	 * changesets keep naming the package in checkouts under any directory name.
	 *
	 * @param string[] $plugin_files
	 */
	private function root_id( string $id_from, array $plugin_files ): ?string {
		$id = $this->project_name;

		if ( null === $id && 'path' === $id_from ) {
			if ( [] === $plugin_files ) {
				throw new Release_Exception( 'Pass --project-name NAME, add a name to composer.json, or add a PHP Changesets config with a package id, to name the project.' );
			}

			$id = basename( $plugin_files[0], '.php' );
		}

		return $id;
	}

	/**
	 * Build the inferred `packages/*` group, or null when the layout is
	 * ambiguous and the single root group should stay alone.
	 *
	 * @return array{glob: string, idFrom: string, version: array}|null
	 */
	private function manifest_packages_group( ?string $root_name ): ?array {
		$matched = glob( $this->files->get_root() . '/packages/*', GLOB_ONLYDIR );

		if ( false === $matched ) {
			return null;
		}

		$names = [];

		foreach ( $matched as $directory ) {
			$manifest = $this->manifest_in( $directory );

			if ( null === $manifest ) {
				return null;
			}

			$name = $manifest['name'];

			if ( $name === $root_name || in_array( $name, $names, true ) ) {
				return null;
			}

			$names[] = $name;
		}

		if ( [] === $names ) {
			return null;
		}

		return [
			'glob' => 'packages/*',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => composer_json_version(),
				'to' => [ composer_json_version() ],
			],
		];
	}

	/**
	 * Read a Composer manifest that declares a non-empty name and version.
	 *
	 * @return array{name: string, version: string}|null
	 */
	private function manifest_in( string $directory ): ?array {
		if ( is_link( $directory ) ) {
			return null;
		}

		$file = $directory . '/composer.json';

		if ( ! is_file( $file ) ) {
			return null;
		}

		$manifest = json_decode( (string) file_get_contents( $file ), true );

		if (
			! is_array( $manifest )
			|| ! isset( $manifest['name'], $manifest['version'] )
			|| ! is_string( $manifest['name'] )
			|| ! is_string( $manifest['version'] )
			|| '' === trim( $manifest['name'] )
			|| '' === trim( $manifest['version'] )
		) {
			return null;
		}

		return $manifest;
	}

	private function reject_removed_configs(): void {
		$root = $this->files->get_root();
		$found = [];

		foreach ( self::REMOVED_PATHS as $path ) {
			$file = $root . '/' . $path;

			if ( $file !== $this->config_path && ( file_exists( $file ) || is_link( $file ) ) ) {
				$found[] = $path;
			}
		}

		if ( [] !== $found ) {
			throw new Release_Exception( 'PHP Changesets config file ' . implode( ', ', $found ) . ' is not supported; move the configuration to ' . self::CONFIG_PATH . ' at the project root.' );
		}
	}

	/**
	 * Double-check that define returned the canonical workspace shape.
	 */
	private function assert_canonical( array $config ): array {
		if ( self::CANONICAL_KEYS !== array_keys( $config ) || ! $this->is_list( $config['packages'] ) ) {
			throw new Release_Exception( 'The PHP Changesets config must return the canonical workspace shape.' );
		}

		return $config;
	}

	private function manifest(): array {
		$file = $this->files->get_root() . '/composer.json';

		if ( ! is_file( $file ) ) {
			return [];
		}

		$manifest = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $manifest ) ) {
			throw new Release_Exception( 'Invalid composer.json.' );
		}

		return $manifest;
	}

	private function is_list( $values ): bool {
		if ( ! is_array( $values ) ) {
			return false;
		}

		$next = 0;

		foreach ( array_keys( $values ) as $key ) {
			if ( $next !== $key ) {
				return false;
			}

			++$next;
		}

		return true;
	}
}
