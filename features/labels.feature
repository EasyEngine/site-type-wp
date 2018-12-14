Feature: Container Labels


  Scenario: All easyengine containers are tagged
    Given I run "bin/ee site create labels.test --type=wp"
    Then There should be 5 containers with labels
    """
    io.easyengine.site=labels.test
    """

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
    And Check local redis cache for 'wp-remote-db-global-redis.test'
