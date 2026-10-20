Feature: WordPress multisite (--mu=subdir|subdom)

  Scenario: Create a subdirectory multisite
    When I run 'bin/ee site create bh-site-type-wp-subdir.test --type=wp --mu=subdir'
    Then the command should succeed
    And the site 'bh-site-type-wp-subdir.test' info should contain:
      | field             | value                        |
      | app_sub_type      | subdir                       |
      | site_ssl_wildcard | 0                            |
      | alias_domains     | bh-site-type-wp-subdir.test  |
    And The site 'bh-site-type-wp-subdir.test' should be 'subdir' multisite
    And the file '{SITES_DIR}/bh-site-type-wp-subdir.test/config/nginx/conf.d/main.conf' should contain:
      """
      # WPSUBDIRECTORY NGINX CONFIGURATION
      rewrite ^(/[^/]+)?(/wp-.*) $2 last;
      server_name bh-site-type-wp-subdir.test;
      """
    And the file '{SITES_DIR}/bh-site-type-wp-subdir.test/app/wp-config.php' should contain:
      """
      define( 'COOKIE_DOMAIN', false );
      """
    When I run WP-CLI 'config get MULTISITE' on site 'bh-site-type-wp-subdir.test'
    Then STDOUT should match '/^1$/m'
    When I create subsite 'bhsub1' in 'bh-site-type-wp-subdir.test'
    Then a GET request to 'http://bh-site-type-wp-subdir.test/' should return 200
    And a GET request to 'http://bh-site-type-wp-subdir.test/bhsub1/' should return 200
    And a GET request to 'http://bh-site-type-wp-subdir.test/bhsub1/wp-login.php' should return 200
    And a GET request to 'http://bh-site-type-wp-subdir.test/bhsub1/wp-admin' should return 301
    When I run WP-CLI 'site list --field=url' on site 'bh-site-type-wp-subdir.test'
    Then STDOUT should contain:
      """
      http://bh-site-type-wp-subdir.test/bhsub1/
      """

  Scenario: Create a subdomain multisite
    When I run 'bin/ee site create bh-site-type-wp-subdom.test --type=wp --mu=subdom'
    Then the command should succeed
    # The implicit wildcard alias marks the site as wildcard even without SSL.
    And the site 'bh-site-type-wp-subdom.test' info should contain:
      | field             | value                                                      |
      | app_sub_type      | subdom                                                     |
      | site_ssl_wildcard | 1                                                          |
      | alias_domains     | bh-site-type-wp-subdom.test,*.bh-site-type-wp-subdom.test  |
    And The site 'bh-site-type-wp-subdom.test' should be 'subdomain' multisite
    And the file '{SITES_DIR}/bh-site-type-wp-subdom.test/docker-compose.yml' should contain:
      """
      VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}
      CERT_NAME=${VIRTUAL_HOST}
      """
    And the file '{SITES_DIR}/bh-site-type-wp-subdom.test/config/nginx/conf.d/main.conf' should contain:
      """
      server_name bh-site-type-wp-subdom.test *.bh-site-type-wp-subdom.test;
      """
    And the file '{SITES_DIR}/bh-site-type-wp-subdom.test/config/nginx/conf.d/main.conf' should not contain:
      """
      # WPSUBDIRECTORY NGINX CONFIGURATION
      """
    And the file '{SITES_DIR}/bh-site-type-wp-subdom.test/app/wp-config.php' should contain:
      """
      define( 'COOKIE_DOMAIN', false );
      """
    When I create subsite 'bhsub1' in 'bh-site-type-wp-subdom.test'
    Then a GET request to 'http://bh-site-type-wp-subdom.test/' should return 200
    And a GET request to 'http://bhsub1.bh-site-type-wp-subdom.test/' should return 200 within 30 seconds
    And the response body should contain 'bhsub1.bh-site-type-wp-subdom.test'
    When I run 'bin/ee site info bh-site-type-wp-subdom.test'
    Then STDOUT should contain:
      """
      *.bh-site-type-wp-subdom.test
      """

  Scenario: Unknown multisite type is rejected before anything is created
    When I run 'bin/ee site create bh-site-type-wp-mubad.test --type=wp --mu=subfoo'
    Then the command should fail
    And STDERR should contain:
      """
      Unrecognized multi-site parameter: subfoo. Only `--mu=subdom` and `--mu=subdir` are supported.
      """
    And the site 'bh-site-type-wp-mubad.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-mubad.test' should not exist

  Scenario: Subdomain multisite cannot inherit SSL
    When I run 'bin/ee site create bh-site-type-wp-muinh.test --type=wp --mu=subdom --ssl=inherit'
    Then the command should fail
    And STDERR should contain:
      """
      --wildcard or --mu=subdom flag can not be passed together with --ssl=inherit flag.
      """
    And the site 'bh-site-type-wp-muinh.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-muinh.test' should not exist

  # WPT-8: the inherit/wildcard check runs before the database step, so nothing is created.
  Scenario: A rejected inherit + subdom create leaves nothing in the global database
    When I run 'bin/ee site create bh-site-type-wp-muinh2.test --type=wp --mu=subdom --ssl=inherit'
    Then the command should fail
    And the global database should not have a database named 'bh_site_type_wp_muinh2_test'
    And the global database should not have users starting with 'bh-site-type-wp-muinh2.test-'

  # AUTH-5 regression: subdom create didn't check its implicit *.site against existing aliases (fixed by site-type-wp #239).
  Scenario: Subdomain multisite whose wildcard is already an alias of another site is rejected
    Given a WordPress site 'bh-site-type-wp-auth5owner.test' exists created with "--skip-install --alias-domains='*.bh-site-type-wp-auth5.test'"
    When I run 'bin/ee site create bh-site-type-wp-auth5.test --type=wp --mu=subdom --skip-install'
    Then the command should fail
    And STDERR should contain:
      """
      is already an alias domain of site bh-site-type-wp-auth5owner.test
      """
    And the site 'bh-site-type-wp-auth5.test' should not exist
