<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;

/**
 * Repair WordPress 7.x core files truncated by `wp core download` with WP-CLI <= 2.12 (wp-cli/wp-cli#6320).
 *
 * Only wp-includes/php-ai-client/ is affected. Never fails the upgrade: sites that can't be checked or repaired get a warning.
 */
class RepairTruncatedCoreFiles extends Base {

	private $sites = [];

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		$this->sites = array_filter(
			Site::all() ?: [],
			function ( $site ) {
				return 'wp' === $site->site_type && $site->site_enabled;
			}
		);
		if ( empty( $this->sites ) || ! function_exists( '\EE\Site\Utils\get_wp_core_shell_functions' ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Check every enabled WordPress site and repair the affected ones.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping repair-truncated-core-files migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			try {
				$this->repair_site( $site );
			} catch ( \Throwable $e ) {
				EE::warning( "Could not check WordPress core files of $site->site_url: " . $e->getMessage() );
			}
		}
	}

	/**
	 * @param Site $site Site to check.
	 */
	private function repair_site( $site ) {

		$wp_root = $site->site_fs_path . '/app/' . str_replace( '/var/www/', '', $site->site_container_fs_path );
		// Only WordPress >= 7.0 ships this directory.
		if ( ! is_dir( $wp_root . '/wp-includes/php-ai-client' ) ) {
			EE::debug( "$site->site_url: no wp-includes/php-ai-client, skipping core repair." );

			return;
		}

		chdir( $site->site_fs_path );
		if ( ! \EE_DOCKER::docker_compose_exec( 'exit', 'php', 'sh', 'www-data', '', true ) ) {
			EE::warning( "Skipped checking WordPress core files of $site->site_url: its php container isn't running. Check later with: ee shell $site->site_url --command='wp core verify-checksums'" );

			return;
		}

		$command = self::get_repair_command( $site->site_container_fs_path );
		$result  = EE::launch( \EE_DOCKER::docker_compose_with_custom() . " exec -T --user='www-data' php bash -c \"$command\"" );
		$status  = preg_match( '/^Status: (\S+)(.*)$/m', $result->stdout, $matches ) ? $matches[1] : '';

		if ( 0 === $result->return_code && 'repaired' === $status ) {
			$details = trim( preg_replace( '/^Status: .*$/m', '', $result->stdout ) );
			EE::log( "Repaired truncated WordPress core files of $site->site_url. " . str_replace( "\n", ' ', $details ) );
		} elseif ( 0 === $result->return_code && 'not-affected' === $status ) {
			EE::debug( "$site->site_url: core files not affected." );
		} elseif ( 0 === $result->return_code && 'unverified' === $status ) {
			EE::warning( "Could not check WordPress core files of $site->site_url (" . trim( $matches[2] ) . "). Check later with: ee shell $site->site_url --command='wp core verify-checksums'" );
		} else {
			$lines  = array_filter( array_map( 'trim', explode( "\n", $result->stdout . "\n" . $result->stderr ) ) );
			$reason = empty( $lines ) ? "exit code $result->return_code" : end( $lines );
			EE::warning( "Could not repair truncated WordPress core files of $site->site_url ($reason). Check with: ee shell $site->site_url --command='wp core verify-checksums'" );
		}
	}

	/**
	 * Build the in-container repair command. It needs no database and never loads WordPress.
	 *
	 * @param string $path WordPress root inside the php container.
	 *
	 * @return string Command escaped for `bash -c "..."`.
	 */
	public static function get_repair_command( string $path ) {

		$script = \EE\Site\Utils\get_wp_core_shell_functions() . 'root=' . escapeshellarg( $path ) . "\n" . <<<'BASH'
prefix=wp-includes/php-ai-client/
rc=0
out=$(wp_cli core verify-checksums --path="$root" 2>&1) || rc=$?
missing=$(printf '%s\n' "$out" | grep -c "File doesn't exist: $prefix" || true)
extra=$(printf '%s\n' "$out" | sed -n "s#^.*File should not exist: \($prefix.*\)\$#\1#p")
if [ "$missing" -eq 0 ] && [ -z "$extra" ]; then
	# Other modified core files aren't ours to fix.
	case "$rc:$out" in
		0:* | *"File doesn't exist:"* | *"File doesn't verify against checksum:"*) echo 'Status: not-affected' ;;
		*) echo "Status: unverified $(printf '%s\n' "$out" | tail -n 1)" ;;
	esac
	exit 0
fi
if [ "$missing" -gt 0 ]; then
	version=$(wp_cli core version --path="$root")
	locale=$(sed -n "s/^\$wp_local_package = '\(.*\)';.*/\1/p" "$root/wp-includes/version.php")
	locale=${locale:-en_US}
	tmp=$(mktemp -d)
	trap 'rm -rf "$tmp"' EXIT
	pkg=$(ee_wp_fetch "$tmp" --version="$version" --locale="$locale")
	ee_wp_extract "$pkg" "$root" "${prefix%/}"
	echo "Restored $prefix from WordPress $version ($locale)."
fi
removed=0
while IFS= read -r file; do
	# Truncated names are exactly 90 characters (100 with "wordpress/") and never end in .php.
	if [ "${#file}" -eq 90 ] && [ "${file%.php}" = "$file" ] && [ -f "$root/$file" ]; then
		rm -f -- "$root/$file"
		removed=$((removed + 1))
	fi
done <<< "$extra"
[ "$removed" -eq 0 ] || echo "Removed $removed truncated leftover files."
out=$(wp_cli core verify-checksums --path="$root" 2>&1) || true
if printf '%s\n' "$out" | grep -e "File doesn't exist: $prefix" -e "File should not exist: $prefix" >&2; then
	echo 'Status: failed'
	exit 1
fi
echo 'Status: repaired'

BASH;

		// docker_compose_exec() style wrapper: bash -c "...".
		return addcslashes( $script, '"$`\\' );
	}

	/**
	 * Restored core files must stay: nothing to revert.
	 */
	public function down() {
	}
}
