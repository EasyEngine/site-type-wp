Feature: WordPress redis cache (--cache, --with-local-redis)

  Scenario: Create a site with page and object cache on the global redis
    When I run 'bin/ee site create bh-site-type-wp-cache.test --type=wp --cache'
    Then the command should succeed
    And the site 'bh-site-type-wp-cache.test' info should contain:
      | field                | value        |
      | cache_nginx_browser  | 1            |
      | cache_nginx_fullpage | 1            |
      | cache_mysql_query    | 1            |
      | cache_app_object     | 1            |
      | cache_host           | global-redis |
    And the file '{SITES_DIR}/bh-site-type-wp-cache.test/config/nginx/conf.d/main.conf' should contain:
      """
      server global-redis:6379;
      # Redis NGINX CONFIGURATION
      set $key "bh-site-type-wp-cache.test_page:http$request_method$host$request_uri";
      """
    And the file '{SITES_DIR}/bh-site-type-wp-cache.test/config/nginx/conf.d/main.conf' should not contain:
      """
      # PHP NGINX CONFIGURATION
      """
    And the 'redis' container of site 'bh-site-type-wp-cache.test' should not exist
    And the 'php' container of site 'bh-site-type-wp-cache.test' should be connected to network 'ee-global-backend-network'
    And the 'nginx' container of site 'bh-site-type-wp-cache.test' should be connected to network 'ee-global-backend-network'
    When I run WP-CLI 'plugin is-active nginx-helper' on site 'bh-site-type-wp-cache.test'
    Then the command should succeed
    When I run WP-CLI 'plugin is-active wp-redis' on site 'bh-site-type-wp-cache.test'
    Then the command should succeed
    And the file '{SITES_DIR}/bh-site-type-wp-cache.test/app/htdocs/wp-content/object-cache.php' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-cache.test/app/wp-config.php' should contain:
      """
      $redis_server = array('host'=> 'global-redis','port'=> 6379,);
      define( 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME', 'global-redis' );
      define( 'RT_WP_NGINX_HELPER_REDIS_PORT', '6379' );
      define( 'RT_WP_NGINX_HELPER_REDIS_PREFIX', 'bh-site-type-wp-cache.test_page:' );
      define( 'WP_CACHE_KEY_SALT', 'bh-site-type-wp-cache.test_obj:' );
      define( 'WP_REDIS_MAXTTL', '14400' );
      """
    When I run WP-CLI 'option get rt_wp_nginx_helper_options --format=json' on site 'bh-site-type-wp-cache.test'
    Then STDOUT should contain:
      """
      "redis_hostname":"global-redis"
      "redis_prefix":"bh-site-type-wp-cache.test_page:"
      "cache_method":"enable_redis"
      """
    When I run 'bin/ee site info bh-site-type-wp-cache.test'
    Then STDOUT should contain:
      """
      Enabled
      """

  Scenario: The page cache is served from redis
    Given a WordPress site 'bh-site-type-wp-cache.test' exists created with '--cache'
    When I send a GET request to 'http://bh-site-type-wp-cache.test/'
    Then the response should have header 'X-SRCache-Fetch-Status'
    And a GET request to 'http://bh-site-type-wp-cache.test/' should have header 'X-SRCache-Fetch-Status: HIT' within 20 seconds
    And the global redis should have keys matching 'bh-site-type-wp-cache.test_page:*'
    # Logged-in cookies and query strings bypass the cache.
    When I send a GET request to 'http://bh-site-type-wp-cache.test/?bh=1'
    Then the response should have header 'X-SRCache-Fetch-Status: BYPASS'

  # Regression check (fixed in v4.13.2 by EasyEngine/site-command#510): clean_site_cache() runs `docker exec -it`, which fails without a TTY (and can hang under nested ptys), yet `ee site clean` still prints Success.
  Scenario: ee site clean purges the page and object cache keys
    Given a WordPress site 'bh-site-type-wp-cacheclean.test' exists created with '--cache --skip-install'
    And I store the key 'bh-site-type-wp-cacheclean.test_page:httpGET/bh' in the global redis
    And I store the key 'bh-site-type-wp-cacheclean.test_obj:bh' in the global redis
    When I run 'bin/ee site clean bh-site-type-wp-cacheclean.test --page'
    Then the command should succeed
    And STDOUT should contain:
      """
      Page cache cleared for bh-site-type-wp-cacheclean.test
      """
    And the global redis should not have keys matching 'bh-site-type-wp-cacheclean.test_page:*'
    And the global redis should have keys matching 'bh-site-type-wp-cacheclean.test_obj:*'
    When I run 'bin/ee site clean bh-site-type-wp-cacheclean.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      Page cache cleared for bh-site-type-wp-cacheclean.test
      Object cache cleared for bh-site-type-wp-cacheclean.test
      """
    And the global redis should not have keys matching 'bh-site-type-wp-cacheclean.test_obj:*'

  Scenario: Cache clean without proxy cache rejects --proxy
    Given a WordPress site 'bh-site-type-wp-cache.test' exists created with '--cache'
    When I run 'bin/ee site clean bh-site-type-wp-cache.test --proxy'
    Then the command should fail
    And STDERR should contain:
      """
      Proxy cache is not enabled on site.
      """

  Scenario: Create a site with cache on a local redis container
    When I run 'bin/ee site create bh-site-type-wp-lredis.test --type=wp --cache --with-local-redis'
    Then the command should succeed
    And the site 'bh-site-type-wp-lredis.test' info should contain:
      | field                | value |
      | cache_nginx_fullpage | 1     |
      | cache_host           | redis |
    And the site 'bh-site-type-wp-lredis.test' should have 4 running containers
    And the 'redis' container of site 'bh-site-type-wp-lredis.test' should be running
    And the 'redis' container of site 'bh-site-type-wp-lredis.test' should use image 'easyengine/redis:'
    And the 'nginx' container of site 'bh-site-type-wp-lredis.test' should not be connected to network 'ee-global-backend-network'
    And the file '{SITES_DIR}/bh-site-type-wp-lredis.test/config/nginx/conf.d/main.conf' should contain:
      """
      server redis:6379;
      """
    And the file '{SITES_DIR}/bh-site-type-wp-lredis.test/docker-compose.yml' should contain:
      """
      image: easyengine/redis:
      - redis
      """
    And the file '{SITES_DIR}/bh-site-type-wp-lredis.test/app/wp-config.php' should contain:
      """
      define( 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME', 'redis' );
      $redis_server = array('host'=> 'redis','port'=> 6379,);
      """
    And a GET request to 'http://bh-site-type-wp-lredis.test/' should have header 'X-SRCache-Fetch-Status: HIT' within 20 seconds
    And the global redis should not have keys matching 'bh-site-type-wp-lredis.test_page:*'
    When I run 'bin/ee site delete bh-site-type-wp-lredis.test --yes'
    Then the command should succeed
    And the 'redis' container of site 'bh-site-type-wp-lredis.test' should not exist

  Scenario: Subdomain multisite with cache stores the nginx-helper options as network meta
    When I run 'bin/ee site create bh-site-type-wp-mucache.test --type=wp --mu=subdom --cache'
    Then the command should succeed
    When I run WP-CLI 'network meta get 1 rt_wp_nginx_helper_options --format=json' on site 'bh-site-type-wp-mucache.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      "redis_hostname":"global-redis"
      """
    And a GET request to 'http://bh-site-type-wp-mucache.test/' should return 200
