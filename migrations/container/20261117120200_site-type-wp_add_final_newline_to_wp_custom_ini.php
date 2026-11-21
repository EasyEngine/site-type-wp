<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Ends the custom.ini of every WordPress site with a newline, so a line appended to it doesn't join its last line (sendmail_path as shipped).
 *
 * Nothing else changes, and PHP reads the file the same way, so php-fpm needs no reload.
 */
class AddFinalNewlineToWpCustomIni extends Base {

	const SITE_TYPE = 'wp';

	/** Largest custom.ini read. */
	const MAX_SIZE = 1048576;

	/** @var Site[] */
	private $sites = [];

	/** @var array[] Files changed by up(), restored by down(). */
	private $changed = [];

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
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping custom.ini final-newline migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			// One broken site must not block the upgrade of the others.
			try {
				$this->migrate_site( $site );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not add the missing final newline to the custom.ini of %s: %s.', $site->site_url, rtrim( $e->getMessage(), '.' ) ) );
			}
		}
	}

	/**
	 * Removes the newline again from the files changed by up(), while they still hold what up() wrote.
	 */
	public function down() {

		foreach ( array_reverse( $this->changed ) as $change ) {
			try {
				// The file holds the change, which can be a byte larger than MAX_SIZE.
				\EE\Site\Utils\put_container_file_contents( $change['file'], $change['dir'], $change['old'], $change['new'], strlen( $change['new'] ) );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not remove the final newline added to the custom.ini of %s: %s.', $change['site']->site_url, rtrim( $e->getMessage(), '.' ) ) );
			}
		}
		$this->changed = [];
	}

	/**
	 * @param Site $site Site.
	 *
	 * @throws \Exception
	 */
	private function migrate_site( $site ) {

		$file = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
		if ( ! file_exists( $file ) && ! is_link( $file ) ) {
			EE::debug( "$site->site_url: no custom.ini at $file." );

			return;
		}

		if ( ! function_exists( '\EE\Site\Utils\put_container_file_contents' ) ) {
			throw new \Exception( 'this site-command version cannot change custom.ini safely' );
		}

		// The site's php container can swap anything in its config volume, so custom.ini is read and rewritten in place through a checked handle.
		$base = realpath( $site->site_fs_path . '/config/php' );
		if ( false === $base ) {
			throw new \Exception( 'could not resolve config/php' );
		}
		$dir     = $base . '/php/conf.d';
		$content = \EE\Site\Utils\get_container_file_contents( $file, $dir, self::MAX_SIZE );

		$new_content = self::add_newline( $content );
		if ( null === $new_content ) {
			EE::debug( "$site->site_url: custom.ini is empty or already ends with a newline." );

			return;
		}

		// The write fails without changing the file, or puts the old content back itself.
		\EE\Site\Utils\put_container_file_contents( $file, $dir, $new_content, $content, self::MAX_SIZE );

		$this->changed[] = [
			'site' => $site,
			'file' => $file,
			'dir'  => $dir,
			'old'  => $content,
			'new'  => $new_content,
		];
		EE::debug( "$site->site_url: added the missing final newline to custom.ini." );
	}

	/**
	 * Return the content with a final line break in the file's own style, or null when it is blank or already ends with one.
	 *
	 * @param string $content custom.ini content.
	 *
	 * @return string|null
	 */
	public static function add_newline( string $content ) {

		if ( '' === trim( $content ) || "\n" === substr( $content, -1 ) || "\r" === substr( $content, -1 ) ) {
			return null;
		}

		$eol = false !== strpos( $content, "\r\n" ) ? "\r\n" : ( false === strpos( $content, "\n" ) && false !== strpos( $content, "\r" ) ? "\r" : "\n" );

		return $content . $eol;
	}
}
