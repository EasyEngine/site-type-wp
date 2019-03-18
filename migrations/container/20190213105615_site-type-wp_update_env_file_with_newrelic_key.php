<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;
use function EE\Utils\http_request;

class UpdateEnvFileWithNewrelicKey extends Base {

	private $sites;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites || IS_DARWIN ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute env update.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping update-env-file-with-newrelic-key migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {

			if ( 'html' === $site->site_type ) {
				continue;
			}
			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			$env_file     = $site->site_fs_path . '/.env';
			$newrelic_ini = $site->site_fs_path . '/config/php/php/conf.d/newrelic.ini';
			$this->fs->appendToFile( $env_file, 'NEWRELIC_LICENSE_KEY=' );

			chdir( $site->site_fs_path );

			// Setup newrelic extension.
			EE::exec( 'docker-compose exec --user=\'root\' php bash -c "curl -L https://download.newrelic.com/php_agent/release/newrelic-php5-8.5.0.235-linux.tar.gz | tar -C /tmp -zx && \
			export NR_INSTALL_USE_CP_NOT_LN=1 && \
			export NR_INSTALL_SILENT=1 && \
			/tmp/newrelic-php5-*/newrelic-install install && \
			rm -rf /tmp/newrelic-php5-* /tmp/nrinstall*"' );

			// Setup newrelic ini.
			$download_url = 'https://raw.githubusercontent.com/EasyEngine/dockerfiles/8acc9b58524702ed086b44c278f6138d4c1f28f7/php/newrelic.ini';
			$headers      = [];
			$options      = [
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
				'filename' => $newrelic_ini,
			];
			http_request( 'GET', $download_url, null, $headers, $options );

			// Fix permissions.
			EE::exec( 'docker-compose exec --user=\'root\' php bash -c "mkdir -p /var/log/php /var/log/newrelic /run/newrelic; \
			chown -R www-data:www-data /var/log/php; \
			chown -R www-data:www-data /var/log/newrelic; \
			chown -R www-data:www-data /usr/local/etc/php/conf.d;"' );
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

