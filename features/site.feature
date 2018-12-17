Feature: Site Command

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

  Scenario: Check site create sub command is present
    When I run 'bin/ee site create'
    Then STDOUT should return exactly
    """
    usage: ee site create <site-name> [--ssl=<value>] [--wildcard] [--type=<type>] [--skip-status-check]
    """

  Scenario: Create wp site successfully
    When I run 'bin/ee site create wp.test --type=wp'
    Then After delay of 5 seconds
      And The site 'wp.test' should have webroot
      And The site 'wp.test' should have WordPress
      And Request on 'wp.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wp cache site successfully
    When I run 'bin/ee site create wpcache.test --type=wp --cache'
    Then After delay of 5 seconds
    And The site 'wpcache.test' should have webroot
    And The site 'wpcache.test' should have WordPress
    And Request on 'wpcache.test' should contain following headers:
      | header           |
      | HTTP/1.1 200 OK  |

  Scenario: Create wpsubdir site successfully
    When I run 'bin/ee site create wpsubdir.test --type=wp --mu=subdir'
      And I create subsite '1' in 'wpsubdir.test'
    Then After delay of 5 seconds
      And The site 'wpsubdir.test' should have webroot
      And The site 'wpsubdir.test' should have WordPress
      And The site 'wpsubdir.test' should be 'subdir' multisite
      And Request on 'wpsubdir.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wpsubdom site successfully
    When I run 'bin/ee site create wpsubdom.test --type=wp --mu=subdom'
      And I create subsite '1' in 'wpsubdom.test'
    Then After delay of 5 seconds
      And The site 'wpsubdom.test' should have webroot
      And The site 'wpsubdom.test' should have WordPress
      And The site 'wpsubdom.test' should be 'subdomain' multisite
      And Request on 'wpsubdom.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: List the sites
    When I run 'bin/ee site list --format=text'
    Then STDOUT should return exactly
    """
    wp.test
    wpcache.test
    wpsubdir.test
    wpsubdom.test
    """

  Scenario: Check site disable sub command is present
    When I run 'bin/ee site disable'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site disable command on.
    Either pass it as an argument: `ee site disable <site-name>`
    or run `ee site disable` from inside the site folder.
    """

  Scenario: Disable the site
    When I run 'bin/ee site disable wp.test'
    Then STDOUT should return exactly
    """
    Disabling site wp.test.
    Success: Site wp.test disabled.
    """
    And Request on 'wp.test' should contain following headers:
        | header                                       |
        | HTTP/1.1 503 Service Temporarily Unavailable |

  Scenario: Check site reload sub command is present
    When I run 'bin/ee site reload'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site reload command on.
    Either pass it as an argument: `ee site reload <site-name>`
    or run `ee site reload` from inside the site folder.
    """

  Scenario: Reload site services
    When I run 'bin/ee site reload wp.test'
    Then STDERR should return something like
    """
    Error: Site wp.test is not enabled. Use `ee site enable wp.test` to enable it.
    """

  Scenario: Check site enable sub command is present
    When I run 'bin/ee site enable'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site enable command on.
    Either pass it as an argument: `ee site enable <site-name>`
    or run `ee site enable` from inside the site folder.
    """

  Scenario: Enable the site
    When I run 'bin/ee site enable wp.test'
    Then STDOUT should return exactly
    """
    Enabling site wp.test.
    Success: Site wp.test enabled.
    Running post enable configurations.
    Starting site's services.
    Success: Post enable configurations complete.
    """
    And Request on 'wp.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Check site info sub command is present
    When I run 'bin/ee site info'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site info command on.
    Either pass it as an argument: `ee site info <site-name>`
    or run `ee site info` from inside the site folder.
    """

  Scenario: Details of the site uing site info command
    When I run 'bin/ee site info wp.test'
    Then STDOUT should return something like
    """
    | Site               | http://wp.test
    """

  Scenario: Reload site services
    When I run 'bin/ee site reload wp.test'
    Then STDOUT should return something like
    """
    Reloading nginx
    """

  Scenario: Reload site nginx services
    When I run 'bin/ee site reload wp.test --nginx'
    Then STDOUT should return something like
    """
    Reloading nginx
    """

  Scenario: Check site delete sub command is present
    When I run 'bin/ee site delete'
    Then STDOUT should return exactly
    """
    usage: ee site delete <site-name> [--yes]
    """

  Scenario: Delete the sites
    When I run 'bin/ee site delete wp.test --yes'
    Then STDOUT should return something like
    """
    Site wp.test deleted.
    """
      And STDERR should return exactly
      """
      """
      And The 'wp.test' db entry should be removed
      And The 'wp.test' webroot should be removed
      And Following containers of site 'wp.test' should be removed:
        | container  |
        | nginx      |
        | php        |
        | db         |
        | redis      |
        | phpmyadmin |

  Scenario: Create WordPress site with local DB and global redis
    When I run 'bin/ee site create wp-local-db.test --cache --type=wp --local-db'
    Then After delay of 2 seconds
    And The site 'wp-local-db.test' should have webroot
    And The site 'wp-local-db.test' should have WordPress
    And Request on 'wp-local-db.test' should contain following headers:
      | header          |
      | HTTP/1.1 200 OK |
    And Check global redis cache for 'wp-local-db.test'

  Scenario: Create WordPress site with local redis and global db
    When I run 'bin/ee site create wp-local-redis.test --cache --type=wp --with-local-redis'
    Then After delay of 2 seconds
    And The site 'wp-local-redis.test' should have webroot
    And The site 'wp-local-redis.test' should have WordPress
    And Request on 'wp-local-redis.test' should contain following headers:
      | header          |
      | HTTP/1.1 200 OK |
    And Check local redis cache for 'wp-local-redis.test'

  Scenario: Create WordPress site with local DB and local redis
    When I run 'bin/ee site create wp-local-db-redis.test --cache --type=wp --local-db --with-local-redis'
    Then After delay of 2 seconds
    And The site 'wp-local-db-redis.test' should have webroot
    And The site 'wp-local-db-redis.test' should have WordPress
    And Request on 'wp-local-db-redis.test' should contain following headers:
      | header          |
      | HTTP/1.1 200 OK |
    And Check local redis cache for 'wp-local-db-redis.test'

  Scenario: Create WordPress site with remote DB and local redis
    When I run 'bin/ee site create wp-remote-db-local-redis.test --cache --type=wp --dbuser="root" --dbpass="" --dbhost="localhost" --with-local-redis'
    Then After delay of 2 seconds
    And The site 'wp-remote-db-local-redis.test' should have webroot
    And The site 'wp-remote-db-local-redis.test' should have WordPress
    And Request on 'wp-remote-db-local-redis.test' should contain following headers:
      | header          |
      | HTTP/1.1 200 OK |
    And Check local redis cache for 'wp-remote-db-local-redis.test'

  Scenario: Create WordPress site with remote DB and global redis
    When I run 'bin/ee site create wp-remote-db-global-redis.test --cache --type=wp --dbuser="travis" --dbpass="" --dbhost="127.0.0.1"'
    Then After delay of 2 seconds
    And The site 'wp-remote-db-global-redis.test' should have webroot
    And The site 'wp-remote-db-global-redis.test' should have WordPress
    And Request on 'wp-remote-db-global-redis.test' should contain following headers:
      | header          |
      | HTTP/1.1 200 OK |
    And Check global redis cache for 'wp-remote-db-global-redis.test'
