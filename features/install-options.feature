Feature: WordPress install options (title/admin flags, locale, version, skip-content, skip-install, public-dir, env)

  Scenario: Custom title and administrator, production environment, URL-style site name
    When I run "bin/ee site create http://BH-SITE-TYPE-WP-Install.test/ --type=wp --title='BH Test Site' --admin-user=bhadmin --admin-email=BH.Admin@Example.test --admin-pass=BhPass1234 --env=production"
    Then the command should succeed
    And the site 'bh-site-type-wp-install.test' should exist
    And the site 'bh-site-type-wp-install.test' info should contain:
      | field              | value                 |
      | app_admin_url      | BH Test Site          |
      | app_admin_username | bhadmin               |
      | app_admin_password | BhPass1234            |
      | app_admin_email    | BH.Admin@Example.test |
      | mailhog_enabled    | 0                     |
    When I run WP-CLI 'option get blogname' on site 'bh-site-type-wp-install.test'
    Then STDOUT should contain:
      """
      BH Test Site
      """
    When I run WP-CLI 'user get bhadmin --field=user_email' on site 'bh-site-type-wp-install.test'
    Then STDOUT should contain:
      """
      BH.Admin@Example.test
      """
    When I run WP-CLI 'user check-password bhadmin BhPass1234' on site 'bh-site-type-wp-install.test'
    Then the command should succeed
    When I run WP-CLI 'config get WP_ENVIRONMENT_TYPE' on site 'bh-site-type-wp-install.test'
    Then STDOUT should contain:
      """
      production
      """
    And the 'mailhog' container of site 'bh-site-type-wp-install.test' should not exist
    When I run 'bin/ee site info bh-site-type-wp-install.test'
    Then STDOUT should contain:
      """
      BH Test Site
      bhadmin
      BhPass1234
      """

  # The message no longer echoes the password (EasyEngine/site-type-wp#244, v4.13.2).
  Scenario Outline: Weak administrator password <pass> is rejected before anything is created
    When I run 'bin/ee site create bh-site-type-wp-weakpass.test --type=wp --admin-pass=<pass>'
    Then the command should fail
    And STDERR should contain:
      """
      Issues found in the admin password:
      """
    And STDERR should contain:
      """
      <message>
      """
    And STDERR should not contain:
      """
      <pass>
      """
    And the site 'bh-site-type-wp-weakpass.test' should not exist
    And the global database should not have a database named 'bh_site_type_wp_weakpass_test'

    Examples:
      | pass     | message                                            |
      | abc1     | Password too short! Must be at least 8 characters long. |
      | 12345678 | Password must include at least one letter!         |
      | abcdefgh | Password must include at least one number!         |

  Scenario: Insecure password allowed, status check skipped, default admin e-mail from --wp-mail
    When I run 'bin/ee site create bh-site-type-wp-insecure.test --type=wp --admin-user=bhweak --admin-pass=abc --allow-insecure-pass --skip-status-check --wp-mail=Owner@Example.Test'
    Then the command should succeed
    And STDOUT should not contain:
      """
      Checking and verifying site-up status.
      """
    And the site 'bh-site-type-wp-insecure.test' info should contain:
      | field              | value              |
      | app_admin_password | abc                |
      | app_admin_email    | owner@example.test |
    When I run WP-CLI 'user check-password bhweak abc' on site 'bh-site-type-wp-insecure.test'
    Then the command should succeed
    And a GET request to 'http://bh-site-type-wp-insecure.test/' should return 200 within 60 seconds

  Scenario: EasyEngine v3 flags create a cached WordPress site
    When I run 'bin/ee site create bh-site-type-wp-ee3.test --wpredis --user=bhee3 --pass=BhEe3pass1 --email=ee3@example.test'
    Then the command should succeed
    And the site 'bh-site-type-wp-ee3.test' info should contain:
      | field                | value            |
      | site_type            | wp               |
      | cache_nginx_fullpage | 1                |
      | cache_host           | global-redis     |
      | app_admin_username   | bhee3            |
      | app_admin_password   | BhEe3pass1       |
      | app_admin_email      | ee3@example.test |

  Scenario: Removed EasyEngine v3 cache flags are rejected
    When I run 'bin/ee site create bh-site-type-wp-w3tc.test --type=wp --w3tc'
    Then the command should fail
    # The dedicated "no longer supported in EE v4" message is unreachable.
    And STDERR should contain:
      """
      unknown --w3tc parameter
      """
    And the site 'bh-site-type-wp-w3tc.test' should not exist

  Scenario: Localized install on a staging server enables mailhog
    When I run 'bin/ee site create bh-site-type-wp-locale.test --type=wp --locale=de_DE --env=staging'
    Then the command should succeed
    And STDOUT should contain:
      """
      Enabling mailhog as this is a staging server.
      """
    And the file '{SITES_DIR}/bh-site-type-wp-locale.test/app/htdocs/wp-includes/version.php' should contain:
      """
      $wp_local_package = 'de_DE';
      """
    And a GET request to 'http://bh-site-type-wp-locale.test/' should return 200
    # The translation's html_lang_attribute decides between de and de-DE.
    And the response body should contain 'lang="de'
    And the 'mailhog' container of site 'bh-site-type-wp-locale.test' should be running
    And the site 'bh-site-type-wp-locale.test' info should contain:
      | field           | value |
      | mailhog_enabled | 1     |
    When I run WP-CLI 'config get WP_ENVIRONMENT_TYPE' on site 'bh-site-type-wp-locale.test'
    Then STDOUT should contain:
      """
      staging
      """

  Scenario: Install a specific WordPress version
    When I run 'bin/ee site create bh-site-type-wp-version.test --type=wp --version=6.8.3'
    Then the command should succeed
    When I run WP-CLI 'core version' on site 'bh-site-type-wp-version.test'
    Then STDOUT should contain:
      """
      6.8.3
      """
    When I run WP-CLI 'core verify-checksums' on site 'bh-site-type-wp-version.test'
    Then the command should succeed
    And a GET request to 'http://bh-site-type-wp-version.test/' should return 200

  Scenario: Install WordPress without the default themes and plugins
    When I run 'bin/ee site create bh-site-type-wp-nocontent.test --type=wp --skip-content'
    Then the command should succeed
    And the file '{SITES_DIR}/bh-site-type-wp-nocontent.test/app/htdocs/wp-load.php' should exist
    And the directory '{SITES_DIR}/bh-site-type-wp-nocontent.test/app/htdocs/wp-content/plugins/akismet' should not exist
    When I run WP-CLI 'theme list --format=count' on site 'bh-site-type-wp-nocontent.test'
    Then STDOUT should contain:
      """
      0
      """
    When I run WP-CLI 'plugin list --format=count' on site 'bh-site-type-wp-nocontent.test'
    Then STDOUT should contain:
      """
      0
      """

  Scenario: Skip the WordPress install (site name without TLD gets .test)
    When I run 'bin/ee site create bh-site-type-wp-skipinstall --type=wp --skip-install'
    Then the command should succeed
    And STDOUT should not contain:
      """
      Installing WordPress site.
      Checking and verifying site-up status.
      """
    And the site 'bh-site-type-wp-skipinstall.test' should exist
    And the site 'bh-site-type-wp-skipinstall.test' info should contain:
      | field              | value   |
      | app_admin_username | <empty> |
      | app_admin_password | <empty> |
    And The site 'bh-site-type-wp-skipinstall.test' should have WordPress
    And the file '{SITES_DIR}/bh-site-type-wp-skipinstall.test/app/htdocs/wp-load.php' should exist
    And the hosts file should not contain 'bh-site-type-wp-skipinstall.test'
    And the EE database table 'cron' should have 1 rows where 'site_url' is 'bh-site-type-wp-skipinstall.test'
    When I run WP-CLI 'core is-installed' on site 'bh-site-type-wp-skipinstall.test'
    Then the command should fail
    When I send a GET request to 'http://bh-site-type-wp-skipinstall.test/'
    Then the response should have header 'wp-admin/install.php'
    When I run 'bin/ee site info bh-site-type-wp-skipinstall.test'
    Then STDOUT should not contain:
      """
      WordPress Username
      """

  Scenario: Serve WordPress from a public directory inside htdocs
    When I run 'bin/ee site create bh-site-type-wp-pubdir.test --type=wp --public-dir=current'
    Then the command should succeed
    And the site 'bh-site-type-wp-pubdir.test' info should contain:
      | field                  | value                   |
      | site_container_fs_path | /var/www/htdocs/current |
    And the file '{SITES_DIR}/bh-site-type-wp-pubdir.test/app/htdocs/current/wp-load.php' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-pubdir.test/app/htdocs/wp-config.php' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-pubdir.test/app/htdocs/wp-cli.yml' should contain:
      """
      path: current/
      """
    And the file '{SITES_DIR}/bh-site-type-wp-pubdir.test/config/nginx/conf.d/main.conf' should contain:
      """
      root /var/www/htdocs/current;
      """
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-pubdir.test'
    Then STDOUT should contain:
      """
      http://bh-site-type-wp-pubdir.test
      """
    And a GET request to 'http://bh-site-type-wp-pubdir.test/' should return 200

  # Regression check (fixed in v4.13.1 by EasyEngine/site-type-wp#241): WP-CLI 2.12 corrupts WordPress 7.x downloads (PAX long names are truncated), so the latest version fails its checksums.
  Scenario: The latest WordPress download passes its checksums
    Given a WordPress site 'bh-site-type-wp-wp1.test' exists created with '--skip-install'
    When I run WP-CLI 'core verify-checksums' on site 'bh-site-type-wp-wp1.test'
    Then the command should succeed

  # Regression check (fixed in v4.13.2 by EasyEngine/site-type-wp#243 and EasyEngine/site-command#509): shell metacharacters in the site name reach unescaped shell sinks during create.
  # The payload survives lowercasing and the docker-prefix stripping of `.`/`-`: it only creates an empty marker in $HOME.
  Scenario: Shell metacharacters in the site name are rejected
    When I run "bin/ee site create 'bh-site-type-wp-sec1$(cd;>bhsitetypewpsec1pwned).test' --type=wp --skip-install"
    Then the command should fail
    And the file '{HOME}/bhsitetypewpsec1pwned' should not exist
