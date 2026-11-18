<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Stops the page cache of every WordPress site with --cache from storing responses that set a cookie (e.g. a new PHP session), which it replayed to other visitors.
 *
 * Changes only a main.conf whose srcache_store_skip line is still the one EasyEngine wrote. Disabled sites pick it up on their next start.
 */
class SkipSetCookieResponsesInWpPageCache extends Base {

	const SITE_TYPE = 'wp';

	/** Largest main.conf read. */
	const MAX_SIZE = 1048576;

	const OLD_LINE = 'srcache_store_skip $skip;';

	const NEW_LINE = 'srcache_store_skip $skip$upstream_http_set_cookie;';

	/** Same as in templates/config/nginx/main.conf.mustache, so a migrated file matches a newly generated one. */
	const COMMENT = '# Evaluated after the response headers: a response that sets a cookie (e.g. a new session) is never stored.';

	/** Prefix of the site's page-cache keys in Redis, as `set $key` in main.conf.mustache builds them. */
	const CACHE_KEY = '%s_page:http';

	/** @var Site[] */
	private $sites = [];

	/** @var array[] Files changed by up(), restored by down(). */
	private $changed = [];

	/** @var string|null Where up() saves the previous main.conf of each site it changes. */
	private $backup_root;

	/** @var int Changed sites whose running nginx may still use the previous main.conf (not found or not reloaded). */
	private $pending = 0;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		$this->sites = array_filter(
			Site::all() ?: [],
			function ( $site ) {
				return self::SITE_TYPE === $site->site_type && $site->cache_nginx_fullpage;
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
			EE::debug( 'Skipping page-cache set-cookie migration as it is not needed.' );

			return;
		}

		// Not EE_BACKUP_DIR/<site>/: `ee site backup` and `ee site restore` empty that dir.
		$this->backup_root = EE_BACKUP_DIR . '/page-cache-set-cookie-' . self::SITE_TYPE . '-' . date( 'Ymd-His' );
		if ( file_exists( $this->backup_root ) ) {
			$this->backup_root .= '-' . getmypid();
		}

		foreach ( $this->sites as $site ) {
			// One broken site must not block the upgrade of the others.
			try {
				$this->migrate_site( $site );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not update the page cache config of %s: %s. %s', $site->site_url, rtrim( $e->getMessage(), '.' ), self::manual_fix( $site ) ) );
			}
		}

		if ( ! empty( $this->changed ) ) {
			$pending = $this->pending ? "; {$this->pending} more once their nginx is reloaded" : '';
			EE::log( 'Responses that set a cookie are no longer stored in the page cache of ' . ( count( $this->changed ) - $this->pending ) . ' ' . self::SITE_TYPE . " site(s)$pending. The previous main.conf files are in " . $this->backup_root );
		}
	}

	/**
	 * Restores the files changed by up() and reloads their running nginx.
	 */
	public function down() {

		foreach ( array_reverse( $this->changed ) as $change ) {
			try {
				$this->restore( $change );
				$id   = self::get_nginx_container( $change['site'] );
				$test = self::test_nginx( $id );
				if ( null === $test ) {
					self::reload_nginx( $change['site'], $id );
				} else {
					EE::warning( sprintf( 'Restored the main.conf of %1$s, but nginx -t failed in its nginx container (%2$s), so it was not reloaded. Run: ee site reload %1$s --nginx', $change['site']->site_url, $test ) );
				}
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not restore the main.conf of %s from %s: %s', $change['site']->site_url, $change['backup'], $e->getMessage() ) );
			}
		}
		$this->changed = [];
		$this->pending = 0;
	}

	/**
	 * @param Site $site Site with the page cache.
	 *
	 * @throws \Exception
	 */
	private function migrate_site( $site ) {

		$file = $site->site_fs_path . '/config/nginx/conf.d/main.conf';
		if ( ! file_exists( $file ) && ! is_link( $file ) ) {
			throw new \Exception( "$file is missing" );
		}

		if ( ! function_exists( '\EE\Site\Utils\put_container_file_contents' ) ) {
			throw new \Exception( 'this site-command version cannot change main.conf safely' );
		}

		// The site's nginx container can swap anything in its config volume, so main.conf is read and rewritten in place through a checked handle.
		$base = realpath( $site->site_fs_path . '/config/nginx' );
		if ( false === $base ) {
			throw new \Exception( 'could not resolve config/nginx' );
		}
		$dir     = $base . '/conf.d';
		$content = \EE\Site\Utils\get_container_file_contents( $file, $dir, self::MAX_SIZE );

		$new_content = self::replace_line( $content );
		if ( null === $new_content ) {
			if ( preg_match( '/^[ \t]*' . preg_quote( self::NEW_LINE, '/' ) . '/m', $content ) ) {
				EE::debug( "$site->site_url: main.conf already skips responses that set a cookie." );
			} else {
				EE::warning( "Left the page cache config of $site->site_url as it is: its srcache_store_skip line isn't the one EasyEngine wrote. " . self::manual_fix( $site ) );
			}

			return;
		}

		$backup = $this->backup_root . '/' . $site->site_url . '/main.conf';
		try {
			$this->fs->mkdir( dirname( $backup ), 0700 );
			$this->fs->dumpFile( $backup, $content );
		} catch ( \Throwable $e ) {
			$this->remove_backup( $backup );
			throw new \Exception( "could not back up main.conf ({$e->getMessage()})" );
		}

		try {
			\EE\Site\Utils\put_container_file_contents( $file, $dir, $new_content, $content, self::MAX_SIZE );
		} catch ( \Exception $e ) {
			// A failed write may have left main.conf partly written; any other failure left it as it was.
			if ( 0 === strpos( $e->getMessage(), 'could not write' ) ) {
				throw new \Exception( $e->getMessage() . ", so it may be partly written: first copy back the previous file $backup" );
			}
			$this->remove_backup( $backup );
			throw $e;
		}

		$change = [
			'site'    => $site,
			'file'    => $file,
			'dir'     => $dir,
			'backup'  => $backup,
			'content' => $new_content,
		];

		$id   = self::get_nginx_container( $site );
		$test = self::test_nginx( $id );
		if ( null !== $test ) {
			try {
				$this->restore( $change );
			} catch ( \Exception $e ) {
				throw new \Exception( "nginx -t failed in its nginx container ($test), and putting main.conf back failed too ({$e->getMessage()}); the previous file is $backup" );
			}
			EE::warning( "Left the page cache config of $site->site_url as it is: nginx -t failed in its nginx container ($test). Fix that first. " . self::manual_fix( $site ) );

			return;
		}

		$this->changed[] = $change;
		EE::debug( "$site->site_url: main.conf no longer stores responses that set a cookie; the previous file is $backup." );
		// Until its nginx loads the change it can store such responses again, so clearing the cache would not last.
		if ( null === $id || ! self::reload_nginx( $site, $id ) ) {
			$this->pending++;

			return;
		}
		self::clear_page_cache( $site );
	}

	/**
	 * Return main.conf content with EasyEngine's srcache_store_skip line replaced, or null when it isn't there exactly once.
	 *
	 * @param string $content main.conf content.
	 *
	 * @return string|null
	 */
	public static function replace_line( string $content ) {

		$pattern = '/^([ \t]*)' . preg_quote( self::OLD_LINE, '/' ) . '([ \t]*)(\r?)$/m';
		// Comment lines don't count; any other mention, even after a directive on the same line, does.
		if ( 1 !== preg_match_all( '/^(?![ \t]*#)[^\r\n]*srcache_store_skip/m', $content ) || 1 !== preg_match( $pattern, $content, $matches ) ) {
			return null;
		}

		list( , $indent, $trailing, $cr ) = $matches;

		return preg_replace_callback(
			$pattern,
			function () use ( $indent, $trailing, $cr ) {
				return $indent . self::COMMENT . $cr . "\n" . $indent . self::NEW_LINE . $trailing . $cr;
			},
			$content
		);
	}

	/**
	 * Put back the file of one change, only while it still holds what up() wrote.
	 *
	 * @param array $change Entry of $changed.
	 *
	 * @throws \Exception
	 */
	private function restore( array $change ) {

		$old = @file_get_contents( $change['backup'] );
		if ( false === $old ) {
			throw new \Exception( 'could not read the backup' );
		}
		// The file holds the change, which can be larger than MAX_SIZE.
		\EE\Site\Utils\put_container_file_contents( $change['file'], $change['dir'], $old, $change['content'], strlen( $change['content'] ) );
		$this->remove_backup( $change['backup'] );
	}

	/**
	 * @param string $backup Saved main.conf; its site dir and the backup root go too once empty.
	 */
	private function remove_backup( string $backup ) {

		try {
			$this->fs->remove( dirname( $backup ) );
		} catch ( \Throwable $e ) {
			EE::debug( "Could not remove $backup: {$e->getMessage()}" );

			return;
		}
		// rmdir() refuses it while it holds other sites' backups.
		@rmdir( dirname( $backup, 2 ) );
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string
	 */
	private static function manual_fix( $site ) {

		return "To stop the page cache from storing responses that set a cookie, add \$upstream_http_set_cookie to the end of the srcache_store_skip value in $site->site_fs_path/config/nginx/conf.d/main.conf, keeping what is there (EasyEngine's line becomes '" . self::NEW_LINE . "'), and run: ee site reload $site->site_url --nginx";
	}

	/**
	 * Delete the site's page-cache entries: those stored before the change may hold a response that set a cookie, e.g. another visitor's session id.
	 *
	 * @param Site $site Site.
	 */
	private static function clear_page_cache( $site ) {

		$prefix = sprintf( self::CACHE_KEY, $site->site_url );
		if ( 'redis' !== $site->cache_host ) {
			$cleared = \EE\Site\Utils\clean_site_cache( $prefix );
		} else {
			// A local redis goes with the site's containers, so a disabled site starts with an empty one.
			$id = self::get_container( $site, 'redis' );
			if ( '' === $id ) {
				EE::debug( "$site->site_url: its redis isn't running, so there is no page cache to clear." );

				return;
			}
			$cleared = null !== $id && 0 === EE::launch( 'docker exec ' . escapeshellarg( $id ) . ' redis-cli --eval purge_all_cache.lua 0 , ' . escapeshellarg( $prefix . '*' ) )->return_code;
		}

		if ( $cleared ) {
			EE::debug( "$site->site_url: cleared its page cache." );
		} else {
			EE::warning( "Could not clear the page cache of $site->site_url. The pages it stored before this upgrade expire within 4 hours." );
		}
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string|null ID of the site's running nginx container, '' when none runs (or the site is disabled), null when the lookup failed (already warned).
	 */
	private static function get_nginx_container( $site ) {

		$id = self::get_container( $site, 'nginx' );
		if ( null === $id ) {
			EE::warning( "Could not check the nginx container of $site->site_url, so it may still use its previous main.conf. Run: ee site reload $site->site_url --nginx" );
		}

		return $id;
	}

	/**
	 * @param Site   $site    Site.
	 * @param string $service Compose service.
	 *
	 * @return string|null ID of its running container, '' when none runs (or the site is disabled), null when the lookup failed.
	 */
	private static function get_container( $site, string $service ) {

		if ( ! $site->site_enabled ) {
			return '';
		}

		$cwd    = getcwd();
		$result = @chdir( $site->site_fs_path ) ? EE::launch( \EE_DOCKER::docker_compose_with_custom() . " ps -q $service" ) : null;
		if ( false !== $cwd ) {
			chdir( $cwd );
		}

		return null === $result || 0 !== $result->return_code ? null : trim( $result->stdout );
	}

	/**
	 * Run nginx -t in the site's running nginx container; a stopped one reads the file when it starts.
	 *
	 * @param string|null $id From get_nginx_container().
	 *
	 * @return string|null The error when nginx -t failed, else null.
	 */
	private static function test_nginx( $id ) {

		if ( null === $id || '' === $id ) {
			return null;
		}

		$result = EE::launch( 'docker exec ' . escapeshellarg( $id ) . ' nginx -t' );
		if ( 0 === $result->return_code ) {
			return null;
		}
		$lines = preg_split( '/\R/', trim( $result->stderr . "\n" . $result->stdout ) );

		return '' !== $lines[0] ? $lines[0] : 'exit code ' . $result->return_code;
	}

	/**
	 * Graceful reload, as `ee site reload --nginx` does after nginx -t.
	 *
	 * @param Site        $site Site.
	 * @param string|null $id   From get_nginx_container().
	 *
	 * @return bool Whether nginx uses the file now: reloaded, or not running.
	 */
	private static function reload_nginx( $site, $id ) {

		if ( null === $id ) {
			return false;
		}
		if ( '' === $id ) {
			return true;
		}

		$result = EE::launch( 'docker exec ' . escapeshellarg( $id ) . ' nginx -s reload' );
		if ( 0 === $result->return_code ) {
			EE::debug( "$site->site_url: reloaded nginx." );

			return true;
		}
		EE::warning( "Could not reload nginx of $site->site_url. Run: ee site reload $site->site_url --nginx" );

		return false;
	}
}
