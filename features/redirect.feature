Feature: www / non-www redirection of WordPress sites

  # The www check curls the other host name from the host, so it needs a hosts entry before the site is created.
  Scenario: no_www-no_ssl redirection works properly
    Given I add a hosts entry for 'www.bh-site-type-wp-redir.test'
    When I run 'bin/ee site create bh-site-type-wp-redir.test --type=wp --skip-install'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-redir.test-redirect.conf' should exist
    And a GET request to 'http://www.bh-site-type-wp-redir.test/' should return 301 within 30 seconds
    And the response should redirect to 'http://bh-site-type-wp-redir.test/'
    And Request on 'localhost' with header 'Host: www.bh-site-type-wp-redir.test' should contain following headers:
      | header                                     |
      | HTTP/1.1 301 Moved Permanently             |
      | Location: http://bh-site-type-wp-redir.test/ |

  Scenario: www-no_ssl redirection works properly
    Given I add a hosts entry for 'bh-site-type-wp-redir2.test'
    When I run 'bin/ee site create www.bh-site-type-wp-redir2.test --type=wp --skip-install'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/www.bh-site-type-wp-redir2.test-redirect.conf' should exist
    And a GET request to 'http://bh-site-type-wp-redir2.test/' should return 301 within 30 seconds
    And the response should redirect to 'http://www.bh-site-type-wp-redir2.test/'

  Scenario: No redirect config when the www host does not resolve
    When I run 'bin/ee site create bh-site-type-wp-redir3.test --type=wp --skip-install'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-redir3.test-redirect.conf' should not exist
    And a GET request to 'http://www.bh-site-type-wp-redir3.test/' should return 503

  Scenario: Deleting the site removes its redirect config
    Given I add a hosts entry for 'www.bh-site-type-wp-redir.test'
    And a WordPress site 'bh-site-type-wp-redir.test' exists created with '--skip-install'
    When I run 'bin/ee site delete bh-site-type-wp-redir.test --yes'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/conf.d/bh-site-type-wp-redir.test-redirect.conf' should not exist
    And a GET request to 'http://www.bh-site-type-wp-redir.test/' should return 503 within 30 seconds
