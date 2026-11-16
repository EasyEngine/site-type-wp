# Uses a throwaway MariaDB on the host network, bound only to the docker bridge gateway ({DOCKER_BRIDGE_IP}), so that
# both EE's verification container (default bridge) and the site's php container can reach it without publishing a port.
# Requires the host to accept connections from docker networks to that address.
@external-db
Feature: WordPress site on a remote database (--dbhost, --force)

  Scenario: Create a site on a remote database that does not exist yet
    Given a MariaDB server 'bh-site-type-wp-extdb' is listening on '{DOCKER_BRIDGE_IP}' port 33306 with root password 'BhRootPass123'
    When I run 'bin/ee site create bh-site-type-wp-ext.test --type=wp --dbhost={DOCKER_BRIDGE_IP}:33306 --dbuser=root --dbpass=BhRootPass123 --dbname=bh_site_type_wp_ext'
    Then the command should succeed
    And STDOUT should contain:
      """
      Verifying connection to remote database
      Success: Connection to remote db verified
      Database `bh_site_type_wp_ext` does not exist. Attempting to create it.
      """
    And the site 'bh-site-type-wp-ext.test' info should contain:
      | field            | value               |
      | db_host          | {DOCKER_BRIDGE_IP}  |
      | db_port          | 33306               |
      | db_name          | bh_site_type_wp_ext |
      | db_user          | root                |
      | db_root_password | <empty>             |
    And the 'db' container of site 'bh-site-type-wp-ext.test' should not exist
    And the global database should not have a database named 'bh_site_type_wp_ext'
    And the file '{SITES_DIR}/bh-site-type-wp-ext.test/.env' should contain:
      """
      WORDPRESS_DB_HOST={DOCKER_BRIDGE_IP}:33306
      """
    When I run WP-CLI 'config get DB_HOST' on site 'bh-site-type-wp-ext.test'
    Then STDOUT should contain:
      """
      {DOCKER_BRIDGE_IP}:33306
      """
    And a GET request to 'http://bh-site-type-wp-ext.test/' should return 200
    When In the docker container 'bh-site-type-wp-extdb' I run 'mysql -h{DOCKER_BRIDGE_IP} -P33306 -uroot -pBhRootPass123 -N -e "SHOW TABLES FROM bh_site_type_wp_ext"'
    Then STDOUT should contain:
      """
      wp_options
      """
    # Remote databases are never dropped by EE.
    When I run 'bin/ee site delete bh-site-type-wp-ext.test --yes'
    Then the command should succeed
    When In the docker container 'bh-site-type-wp-extdb' I run 'mysql -h{DOCKER_BRIDGE_IP} -P33306 -uroot -pBhRootPass123 -N -e "SHOW TABLES FROM bh_site_type_wp_ext"'
    Then STDOUT should contain:
      """
      wp_options
      """

  Scenario: A remote database that already has tables needs --force
    Given a MariaDB server 'bh-site-type-wp-extdb' is listening on '{DOCKER_BRIDGE_IP}' port 33306 with root password 'BhRootPass123'
    And In the docker container 'bh-site-type-wp-extdb' I run 'mysql -h{DOCKER_BRIDGE_IP} -P33306 -uroot -pBhRootPass123 -e "CREATE DATABASE bh_site_type_wp_ext2; CREATE TABLE bh_site_type_wp_ext2.leftover (id INT)"'
    When I run 'bin/ee site create bh-site-type-wp-ext2.test --type=wp --dbhost={DOCKER_BRIDGE_IP}:33306 --dbuser=root --dbpass=BhRootPass123 --dbname=bh_site_type_wp_ext2'
    Then the command should fail
    And STDERR should contain:
      """
      Some database tables seem to exist in database bh_site_type_wp_ext2. Please backup and reset the database or use `--force` in the site create command to reset it.
      Initiating clean-up.
      """
    And the site 'bh-site-type-wp-ext2.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-ext2.test' should not exist
    # The rollback must not touch the remote database.
    When In the docker container 'bh-site-type-wp-extdb' I run 'mysql -h{DOCKER_BRIDGE_IP} -P33306 -uroot -pBhRootPass123 -N -e "SHOW TABLES FROM bh_site_type_wp_ext2"'
    Then STDOUT should contain:
      """
      leftover
      """
    When I run 'bin/ee site create bh-site-type-wp-ext2.test --type=wp --dbhost={DOCKER_BRIDGE_IP}:33306 --dbuser=root --dbpass=BhRootPass123 --dbname=bh_site_type_wp_ext2 --force'
    Then the command should succeed
    And a GET request to 'http://bh-site-type-wp-ext2.test/' should return 200
    When In the docker container 'bh-site-type-wp-extdb' I run 'mysql -h{DOCKER_BRIDGE_IP} -P33306 -uroot -pBhRootPass123 -N -e "SHOW TABLES FROM bh_site_type_wp_ext2"'
    Then STDOUT should contain:
      """
      wp_options
      """
    And STDOUT should not contain:
      """
      leftover
      """

  Scenario: Wrong remote credentials roll the site back
    Given a MariaDB server 'bh-site-type-wp-extdb' is listening on '{DOCKER_BRIDGE_IP}' port 33306 with root password 'BhRootPass123'
    When I run 'bin/ee site create bh-site-type-wp-ext3.test --type=wp --dbhost={DOCKER_BRIDGE_IP}:33306 --dbuser=root --dbpass=WrongPass123'
    Then the command should fail
    And STDERR should contain:
      """
      Unable to connect to remote db
      """
    And the site 'bh-site-type-wp-ext3.test' should not exist
