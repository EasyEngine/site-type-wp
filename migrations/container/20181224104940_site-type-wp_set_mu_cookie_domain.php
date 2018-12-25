<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;

class SetMuCookieDomain extends Base {

	private $sites;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute php config updates.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping update-cookie-domain migration as it is not needed.' );

			return;
		}

		$wp_config_set = 'docker-compose exec php bash -c "wp config set COOKIE_DOMAIN false --type=constant --raw"';
		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! ( 'wp' === $site->site_type && 'wp' !== $site->app_sub_type ) ) {
				continue;
			}
			chdir( $site->site_fs_path );
			EE::exec( $wp_config_set );
		}

	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}

