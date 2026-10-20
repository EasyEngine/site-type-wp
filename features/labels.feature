Feature: Container, volume and network labels of WordPress sites

  Scenario: All easyengine containers, volumes and the site network are tagged
    Given a WordPress site 'bh-site-type-wp-labels.test' exists created with '--skip-install'
    Then There should be 3 containers with labels
    """
    io.easyengine.site=bh-site-type-wp-labels.test
    """
    And the site 'bh-site-type-wp-labels.test' should have 8 docker volumes
    When I run 'docker network inspect -f "{{json .Labels}}" bh-site-type-wp-labels.test'
    Then STDOUT should contain:
      """
      "io.easyengine.site":"bh-site-type-wp-labels.test"
      "org.label-schema.vendor":"EasyEngine"
      """
    When I run 'docker volume ls --filter label=io.easyengine.site=bh-site-type-wp-labels.test --filter label=org.label-schema.vendor=EasyEngine --format "{{.Name}}"'
    Then STDOUT should contain:
      """
      bhsitetypewplabelstest_htdocs
      bhsitetypewplabelstest_config_nginx
      bhsitetypewplabelstest_config_php
      bhsitetypewplabelstest_log_nginx
      bhsitetypewplabelstest_log_php
      bhsitetypewplabelstest_data_postfix
      bhsitetypewplabelstest_ssl_postfix
      bhsitetypewplabelstest_config_postfix
      """
