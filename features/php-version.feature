Feature: PHP versions of WordPress sites (--php on create and update)

  Scenario: Default PHP version and update to another version
    Given a WordPress site 'bh-site-type-wp-php.test' exists
    Then the site 'bh-site-type-wp-php.test' info should contain:
      | field       | value |
      | php_version | 8.4   |
    And the 'php' container of site 'bh-site-type-wp-php.test' should use image 'easyengine/php8.4:'
    When I run 'bin/ee site update bh-site-type-wp-php.test --php=8.2'
    Then the command should succeed
    And STDOUT should contain:
      """
      Starting php version update for: bh-site-type-wp-php.test
      Taking backup of old php config.
      Re-applying previous custom.ini and easyengine.conf changes.
      Success: Updated site bh-site-type-wp-php.test to PHP version: 8.2
      """
    And the site 'bh-site-type-wp-php.test' info should contain:
      | field       | value |
      | php_version | 8.2   |
    And the 'php' container of site 'bh-site-type-wp-php.test' should use image 'easyengine/php8.2:'
    And the directory '{SITES_DIR}/bh-site-type-wp-php.test/.backup/config/php-8.4' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-php.test/config/php/php/conf.d/custom.ini' should contain:
      """
      sendmail_path = /usr/bin/msmtp -t
      """
    When In the 'php' container of site 'bh-site-type-wp-php.test' I run 'php -v'
    Then STDOUT should contain:
      """
      PHP 8.2.
      """
    And a GET request to 'http://bh-site-type-wp-php.test/' should return 200 within 60 seconds

  Scenario: Updating to the current PHP version fails
    Given a WordPress site 'bh-site-type-wp-php.test' exists created with '--php=8.2'
    When I run 'bin/ee site update bh-site-type-wp-php.test --php=8.2'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-php.test is already at PHP version: 8.2
      """

  Scenario: Updating to an unsupported PHP version fails
    Given a WordPress site 'bh-site-type-wp-php.test' exists
    When I run 'bin/ee site update bh-site-type-wp-php.test --php=9.9'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'php'
      """

  Scenario Outline: Create a site on PHP <php>
    When I run 'bin/ee site create bh-site-type-wp-php<name>.test --type=wp --php=<php>'
    Then the command should succeed
    And the site 'bh-site-type-wp-php<name>.test' info should contain:
      | field       | value |
      | php_version | <php> |
    And the 'php' container of site 'bh-site-type-wp-php<name>.test' should use image 'easyengine/<image>:'
    When In the 'php' container of site 'bh-site-type-wp-php<name>.test' I run 'php -v'
    Then STDOUT should contain:
      """
      PHP <runtime>
      """
    And a GET request to 'http://bh-site-type-wp-php<name>.test/' should return 200
    When I run 'bin/ee site delete bh-site-type-wp-php<name>.test --yes'
    Then the command should succeed

    Examples:
      | php    | name   | image   | runtime |
      | 8.3    | 83     | php8.3  | 8.3.    |
      | 8.5    | 85     | php8.5  | 8.5.    |
      | latest | latest | php8.4  | 8.4.    |

  # These pull the older php images (not present on a fresh host) and need an older WordPress for PHP < 7.4.
  @slow
  Scenario Outline: Create a site on legacy PHP <php>
    When I run 'bin/ee site create bh-site-type-wp-php<name>.test --type=wp --php=<php> --version=<wp>'
    Then the command should succeed
    And the 'php' container of site 'bh-site-type-wp-php<name>.test' should use image 'easyengine/php<php>:'
    When In the 'php' container of site 'bh-site-type-wp-php<name>.test' I run 'php -v'
    Then STDOUT should contain:
      """
      PHP <php>.
      """
    And a GET request to 'http://bh-site-type-wp-php<name>.test/' should return 200
    When I run 'bin/ee site delete bh-site-type-wp-php<name>.test --yes'
    Then the command should succeed

    Examples:
      | php | name | wp     |
      | 7.0 | 70   | 6.2.6  |
      | 7.2 | 72   | 6.8.3  |
      | 7.3 | 73   | 6.8.3  |
      | 7.4 | 74   | latest |
      | 8.0 | 80   | latest |
      | 8.1 | 81   | latest |

  # PHP 5.6 uses its own custom.ini template (sendmail instead of msmtp).
  @slow
  Scenario: Create a site on PHP 5.6
    When I run 'bin/ee site create bh-site-type-wp-php56.test --type=wp --php=5.6 --version=6.2.6'
    Then the command should succeed
    And the 'php' container of site 'bh-site-type-wp-php56.test' should use image 'easyengine/php5.6:'
    And the file '{SITES_DIR}/bh-site-type-wp-php56.test/config/php/php/conf.d/custom.ini' should contain:
      """
      sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com
      """
    And a GET request to 'http://bh-site-type-wp-php56.test/' should return 200

  Scenario Outline: Unsupported PHP version <php> is rejected before anything is created
    When I run 'bin/ee site create bh-site-type-wp-phpbad.test --type=wp --php=<php>'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'php'
      """
    And the site 'bh-site-type-wp-phpbad.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-phpbad.test' should not exist

    Examples:
      | php |
      | 7.1 |
      | 9.9 |
      | foo |

  # WPT-4: options are compared loosely, so `--php=8` passes validation ("8" == "8.0") and create builds the image name `easyengine/php8:` (unknown version).
  @known-bug
  Scenario Outline: Loosely matching PHP version <php> is rejected
    When I run 'bin/ee site create bh-site-type-wp-phploose.test --type=wp --php=<php> --skip-install'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'php'
      """
    And the site 'bh-site-type-wp-phploose.test' should not exist

    Examples:
      | php  |
      | 8    |
      | 8.10 |
