<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;

/**
 * Adds `wp` site type to `ee site` command.
 *
 * @package ee-cli
 */
class WordPress extends EE_Site_Command {

	/**
	 * @var string $cache_type Type of caching being used.
	 */
	private $cache_type;

	/**
	 * @var object $docker Object to access `\EE::docker()` functions.
	 */
	private $docker;

	/**
	 * @var int $level The level of creation in progress. Essential for rollback in case of failure.
	 */
	private $level;

	/**
	 * @var object $logger Object of logger.
	 */
	private $logger;

	/**
	 * @var string $locale Language to install WordPress in.
	 */
	private $locale;

	/**
	 * @var bool $skip_install To skip installation of WordPress.
	 */
	private $skip_install;

	/**
	 * @var bool $skip_status_check To skip site status check pre-installation.
	 */
	private $skip_status_check;

	/**
	 * @var bool $force To reset remote database.
	 */
	private $force;

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->docker = \EE::docker();
		$this->logger = \EE::get_file_logger()->withName( 'site_wp_command' );
		$this->fs     = new Filesystem();

		$this->site_data['site_type'] = 'wp';
	}

	/**
	 * Runs the standard WordPress Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--cache]
	 * : Use redis cache for WordPress.
	 *
	 * [--mu=<subdir>]
	 * : WordPress sub-dir Multi-site.
	 *
	 * [--mu=<subdom>]
	 * : WordPress sub-domain Multi-site.
	 *
	 * [--title=<title>]
	 * : Title of your site.
	 *
	 * [--admin-user=<admin-user>]
	 * : Username of the administrator.
	 *
	 * [--admin-pass=<admin-pass>]
	 * : Password for the the administrator.
	 *
	 * [--admin-email=<admin-email>]
	 * : E-Mail of the administrator.
	 *
	 * [--local-db]
	 * : Create separate db container instead of using global db.
	 *
	 * [--with-local-redis]
	 * : Enable cache with local redis container.
	 *
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8mb4
	 * ---
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--version=<version>]
	 * : Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.
	 *
	 * [--skip-content]
	 * : Download WP without the default themes and plugins.
	 *
	 * [--skip-install]
	 * : Skips wp-core install.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * [--ssl=<value>]
	 * : Enables ssl on site.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL .
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create WordPress site
	 *     $ ee site create example.com --type=wp
	 *
	 *     # Create WordPress multisite subdir site
	 *     $ ee site create example.com --type=wp --mu=subdir
	 *
	 *     # Create WordPress multisite subdom site
	 *     $ ee site create example.com --type=wp --mu=subdom
	 *
	 *     # Create WordPress site with ssl from letsencrypt
	 *     $ ee site create example.com --type=wp --ssl=le
	 *
	 *     # Create WordPress site with wildcard ssl
	 *     $ ee site create example.com --type=wp --ssl=le --wildcard
	 *
	 *     # Create WordPress site with remote database
	 *     $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password
	 *
	 *     # Create WordPress site with custom site title, locale, admin user, admin email and admin password
	 *     $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine
	 *
	 */
	public function create( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site create start' );
		\EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_data['site_url'] = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );

		$mu = \EE\Utils\get_flag_value( $assoc_args, 'mu' );

		if ( isset( $assoc_args['mu'] ) && ! in_array( $mu, [ 'subdom', 'subdir' ], true ) ) {
			\EE::error( "Unrecognized multi-site parameter: $mu. Only `--mu=subdom` and `--mu=subdir` are supported." );
		}
		$this->site_data['app_sub_type'] = $mu ?? 'wp';

		if ( Site::find( $this->site_data['site_url'] ) ) {
			\EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$this->cache_type                      = \EE\Utils\get_flag_value( $assoc_args, 'cache' );
		$this->site_data['site_ssl']           = \EE\Utils\get_flag_value( $assoc_args, 'ssl' );
		$this->site_data['site_ssl_wildcard']  = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->site_data['app_admin_url']      = \EE\Utils\get_flag_value( $assoc_args, 'title', $this->site_data['site_url'] );
		$this->site_data['app_admin_username'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-user', 'admin' );
		$this->site_data['app_admin_password'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-pass', \EE\Utils\random_password() );
		$this->site_data['db_name']            = \EE\Utils\get_flag_value( $assoc_args, 'dbname', str_replace( [ '.', '-' ], '_', $this->site_data['site_url'] ) );
		$this->site_data['db_host']            = \EE\Utils\get_flag_value( $assoc_args, 'dbhost', GLOBAL_DB );
		$this->site_data['db_port']            = '3306';
		$this->site_data['db_user']            = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', $this->create_site_db_user( $this->site_data['site_url'] ) );
		$this->site_data['db_password']        = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );
		$this->locale                          = \EE\Utils\get_flag_value( $assoc_args, 'locale', \EE::get_config( 'locale' ) );
		$local_cache                           = \EE\Utils\get_flag_value( $assoc_args, 'with-local-redis' );
		$this->site_data['cache_host']         = '';
		if ( $this->cache_type ) {
			$this->site_data['cache_host'] = $local_cache ? 'redis' : 'global-redis';
		}

		if ( \EE\Utils\get_flag_value( $assoc_args, 'local-db' ) ) {
			$this->site_data['db_host'] = 'db';
		}
		$this->site_data['db_root_password'] = ( 'db' === $this->site_data['db_host'] ) ? \EE\Utils\random_password() : '';

		\EE\Service\Utils\nginx_proxy_check();

		if ( $this->cache_type && ! $local_cache ) {
			\EE\Service\Utils\init_global_container( GLOBAL_REDIS );
		}

		if ( GLOBAL_DB === $this->site_data['db_host'] ) {
			\EE\Service\Utils\init_global_container( GLOBAL_DB );
			try {
				$user_data = \EE\Site\Utils\create_user_in_db( GLOBAL_DB, $this->site_data['db_name'], $this->site_data['db_user'], $this->site_data['db_password'] );
				if ( ! $user_data ) {
					throw new \Exception( sprintf( 'Could not create user %s. Please check logs.', $this->site_data['db_user'] ) );
				}
			} catch ( \Exception $e ) {
				$this->catch_clean( $e );
			}
			$this->site_data['db_name']     = $user_data['db_name'];
			$this->site_data['db_user']     = $user_data['db_user'];
			$this->site_data['db_password'] = $user_data['db_pass'];
		} elseif ( 'db' !== $this->site_data['db_host'] ) {
			// If user wants to connect to remote database.
			if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
				\EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
			}
			$arg_host_port              = explode( ':', $this->site_data['db_host'] );
			$this->site_data['db_host'] = $arg_host_port[0];
			$this->site_data['db_port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
		}

		$this->site_data['app_admin_email'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-email', strtolower( 'admin@' . $this->site_data['site_url'] ) );
		$this->skip_install                 = \EE\Utils\get_flag_value( $assoc_args, 'skip-install' );
		$this->skip_status_check            = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force                        = \EE\Utils\get_flag_value( $assoc_args, 'force' );

		if ( 'inherit' === $this->site_data['site_ssl'] && ( 'subdom' === $mu || $this->site_data['site_ssl_wildcard'] ) ) {
			\EE::error( '--wildcard or --mu=subdom flag can not be passed together with --ssl=inherit flag.' );
		}

		\EE::log( 'Configuring project.' );

		$this->create_site( $assoc_args );
		\EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Enable object cache.
	 */
	private function enable_object_cache() {
		$redis_plugin_constant    = 'docker-compose exec --user=\'www-data\' php wp config set --type=variable redis_server "array(\'host\'=> \'ee-global-redis\',\'port\'=> 6379,)" --raw';
		$activate_wp_redis_plugin = "docker-compose exec --user='www-data' php wp plugin install wp-redis --activate";
		$enable_redis_cache       = "docker-compose exec --user='www-data' php wp redis enable";

		$this->docker_compose_exec( $redis_plugin_constant, 'Unable to download or activate wp-redis plugin.' );
		$this->docker_compose_exec( $activate_wp_redis_plugin, 'Unable to download or activate wp-redis plugin.' );
		$this->docker_compose_exec( $enable_redis_cache, 'Unable to enable object cache' );
	}

	/**
	 * Enable page cache.
	 */
	private function enable_page_cache() {
		$activate_nginx_helper = 'docker-compose exec --user=\'www-data\' php wp plugin install nginx-helper --activate';
		$nginx_helper_fail_msg = 'Unable to download or activate nginx-helper plugin properly.';
		$salt_value            = $this->site_data['site_url'] . ':';

		$redis_host    = ( 'redis' === $this->site_data['cache_host'] ) ? $this->site_data['cache_host'] : 'ee-' . $this->site_data['cache_host'];
		$wp_cli_params = ( 'wp' === $this->site_data['app_sub_type'] ) ? 'option update' : 'network meta update 1';

		$plugin_data = sprintf( '{  
				"log_level":"INFO",
				"log_filesize":5,
				"enable_purge":1,
				"enable_map":0,
				"enable_log":0,
				"enable_stamp":0,
				"purge_homepage_on_new":1,
				"purge_homepage_on_edit":1,
				"purge_homepage_on_del":1,
				"purge_archive_on_new":1,
				"purge_archive_on_edit":0,
				"purge_archive_on_del":0,
				"purge_archive_on_new_comment":0,
				"purge_archive_on_deleted_comment":0,
				"purge_page_on_mod":1,
				"purge_page_on_new_comment":1,
				"purge_page_on_deleted_comment":1,
				"cache_method":"enable_redis",
				"purge_method":"get_request",
				"redis_hostname":"%s",
				"redis_port":"6379",
				"redis_prefix":"%s"
			}',
			$redis_host,
			$salt_value
		);

		$add_hostname_constant = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_HOSTNAME ee-global-redis --add=true --type=constant";
		$add_port_constant     = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_PORT 6379 --add=true --type=constant";
		$add_prefix_constant   = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_PREFIX nginx-cache: --add=true --type=constant";
		$add_cache_key_salt    = "docker-compose exec --user='www-data' php wp config set WP_CACHE_KEY_SALT $salt_value --add=true --type=constant";
		$add_redis_maxttl      = "docker-compose exec --user='www-data' php wp config set WP_REDIS_MAXTTL 14400 --add=true --type=constant";
		$add_plugin_data       = "docker-compose exec --user='www-data' php wp $wp_cli_params rt_wp_nginx_helper_options '$plugin_data' --format=json";

		$this->docker_compose_exec( $add_hostname_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_port_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_prefix_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_cache_key_salt, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $activate_nginx_helper, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_plugin_data, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_redis_maxttl, $nginx_helper_fail_msg );
	}

	/**
	 *  Execute command with fail msg.
	 *
	 * @param string $command  Command to execute.
	 * @param string $fail_msg failure message.
	 */
	private function docker_compose_exec( $command, $fail_msg = '' ) {
		if ( empty( $command ) ) {
			return;
		}
		if ( ! \EE::exec( $command ) ) {
			\EE::warning( $fail_msg );
		}
	}

	/**
	 * Creates database user for a site
	 *
	 * @param string $site_url URL of site.
	 *
	 * @return string Generated db user.
	 */
	private function create_site_db_user( string $site_url ): string {
		if ( strlen( $site_url ) > 53 ) {
			$site_url = substr( $site_url, 0, 53 );
		}

		return $site_url . '-' . \EE\Utils\random_password( 6 );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 *
	 * ## EXAMPLES
	 *
	 *     # Display site info
	 *     $ ee site info example.com
	 */
	public function info( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args             = auto_site_name( $args, 'wp', __FUNCTION__ );
			$this->site_data  = get_site_info( $args, false );
			$this->cache_type = $this->site_data['cache_nginx_fullpage'];
		}
		$ssl    = $this->site_data['site_ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$info   = [ [ 'Site', $prefix . $this->site_data['site_url'] ] ];
		if ( ! empty( $this->site_data['admin_tools'] ) ) {
			$info[] = [ 'Access admin-tools', $prefix . $this->site_data['site_url'] . '/ee-admin/' ];
		}
		$info[] = [ 'Site Title', $this->site_data['app_admin_url'] ];
		if ( ! empty( $this->site_data['app_admin_username'] ) && ! $this->skip_install ) {
			$info[] = [ 'WordPress Username', $this->site_data['app_admin_username'] ];
			$info[] = [ 'WordPress Password', $this->site_data['app_admin_password'] ];
		}
		$info[] = [ 'DB Host', $this->site_data['db_host'] ];
		if ( ! empty( $this->site_data['db_root_password'] ) ) {
			$info[] = [ 'DB Root Password', $this->site_data['db_root_password'] ];
		}
		$info[] = [ 'DB Name', $this->site_data['db_name'] ];
		$info[] = [ 'DB User', $this->site_data['db_user'] ];
		$info[] = [ 'DB Password', $this->site_data['db_password'] ];
		$info[] = [ 'E-Mail', $this->site_data['app_admin_email'] ];
		$info[] = [ 'SSL', $ssl ];

		if ( $this->site_data['site_ssl'] ) {
			$info[] = [ 'SSL Wildcard', $this->site_data['site_ssl_wildcard'] ? 'Yes' : 'No' ];
		}
		$info[] = [ 'Cache', $this->cache_type ? 'Enabled' : 'None' ];

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_conf_env           = $this->site_data['site_fs_path'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/main.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$server_name             = ( 'subdom' === $this->site_data['app_sub_type'] ) ? $this->site_data['site_url'] . ' *.' . $this->site_data['site_url'] : $this->site_data['site_url'];
		$custom_conf_dest        = $site_conf_dir . '/nginx/custom/user.conf';
		$custom_conf_source      = SITE_WP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache';
		$process_user            = posix_getpwuid( posix_geteuid() );

		\EE::log( 'Creating WordPress site ' . $this->site_data['site_url'] );
		\EE::log( 'Copying configuration files.' );

		$default_conf_content = $this->generate_default_conf( $this->site_data['app_sub_type'], $this->cache_type, $server_name );
		$local                = ( 'db' === $this->site_data['db_host'] ) ? true : false;

		$db_host  = $local ? $this->site_data['db_host'] : $this->site_data['db_host'] . ':' . $this->site_data['db_port'];
		$env_data = [
			'local'         => $local,
			'virtual_host'  => $this->site_data['site_url'],
			'root_password' => $this->site_data['db_root_password'],
			'database_name' => $this->site_data['db_name'],
			'database_user' => $this->site_data['db_user'],
			'user_password' => $this->site_data['db_password'],
			'wp_db_host'    => $db_host,
			'wp_db_user'    => $this->site_data['db_user'],
			'wp_db_name'    => $this->site_data['db_name'],
			'wp_db_pass'    => $this->site_data['db_password'],
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];

		$php_ini_data = [
			'admin_email' => $this->site_data['app_admin_email'],
		];

		$env_content     = \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/php-fpm/php.ini.mustache', $php_ini_data );

		try {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );

			\EE\Site\Utils\set_postfix_files( $this->site_data['site_url'], $site_conf_dir );

			\EE::success( 'Configuration files copied.' );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Generate and place docker-compose.yml file.
	 *
	 * @param array $additional_filters Filters to alter docker-compose file.
	 */
	protected function dump_docker_compose_yml( $additional_filters = [] ) {

		$site_docker_yml = $this->site_data['site_fs_path'] . '/docker-compose.yml';

		$filter      = [];
		$filter[]    = $this->site_data['app_sub_type'];
		$filter[]    = $this->site_data['cache_host'];
		$filter[]    = $this->site_data['db_host'];
		$site_docker = new Site_WP_Docker();

		foreach ( $additional_filters as $key => $addon_filter ) {
			$filter[ $key ] = $addon_filter;
		}

		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
	}


	/**
	 * Function to generate main.conf from mustache templates.
	 *
	 * @param string $site_type   Type of site (subdom, subdir etc..).
	 * @param boolean $cache_type Cache enabled or not.
	 * @param string $server_name Name of server to use in virtual_host.
	 *
	 * @return string Parsed mustache template string output.
	 */
	private function generate_default_conf( $site_type, $cache_type, $server_name ) {

		$default_conf_data['site_type']             = $site_type;
		$default_conf_data['server_name']           = $server_name;
		$default_conf_data['include_php_conf']      = ! $cache_type;
		$default_conf_data['include_wpsubdir_conf'] = $site_type === 'subdir';
		$default_conf_data['include_redis_conf']    = $cache_type;
		$default_conf_data['cache_host']            = $this->site_data['cache_host'];

		return \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
	}


	/**
	 * Verify if the passed database credentials are working or not.
	 *
	 * @throws \Exception
	 */
	private function maybe_verify_remote_db_connection() {

		if ( in_array( $this->site_data['db_host'], [ 'db', GLOBAL_DB ], true ) ) {
			return;
		}
		$db_host        = $this->site_data['db_host'];
		$img_versions   = \EE\Utils\get_image_versions();
		$container_name = \EE\Utils\random_password();
		$network        = ( GLOBAL_DB === $this->site_data['db_host'] ) ? "--network='" . GLOBAL_FRONTEND_NETWORK . "'" : '';

		$run_temp_container = sprintf(
			'docker run --name %s %s -e MYSQL_ROOT_PASSWORD=%s -d --restart always easyengine/mariadb:%s',
			$container_name,
			$network,
			\EE\Utils\random_password(),
			$img_versions['easyengine/mariadb']
		);
		if ( ! \EE::exec( $run_temp_container ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'There was a problem creating container to test mysql connection. Please check the logs' );
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway.
		if ( '127.0.0.1' === $db_host || 'localhost' === $db_host ) {
			$launch = \EE::launch( sprintf( "docker exec %s bash -c \"ip route show default | cut -d' ' -f3\"", $container_name ) );

			if ( ! $launch->return_code ) {
				$db_host = trim( $launch->stdout, "\n" );
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}

		\EE::log( 'Verifying connection to remote database' );

		$check_db_connection = sprintf(
			"docker exec %s sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"",
			$container_name,
			$db_host,
			$this->site_data['db_port'],
			$this->site_data['db_user'],
			$this->site_data['db_password']
		);
		if ( ! \EE::exec( $check_db_connection ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'Unable to connect to remote db' );
		}
		\EE::success( 'Connection to remote db verified' );

		$name            = str_replace( '_', '\_', $this->site_data['db_name'] );
		$check_db_exists = sprintf( "docker exec %s bash -c \"mysqlshow --user='%s' --password='%s' --host='%s' --port='%s' '%s'\"", $container_name, $this->site_data['db_user'], $this->site_data['db_password'], $db_host, $this->site_data['db_port'], $name );

		if ( ! \EE::exec( $check_db_exists ) ) {
			\EE::log( sprintf( 'Database `%s` does not exist. Attempting to create it.', $this->site_data['db_name'] ) );
			$create_db_command = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			if ( ! \EE::exec( $create_db_command ) ) {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( sprintf(
					'Could not create database `%s` on `%s:%s`. Please check if %s has rights to create database or manually create a database and pass with `--dbname` parameter.',
					$this->site_data['db_name'],
					$this->site_data['db_host'],
					$this->site_data['db_port'],
					$this->site_data['db_user']
				) );
			}
		} else {
			if ( $this->force ) {
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='DROP DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
			}
			$check_tables = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='USE %s; show tables;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			$launch = \EE::launch( $check_tables );
			if ( ! $launch->return_code ) {
				$tables = trim( $launch->stdout, "\n" );
				if ( ! empty( $tables ) ) {
					\EE::exec( "docker rm -f $container_name" );
					throw new \Exception( sprintf( 'Some database tables seem to exist in database %s. Please backup and reset the database or use `--force` in the site create command to reset it.', $this->site_data['db_name'] ) );
				}
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}
		\EE::exec( "docker rm -f $container_name" );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->site_data['site_fs_path'] = WEBROOT . $this->site_data['site_url'];
		$this->level                     = 1;
		try {
			\EE\Site\Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 2;
			$this->maybe_verify_remote_db_connection();
			$this->configure_site_files();
			$this->level = 3;

			\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'postfix' ] );
			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );
			$this->wp_download_and_config( $assoc_args );

			if ( ! $this->skip_install ) {
				\EE\Site\Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
				if ( ! $this->skip_status_check ) {
					$this->level = 4;
					\EE\Site\Utils\site_status_check( $this->site_data['site_url'] );
				}
				$this->install_wp();
			}

			$this->www_ssl_wrapper( [ 'nginx' ] );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( ! empty( $this->cache_type ) ) {
			$this->enable_object_cache();
			$this->enable_page_cache();
		}

		$this->create_site_db_entry();
		\EE::log( 'Site entry created.' );

		\EE::log( 'Creating cron entry' );
		\EE::runcommand( 'cron create ' . $this->site_data['site_url'] . ' --user=www-data --command=\'wp cron event run --due-now\' --schedule=\'@every 1h\'' );

		$this->info( [ $this->site_data['site_url'] ], [] );
	}

	/**
	 * Download and configure WordPress according to the user passed parameters.
	 *
	 * @param array $assoc_args Associative arguments passed during site creation.
	 *
	 * @throws \EE\ExitException
	 * @throws \Exception
	 */
	private function wp_download_and_config( $assoc_args ) {

		$core_download_args = [
			'version',
			'skip-content',
		];

		$config_args = [
			'dbprefix',
			'dbcharset',
			'dbcollate',
			'skip-check',
		];

		$core_download_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				$core_download_arguments .= in_array( $key, $core_download_args, true ) ? ' --' . $key . '=' . $value : '';
			}
		}

		$config_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				$config_arguments .= in_array( $key, $config_args, true ) ? ' --' . $key . '=' . $value : '';
			}
		}

		\EE::log( 'Downloading and configuring WordPress.' );

		$chown_command = "docker-compose exec --user=root php chown -R www-data: /var/www/";
		\EE::exec( $chown_command );

		$core_download_command = "docker-compose exec --user='www-data' php wp core download --locale='$this->locale' $core_download_arguments";

		if ( ! \EE::exec( $core_download_command ) ) {
			\EE::error( 'Unable to download wp core.', false );
		}

		if ( 'db' === $this->site_data['db_host'] ) {
			$mysql_unhealthy = true;
			$health_chk      = sprintf( "docker-compose exec --user='www-data' php mysql --user='root' --password='%s' --host='db' -e exit", $this->site_data['db_root_password'] );
			$count           = 0;
			while ( $mysql_unhealthy ) {
				$mysql_unhealthy = ! \EE::exec( $health_chk );
				if ( $count ++ > 30 ) {
					break;
				}
				sleep( 1 );
			}
		}

		$db_host                  = isset( $this->site_data['db_port'] ) ? $this->site_data['db_host'] . ':' . $this->site_data['db_port'] : $this->site_data['db_host'];
		$wp_config_create_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp config create --dbuser=\'%s\' --dbname=\'%s\' --dbpass=\'%s\' --dbhost=\'%s\' %s --extra-php="if ( isset( \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] == \'https\'){\$_SERVER[\'HTTPS\']=\'on\';}"', $this->site_data['db_user'], $this->site_data['db_name'], $this->site_data['db_password'], $db_host, $config_arguments );

		try {
			if ( ! \EE::exec( $wp_config_create_command ) ) {
				throw new \Exception( sprintf( 'Couldn\'t connect to %s:%s or there was issue in `wp config create`. Please check logs.', $this->site_data['db_host'], $this->site_data['db_port'] ) );
			}
			$default_wp_config_path = $this->site_data['site_fs_path'] . '/app/htdocs/wp-config.php';
			$new_wp_config_path     = $this->site_data['site_fs_path'] . '/app/wp-config.php';
			$this->fs->rename( $default_wp_config_path, $new_wp_config_path );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

	}

	/**
	 * Install WordPress with given credentials.
	 */
	private function install_wp() {

		\EE::log( "Installing WordPress site." );
		chdir( $this->site_data['site_fs_path'] );

		$wp_install_command   = 'install';
		$maybe_multisite_type = '';

		if ( 'subdom' === $this->site_data['app_sub_type'] || 'subdir' === $this->site_data['app_sub_type'] ) {
			$wp_install_command   = 'multisite-install';
			$maybe_multisite_type = $this->site_data['app_sub_type'] === 'subdom' ? '--subdomains' : '';
		}

		$prefix          = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$install_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp core %s --url=\'%s%s\' --title=\'%s\' --admin_user=\'%s\'', $wp_install_command, $prefix, $this->site_data['site_url'], $this->site_data['app_admin_url'], $this->site_data['app_admin_username'] );
		$install_command .= $this->site_data['app_admin_password'] ? sprintf( ' --admin_password=\'%s\'', $this->site_data['app_admin_password'] ) : '';
		$install_command .= sprintf( ' --admin_email=\'%s\' %s', $this->site_data['app_admin_email'], $maybe_multisite_type );

		$core_install = \EE::exec( $install_command, false, false, [
			$this->site_data['app_admin_username'],
			$this->site_data['app_admin_email'],
			$this->site_data['app_admin_password'],
		] );

		if ( ! $core_install ) {
			\EE::warning( 'WordPress install failed. Please check logs.' );
		}

		\EE::success( $prefix . $this->site_data['site_url'] . ' has been created successfully!' );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl = null;

		if ( $this->site_data['site_ssl'] ) {
			$ssl = 'letsencrypt';
			if ( 'subdom' === $this->site_data['app_sub_type'] ) {
				$ssl = 'wildcard';
			}
		}

		$data = [
			'site_url'             => $this->site_data['site_url'],
			'site_type'            => $this->site_data['site_type'],
			'app_admin_url'        => $this->site_data['app_admin_url'],
			'app_admin_email'      => $this->site_data['app_admin_email'],
			'app_mail'             => 'postfix',
			'app_sub_type'         => $this->site_data['app_sub_type'],
			'cache_nginx_browser'  => (int) $this->cache_type,
			'cache_nginx_fullpage' => (int) $this->cache_type,
			'cache_mysql_query'    => (int) $this->cache_type,
			'cache_app_object'     => (int) $this->cache_type,
			'cache_host'           => $this->site_data['cache_host'],
			'site_fs_path'         => $this->site_data['site_fs_path'],
			'db_name'              => $this->site_data['db_name'],
			'db_user'              => $this->site_data['db_user'],
			'db_host'              => $this->site_data['db_host'],
			'db_port'              => isset( $this->site_data['db_port'] ) ? $this->site_data['db_port'] : '',
			'db_password'          => $this->site_data['db_password'],
			'db_root_password'     => $this->site_data['db_root_password'],
			'site_ssl'             => $ssl,
			'site_ssl_wildcard'    => 'subdom' === $this->site_data['app_sub_type'] || $this->site_data['site_ssl_wildcard'] ? 1 : 0,
			'php_version'          => '7.2',
			'created_on'           => date( 'Y-m-d H:i:s', time() ),
		];

		if ( ! $this->skip_install ) {
			$data['app_admin_username'] = $this->site_data['app_admin_username'];
			$data['app_admin_password'] = $this->site_data['app_admin_password'];
		}

		try {
			if ( ! Site::create( $data ) ) {
				throw new \Exception( 'Error creating site entry in database.' );
			}
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * [--php]
	 * : Restart php container of site.
	 *
	 * [--db]
	 * : Restart db container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 *     # Restart single container of site
	 *     $ ee site restart example.com --nginx
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {
		$whitelisted_containers = [ 'nginx', 'php', 'db' ];
		parent::restart( $args, $assoc_args, $whitelisted_containers );
	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * [--php]
	 * : Reload php container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 *     # Reload single containers of site
	 *     $ ee site reload example.com --nginx
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {
		$whitelisted_containers = [ 'nginx', 'php' ];
		$reload_commands['php'] = 'kill -USR2 1';
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands );
	}


	/**
	 * Catch and clean exceptions.
	 *
	 * @param \Exception $e
	 */
	private function catch_clean( $e ) {
		\EE\Utils\delem_log( 'site cleanup start' );
		\EE::warning( $e->getMessage() );
		\EE::warning( 'Initiating clean-up.' );
		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {
		\EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
				'db_host' => $this->site_data['db_host'],
				'db_user' => $this->site_data['db_user'],
				'db_name' => $this->site_data['db_name'],
			];
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		}
		\EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

}
