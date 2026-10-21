<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Turns off session.upload_progress in the custom.ini of every WordPress site, as hardening against php2shell (CVE-2026-109618, CVE-2026-109619).
 *
 * A custom.ini that already sets it is left alone. Disabled sites pick it up on their next start.
 */
class DisableWpSessionUploadProgress extends Base {

	const SITE_TYPE = 'wp';

	const SETTING_LINES = [
		'; Session upload progress is off for security; set it to On only if your app needs it.',
		'session.upload_progress.enabled = Off',
	];

	/** @var Site[] */
	private $sites = [];

	/** @var array site_url => [ 'site' => Site, 'file' => string, 'backup' => string|null ] for every custom.ini changed by up(). */
	private $changed = [];

	/** @var array site_url => php container ID seen by up(), for running sites. */
	private $containers = [];

	/** @var string|null */
	private $backup_root;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		$this->sites = array_filter(
			Site::all() ?: [],
			function ( $site ) {
				return self::SITE_TYPE === $site->site_type;
			}
		);
		if ( empty( $this->sites ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Add the setting to every site's custom.ini.
	 *
	 * @throws \Throwable
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping disable-session-upload-progress migration as it is not needed.' );

			return;
		}

		$this->backup_root = EE_BACKUP_DIR . '/session-upload-progress-' . self::SITE_TYPE . '-' . date( 'Ymd-His' );
		if ( file_exists( $this->backup_root ) ) {
			$this->backup_root .= '-' . getmypid();
		}

		try {
			foreach ( $this->sites as $site ) {
				$this->update_site( $site );
			}
		} catch ( \Throwable $e ) {
			$this->restore_files();
			throw $e;
		}

		if ( empty( $this->changed ) ) {
			return;
		}

		EE::log( 'Disabled session.upload_progress in custom.ini of ' . count( $this->changed ) . ' ' . self::SITE_TYPE . ' site(s). The original files are in ' . $this->backup_root );

		// The image migration may recreate php containers, which then read the new file anyway; reload only the others, once it is done.
		if ( method_exists( '\EE\Migration\CustomContainerMigrations', 'keep_executed_migrations' ) ) {
			EE::add_hook( 'after_docker_image_migration', [ $this, 'reload_unchanged_containers' ] );
		} else {
			$this->reload_unchanged_containers();
		}
	}

	/**
	 * Restore the original custom.ini files and reload the running sites.
	 */
	public function down() {

		if ( empty( $this->changed ) ) {
			return;
		}

		$sites = array_column( $this->changed, 'site' );
		$this->restore_files();

		// A rolled-back image migration may have restarted them on the new file.
		foreach ( $sites as $site ) {
			if ( $site->site_enabled ) {
				$id = $this->get_php_container( $site );
				if ( '' !== $id ) {
					$this->reload_php( $site->site_url, $id );
				}
			}
		}
	}

	/**
	 * Gracefully reload php-fpm of the sites whose php container is still the one up() saw.
	 */
	public function reload_unchanged_containers() {

		foreach ( $this->containers as $site_url => $id ) {
			if ( ! isset( $this->changed[ $site_url ] ) ) {
				continue;
			}
			if ( $this->get_php_container( $this->changed[ $site_url ]['site'] ) !== $id ) {
				EE::debug( "$site_url: php container was recreated or stopped, no reload needed." );
				continue;
			}
			$this->reload_php( $site_url, $id );
		}
		$this->containers = [];
	}

	/**
	 * Return custom.ini content with the setting added, or null when it already sets it.
	 *
	 * @param string $content Current content.
	 *
	 * @return string|null
	 */
	public static function add_setting( string $content ) {

		$bom = '';
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$bom     = "\xEF\xBB\xBF";
			$content = substr( $content, 3 );
		}

		// Directive names are case-sensitive, so only this spelling takes effect.
		if ( preg_match( '/^[ \t]*session\.upload_progress\.enabled[ \t]*=/m', $content ) ) {
			return null;
		}

		$eol   = false !== strpos( $content, "\r\n" ) ? "\r\n" : "\n";
		$lines = implode( $eol, self::SETTING_LINES ) . $eol;

		if ( '' === trim( $content ) ) {
			return $bom . $lines;
		}

		// Directives after a [PATH=] or [HOST=] section apply only there, so it goes before the first section.
		if ( preg_match( '/^[ \t]*\[/m', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos = $matches[0][1];

			return $bom . substr( $content, 0, $pos ) . $lines . $eol . substr( $content, $pos );
		}

		if ( "\n" !== substr( $content, -1 ) ) {
			$content .= $eol;
		}

		return $bom . $content . $eol . $lines;
	}

	/**
	 * Add the setting to one site's custom.ini. Problems with one site are warnings, so they don't block the upgrade.
	 *
	 * @param Site $site Site to update.
	 */
	private function update_site( $site ) {

		$conf_dir = $site->site_fs_path . '/config/php/php/conf.d';
		$file     = $conf_dir . '/custom.ini';
		$manual   = "Add 'session.upload_progress.enabled = Off' to $file and run: ee site reload $site->site_url";

		if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: custom.ini is not a regular file. $manual" );

			return;
		}

		$created = ! file_exists( $file );
		if ( $created && ! is_dir( $conf_dir ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: $conf_dir is missing. $manual" );

			return;
		}

		$content = $created ? '' : @file_get_contents( $file );
		if ( false === $content ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not read custom.ini. $manual" );

			return;
		}

		$new_content = self::add_setting( $content );
		if ( null === $new_content ) {
			EE::debug( "$site->site_url: custom.ini already sets session.upload_progress.enabled, leaving it." );

			return;
		}

		$backup = null;
		if ( ! $created ) {
			$backup = $this->backup_root . '/' . $site->site_url . '/custom.ini';
			try {
				$this->fs->mkdir( dirname( $backup ), 0700 );
				$this->fs->copy( $file, $backup, true );
				$this->fs->chmod( $backup, fileperms( $file ) & 0777 );
				$this->fs->touch( $backup, filemtime( $file ) );
			} catch ( \Throwable $e ) {
				EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not back up custom.ini ({$e->getMessage()}). $manual" );

				return;
			}
		}

		$this->changed[ $site->site_url ] = [
			'site'   => $site,
			'file'   => $file,
			'backup' => $backup,
		];

		// Written in place: keeps owner, mode and inode (macOS bind-mounts this single file).
		if ( ! $this->write_in_place( $file, $new_content ) ) {
			$this->restore_file( $site->site_url );
			EE::warning( "Could not disable session.upload_progress for $site->site_url: could not write custom.ini. $manual" );

			return;
		}

		if ( $created ) {
			$this->match_owner( $file, $conf_dir );
		}

		$id = $site->site_enabled ? $this->get_php_container( $site ) : '';
		if ( '' !== $id ) {
			$this->containers[ $site->site_url ] = $id;
		}
		EE::debug( "$site->site_url: disabled session.upload_progress in custom.ini." );
	}

	/**
	 * Restore every file changed by up().
	 */
	private function restore_files() {

		foreach ( array_keys( $this->changed ) as $site_url ) {
			$this->restore_file( $site_url );
		}
		$this->containers = [];

		if ( ! empty( $this->backup_root ) && is_dir( $this->backup_root ) && ! ( new \FilesystemIterator( $this->backup_root ) )->valid() ) {
			$this->fs->remove( $this->backup_root );
		}
	}

	/**
	 * Restore one file changed by up(); its backup is removed once restored.
	 *
	 * @param string $site_url Site.
	 */
	private function restore_file( string $site_url ) {

		$entry = $this->changed[ $site_url ];
		unset( $this->changed[ $site_url ] );

		if ( null === $entry['backup'] ) {
			if ( ! @unlink( $entry['file'] ) && file_exists( $entry['file'] ) ) {
				EE::warning( "Could not remove {$entry['file']} added by the session.upload_progress migration." );
			}

			return;
		}

		$content = @file_get_contents( $entry['backup'] );
		// Skip the write when it never happened, e.g. on an immutable file.
		if ( false === $content || ( @file_get_contents( $entry['file'] ) !== $content && ! $this->write_in_place( $entry['file'], $content ) ) ) {
			EE::warning( "Could not restore {$entry['file']}; its original is {$entry['backup']}" );

			return;
		}
		$this->fs->remove( dirname( $entry['backup'] ) );
		EE::debug( "$site_url: restored custom.ini." );
	}

	/**
	 * @param string $file    File to overwrite without replacing it.
	 * @param string $content New content.
	 *
	 * @return bool Whether the file now holds exactly $content.
	 */
	private function write_in_place( string $file, string $content ) {

		$written = @file_put_contents( $file, $content );
		clearstatcache( true, $file );

		return strlen( $content ) === $written && @file_get_contents( $file ) === $content;
	}

	/**
	 * Give a new file the owner and group of its directory.
	 *
	 * @param string $file File.
	 * @param string $dir  Directory.
	 */
	private function match_owner( string $file, string $dir ) {

		@chmod( $file, 0644 );
		@chown( $file, fileowner( $dir ) );
		@chgrp( $file, filegroup( $dir ) );
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string ID of the site's running php container, or '' when there is none.
	 */
	private function get_php_container( $site ) {

		$cwd = getcwd();
		if ( ! @chdir( $site->site_fs_path ) ) {
			return '';
		}
		$result = EE::launch( \EE_DOCKER::docker_compose_with_custom() . ' ps -q php' );
		if ( false !== $cwd ) {
			chdir( $cwd );
		}

		return 0 === $result->return_code ? trim( $result->stdout ) : '';
	}

	/**
	 * Same graceful reload as `ee site reload`: php-fpm re-reads its ini files.
	 *
	 * @param string $site_url Site.
	 * @param string $id       php container ID.
	 */
	private function reload_php( string $site_url, string $id ) {

		$result = EE::launch( 'docker exec --user root ' . escapeshellarg( $id ) . " sh -c 'kill -USR2 1'" );
		if ( 0 === $result->return_code ) {
			EE::debug( "$site_url: reloaded php-fpm." );
		} else {
			EE::warning( "Could not reload php-fpm of $site_url. Run: ee site reload $site_url" );
		}
	}
}
