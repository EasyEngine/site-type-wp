# Needs git on the host and access to github.com (clones vip-go-skeleton and the large vip-go-mu-plugins-built repo).
@network
Feature: WordPress VIP Go sites (--vip)

  Scenario: Create a VIP site from the default skeleton with cache
    When I run 'bin/ee site create bh-site-type-wp-vip.test --type=wp --vip --cache'
    Then the command should succeed
    And STDOUT should contain:
      """
      VIP Skeleton repo will be used for wp-content directory: https://github.com/Automattic/vip-go-skeleton.git
      Repo access check completed.
      Setting up VIP Go environment.
      VIP Go environment setup completed.
      """
    And STDERR should contain:
      """
      Nginx-helper and wp-redis plugin is installed to enable cache.
      """
    And the site 'bh-site-type-wp-vip.test' should have the site meta 'is_vip' set to '1'
    And the site 'bh-site-type-wp-vip.test' should have the site meta 'vip_repo_url' set to 'https://github.com/Automattic/vip-go-skeleton.git'
    And the directory '{SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/wp-content/.git' should exist
    And the directory '{SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/wp-content/mu-plugins' should exist
    And the directory '{SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/wp-content/uploads' should exist
    And the directory '{SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/mu-plugins' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/wp-content-bkp' should not exist
    And the file '{SITES_DIR}/bh-site-type-wp-vip.test/app/wp-config.php' should contain:
      """
      /wp-content/mu-plugins/000-pre-vip-config/requires.php
      /wp-content/vip-config/vip-config.php
      """
    When I run 'stat -c %U {SITES_DIR}/bh-site-type-wp-vip.test/app/htdocs/wp-content'
    Then STDOUT should contain:
      """
      www-data
      """
    And a GET request to 'http://bh-site-type-wp-vip.test/' should return 200

  Scenario: An inaccessible VIP repository is rejected before anything is created
    When I run 'env GIT_TERMINAL_PROMPT=0 bin/ee site create bh-site-type-wp-vipbad.test --type=wp --vip=https://github.com/Automattic/bh-site-type-wp-does-not-exist.git'
    Then the command should fail
    And STDERR should contain:
      """
      Your repo is being cloned at wp-content directory make sure the repo has wp-content data.
      Could not read from remote repository. Please make sure you have the correct access rights and the repository exists.
      """
    And the site 'bh-site-type-wp-vipbad.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-vipbad.test' should not exist
