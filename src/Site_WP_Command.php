<?php

declare( ticks=1 );


use \Symfony\Component\Filesystem\Filesystem;
use EE\Model\Site;

/**
 * Creates a simple WordPress Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee site create example.com --wp
 *
 * @package ee-cli
 */
class Site_WP_Command extends EE_Site_Command {

	/**
	 * @var string $site url of the site.
	 */
	private $site_url;

	/**
	 * @var string $site directory on which site is installed.
	 */
	private $site_root;

	/**
	 * @var object $docker Object to access `EE::docker()` functions.
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
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		$this->level = 0;
		pcntl_signal( SIGTERM, [ $this, 'rollback' ] );
		pcntl_signal( SIGHUP,  [ $this, 'rollback' ] );
		pcntl_signal( SIGUSR1, [ $this, 'rollback' ] );
		pcntl_signal( SIGINT,  [ $this, 'rollback' ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, 'cleanup' ], [ &$this ] );
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'site_wp_command' );
		$this->fs     = new Filesystem();
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
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 * ---
	 * default: wordpress
	 * ---
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 * ---
	 * default: db
	 * ---
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8
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
	 */
	public function create( $args, $assoc_args ) {

		EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$site_config['url'] = strtolower( EE\Utils\remove_trailing_slash( $args[0] ) );

		$mu = EE\Utils\get_flag_value( $assoc_args, 'mu' );

		if ( isset( $assoc_args['mu'] ) && ! in_array( $mu, [ 'subdom', 'subdir' ], true ) ) {
			EE::error( "Unrecognized multi-site parameter: $mu. Only `--mu=subdom` and `--mu=subdir` are supported." );
		}
		$site_config['app_sub_type'] = $mu ?? 'wp';

		if ( Site::find( $site_config['url'] ) ) {
			EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $site_config['url'] ) );
		}

		$site_config['cache_nginx_browser']  = EE\Utils\get_flag_value( $assoc_args, 'cache', false );
		$site_config['cache_nginx_fullpage'] = EE\Utils\get_flag_value( $assoc_args, 'cache', false );
		$site_config['cache_mysql_query']    = EE\Utils\get_flag_value( $assoc_args, 'cache', false );
		$site_config['cache_app_object']     = EE\Utils\get_flag_value( $assoc_args, 'cache', false );
		$site_config['ssl']                  = EE\Utils\get_flag_value( $assoc_args, 'ssl' );
		$site_config['ssl_wildcard']         = EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$site_config['title']                = EE\Utils\get_flag_value( $assoc_args, 'title', $site_config['url'] );
		$site_config['wp_user']              = EE\Utils\get_flag_value( $assoc_args, 'admin-user', 'admin' );
		$site_config['wp_pass']              = EE\Utils\get_flag_value( $assoc_args, 'admin-pass', EE\Utils\random_password() );
		$site_config['db_name']              = str_replace( [ '.', '-' ], '_', $site_config['url'] );
		$site_config['db_host']              = EE\Utils\get_flag_value( $assoc_args, 'dbhost' );
		$site_config['db_port']              = '3306';
		$site_config['db_user']              = EE\Utils\get_flag_value( $assoc_args, 'dbuser', $this->create_site_db_user( $site_config['url'] ) );
		$site_config['db_pass']              = EE\Utils\get_flag_value( $assoc_args, 'dbpass', EE\Utils\random_password() );
		$site_config['locale']               = EE\Utils\get_flag_value( $assoc_args, 'locale', EE::get_config( 'locale' ) );
		$site_config['db_root_pass']         = EE\Utils\random_password();
		$site_config['root']                 = WEBROOT . $site_config['url'];

		$this->site_url  = $site_config['url'];
		$this->site_root = $site_config['root'];

		// If user wants to connect to remote database
		if ( 'db' !== $site_config['db_host'] ) {
			if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
				EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
			}
			$arg_host_port          = explode( ':', $site_config['db_host'] );
			$site_config['db_host'] = $arg_host_port[0];
			$site_config['db_port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
		}

		$site_config['wp_email'] = EE\Utils\get_flag_value( $assoc_args, 'admin_email', strtolower( 'admin@' . $site_config['url'] ) );
		$site_config['skip_install']      = EE\Utils\get_flag_value( $assoc_args, 'skip-install' );
		$site_config['skip_check']          = EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$site_config['force']             = EE\Utils\get_flag_value( $assoc_args, 'force' );

		EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site( $assoc_args, $site_config );
		EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Creates database user for a site
	 *
	 * @param string $site_url URL of site
	 *
	 * @return string Generated db user
	 */
	private function create_site_db_user( string $site_url ) : string {
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
	 */
	public function info( $args, $assoc_args, $site_config = [] ) {

		EE\Utils\delem_log( 'site info start' );

		if ( ! isset( $site_config['url'] ) ) {
			$args                               = EE\SiteUtils\auto_site_name( $args, 'wp', __FUNCTION__ );
			$site_url                           = EE\Utils\remove_trailing_slash( $args[0] );
			$site                               = $this->get_site( $site_url );
			$site_config['ssl']                 = $site->site_ssl;
			$site_config['url']                 = $site_url;
			$site_config['title']               = $site->app_admin_url;
			$site_config['ssl_wildcard']        = $site->site_ssl_wildcard;
			$site_config['admin_tools']         = $site->admin_tools;
			$site_config['wp_user']             = $site->app_admin_username;
			$site_config['wp_pass']             = $site->app_admin_password;
			$site_config['db_root_pass']        = $site->db_root_password;
			$site_config['db_name']             = $site->db_name;
			$site_config['db_user']             = $site->db_user;
			$site_config['db_pass']             = $site->db_password;
			$site_config['wp_email']            = $site->app_admin_email;
			$site_config['cache_nginx_browser'] = $site->cache_nginx_browser;
		}

		$ssl    = $site_config['ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $site_config['ssl'] ) ? 'https://' : 'http://';
		$info   = [ [ 'Site', $prefix . $site_config['url'] ] ];
		if ( ! empty( $site_config['admin_tools'] ) ) {
			$info[] = [ 'Access admin-tools', $prefix . $site_config['url'] . '/ee-admin/' ];
		}
		$info[] = [ 'Site Title', $site_config['title'] ];
		if ( ! empty( $site_config['wp_user'] ) && ! empty( $site_config['skip_install'] ) ) {
			$info[] = [ 'WordPress Username', $site_config['wp_user'] ];
			$info[] = [ 'WordPress Password', $site_config['wp_pass'] ];
		}
		$info[] = [ 'DB Root Password', $site_config['db_root_pass'] ];
		$info[] = [ 'DB Name', $site_config['db_name'] ];
		$info[] = [ 'DB User', $site_config['db_user'] ];
		$info[] = [ 'DB Password', $site_config['db_pass'] ];
		$info[] = [ 'E-Mail', $site_config['wp_email'] ];
		$info[] = [ 'SSL', $ssl ];

		if ( $site_config['ssl'] ) {
			$info[] = [ 'SSL Wildcard', $site_config['ssl_wildcard'] ? 'Yes' : 'No' ];
		}
		$info[] = [ 'Cache', $site_config['cache_nginx_browser'] ? 'Enabled' : 'None' ];

		EE\Utils\format_table( $info );

		EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Function to configure site and copy all the required files.
	 *
	 * @param array $site_config site build configuration
	 */
	private function configure_site_files( array $site_config ) {
		$site_conf_dir           = $site_config['root'] . '/config';
		$site_docker_yml         = $site_config['root'] . '/docker-compose.yml';
		$site_conf_env           = $site_config['root'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$server_name             = ( 'subdom' === $site_config['app_sub_type'] ) ? $site_config['url'] . ' *.' . $site_config['url'] : $site_config['url'];
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( 'Creating WordPress site ' . $site_config['url'] );
		EE::log( 'Copying configuration files.' );

		$filter                 = [];
		$filter[]               = $site_config['app_sub_type'];
		$filter[]               = $site_config['cache_nginx_browser'] ? 'redis' : 'none';
		$filter[]               = $site_config['db_host'];
		$site_docker            = new Site_WP_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $this->generate_default_conf( $site_config['app_sub_type'], $site_config['cache_nginx_browser'], $server_name );
		$local                  = ( 'db' === $site_config['db_host'] ) ? true : false;

		$db_host  = $local ? $site_config['db_host'] : $site_config['db_host'] . ':' . $site_config['db_port'];
		$env_data = [
			'local'         => $local,
			'virtual_host'  => $site_config['url'],
			'root_password' => $site_config['db_root_pass'],
			'database_name' => $site_config['db_name'],
			'database_user' => $site_config['db_user'],
			'user_password' => $site_config['db_pass'],
			'wp_db_host'    => $db_host,
			'wp_db_user'    => $site_config['db_user'],
			'wp_db_name'    => $site_config['db_name'],
			'wp_db_pass'    => $site_config['db_pass'],
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];

		$php_ini_data = [
			'admin_email' => $site_config['wp_email'],
		];

		$env_content     = EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/php-fpm/php.ini.mustache', $php_ini_data );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->mkdir( $site_conf_dir );
			$this->fs->mkdir( $site_conf_dir . '/nginx' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->mkdir( $site_conf_dir . '/php-fpm' );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );

			EE\SiteUtils\set_postfix_files( $site_config['url'], $site_conf_dir );

			EE::success( 'Configuration files copied.' );
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}
	}


	/**
	 * Function to generate default.conf from mustache templates.
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
		$default_conf_data['include_wpsubdir_conf'] = 'subdir' === $site_type;
		$default_conf_data['include_redis_conf']    = $cache_type;

		return EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', $default_conf_data );
	}

	/**
	 * Verifies remote db connection if remote db is not used
	 *
	 * @param array $site_config site build configuration
	 *
	 * @throws Exception
	 */
	private function maybe_verify_remote_db_connection( array $site_config ) {

		if ( 'db' === $site_config['db_host'] ) {
			return;
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway
		if ( '127.0.0.1' === $site_config['db_host'] || 'localhost' === $site_config['db_host'] ) {
			$launch = EE::exec( sprintf( "docker network inspect %s --format='{{ (index .IPAM.Config 0).Gateway }}'", $site_config['url'] ), false, true );

			if ( ! $launch->return_code ) {
				$site_config['db_host'] = trim( $launch->stdout, "\n" );
			} else {
				throw new Exception( 'There was a problem inspecting network. Please check the logs' );
			}
		}
		\EE::log( 'Verifying connection to remote database' );

		if ( ! EE::exec( sprintf( "docker run -it --rm --network='%s' mysql sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"", $site_config['url'], $site_config['db_host'], $site_config['db_port'], $site_config['db_user'], $site_config['db_pass'] ) ) ) {
			throw new Exception( 'Unable to connect to remote db' );
		}

		\EE::success( 'Connection to remote db verified' );
	}

	/**
	 * Function to create the site.
	 *
	 * @param $assoc_args  array Associative args passed to site create
	 * @param $site_config array site build configuration
	 */
	private function create_site( $assoc_args, $site_config ) {

		$this->level        = 1;
		try {
			EE\SiteUtils\create_site_root( $site_config['root'], $site_config['url'] );
			$this->level = 2;
			$this->maybe_verify_remote_db_connection( $site_config );
			$this->level = 3;
			$this->configure_site_files( $site_config );

			EE\SiteUtils\start_site_containers( $site_config['root'], [ 'nginx', 'postfix' ] );
			EE\SiteUtils\configure_postfix( $site_config['url'], $site_config['root'] );
			$this->wp_download_and_config( $assoc_args, $site_config );

			if ( ! $site_config['skip_install'] ) {
				EE\SiteUtils\create_etc_hosts_entry( $site_config['url'] );
				if ( ! $site_config['skip_check'] ) {
					$this->level = 4;
					EE\SiteUtils\site_status_check( $site_config['url'] );
				}
				$this->install_wp( $site_config );
			}

			EE\SiteUtils\add_site_redirects( $site_config['url'], false, 'inherit' === $site_config['ssl'] );
			EE\SiteUtils\reload_proxy_configuration();

			if ( $site_config['ssl'] ) {
				$wildcard = 'subdom' === $site_config['app_sub_type'] || $site_config['ssl_wildcard'];
				EE::debug( "Wildcard in site wp command: ${site_config['ssl_wildcard']}" );
				$this->init_ssl( $site_config['url'], $site_config['root'], $site_config['ssl'], $wildcard );

				EE\SiteUtils\add_site_redirects( $site_config['url'], true, 'inherit' === $site_config['ssl'] );
				EE\SiteUtils\reload_proxy_configuration();
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}

		$this->create_site_db_entry( $site_config );
		$this->info( [ $site_config['url'] ], [], $site_config );
	}

	/**
	 * Download and configure WordPress according to the user passed parameters.
	 *
	 * @param $assoc_args  array associative args passed to site create
	 * @param $site_config array site build configuration
	 *
	 * @throws \EE\ExitException
	 */
	private function wp_download_and_config( $assoc_args, $site_config ) {

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

		EE::log( 'Downloading and configuring WordPress.' );

		$chown_command = 'docker-compose exec --user=root php chown -R www-data: /var/www/';
		EE::exec( $chown_command );

		$core_download_command = "docker-compose exec --user='www-data' php wp core download --locale='${site_config['locale']}' $core_download_arguments";

		if ( ! EE::exec( $core_download_command ) ) {
			EE::error( 'Unable to download wp core.', false );
		}

		// TODO: Look for better way to handle mysql healthcheck
		if ( 'db' === $site_config['db_host'] ) {
			$mysql_unhealthy = true;
			$health_chk      = sprintf( "docker-compose exec --user='www-data' php mysql --user='root' --password='%s' --host='db' -e exit", $site_config['db_root_pass'] );
			$count           = 0;
			while ( $mysql_unhealthy ) {
				$mysql_unhealthy = ! EE::exec( $health_chk );
				if ( $count ++ > 30 ) {
					break;
				}
				sleep( 1 );
			}
		}

		$db_host                  = isset( $site_config['db_port'] ) ? $site_config['db_host'] . ':' . $site_config['db_port'] : $site_config['db_host'];
		$wp_config_create_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp config create --dbuser=\'%s\' --dbname=\'%s\' --dbpass=\'%s\' --dbhost=\'%s\' %s --extra-php="if ( isset( \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] == \'https\'){\$_SERVER[\'HTTPS\']=\'on\';}"', $site_config['db_user'], $site_config['db_name'], $site_config['db_pass'], $db_host, $config_arguments );

		try {
			if ( ! EE::exec( $wp_config_create_command ) ) {
				throw new Exception( sprintf( 'Couldn\'t connect to %s:%s or there was issue in `wp config create`. Please check logs.', $site_config['db_host'], $site_config['db_port'] ) );
			}
			if ( 'db' !== $site_config['db_host'] ) {
				$name            = str_replace( '_', '\_', $site_config['db_name'] );
				$check_db_exists = sprintf( "docker-compose exec php bash -c \"mysqlshow --user='%s' --password='%s' --host='%s' --port='%s' '%s'", $site_config['db_user'], $site_config['db_pass'], $site_config['db_host'], $site_config['db_port'], $name );

				if ( ! EE::exec( $check_db_exists ) ) {
					EE::log( sprintf( 'Database `%s` does not exist. Attempting to create it.', $site_config['db_name'] ) );
					$create_db_command = sprintf( 'docker-compose exec php bash -c "mysql --host=%s --port=%s --user=%s --password=%s --execute="CREATE DATABASE %s;"', $site_config['db_host'], $site_config['db_port'], $site_config['db_user'], $site_config['db_pass'], $site_config['db_name'] );

					if ( ! EE::exec( $create_db_command ) ) {
						throw new Exception( sprintf( 'Could not create database `%s` on `%s:%s`. Please check if %s has rights to create database or manually create a database and pass with `--dbname` parameter.', $site_config['db_name'], $site_config['db_host'], $site_config['db_port'], $site_config['db_user'] ) );
					}
					$this->level = 4;
				} else {
					if ( $site_config['force'] ) {
						EE::exec( 'docker-compose exec --user=\'www-data\' php wp db reset --yes' );
					}
					$check_tables = 'docker-compose exec --user=\'www-data\' php wp db tables';
					if ( EE::exec( $check_tables ) ) {
						throw new Exception( 'WordPress tables already seem to exist. Please backup and reset the database or use `--force` in the site create command to reset it.' );
					}
				}
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}

	}

	/**
	 * Install WordPress with given credentials.
	 *
	 * @param $site_config array site build configuration
	 */
	private function install_wp( array $site_config ) {

		EE::log( 'Installing WordPress site.' );
		chdir( $site_config['root'] );

		$wp_install_command   = 'install';
		$maybe_multisite_type = '';

		if ( 'subdom' === $site_config['app_sub_type'] || 'subdir' === $site_config['app_sub_type'] ) {
			$wp_install_command   = 'multisite-install';
			$maybe_multisite_type = $site_config['app_sub_type'] === 'subdom' ? '--subdomains' : '';
		}

		$install_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp core %s --url=\'%s\' --title=\'%s\' --admin_user=\'%s\'', $wp_install_command, $site_config['url'], $site_config['title'], $site_config['wp_user'] );
		$install_command .= $site_config['wp_pass'] ? sprintf( ' --admin_password=\'%s\'', $site_config['wp_pass'] ) : '';
		$install_command .= sprintf( ' --admin_email=\'%s\' %s', $site_config['wp_email'], $maybe_multisite_type );

		$core_install = EE::exec( $install_command );

		if ( ! $core_install ) {
			EE::warning( 'WordPress install failed. Please check logs.' );
		}

		$prefix = ( $site_config['ssl'] ) ? 'https://' : 'http://';
		EE::success( $prefix . $site_config['url'] . ' has been created successfully!' );
	}

	/**
	 * Function to save the site configuration entry into database.
	 *
	 * @param array $site_config site build configuration
	 */
	private function create_site_db_entry( array $site_config ) {
		$ssl = null;

		if ( $site_config['ssl'] ) {
			$ssl = 'letsencrypt';
			if ( 'subdom' === $site_config['app_sub_type'] ) {
				$ssl = 'wildcard';
			}
		}

		$data = [
			'site_url'             => $site_config['url'],
			'site_type'            => 'wp',
			'app_admin_url'        => $site_config['title'],
			'app_admin_email'      => $site_config['wp_email'],
			'app_mail'             => 'postfix',
			'app_sub_type'         => $site_config['app_sub_type'],
			'cache_nginx_browser'  => $site_config['cache_nginx_browser'],
			'cache_nginx_fullpage' => $site_config['cache_nginx_fullpage'],
			'cache_mysql_query'    => $site_config['cache_mysql_query'],
			'cache_app_object'     => $site_config['cache_app_object'],
			'site_fs_path'         => $site_config['root'],
			'db_name'              => $site_config['db_name'],
			'db_user'              => $site_config['db_user'],
			'db_host'              => $site_config['db_host'],
			'db_port'              => isset( $site_config['db_port'] ) ? $site_config['db_port'] : '',
			'db_password'          => $site_config['db_pass'],
			'db_root_password'     => $site_config['db_root_pass'],
			'site_ssl'             => $ssl,
			'site_ssl_wildcard'    => 'subdom' === $site_config['app_sub_type'] || $site_config['ssl_wildcard'] ? 1 : 0,
			'php_version'          => '7.2',
			'created_on'           => date( 'Y-m-d H:i:s', time() ),
		];

		if ( ! $site_config['skip_install'] ) {
			$data['app_admin_username'] = $site_config['wp_user'];
			$data['app_admin_password'] = $site_config['wp_pass'];
		}

		try {
			if ( Site::create( $data ) ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}
	}

	/**
	 * Populate basic site info from db.
	 *
	 * @param string $site_url
	 *
	 * @throws \EE\ExitException
	 * @return Site
	 */
	private function get_site( string $site_url ) : Site {
		$site = Site::find( $site_url );

		if ( $site ) {
			return $site;
		} else {
			EE::error( "Site $site_url does not exist." );
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
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {
		$whitelisted_containers = [ 'nginx', 'php' ];
		$reload_commands['php'] = 'kill -USR2 1';
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands );
	}


	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 * @param array     $site_config
	 *
	 * @throws \EE\ExitException
	 */
	private function catch_clean( Exception $e, array $site_config ) {
		EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site_url, $this->site_root );
		EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {
		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site_url, $this->site_root );
		}
		EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	private function shutDownFunction() {

		$error = error_get_last();
		if ( isset( $error ) && $error['type'] === E_ERROR ) {
			EE::warning( 'An Error occurred. Initiating clean-up.' );
			$this->logger->error( 'Type: ' . $error['type'] );
			$this->logger->error( 'Message: ' . $error['message'] );
			$this->logger->error( 'File: ' . $error['file'] );
			$this->logger->error( 'Line: ' . $error['line'] );
			$this->rollback();
		}
	}
}
