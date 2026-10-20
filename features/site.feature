Feature: WordPress site lifecycle (create, info, list, enable/disable, restart/reload, delete)

  Scenario: ee executable is command working correctly
    Given 'bin/ee' is installed
    When I run 'bin/ee'
    Then STDOUT should return something like
    """
    NAME

      ee
    """

  Scenario: Check site command is present
    When I run 'bin/ee site'
    Then STDOUT should return something like
    """
    usage: ee site
    """

  Scenario: Help of the wp site type documents every wp create flag
    When I run 'bin/ee help site create --type=wp'
    Then the command should succeed
    And STDOUT should contain:
      """
      Runs the standard WordPress Site installation.
      [--cache]
      [--vip]
      [--mu=<subdir|subdom>]
      [--alias-domains=<domains>]
      [--title=<title>]
      [--admin-user=<admin-user>]
      [--admin-email=<admin-email>]
      [--admin-pass=<admin-pass>]
      [--allow-insecure-pass]
      [--local-db]
      [--with-local-redis]
      [--public-dir]
      [--php=<php-version>]
      [--dbhost=<dbhost>]
      [--dbprefix=<dbprefix>]
      [--skip-check]
      [--version=<version>]
      [--skip-content]
      [--skip-install]
      [--skip-status-check]
      [--proxy-cache=<on-or-off>]
      [--force]
      """

  Scenario: Create without a site name prints the usage
    When I run 'bin/ee site create --type=wp'
    Then the command should fail
    And the output should contain:
      """
      usage: ee site create <site-name>
      """

  Scenario: Create a default WordPress site
    When I run 'bin/ee site create bh-site-type-wp-basic.test --type=wp'
    Then the command should succeed
    And STDOUT should contain:
      """
      Starting site creation.
      Configuring project.
      Creating WordPress site bh-site-type-wp-basic.test
      Downloading and configuring WordPress.
      Checking and verifying site-up status.
      Installing WordPress site.
      Success: http://bh-site-type-wp-basic.test has been created successfully!
      Site entry created.
      Creating cron entry
      """
    And STDERR should not contain:
      """
      Error:
      """
    And the site 'bh-site-type-wp-basic.test' should exist
    And the site 'bh-site-type-wp-basic.test' should be enabled
    And the site 'bh-site-type-wp-basic.test' info should contain:
      | field                  | value                               |
      | site_type              | wp                                  |
      | app_sub_type           | wp                                  |
      | site_fs_path           | {SITES_DIR}/bh-site-type-wp-basic.test |
      | site_container_fs_path | /var/www/htdocs                     |
      | site_ssl               | <empty>                             |
      | site_ssl_wildcard      | 0                                   |
      | cache_nginx_browser    | 0                                   |
      | cache_nginx_fullpage   | 0                                   |
      | cache_mysql_query      | 0                                   |
      | cache_app_object       | 0                                   |
      | cache_host             | <empty>                             |
      | php_version            | 8.4                                 |
      | db_host                | global-db                           |
      | db_port                | 3306                                |
      | db_name                | bh_site_type_wp_basic_test          |
      | db_user                | /^bh-site-type-wp-basic\.test-.{6}$/ |
      | db_password            | <not empty>                         |
      | db_root_password       | <empty>                             |
      | app_admin_url          | bh-site-type-wp-basic.test          |
      | app_admin_email        | admin@bh-site-type-wp-basic.test    |
      | app_admin_username     | /^wp-user-\w{4,6}$/                 |
      | app_admin_password     | /^.{18}$/                           |
      | app_mail               | postfix                             |
      | alias_domains          | bh-site-type-wp-basic.test          |
      | mailhog_enabled        | 0                                   |
    # Webroot layout and config files.
    And The site 'bh-site-type-wp-basic.test' should have webroot
    And The site 'bh-site-type-wp-basic.test' should have WordPress
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/app/htdocs/wp-load.php' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/app/htdocs/wp-config.php' should not exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/app/htdocs/check.html' should not exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/app/htdocs/wp-cli.yml' should not exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/config/nginx/conf.d/main.conf' should contain:
      """
      server bh-site-type-wp-basic.test_php:9000;
      server_name bh-site-type-wp-basic.test;
      root /var/www/htdocs;
      # PHP NGINX CONFIGURATION
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/config/nginx/conf.d/main.conf' should not contain:
      """
      # Redis NGINX CONFIGURATION
      # WPSUBDIRECTORY NGINX CONFIGURATION
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/config/nginx/custom/user.conf' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/config/nginx/custom/admin-tools.conf' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/config/php/php/conf.d/custom.ini' should contain:
      """
      upload_max_filesize = 100M
      sendmail_path = /usr/bin/msmtp -t
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/.env' should contain:
      """
      VIRTUAL_HOST=bh-site-type-wp-basic.test
      WORDPRESS_DB_HOST=global-db:3306
      WORDPRESS_DB_NAME=bh_site_type_wp_basic_test
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/.env' should not contain:
      """
      MYSQL_ROOT_PASSWORD
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/docker-compose.yml' should contain:
      """
      image: easyengine/php8.4:
      - VIRTUAL_HOST
      - VIRTUAL_PATH=/
      - HSTS=off
      global-backend-network:
      HTTPS_METHOD=nohttps
      """
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/docker-compose.yml' should not contain:
      """
      easyengine/mariadb
      easyengine/redis
      """
    # Containers, volumes and networks.
    And the site 'bh-site-type-wp-basic.test' should have 3 running containers
    And the 'nginx' container of site 'bh-site-type-wp-basic.test' should be running
    And the 'php' container of site 'bh-site-type-wp-basic.test' should be running
    And the 'postfix' container of site 'bh-site-type-wp-basic.test' should be running
    And the 'mailhog' container of site 'bh-site-type-wp-basic.test' should not exist
    And the 'db' container of site 'bh-site-type-wp-basic.test' should not exist
    And the 'redis' container of site 'bh-site-type-wp-basic.test' should not exist
    And the 'php' container of site 'bh-site-type-wp-basic.test' should use image 'easyengine/php8.4:'
    And the 'php' container of site 'bh-site-type-wp-basic.test' should have restart policy 'always'
    And the 'php' container of site 'bh-site-type-wp-basic.test' should be connected to network 'ee-global-backend-network'
    And the 'nginx' container of site 'bh-site-type-wp-basic.test' should be connected to network 'ee-global-frontend-network'
    And the 'nginx' container of site 'bh-site-type-wp-basic.test' should not be connected to network 'ee-global-backend-network'
    And the docker network 'bh-site-type-wp-basic.test' should exist
    And the site 'bh-site-type-wp-basic.test' should have 8 docker volumes
    # Global DB, hosts entry, cron and proxy side effects.
    And the global database should have a database named 'bh_site_type_wp_basic_test'
    And the global database should have the user of site 'bh-site-type-wp-basic.test'
    And the hosts file should contain 'bh-site-type-wp-basic.test'
    And the EE database table 'cron' should have 1 rows where 'site_url' is 'bh-site-type-wp-basic.test'
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-basic.test-redirect.conf' should not exist
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-basic.test.conf' should not exist
    # WordPress itself.
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 200
    And the response body should contain 'bh-site-type-wp-basic.test'
    And a GET request to 'http://bh-site-type-wp-basic.test/wp-login.php' should return 200
    And a GET request to 'http://www.bh-site-type-wp-basic.test/' should return 503

  Scenario: WordPress configuration written by create
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run WP-CLI 'core is-installed' on site 'bh-site-type-wp-basic.test'
    Then the command should succeed
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-basic.test'
    Then STDOUT should contain:
      """
      http://bh-site-type-wp-basic.test
      """
    When I run WP-CLI 'option get permalink_structure' on site 'bh-site-type-wp-basic.test'
    Then STDOUT should contain:
      """
      /%year%/%monthnum%/%day%/%postname%/
      """
    When I run WP-CLI 'config get DB_HOST' on site 'bh-site-type-wp-basic.test'
    Then STDOUT should contain:
      """
      global-db:3306
      """
    When I run WP-CLI 'config get DB_NAME' on site 'bh-site-type-wp-basic.test'
    Then STDOUT should contain:
      """
      bh_site_type_wp_basic_test
      """
    When I run WP-CLI 'config get WP_DEBUG_DISPLAY' on site 'bh-site-type-wp-basic.test'
    Then the command should succeed
    When I run WP-CLI 'config has COOKIE_DOMAIN' on site 'bh-site-type-wp-basic.test'
    Then the command should fail
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/app/wp-config.php' should contain:
      """
      $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
      define( 'WP_DEBUG_LOG', false );
      define( 'SCRIPT_DEBUG', false );
      """
    When I run WP-CLI 'user list --role=administrator --field=user_login' on site 'bh-site-type-wp-basic.test'
    Then STDOUT should match '/^wp-user-\w{4,6}$/m'

  Scenario: WordPress hardening rules of the generated nginx config
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    Then a GET request to 'http://bh-site-type-wp-basic.test/readme.html' should return 403
    And a GET request to 'http://bh-site-type-wp-basic.test/license.txt' should return 403
    And a GET request to 'http://bh-site-type-wp-basic.test/wp-config.txt' should return 403
    And a GET request to 'http://bh-site-type-wp-basic.test/.htaccess' should return 403
    And a GET request to 'http://bh-site-type-wp-basic.test/backup.sql' should return 403
    And a GET request to 'http://bh-site-type-wp-basic.test/wp-content/uploads/evil.php' should return 403

  Scenario: Creating an existing site fails and leaves it untouched
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site create bh-site-type-wp-basic.test --type=wp'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-basic.test already exists.
      """
    And the site 'bh-site-type-wp-basic.test' should be enabled
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 200

  Scenario: Site info as a table
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site info bh-site-type-wp-basic.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      http://bh-site-type-wp-basic.test
      {SITES_DIR}/bh-site-type-wp-basic.test
      Site Title
      WordPress Username
      WordPress Password
      Alias Domains
      None
      global-db
      bh_site_type_wp_basic_test
      admin@bh-site-type-wp-basic.test
      Not Enabled
      Proxy Cache
      Off
      """
    And STDOUT should not contain:
      """
      DB Root Password
      SSL Wildcard
      Access admin-tools
      """

  Scenario: Site info as JSON
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site info bh-site-type-wp-basic.test --format=json'
    Then the command should succeed
    And STDOUT should be valid json
    And STDOUT should contain:
      """
      "site_url":"bh-site-type-wp-basic.test"
      "site_type":"wp"
      """

  Scenario: Site info detects the site from the current directory
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When From the directory '{SITES_DIR}/bh-site-type-wp-basic.test/app' I run 'bin/ee site info'
    Then the command should succeed
    And STDOUT should contain:
      """
      http://bh-site-type-wp-basic.test
      """

  Scenario: Site info of an unknown site fails
    When I run 'bin/ee site info bh-site-type-wp-unknown.test'
    Then the command should fail
    And STDERR should contain:
      """
      Could not find the site you wish to run site info command on.
      """

  Scenario: Update of an unknown site fails
    When I run 'bin/ee site update bh-site-type-wp-unknown.test --php=8.3'
    Then the command should fail
    And STDERR should contain:
      """
      Could not find the site you wish to run site update command on.
      """

  Scenario: Invalid info format is rejected
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site info bh-site-type-wp-basic.test --format=yaml'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'format'
      """

  Scenario: List shows the site in every format
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site list --format=text'
    Then STDOUT should contain:
      """
      bh-site-type-wp-basic.test
      """
    When I run 'bin/ee site list --format=json'
    Then STDOUT should be valid json
    And STDOUT should contain:
      """
      {"site":"bh-site-type-wp-basic.test","status":"enabled"}
      """
    When I run 'bin/ee site list --enabled --format=text'
    Then STDOUT should contain:
      """
      bh-site-type-wp-basic.test
      """

  Scenario: Cache clean on a site without cache fails
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site clean bh-site-type-wp-basic.test'
    Then the command should fail
    And STDERR should contain:
      """
      Site object cache is not enabled.
      Site page cache is not enabled.
      """
    When I run 'bin/ee site clean bh-site-type-wp-basic.test --proxy'
    Then the command should fail
    And STDERR should contain:
      """
      Proxy cache is not enabled on site.
      """

  Scenario: Share without an active share fails before touching WordPress
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site share bh-site-type-wp-basic.test --disable'
    Then the command should fail
    And STDERR should contain:
      """
      bh-site-type-wp-basic.test does not have active share running.
      """
    And STDOUT should not contain:
      """
      Running additional WordPress configurations.
      """

  Scenario: Restart and reload the WordPress containers
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    And I note the start time of the 'php' container of site 'bh-site-type-wp-basic.test'
    And I note the start time of the 'nginx' container of site 'bh-site-type-wp-basic.test'
    When I run 'bin/ee site restart bh-site-type-wp-basic.test --php'
    Then the command should succeed
    And the 'php' container of site 'bh-site-type-wp-basic.test' should have been restarted
    When I run 'bin/ee site restart bh-site-type-wp-basic.test --nginx'
    Then the command should succeed
    And the 'nginx' container of site 'bh-site-type-wp-basic.test' should have been restarted
    When I run 'bin/ee site restart bh-site-type-wp-basic.test'
    Then the command should succeed
    When I run 'bin/ee site restart bh-site-type-wp-basic.test --all'
    Then the command should succeed
    When I run 'bin/ee site reload bh-site-type-wp-basic.test --php'
    Then the command should succeed
    When I run 'bin/ee site reload bh-site-type-wp-basic.test --nginx'
    Then the command should succeed
    When I run 'bin/ee site reload bh-site-type-wp-basic.test'
    Then the command should succeed
    When I run 'bin/ee site reload bh-site-type-wp-basic.test --all'
    Then the command should succeed
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 200 within 60 seconds

  Scenario: Refresh regenerates docker-compose.yml and keeps the site working
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run '/bin/bash -c "echo \# stale >> {SITES_DIR}/bh-site-type-wp-basic.test/docker-compose.yml"'
    And I run 'bin/ee site refresh bh-site-type-wp-basic.test'
    Then the command should succeed
    And the file '{SITES_DIR}/bh-site-type-wp-basic.test/docker-compose.yml' should not contain:
      """
      # stale
      """
    And the site 'bh-site-type-wp-basic.test' should have 3 running containers
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 200 within 60 seconds

  Scenario: Disable and enable a WordPress site
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site disable bh-site-type-wp-basic.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      Site bh-site-type-wp-basic.test disabled.
      """
    And the site 'bh-site-type-wp-basic.test' should be disabled
    And the site 'bh-site-type-wp-basic.test' should have 0 running containers
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 503 within 30 seconds
    When I run 'bin/ee site list --disabled --format=text'
    Then STDOUT should contain:
      """
      bh-site-type-wp-basic.test
      """
    # Info works on a disabled site, update/restart/reload refuse it.
    When I run 'bin/ee site info bh-site-type-wp-basic.test'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-type-wp-basic.test --php=8.3'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-basic.test is not enabled. Use `ee site enable bh-site-type-wp-basic.test` to enable it.
      """
    When I run 'bin/ee site restart bh-site-type-wp-basic.test'
    Then the command should fail
    When I run 'bin/ee site reload bh-site-type-wp-basic.test'
    Then the command should fail
    When I run 'bin/ee site enable bh-site-type-wp-basic.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      Site bh-site-type-wp-basic.test enabled.
      Post enable configurations complete.
      """
    And the site 'bh-site-type-wp-basic.test' should be enabled
    And the site 'bh-site-type-wp-basic.test' should have 3 running containers
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 200 within 60 seconds
    When I run 'bin/ee site enable bh-site-type-wp-basic.test'
    Then the command should fail
    And STDERR should contain:
      """
      bh-site-type-wp-basic.test is already enabled!
      """

  Scenario: Declining the delete confirmation keeps the site
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run '/bin/bash -c "echo n | bin/ee site delete bh-site-type-wp-basic.test"'
    Then STDOUT should contain:
      """
      Are you sure you want to delete bh-site-type-wp-basic.test?
      """
    And the site 'bh-site-type-wp-basic.test' should exist
    And the 'php' container of site 'bh-site-type-wp-basic.test' should be running

  Scenario: Delete removes every trace of the site
    Given a WordPress site 'bh-site-type-wp-basic.test' exists
    When I run 'bin/ee site delete bh-site-type-wp-basic.test --yes'
    Then the command should succeed
    And STDOUT should contain:
      """
      Site bh-site-type-wp-basic.test deleted.
      """
    And STDERR should not contain:
      """
      Error:
      """
    And The 'bh-site-type-wp-basic.test' db entry should be removed
    And The 'bh-site-type-wp-basic.test' webroot should be removed
    And Following containers of site 'bh-site-type-wp-basic.test' should be removed:
      | container |
      | nginx     |
      | php       |
      | postfix   |
      | mailhog   |
    And the site 'bh-site-type-wp-basic.test' should have 0 docker volumes
    And the docker network 'bh-site-type-wp-basic.test' should not exist
    And the global database should not have a database named 'bh_site_type_wp_basic_test'
    And the global database should not have users starting with 'bh-site-type-wp-basic.test-'
    And the hosts file should not contain 'bh-site-type-wp-basic.test'
    And the EE database table 'cron' should have 0 rows where 'site_url' is 'bh-site-type-wp-basic.test'
    And a GET request to 'http://bh-site-type-wp-basic.test/' should return 503

  Scenario: Deleting a missing site fails
    When I run 'bin/ee site delete bh-site-type-wp-missing.test --yes'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-missing.test does not exist.
      """
