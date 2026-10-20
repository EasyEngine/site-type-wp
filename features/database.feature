Feature: Database options of WordPress sites (global DB flags, --local-db)

  Scenario: Custom database name, user, password, prefix, charset and collation on the global DB
    When I run 'bin/ee site create bh-site-type-wp-dbopts.test --type=wp --dbname=bh_site_type_wp_customdb --dbuser=bh_site_type_wp_customuser --dbpass=BhDbPass123 --dbprefix=bhp_ --dbcharset=utf8 --dbcollate=utf8_general_ci --skip-check'
    Then the command should succeed
    And the site 'bh-site-type-wp-dbopts.test' info should contain:
      | field       | value                      |
      | db_host     | global-db                  |
      | db_name     | bh_site_type_wp_customdb   |
      | db_user     | bh_site_type_wp_customuser |
      | db_password | BhDbPass123                |
    And the global database should have a database named 'bh_site_type_wp_customdb'
    And the global database should have a user named 'bh_site_type_wp_customuser'
    And the global database 'bh_site_type_wp_customdb' should have a table named 'bhp_options'
    And the global database should not have a database named 'bh_site_type_wp_dbopts_test'
    And the file '{SITES_DIR}/bh-site-type-wp-dbopts.test/.env' should contain:
      """
      WORDPRESS_DB_USER=bh_site_type_wp_customuser
      WORDPRESS_DB_NAME=bh_site_type_wp_customdb
      WORDPRESS_DB_PASSWORD=BhDbPass123
      """
    When I run WP-CLI 'config get table_prefix' on site 'bh-site-type-wp-dbopts.test'
    Then STDOUT should contain:
      """
      bhp_
      """
    When I run WP-CLI 'config get DB_CHARSET' on site 'bh-site-type-wp-dbopts.test'
    Then STDOUT should match '/^utf8$/m'
    When I run WP-CLI 'config get DB_COLLATE' on site 'bh-site-type-wp-dbopts.test'
    Then STDOUT should contain:
      """
      utf8_general_ci
      """
    When I run WP-CLI 'config get DB_USER' on site 'bh-site-type-wp-dbopts.test'
    Then STDOUT should contain:
      """
      bh_site_type_wp_customuser
      """
    And a GET request to 'http://bh-site-type-wp-dbopts.test/' should return 200

  Scenario: Deleting the site drops its custom database and user
    Given a WordPress site 'bh-site-type-wp-dbopts.test' exists created with '--dbname=bh_site_type_wp_customdb --dbuser=bh_site_type_wp_customuser --dbpass=BhDbPass123'
    When I run 'bin/ee site delete bh-site-type-wp-dbopts.test --yes'
    Then the command should succeed
    And the global database should not have a database named 'bh_site_type_wp_customdb'
    And the global database should not have a user named 'bh_site_type_wp_customuser'

  Scenario: Create a site with a local database container
    When I run 'bin/ee site create bh-site-type-wp-localdb.test --type=wp --local-db'
    Then the command should succeed
    And the site 'bh-site-type-wp-localdb.test' info should contain:
      | field            | value                         |
      | db_host          | db                            |
      | db_name          | bh_site_type_wp_localdb_test  |
      | db_root_password | <not empty>                   |
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should be running
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should use image 'easyengine/mariadb:'
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should have restart policy 'always'
    And the site 'bh-site-type-wp-localdb.test' should have 4 running containers
    And the site 'bh-site-type-wp-localdb.test' should have 11 docker volumes
    And the 'php' container of site 'bh-site-type-wp-localdb.test' should not be connected to network 'ee-global-backend-network'
    And the global database should not have a database named 'bh_site_type_wp_localdb_test'
    And the file '{SITES_DIR}/bh-site-type-wp-localdb.test/.env' should contain:
      """
      MYSQL_ROOT_PASSWORD=
      MYSQL_DATABASE=bh_site_type_wp_localdb_test
      WORDPRESS_DB_HOST=db
      """
    And the file '{SITES_DIR}/bh-site-type-wp-localdb.test/docker-compose.yml' should contain:
      """
      image: easyengine/mariadb:
      - db
      """
    When I run WP-CLI 'config get DB_HOST' on site 'bh-site-type-wp-localdb.test'
    Then STDOUT should contain:
      """
      db:3306
      """
    When I run 'bin/ee site info bh-site-type-wp-localdb.test'
    Then STDOUT should contain:
      """
      DB Root Password
      """
    And a GET request to 'http://bh-site-type-wp-localdb.test/' should return 200

  Scenario: Restart of a local-db site includes the db container
    Given a WordPress site 'bh-site-type-wp-localdb.test' exists created with '--local-db'
    And I note the start time of the 'db' container of site 'bh-site-type-wp-localdb.test'
    When I run 'bin/ee site restart bh-site-type-wp-localdb.test --db'
    Then the command should succeed
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should have been restarted
    And a GET request to 'http://bh-site-type-wp-localdb.test/' should return 200 within 90 seconds

  Scenario: Disable and enable keep the local database
    Given a WordPress site 'bh-site-type-wp-localdb.test' exists created with '--local-db'
    When I run 'bin/ee site disable bh-site-type-wp-localdb.test'
    Then the command should succeed
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should not exist
    When I run 'bin/ee site enable bh-site-type-wp-localdb.test'
    Then the command should succeed
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should be running
    And a GET request to 'http://bh-site-type-wp-localdb.test/' should return 200 within 90 seconds

  Scenario: Deleting a local-db site removes the db container and volumes
    Given a WordPress site 'bh-site-type-wp-localdb.test' exists created with '--local-db'
    When I run 'bin/ee site delete bh-site-type-wp-localdb.test --yes'
    Then the command should succeed
    And the 'db' container of site 'bh-site-type-wp-localdb.test' should not exist
    And the site 'bh-site-type-wp-localdb.test' should have 0 docker volumes

  Scenario: Remote database host requires credentials
    When I run 'bin/ee site create bh-site-type-wp-remote.test --type=wp --dbhost=203.0.113.10'
    Then the command should fail
    And STDERR should contain:
      """
      `--dbuser` and `--dbpass` are required for remote db host.
      """
    And the site 'bh-site-type-wp-remote.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-remote.test' should not exist

  Scenario: Unreachable remote database rolls the site back
    When I run 'bin/ee site create bh-site-type-wp-remote.test --type=wp --dbhost={DOCKER_BRIDGE_IP}:1 --dbuser=bh --dbpass=bh'
    Then the command should fail
    And STDERR should contain:
      """
      Unable to connect to remote db
      Initiating clean-up.
      """
    And the site 'bh-site-type-wp-remote.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-remote.test' should not exist

  # WPT-1: `a-b.test` and `a.b.test` both map to the default name `a_b_test` (and to the same volumes, PHPT-4). The create is
  # refused before the database step, and a failed create only drops the database and user it created itself.
  Scenario: A database name collision does not drop the other site's database
    Given a WordPress site 'bh-site-type-wp-db-c.test' exists created with '--skip-install'
    And the global database should have a database named 'bh_site_type_wp_db_c_test'
    When I run 'bin/ee site create bh-site-type-wp-db.c.test --type=wp --skip-install'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-db.c.test would share docker volumes (bhsitetypewpdbctest_*) with the existing site bh-site-type-wp-db-c.test.
      """
    And the site 'bh-site-type-wp-db.c.test' should not exist
    And the global database should have a database named 'bh_site_type_wp_db_c_test'

  # WPT-1: --dbuser of another site is refused up front instead of being dropped by the rollback.
  Scenario: A database user collision does not drop the other site's user
    Given a WordPress site 'bh-site-type-wp-dbuser.test' exists created with '--dbuser=bh_site_type_wp_shareduser --dbpass=BhDbPass123 --skip-install'
    When I run 'bin/ee site create bh-site-type-wp-dbuser2.test --type=wp --dbuser=bh_site_type_wp_shareduser --dbpass=BhDbPass123 --skip-install'
    Then the command should fail
    And STDERR should contain:
      """
      Database user `bh_site_type_wp_shareduser` is already used by site bh-site-type-wp-dbuser.test on the global database.
      """
    And the site 'bh-site-type-wp-dbuser2.test' should not exist
    And the global database should have a user named 'bh_site_type_wp_shareduser'
    And the global database should have a database named 'bh_site_type_wp_dbuser_test'

  # WPT-8: the webroot check runs before the database step, so nothing is created.
  Scenario: An existing webroot aborts the create without leaking database objects
    Given I run 'mkdir -p {SITES_DIR}/bh-site-type-wp-stale.test'
    And I run 'touch {SITES_DIR}/bh-site-type-wp-stale.test/keep-me'
    When I run 'bin/ee site create bh-site-type-wp-stale.test --type=wp'
    Then the command should fail
    And STDERR should contain:
      """
      Webroot directory for site bh-site-type-wp-stale.test already exists.
      """
    And the file '{SITES_DIR}/bh-site-type-wp-stale.test/keep-me' should exist
    And the site 'bh-site-type-wp-stale.test' should not exist
    And the global database should not have a database named 'bh_site_type_wp_stale_test'
    And the global database should not have users starting with 'bh-site-type-wp-stale.test-'

  # WPT-1: a failed --ssl=custom validation rolls back before any DB work and must not drop the colliding site's default-named database.
  Scenario: A failed custom-SSL create does not drop another site's database
    Given a WordPress site 'bh-site-type-wp-db-s.test' exists created with '--skip-install'
    And the global database should have a database named 'bh_site_type_wp_db_s_test'
    When I run 'bin/ee site create bh-site-type-wp-db.s.test --type=wp --ssl=custom --ssl-key=/nonexistent/bh.key --ssl-crt=/nonexistent/bh.crt'
    Then the command should fail
    And STDERR should contain:
      """
      ssl-key OR ssl-crt path does not exist
      """
    And the site 'bh-site-type-wp-db.s.test' should not exist
    And the global database should have a database named 'bh_site_type_wp_db_s_test'
