<?php

namespace WPElevator\PHP_Changesets;

/**
 * Validate the release configuration and return its canonical shape.
 *
 * define() is the single accumulator of configuration validation: it
 * accepts a workspace configuration, rejects unknown or malformed settings
 * eagerly and returns the canonical shape every other component consumes.
 * Configs reach it through define_config(), and the descriptor builders in
 * functions.php produce the version descriptors it validates.
 */
final class Config_Schema {

	private const DEFAULT_CHANGESETS = '.changeset';

	private const DEFAULT_CHANGELOG = 'CHANGELOG.md';

	private const DEFAULT_ID_FROM = 'path';

	private const DEFAULT_REMOTE = 'origin';

	private const TOP_LEVEL_KEYS = [ 'changesets', 'tagFormat', 'releaseTitle', 'root', 'remote', 'baseBranch', 'changelog', 'readmeChangelog', 'nextVersion', 'changelogEntry', 'packages' ];

	private const GROUP_KEYS = [ 'glob', 'exclude', 'id', 'idFrom', 'version', 'changelog', 'tagFormat' ];

	private const ID_SOURCES = [ 'path', 'composer.json#name', 'package.json#name' ];

	private const DESCRIPTOR_KEYS = [
		'composer-json' => [ 'kind', 'file', 'ifPresent' ],
		'wordpress-plugin-header' => [ 'kind', 'file' ],
		'wordpress-theme-header' => [ 'kind', 'file' ],
		'wordpress-stable-tag' => [ 'kind', 'file', 'ifPresent' ],
		'php-constant' => [ 'kind', 'file', 'constant' ],
		'git-tag' => [ 'kind', 'pattern', 'initialVersion' ],
		'callback' => [ 'kind', 'file', 'resolve', 'replace' ],
	];

	private const TAG_RELATIVE_DESCRIPTOR_KINDS = [ 'composer-json', 'wordpress-stable-tag' ];

	private Semver $semver;

	public function __construct( ?Semver $semver = null ) {
		$this->semver = $semver ?? new Semver();
	}

	/**
	 * Validate a workspace configuration and return its canonical shape.
	 *
	 * Configuration file contents and inferred settings are funneled through
	 * this method, so it reports every malformed setting with the key path
	 * that carries it.
	 *
	 * @return array{changesets: string, tagFormat: string, releaseTitle: string|callable|null, root: string|null, remote: string, baseBranch: string|null, changelog: string, readmeChangelog: bool, nextVersion: callable|null, changelogEntry: callable|null, packages: array<int, mixed>}
	 */
	public function define( array $config ): array {
		$unknown = array_diff_key( $config, array_flip( self::TOP_LEVEL_KEYS ) );

		if ( [] !== $unknown ) {
			throw new Release_Exception( 'Configuration key ' . $this->first_key( $unknown ) . ' is not supported.' );
		}

		$packages = $config['packages'] ?? null;

		if ( ! $this->is_list( $packages ) || [] === $packages ) {
			throw new Release_Exception( 'Configuration key packages must be a non-empty list of package groups.' );
		}

		$groups = [];

		foreach ( $packages as $index => $group ) {
			$groups[] = $this->normalize_group( $group, (int) $index );
		}

		$changesets = $config['changesets'] ?? self::DEFAULT_CHANGESETS;

		if ( ! $this->is_relative_path( $changesets ) ) {
			throw new Release_Exception( 'Configuration key changesets must be a non-empty relative path.' );
		}

		$tag_format = $config['tagFormat'] ?? Tag_Format::DEFAULT;

		if ( ! $this->has_version_placeholder( $tag_format ) ) {
			throw new Release_Exception( 'Configuration key tagFormat must contain {version} exactly once.' );
		}

		$release_title = $config['releaseTitle'] ?? null;

		if ( null !== $release_title && ! is_callable( $release_title ) && ! $this->is_release_title( $release_title ) ) {
			throw new Release_Exception( 'Configuration key releaseTitle must be a callable, or a template with a {name}, {path}, {version} or {tag} placeholder.' );
		}

		$root = $config['root'] ?? null;

		if ( null !== $root && ! $this->is_relative_path( $root ) ) {
			throw new Release_Exception( 'Configuration key root must be a non-empty relative path or null.' );
		}

		$remote = $config['remote'] ?? self::DEFAULT_REMOTE;

		if ( ! is_string( $remote ) || '' === trim( $remote ) ) {
			throw new Release_Exception( 'Configuration key remote must be a non-empty string.' );
		}

		$base_branch = $config['baseBranch'] ?? null;

		if ( null !== $base_branch && ( ! is_string( $base_branch ) || '' === trim( $base_branch ) ) ) {
			throw new Release_Exception( 'Configuration key baseBranch must be a non-empty string or null.' );
		}

		$changelog = $config['changelog'] ?? self::DEFAULT_CHANGELOG;

		if ( ! is_string( $changelog ) ) {
			throw new Release_Exception( 'Configuration key changelog must be a string; use an empty string to disable changelog generation.' );
		}

		$readme_changelog = $config['readmeChangelog'] ?? false;

		if ( ! is_bool( $readme_changelog ) ) {
			throw new Release_Exception( 'Configuration key readmeChangelog must be a boolean.' );
		}

		$next_version = $config['nextVersion'] ?? null;

		if ( null !== $next_version && ! is_callable( $next_version ) ) {
			throw new Release_Exception( 'Configuration key nextVersion must be a callable or null.' );
		}

		$changelog_entry = $config['changelogEntry'] ?? null;

		if ( null !== $changelog_entry && ! is_callable( $changelog_entry ) ) {
			throw new Release_Exception( 'Configuration key changelogEntry must be a callable or null.' );
		}

		return [
			'changesets' => $changesets,
			'tagFormat' => $tag_format,
			'releaseTitle' => $release_title,
			'root' => $root,
			'remote' => $remote,
			'baseBranch' => $base_branch,
			'changelog' => $changelog,
			'readmeChangelog' => $readme_changelog,
			'nextVersion' => $next_version,
			'changelogEntry' => $changelog_entry,
			'packages' => $groups,
		];
	}

	private function normalize_group( $group, int $index ): array {
		$location = 'packages[' . $index . ']';

		if ( ! is_array( $group ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . ' must be a package group array.' );
		}

		$unknown = array_diff_key( $group, array_flip( self::GROUP_KEYS ) );

		if ( [] !== $unknown ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.' . $this->first_key( $unknown ) . ' is not supported.' );
		}

		$glob = $group['glob'] ?? null;

		if ( ! $this->is_relative_path( $glob ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.glob must be a non-empty relative path pattern.' );
		}

		$exclude = $group['exclude'] ?? [];

		if ( ! $this->is_string_list( $exclude ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.exclude must be a list of relative path patterns.' );
		}

		$id = $group['id'] ?? null;

		if ( null !== $id && ( ! is_string( $id ) || '' === trim( $id ) ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.id must be a non-empty package identifier or null.' );
		}

		$id_from = $group['idFrom'] ?? self::DEFAULT_ID_FROM;

		if ( ! in_array( $id_from, self::ID_SOURCES, true ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.idFrom is not supported; use path, composer.json#name, or package.json#name.' );
		}

		$changelog = $group['changelog'] ?? null;

		if ( null !== $changelog && ! is_string( $changelog ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.changelog must be a string or null; use an empty string to disable changelog generation.' );
		}

		$tag_format = $group['tagFormat'] ?? null;

		if ( null !== $tag_format && ( ! is_string( $tag_format ) || ! $this->has_version_placeholder( $tag_format ) ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.tagFormat must contain {version} exactly once or be null.' );
		}

		return [
			'glob' => $glob,
			'exclude' => $exclude,
			'id' => $id,
			'idFrom' => $id_from,
			'version' => $this->normalize_version( $group['version'] ?? null, $location . '.version' ),
			'changelog' => $changelog,
			'tagFormat' => $tag_format,
		];
	}

	private function normalize_version( $version, string $location ): array {
		if ( ! is_array( $version ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . ' must be an array with a from descriptor and a to list.' );
		}

		$unknown = array_diff_key(
			$version,
			[
				'from' => true,
				'to' => true,
			]
		);

		if ( [] !== $unknown ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.' . $this->first_key( $unknown ) . ' is not supported.' );
		}

		$targets = $version['to'] ?? null;

		if ( ! $this->is_list( $targets ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.to must be a list of target descriptors; use an empty list for a tag-only project.' );
		}

		return [
			'from' => $this->validate_descriptor( $version['from'] ?? null, $location . '.from', false ),
			'to' => array_map(
				fn( $target, $index ): array => $this->validate_descriptor( $target, $location . '.to[' . $index . ']', true ),
				$targets,
				array_keys( $targets )
			),
		];
	}

	private function validate_descriptor( $descriptor, string $location, bool $is_target ): array {
		if ( ! is_array( $descriptor ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . ' must be a version descriptor array.' );
		}

		$kind = $descriptor['kind'] ?? null;
		$kinds = array_keys( self::DESCRIPTOR_KEYS );

		if ( ! is_string( $kind ) || ! in_array( $kind, $kinds, true ) || ( $is_target && 'git-tag' === $kind ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.kind is not supported' . ( $is_target ? ' as a version target.' : '.' ) );
		}

		$unknown = array_diff_key( $descriptor, array_flip( self::DESCRIPTOR_KEYS[ $kind ] ) );

		if ( [] !== $unknown ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.' . $this->first_key( $unknown ) . ' is not supported for the ' . $kind . ' descriptor.' );
		}

		if ( in_array( $kind, [ 'composer-json', 'php-constant', 'callback' ], true ) && ! $this->is_relative_path( $descriptor['file'] ?? null ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.file must be a non-empty relative path.' );
		}

		if ( 'php-constant' === $kind && ( ! is_string( $descriptor['constant'] ?? null ) || '' === $descriptor['constant'] ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.constant must be a non-empty PHP constant name.' );
		}

		if ( 'callback' === $kind && ( ! is_callable( $descriptor['resolve'] ?? null ) ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.resolve must be a callable.' );
		}

		if ( 'callback' === $kind && isset( $descriptor['replace'] ) && ! is_callable( $descriptor['replace'] ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.replace must be a callable.' );
		}

		if ( 'git-tag' === $kind ) {
			$this->validate_git_tag( $descriptor, $location );
		}

		if ( in_array( $kind, self::TAG_RELATIVE_DESCRIPTOR_KINDS, true ) && isset( $descriptor['ifPresent'] ) && ! is_bool( $descriptor['ifPresent'] ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.ifPresent must be a boolean.' );
		}

		return $descriptor;
	}

	private function validate_git_tag( array $descriptor, string $location ): void {
		$pattern = $descriptor['pattern'] ?? null;

		if ( null !== $pattern && ! is_string( $pattern ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.pattern must be a string.' );
		}

		$initial_version = $descriptor['initialVersion'] ?? null;

		if ( null !== $initial_version && ( ! is_string( $initial_version ) || ! $this->semver->is_valid( $initial_version ) || $this->semver->is_pre_release( $initial_version ) ) ) {
			throw new Release_Exception( 'Configuration key ' . $location . '.initialVersion must be a stable version.' );
		}
	}

	private function is_relative_path( $value ): bool {
		return is_string( $value ) && '' !== $value && 0 !== strpos( $value, '/' ) && false === strpos( $value, "\0" );
	}

	/**
	 * Whether a value is a release title template with a placeholder, since
	 * a title without one would name every release alike.
	 */
	public function is_release_title( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\{(?:name|path|version|tag)\}/', $value );
	}

	private function has_version_placeholder( $value ): bool {
		return is_string( $value ) && 1 === substr_count( $value, '{version}' );
	}

	private function is_list( $values ): bool {
		if ( ! is_array( $values ) ) {
			return false;
		}

		return [] === $values || $this->sequential_keys( $values );
	}

	private function is_string_list( $values ): bool {
		if ( ! $this->is_list( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return false;
			}
		}

		return true;
	}

	private function sequential_keys( array $values ): bool {
		$next = 0;

		foreach ( array_keys( $values ) as $key ) {
			if ( $next !== $key ) {
				return false;
			}

			++$next;
		}

		return true;
	}

	private function first_key( array $values ): string {
		return (string) array_key_first( $values );
	}
}
