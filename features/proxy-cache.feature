# Enabling or disabling proxy cache runs `docker restart` on the global nginx-proxy, which briefly interrupts every site on the host.
Feature: Proxy cache of WordPress sites (--proxy-cache)

  @destructive
  Scenario: Create a site with proxy cache and custom limits
    When I run 'bin/ee site create bh-site-type-wp-proxy.test --type=wp --proxy-cache=on --proxy-cache-max-time=30s --proxy-cache-max-size=bad'
    Then the command should succeed
    And STDERR should contain:
      """
      Wrong value `bad` supplied to param: `proxy-cache-max-size`, replacing it with default:256m
      """
    And STDOUT should contain:
      """
      Proxy cache update on site bh-site-type-wp-proxy.test completed.
      """
    # Proxy cache implies the redis page/object cache.
    And the site 'bh-site-type-wp-proxy.test' info should contain:
      | field                | value        |
      | proxy_cache          | on           |
      | cache_nginx_fullpage | 1            |
      | cache_host           | global-redis |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-proxy.test.conf' should contain:
      """
      proxy_cache_path /var/cache/nginx/bh-site-type-wp-proxy.test levels=1:2 keys_zone=bh-site-type-wp-proxy-test:10m max_size=256m;
      """
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-site-type-wp-proxy.test_location' should contain:
      """
      proxy_cache bh-site-type-wp-proxy-test;
      proxy_cache_valid 200 301 302 404 30s;
      add_header X-Proxy-Cache $upstream_cache_status;
      """
    And a GET request to 'http://bh-site-type-wp-proxy.test/' should return 200 within 60 seconds
    And a GET request to 'http://bh-site-type-wp-proxy.test/' should have header 'X-Proxy-Cache: HIT' within 20 seconds
    When I run 'bin/ee site info bh-site-type-wp-proxy.test'
    Then STDOUT should contain:
      """
      Proxy Cache
      Enabled
      """

  @destructive
  Scenario: Proxy cache can be turned off and is not turned on twice
    Given a WordPress site 'bh-site-type-wp-proxy.test' exists created with '--proxy-cache=on'
    When I run 'bin/ee site update bh-site-type-wp-proxy.test --proxy-cache=on'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-proxy.test already has proxy cache: on
      """
    When I run 'bin/ee site update bh-site-type-wp-proxy.test --proxy-cache=off'
    Then the command should succeed
    And the site 'bh-site-type-wp-proxy.test' info should contain:
      | field       | value |
      | proxy_cache | off   |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-proxy.test.conf' should not exist
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-site-type-wp-proxy.test_location' should not exist
    And a GET request to 'http://bh-site-type-wp-proxy.test/' should return 200 within 60 seconds
    And the response should not have header 'X-Proxy-Cache'

  @destructive
  Scenario: Enable proxy cache on an existing site with update
    Given a WordPress site 'bh-site-type-wp-proxyupd.test' exists created with '--skip-install'
    When I run 'bin/ee site update bh-site-type-wp-proxyupd.test --proxy-cache=on --proxy-cache-max-size=1g --proxy-cache-max-time=xyz --proxy-cache-key-zone-size=20m'
    Then the command should succeed
    And STDERR should contain:
      """
      Wrong value `xyz` supplied to param: `proxy-cache-max-time`, replacing it with default:1s
      """
    And the site 'bh-site-type-wp-proxyupd.test' info should contain:
      | field       | value |
      | proxy_cache | on    |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-proxyupd.test.conf' should contain:
      """
      keys_zone=bh-site-type-wp-proxyupd-test:20m max_size=1g;
      """
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-site-type-wp-proxyupd.test_location' should contain:
      """
      proxy_cache_valid 200 301 302 404 1s;
      """
    When I run 'bin/ee site delete bh-site-type-wp-proxyupd.test --yes'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-proxyupd.test.conf' should not exist
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-site-type-wp-proxyupd.test_location' should not exist

  # WPT-3: the synopsis options `on`/`off` are parsed by YAML as booleans, so any non-empty value passes validation; update then runs the disable path.
  @known-bug @destructive
  Scenario: Invalid proxy-cache value on update is rejected
    Given a WordPress site 'bh-site-type-wp-proxyval.test' exists created with '--skip-install'
    When I run 'bin/ee site update bh-site-type-wp-proxyval.test --proxy-cache=maybe'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'proxy-cache'
      """

  # WPT-3: same YAML boolean parsing on create; the invalid value is silently ignored.
  @known-bug
  Scenario: Invalid proxy-cache value on create is rejected
    When I run 'bin/ee site create bh-site-type-wp-proxybad.test --type=wp --proxy-cache=maybe --skip-install'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'proxy-cache'
      """
    And the site 'bh-site-type-wp-proxybad.test' should not exist

  # WPT-3: without --proxy-cache the YAML default `off` becomes boolean false and is stored as '' instead of 'off', so `--proxy-cache=off` is not detected as a no-op (and restarts the global proxy).
  @known-bug @destructive
  Scenario: A new site stores proxy_cache off and refuses to turn it off again
    Given a WordPress site 'bh-site-type-wp-proxyoff.test' exists created with '--skip-install'
    Then the site 'bh-site-type-wp-proxyoff.test' info should contain:
      | field       | value |
      | proxy_cache | off   |
    When I run 'bin/ee site update bh-site-type-wp-proxyoff.test --proxy-cache=off'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-proxyoff.test already has proxy cache: off
      """

  # CACHE-1 (fixed in v4.13.2, site-command 276d118), regression check: the proxy purge also uses `docker exec -it`, so the files stay while "Proxy cache cleared" is printed.
  @destructive
  Scenario: ee site clean --proxy empties the proxy cache directory
    Given a WordPress site 'bh-site-type-wp-proxyclean.test' exists created with '--proxy-cache=on --skip-install'
    And I run 'docker exec services_global-nginx-proxy_1 sh -c "mkdir -p /var/cache/nginx/bh-site-type-wp-proxyclean.test/x && touch /var/cache/nginx/bh-site-type-wp-proxyclean.test/x/entry"'
    When I run 'bin/ee site clean bh-site-type-wp-proxyclean.test --proxy'
    Then the command should succeed
    And STDOUT should contain:
      """
      Proxy cache cleared for bh-site-type-wp-proxyclean.test
      """
    When I run 'docker exec services_global-nginx-proxy_1 test -e /var/cache/nginx/bh-site-type-wp-proxyclean.test/x/entry'
    Then the command should fail
