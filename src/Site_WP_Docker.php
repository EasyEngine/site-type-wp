<?php

use function \EE\Utils\mustache_render;

class Site_WP_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array of flags to determine the docker-compose.yml generation.
	 *                       Empty/Default -> Generates default WordPress docker-compose.yml
	 *                       ['le']        -> Enables letsencrypt in the generation.
	 *
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [] ) {
		$base = [];

		$restart_default = [ 'name' => 'always' ];
		$network_default = [ 'name' => 'site-network' ];

		// db configuration.
		$db['service_name'] = [ 'name' => 'db' ];
		$db['image']        = [ 'name' => 'easyengine/mariadb:v' . EE_VERSION ];
		$db['restart']      = $restart_default;
		$db['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$db['volumes']      = [
			[
				'vol' => [
					'name' => './app/db:/var/lib/mysql',
				],
			],
		];
		$db['environment']  = [
			'env' => [
				[ 'name' => 'MYSQL_ROOT_PASSWORD' ],
				[ 'name' => 'MYSQL_DATABASE' ],
				[ 'name' => 'MYSQL_USER' ],
				[ 'name' => 'MYSQL_PASSWORD' ],
			],
		];
		$db['networks']     = $network_default;
		// PHP configuration.
		$php['service_name'] = [ 'name' => 'php' ];
		$php['image']        = [ 'name' => 'easyengine/php:v' . EE_VERSION ];
		$php['depends_on']   = [ 'name' => 'db' ];
		$php['restart']      = $restart_default;
		$php['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$php['volumes']      = [
			[
				'vol' => [
					[ 'name' => './app/src:/var/www/htdocs' ],
					[ 'name' => './config/php-fpm/php.ini:/usr/local/etc/php/php.ini' ],
				],
			],
		];
		$php['environment']  = [
			'env' => [
				[ 'name' => 'WORDPRESS_DB_HOST' ],
				[ 'name' => 'WORDPRESS_DB_NAME' ],
				[ 'name' => 'WORDPRESS_DB_USER' ],
				[ 'name' => 'WORDPRESS_DB_PASSWORD' ],
				[ 'name' => 'USER_ID' ],
				[ 'name' => 'GROUP_ID' ],
				[ 'name' => 'VIRTUAL_HOST' ],
			],
		];
		$php['networks']     = $network_default;

		// nginx configuration.
		$nginx['service_name'] = [ 'name' => 'nginx' ];
		$nginx['image']        = [ 'name' => 'easyengine/nginx:v' . EE_VERSION ];
		$nginx['depends_on']   = [ 'name' => 'php' ];
		$nginx['restart']      = $restart_default;

		$v_host = in_array( 'subdom', $filters, true ) ? 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}' : 'VIRTUAL_HOST';

		$nginx['environment'] = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/' ],
				[ 'name' => 'HSTS=off' ],
			],
		];
		$nginx['volumes']     = [
			'vol' => [
				[ 'name' => './app/src:/var/www/htdocs' ],
				[ 'name' => './config/nginx/default.conf:/etc/nginx/conf.d/default.conf' ],
				[ 'name' => './logs/nginx:/var/log/nginx' ],
				[ 'name' => './config/nginx/common:/usr/local/openresty/nginx/conf/common' ],
			],
		];
		$nginx['labels']      = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$nginx['networks']    = $network_default;

		// PhpMyAdmin configuration.
		$phpmyadmin['service_name'] = [ 'name' => 'phpmyadmin' ];
		$phpmyadmin['image']        = [ 'name' => 'easyengine/phpmyadmin:v' . EE_VERSION ];
		$phpmyadmin['restart']      = $restart_default;
		$phpmyadmin['environment']  = [
			'env' => [
				[ 'name' => 'PMA_ABSOLUTE_URI=http://${VIRTUAL_HOST}/ee-admin/pma/' ],
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/ee-admin/pma/' ],
			],
		];
		$phpmyadmin['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$phpmyadmin['networks']     = $network_default;

		// mailhog configuration.
		$mailhog['service_name'] = [ 'name' => 'mailhog' ];
		$mailhog['image']        = [ 'name' => 'easyengine/mailhog:v' . EE_VERSION ];
		$mailhog['restart']      = $restart_default;
		$mailhog['command']      = [ 'name' => '["-invite-jim=false"]' ];
		$mailhog['environment']  = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/ee-admin/mailhog/' ],
				[ 'name' => 'VIRTUAL_PORT=8025' ],
			],
		];
		$mailhog['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$mailhog['networks']     = $network_default;

		// redis configuration.
		$redis['service_name'] = [ 'name' => 'redis' ];
		$redis['image']        = [ 'name' => 'easyengine/redis:v' . EE_VERSION ];
		$redis['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$redis['networks']     = $network_default;

		if ( in_array( 'db', $filters, true ) ) {
			$base[] = $db;
		}

		$base[] = $php;
		$base[] = $nginx;
		//$base[] = $mailhog;
		$base[] = $phpmyadmin;

		if ( in_array( 'redis', $filters, true ) ) {
			$base[] = $redis;
		}

		$binding = [
			'services' => $base,
			'network'  => true,
		];

		$docker_compose_yml = mustache_render( SITE_WP_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
