<?php

namespace EE\Site\Type;

use function EE\Utils\mustache_render;

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
		$img_versions = \EE\Utils\get_image_versions();
		$base         = [];

		$restart_default = [ 'name' => 'always' ];
		$network_default = [
			'net' => [
				[ 'name' => 'site-network' ]
			]
		];

		// db configuration.
		$db['service_name'] = [ 'name' => 'db' ];
		$db['image']        = [ 'name' => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'] ];
		$db['restart']      = $restart_default;
		$db['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$db['volumes']      = [
			[
				'vol' => [
					'name' => './services/db:/var/lib/mysql',
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
		$php['image']        = [ 'name' => 'easyengine/php:' . $img_versions['easyengine/php'] ];

		if ( in_array( 'db', $filters, true ) ) {
			$php['depends_on']['dependency'][] = [ 'name' => 'db' ];
		}

		if ( in_array( 'redis', $filters, true ) ) {
			$php['depends_on']['dependency'][] = [ 'name' => 'redis' ];
		}

		$php['restart']     = $restart_default;
		$php['labels']      = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$php['volumes']     = [
			[
				'vol' => [
					[ 'name' => './app:/var/www' ],
					[ 'name' => './config/php-fpm/php.ini:/usr/local/etc/php/php.ini' ],
				],
			],
		];
		$php['environment'] = [
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

		$php['networks'] = [
			'net' => [
				[
					'name'    => 'site-network',
					'aliases' => [
						'alias' => [
							'name' => '${VIRTUAL_HOST}_php',
						],
					],
				],
			],
		];

		if ( in_array( GLOBAL_DB, $filters, true ) ) {
			$php['networks']['net'][] = [ 'name' => 'global-backend-network' ];
		}

		// nginx configuration.
		$nginx['service_name']               = [ 'name' => 'nginx' ];
		$nginx['image']                      = [ 'name' => 'easyengine/nginx:' . $img_versions['easyengine/nginx'] ];
		$nginx['depends_on']['dependency'][] = [ 'name' => 'php' ];
		$nginx['restart']                    = $restart_default;

		$v_host = in_array( 'subdom', $filters, true ) ? 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}' : 'VIRTUAL_HOST';

		$nginx['environment'] = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/' ],
				[ 'name' => 'HSTS=off' ],
			],
		];
		if ( ! empty( $filters['nohttps'] ) ) {
			$nginx['environment']['env'][] = [ 'name' => 'HTTPS_METHOD=nohttps' ];
		}
		$nginx['volumes']  = [
			'vol' => [
				[ 'name' => './app:/var/www' ],
				[ 'name' => './config/nginx/main.conf:/etc/nginx/conf.d/default.conf' ],
				[ 'name' => './config/nginx/custom:/etc/nginx/custom' ],
				[ 'name' => './logs/nginx:/var/log/nginx' ],
			],
		];
		$nginx['labels']   = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$nginx['networks'] = [
			'net' => [
				[
					'name'    => 'site-network',
					'aliases' => [
						'alias' => [
							'name' => '${VIRTUAL_HOST}',
						],
					],
				],
				[ 'name' => 'global-frontend-network' ],
			]
		];
		if ( in_array( GLOBAL_REDIS, $filters, true ) ) {
			$nginx['networks']['net'][] = [ 'name' => 'global-backend-network' ];
		}

		// mailhog configuration.
		$mailhog['service_name'] = [ 'name' => 'mailhog' ];
		$mailhog['image']        = [ 'name' => 'easyengine/mailhog:' . $img_versions['easyengine/mailhog'] ];
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
		$mailhog['networks']     = [
			'net' => [
				[ 'name' => 'site-network' ],
				[ 'name' => 'global-frontend-network' ],
			]
		];

		// postfix configuration.
		$postfix['service_name'] = [ 'name' => 'postfix' ];
		$postfix['image']        = [ 'name' => 'easyengine/postfix:' . $img_versions['easyengine/postfix'] ];
		$postfix['hostname']     = [ 'name' => '${VIRTUAL_HOST}' ];
		$postfix['restart']      = $restart_default;
		$postfix['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$postfix['volumes']      = [
			'vol' => [
				[ 'name' => '/dev/log:/dev/log' ],
				[ 'name' => './config/postfix/ssl:/etc/ssl/postfix' ],
				[ 'name' => './services/postfix/spool:/var/spool/postfix' ],
			],
		];
		$postfix['networks']     = $network_default;

		// redis configuration.
		$redis['service_name'] = [ 'name' => 'redis' ];
		$redis['image']        = [ 'name' => 'easyengine/redis:' . $img_versions['easyengine/redis'] ];
		$redis['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$redis['networks']     = $network_default;

		$base[] = $php;
		$base[] = $nginx;
		$base[] = $mailhog;
		$base[] = $postfix;

		if ( in_array( 'redis', $filters, true ) ) {
			$base[] = $redis;
		}

		$network = [
			'networks_labels' => [
				'label' => [
					[ 'name' => 'org.label-schema.vendor=EasyEngine' ],
					[ 'name' => 'io.easyengine.site=${VIRTUAL_HOST}' ],
				],
			],
		];

		if ( in_array( 'db', $filters, true ) ) {
			$base[] = $db;
		} else {
			$network['enable_backend_network'] = true;
		}

		$binding = [
			'services' => $base,
			'network'  => $network,
		];

		$docker_compose_yml = mustache_render( SITE_WP_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
