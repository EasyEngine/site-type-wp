<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;

/**
 * Repair WordPress 7.x core files truncated by `wp core download` with WP-CLI <= 2.12 (wp-cli/wp-cli#6320).
 *
 * Only en_US packages are affected, and only wp-includes/php-ai-client/ is repaired: the truncated default-theme files under wp-content aren't covered by verify-checksums. Never fails the upgrade: sites that can't be checked or repaired get a warning.
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
				return 'wp' === $site->site_type;
			}
		);
		if ( empty( $this->sites ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Check every WordPress site and repair the affected ones.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping repair-truncated-core-files migration as it is not needed.' );

			return;
		}

		// Throwing would block the upgrade, and the migration is recorded either way.
		if ( ! function_exists( '\EE\Site\Utils\get_wp_core_shell_functions' ) ) {
			EE::warning( "Skipped checking WordPress core files for truncated names: site-command is too old. Check each WordPress site with: ee shell <site> --command='wp core verify-checksums'" );

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
	 * Check one site and repair it if needed.
	 *
	 * @param Site $site Site to check.
	 */
	private function repair_site( $site ) {

		$wp_root = $site->site_fs_path . '/app/' . str_replace( '/var/www/', '', $site->site_container_fs_path );
		// Only WordPress >= 7.0 ships this directory.
		if ( ! is_dir( $wp_root . '/wp-includes/php-ai-client' ) ) {
			EE::debug( "$site->site_url: no wp-includes/php-ai-client, skipping core repair." );

			return;
		}

		if ( ! $site->site_enabled ) {
			EE::warning( "Skipped checking WordPress core files of $site->site_url: the site is disabled. After enabling it, check with: ee shell $site->site_url --command='wp core verify-checksums'" );

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
		$details = str_replace( "\n", ' ', trim( preg_replace( '/^Status: .*$/m', '', $result->stdout ) ) );

		if ( 0 === $result->return_code && 'repaired' === $status ) {
			EE::log( rtrim( "Repaired truncated WordPress core files of $site->site_url. $details" ) );
		} elseif ( 0 === $result->return_code && 'not-affected' === $status ) {
			EE::debug( "$site->site_url: core files not affected." );
		} elseif ( 0 === $result->return_code && 'unverified' === $status ) {
			EE::warning( "Could not check WordPress core files of $site->site_url (" . trim( $matches[2] ) . '). ' . ( '' === $details ? '' : "$details " ) . "Check later with: ee shell $site->site_url --command='wp core verify-checksums'" );
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
# Truncated names are exactly 90 characters (100 with "wordpress/") and never end in .php; other extra files aren't ours.
leftovers() { printf '%s\n' "$1" | sed -n "s#^.*File should not exist: \($prefix.*\)\$#\1#p" | awk 'length($0) == 90 && $0 !~ /\.php$/'; }
extra=$(leftovers "$out")
if [ "$missing" -eq 0 ] && [ -z "$extra" ]; then
	# Other modified core files aren't ours to fix.
	case "$rc:$out" in
		0:* | *"File doesn't exist:"* | *"File doesn't verify against checksum:"*) echo 'Status: not-affected' ;;
		*) echo "Status: unverified $(printf '%s\n' "$out" | grep -m 1 '^Error:' || echo "exit code $rc")" ;;
	esac
	exit 0
fi
if [ "$missing" -gt 0 ]; then
	version=$(wp_cli core version --path="$root")
	locale=$(sed -n "s/^\$wp_local_package = '\(.*\)';.*/\1/p" "$root/wp-includes/version.php")
	locale=${locale:-en_US}
	tmp=$(mktemp -d)
	trap 'rm -rf "$tmp"' EXIT
	mkdir "$tmp/dl"
	pkg=$(ee_wp_fetch "$tmp/dl" --version="$version" --locale="$locale")
	# Extract aside, so a bad package can't damage the site. A cached package isn't md5-checked again, so fetch a fresh copy once.
	if ! ee_wp_extract "$pkg" "$tmp/wp" "${prefix%/}"; then
		rm -rf "$tmp/dl" "$tmp/wp"
		mkdir "$tmp/dl"
		pkg=$(WP_CLI_CACHE_DIR="$tmp/cache" ee_wp_fetch "$tmp/dl" --version="$version" --locale="$locale")
		ee_wp_extract "$pkg" "$tmp/wp" "${prefix%/}"
	fi
	cp -R "$tmp/wp/$prefix." "$root/$prefix"
	echo "Restored $prefix from WordPress $version ($locale)."
fi
removed=0
while IFS= read -r file; do
	if [ -n "$file" ] && [ -f "$root/$file" ]; then
		rm -f -- "$root/$file"
		removed=$((removed + 1))
	fi
done <<< "$extra"
[ "$removed" -eq 0 ] || echo "Removed $removed truncated leftover files."
rc=0
out=$(wp_cli core verify-checksums --path="$root" 2>&1) || rc=$?
if printf '%s\n' "$out" | grep -e "File doesn't exist: $prefix" -e "File doesn't verify against checksum: $prefix" >&2 || leftovers "$out" | grep . >&2; then
	echo 'Status: failed'
	exit 1
fi
case "$rc:$out" in
	0:* | *"File doesn't exist:"* | *"File doesn't verify against checksum:"*) echo 'Status: repaired' ;;
	*) echo "Status: unverified $(printf '%s\n' "$out" | grep -m 1 '^Error:' || echo "exit code $rc")" ;;
esac

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
