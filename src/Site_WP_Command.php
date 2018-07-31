<?php

declare( ticks=1 );

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

use \Symfony\Component\Filesystem\Filesystem;

class Site_WP_Command extends EE_Site_Command {
	private $command;
	private $site;
	private $proxy_type;
	private $cache_type;
	private $db;
	private $docker;
	private $level;
	private $logger;
	private $le;
	private $locale;
	private $skip_install;
	private $skip_chk;
	private $force;
	private $fs;

	public function __construct() {
		$this->level   = 0;
		$this->command = 'wp';
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
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
	 * [--wp]
	 * : WordPress website.
	 *
	 * [--wpredis]
	 * : Use redis for WordPress.
	 *
	 * [--wpsubdir]
	 * : WordPress sub-dir Multi-site.
	 *
	 * [--wpsubdom]
	 * : WordPress sub-domain Multi-site.
	 *
	 * [--title=<title>]
	 * : Title of your site.
	 *
	 * [--admin_user=<admin_user>]
	 * : Username of the administrator.
	 *
	 * [--admin_pass=<admin_pass>]
	 * : Password for the the administrator.
	 *
	 * [--admin_email=<admin_email>]
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
	 * : Select which wordpress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.
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
	 * [--letsencrypt]
	 * : Enables ssl via letsencrypt certificate.
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
	 */
	public function create( $args, $assoc_args ) {

		EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site['name'] = strtolower( EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site['type'] = EE\Utils\get_type( $assoc_args, [ 'wp', 'wpsubdom', 'wpsubdir' ], 'wp' );
		if ( false === $this->site['type'] ) {
			EE::error( 'Invalid arguments' );
		}

		if ( EE::db()::site_in_db( $this->site['name'] ) ) {
			EE::error( sprintf( 'Site %1$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1$s`', $this->site['name'] ) );
		}

		$this->proxy_type      = EE_PROXY_TYPE;
		$this->cache_type      = empty( $assoc_args['wpredis'] ) ? 'none' : 'wpredis';
		$this->le              = EE\Utils\get_flag_value( $assoc_args, 'letsencrypt' );
		$this->site['title']   = EE\Utils\get_flag_value( $assoc_args, 'title', $this->site['name'] );
		$this->site['user']    = EE\Utils\get_flag_value( $assoc_args, 'admin_user', 'admin' );
		$this->site['pass']    = EE\Utils\get_flag_value( $assoc_args, 'admin_pass', EE\Utils\random_password() );
		$this->db['name']      = str_replace( [ '.', '-' ], '_', $this->site['name'] );
		$this->db['host']      = EE\Utils\get_flag_value( $assoc_args, 'dbhost' );
		$this->db['user']      = EE\Utils\get_flag_value( $assoc_args, 'dbuser', 'wordpress' );
		$this->db['pass']      = EE\Utils\get_flag_value( $assoc_args, 'dbpass', EE\Utils\random_password() );
		$this->locale          = EE\Utils\get_flag_value( $assoc_args, 'locale', EE::get_config( 'locale' ) );
		$this->db['root_pass'] = EE\Utils\random_password();

		// If user wants to connect to remote database
		if ( 'db' !== $this->db['host'] ) {
			if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
				EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
			}
			$arg_host_port    = explode( ':', $this->db['host'] );
			$this->db['host'] = $arg_host_port[0];
			$this->db['port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
		}

		$this->site['email'] = EE\Utils\get_flag_value( $assoc_args, 'admin_email', strtolower( 'mail@' . $this->site['name'] ) );
		$this->skip_install  = EE\Utils\get_flag_value( $assoc_args, 'skip-install' );
		$this->skip_chk      = EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force         = EE\Utils\get_flag_value( $assoc_args, 'force' );

		EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site( $assoc_args );
		EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 */
	public function info( $args ) {

		EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site['name'] ) ) {
			$args = EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
			$this->populate_site_info( $args );
		}
		$ssl    = $this->le ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = [
			[ 'Site', $prefix . $this->site['name'] ],
			[ 'Access phpMyAdmin', $prefix . $this->site['name'] . '/ee-admin/pma/' ],
			[ 'Access mailhog', $prefix . $this->site['name'] . '/ee-admin/mailhog/' ],
			[ 'Site Title', $this->site['title'] ],
			[ 'DB Root Password', $this->db['root_pass'] ],
			[ 'DB Name', $this->db['name'] ],
			[ 'DB User', $this->db['user'] ],
			[ 'DB Password', $this->db['pass'] ],
			[ 'E-Mail', $this->site['email'] ],
			[ 'Cache Type', $this->cache_type ],
			[ 'SSL', $ssl ],
		];

		if ( ! empty( $this->site['user'] ) && ! $this->skip_install ) {
			$info[] = [ 'WordPress Username', $this->site['user'] ];
			$info[] = [ 'WordPress Password', $this->site['pass'] ];
		}

		EE\Utils\format_table( $info );

		EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site['root'] . '/config';
		$site_docker_yml         = $this->site['root'] . '/docker-compose.yml';
		$site_conf_env           = $this->site['root'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$server_name             = ( 'wpsubdom' === $this->site['type'] ) ? $this->site['name'] . ' *.' . $this->site['name'] : $this->site['name'];
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( 'Creating WordPress site ' . $this->site['name'] );
		EE::log( 'Copying configuration files.' );

		$filter                 = [];
		$filter[]               = $this->site['type'];
		$filter[]               = $this->cache_type;
		$filter[]               = $this->le;
		$filter[]               = $this->db['host'];
		$site_docker            = new Site_WP_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $this->generate_default_conf( $this->site['type'], $this->cache_type, $server_name );
		$local                  = ( 'db' === $this->db['host'] ) ? true : false;

		$db_host  = isset( $this->db['port'] ) ? $this->db['host'] . ':' . $this->db['port'] : $this->db['host'];
		$env_data = [
			'local'         => $local,
			'virtual_host'  => $this->site['name'],
			'root_password' => $this->db['root_pass'],
			'database_name' => $this->db['name'],
			'database_user' => $this->db['user'],
			'user_password' => $this->db['pass'],
			'wp_db_host'    => $db_host,
			'wp_db_user'    => $this->db['user'],
			'wp_db_name'    => $this->db['name'],
			'wp_db_pass'    => $this->db['pass'],
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];

		$env_content     = EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/php-fpm/php.ini.mustache', [] );

		EE\Siteutils\add_site_redirects( $this->site['name'], $this->le );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->mkdir( $site_conf_dir );
			$this->fs->mkdir( $site_conf_dir . '/nginx' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->mkdir( $site_conf_dir . '/php-fpm' );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );
			EE::success( 'Configuration files copied.' );
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}


	/**
	 * Function to generate default.conf from mustache templates.
	 *
	 * @param string $site_type   Type of site (wpsubdom, wpredis etc..)
	 * @param string $cache_type  Type of cache(wpredis or none)
	 * @param string $server_name Name of server to use in virtual_host
	 */
	private function generate_default_conf( $site_type, $cache_type, $server_name ) {

		$default_conf_data['site_type']             = $site_type;
		$default_conf_data['server_name']           = $server_name;
		$default_conf_data['include_php_conf']      = $cache_type !== 'wpredis';
		$default_conf_data['include_wpsubdir_conf'] = $site_type === 'wpsubdir';
		$default_conf_data['include_redis_conf']    = $cache_type === 'wpredis';

		return EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', $default_conf_data );
	}

	private function maybe_verify_remote_db_connection() {

		if ( 'db' === $this->db['host'] ) {
			return;
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway
		if ( $this->db['host'] === '127.0.0.1' || $this->db['host'] === 'localhost' ) {
			$launch = EE::launch( sprintf( "docker network inspect %s --format='{{ (index .IPAM.Config 0).Gateway }}'", $this->site['name'] ), false, true );
			EE\Utils\default_debug( $launch );

			if ( ! $launch->return_code ) {
				$this->db['host'] = trim( $launch->stdout, "\n" );
			} else {
				throw new Exception( 'There was a problem inspecting network. Please check the logs' );
			}
		}
		\EE::log( 'Verifying connection to remote database' );

		if ( ! EE\Utils\default_launch( sprintf( "docker run -it --rm --network='%s' mysql sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"", $this->site['name'], $this->db['host'], $this->db['port'], $this->db['user'], $this->db['pass'] ) ) ) {
			throw new Exception( 'Unable to connect to remote db' );
		}

		\EE::success( 'Connection to remote db verified' );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->site['root'] = WEBROOT . $this->site['name'];
		$this->level        = 1;
		try {
			EE\Siteutils\create_site_root( $this->site['root'], $this->site['name'] );
			$this->level = 2;
			EE\Siteutils\setup_site_network( $this->site['name'] );
			$this->maybe_verify_remote_db_connection();
			$this->level = 3;
			$this->configure_site_files();

			EE\Siteutils\start_site_containers( $this->site['root'] );

			$this->wp_download_and_config( $assoc_args );

			if ( ! $this->skip_install ) {
				EE\Siteutils\create_etc_hosts_entry( $this->site['name'] );
				if ( ! $this->skip_chk ) {
					$this->level = 4;
					EE\Siteutils\site_status_check( $this->site['name'] );
				}
				$this->install_wp();
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( $this->le ) {
			$this->init_le();
		}
		$this->info( [ $this->site['name'] ], [] );
		$this->create_site_db_entry();
	}

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

		EE::log( 'Downloading and configuring WordPress.' );

		$chown_command = "docker-compose exec --user=root php chown -R www-data: /var/www/";
		EE\Utils\default_launch( $chown_command );

		$core_download_command = "docker-compose exec --user='www-data' php wp core download --locale='$this->locale' $core_download_arguments";
		EE\Utils\default_launch( $core_download_command );

		// TODO: Look for better way to handle mysql healthcheck
		if ( 'db' === $this->db['host'] ) {
			$mysql_unhealthy = true;
			$health_chk      = sprintf( "docker-compose exec --user='www-data' php mysql --user='root' --password='%s' --host='db' -e exit", $this->db['root_pass'] );
			$count           = 0;
			while ( $mysql_unhealthy ) {
				$mysql_unhealthy = ! EE\Utils\default_launch( $health_chk );
				if ( $count ++ > 30 ) {
					break;
				}
				sleep( 1 );
			}
		}

		$db_host                  = isset( $this->db['port'] ) ? $this->db['host'] . ':' . $this->db['port'] : $this->db['host'];
		$wp_config_create_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp config create --dbuser=\'%s\' --dbname=\'%s\' --dbpass=\'%s\' --dbhost=\'%s\' %s --extra-php="if ( isset( \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] == \'https\'){\$_SERVER[\'HTTPS\']=\'on\';}"', $this->db['user'], $this->db['name'], $this->db['pass'], $db_host, $config_arguments );

		try {
			if ( ! EE\Utils\default_launch( $wp_config_create_command ) ) {
				throw new Exception( sprintf( 'Couldn\'t connect to %s:%s or there was issue in `wp config create`. Please check logs.', $this->db['host'], $this->db['port'] ) );
			}
			if ( 'db' !== $this->db['host'] ) {
				$name            = str_replace( '_', '\_', $this->db['name'] );
				$check_db_exists = sprintf( "docker-compose exec php bash -c \"mysqlshow --user='%s' --password='%s' --host='%s' --port='%s' '%s'", $this->db['user'], $this->db['pass'], $this->db['host'], $this->db['port'], $name );

				if ( ! EE\Utils\default_launch( $check_db_exists ) ) {
					EE::log( sprintf( 'Database `%s` does not exist. Attempting to create it.', $this->db['name'] ) );
					$create_db_command = sprintf( 'docker-compose exec php bash -c "mysql --host=%s --port=%s --user=%s --password=%s --execute="CREATE DATABASE %s;"', $this->db['host'], $this->db['port'], $this->db['user'], $this->db['pass'], $this->db['name'] );

					if ( ! EE\Utils\default_launch( $create_db_command ) ) {
						throw new Exception( sprintf( 'Could not create database `%s` on `%s:%s`. Please check if %s has rights to create database or manually create a database and pass with `--dbname` parameter.', $this->db['name'], $this->db['host'], $this->db['port'], $this->db['user'] ) );
					}
					$this->level = 4;
				} else {
					if ( $this->force ) {
						EE\Utils\default_launch( 'docker-compose exec --user=\'www-data\' php wp db reset --yes' );
					}
					$check_tables = 'docker-compose exec --user=\'www-data\' php wp db tables';
					if ( EE\Utils\default_launch( $check_tables ) ) {
						throw new Exception( 'WordPress tables already seem to exist. Please backup and reset the database or use `--force` in the site create command to reset it.' );
					}
				}
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

	}

	/**
	 * Install wordpress with given credentials.
	 */
	private function install_wp() {

		EE::log( "Installing WordPress site." );
		chdir( $this->site['root'] );

		$wp_install_command   = 'install';
		$maybe_multisite_type = '';

		if ( 'wpsubdom' === $this->site['type'] || 'wpsubdir' === $this->site['type'] ) {
			$wp_install_command   = 'multisite-install';
			$maybe_multisite_type = $this->site['type'] === 'wpsubdom' ? '--subdomains' : '';
		}

		$install_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp core %s --url=\'%s\' --title=\'%s\' --admin_user=\'%s\'', $wp_install_command, $this->site['name'], $this->site['title'], $this->site['user'] );
		$install_command .= $this->site['pass'] ? sprintf( ' --admin_password=\'%s\'', $this->site['pass'] ) : '';
		$install_command .= sprintf( ' --admin_email=\'%s\' %s', $this->site['email'], $maybe_multisite_type );

		$core_install = EE\Utils\default_launch( $install_command );

		if ( ! $core_install ) {
			EE::warning( 'WordPress install failed. Please check logs.' );
		}

		$prefix = ( $this->le ) ? 'https://' : 'http://';
		EE::success( $prefix . $this->site['name'] . ' has been created successfully!' );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {

		$data = [
			'sitename'         => $this->site['name'],
			'site_type'        => $this->site['type'],
			'site_title'       => $this->site['title'],
			'site_command'     => $this->command,
			'proxy_type'       => $this->proxy_type,
			'cache_type'       => $this->cache_type,
			'site_path'        => $this->site['root'],
			'db_name'          => $this->db['name'],
			'db_user'          => $this->db['user'],
			'db_host'          => $this->db['host'],
			'db_port'          => isset( $this->db['port'] ) ? $this->db['port'] : '',
			'db_password'      => $this->db['pass'],
			'db_root_password' => $this->db['root_pass'],
			'email'            => $this->site['email'],
			'is_ssl'           => (int) $this->le,
			'created_on'       => date( 'Y-m-d H:i:s', time() ),
		];

		if ( ! $this->skip_install ) {
			$data['wp_user'] = $this->site['user'];
			$data['wp_pass'] = $this->site['pass'];
		}

		try {
			if ( EE::db()::insert( $data ) ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site['name'] = EE\Utils\remove_trailing_slash( $args[0] );

		if ( EE::db()::site_in_db( $this->site['name'] ) ) {

			$db_select = EE::db()::select( [], [ 'sitename' => $this->site['name'] ], 'sites', 1 );

			$this->site['type']    = $db_select['site_type'];
			$this->site['title']   = $db_select['site_title'];
			$this->proxy_type      = $db_select['proxy_type'];
			$this->cache_type      = $db_select['cache_type'];
			$this->site['root']    = $db_select['site_path'];
			$this->db['user']      = $db_select['db_user'];
			$this->db['name']      = $db_select['db_name'];
			$this->db['host']      = $db_select['db_host'];
			$this->db['port']      = $db_select['db_port'];
			$this->db['pass']      = $db_select['db_password'];
			$this->db['root_pass'] = $db_select['db_root_password'];
			$this->site['user']    = $db_select['wp_user'];
			$this->site['pass']    = $db_select['wp_pass'];
			$this->site['email']   = $db_select['email'];
			$this->le              = $db_select['is_ssl'];

		} else {
			EE::error( "Site $this->site['name'] does not exist." );
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
		$reload_commands['php'] = 'php kill -USR2 1';
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands );
	}


	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {
		EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site['name'], $this->site['root'] );
		EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {
		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site['name'], $this->site['root'] );
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
