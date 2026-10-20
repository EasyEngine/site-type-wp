Feature: Alias domains of WordPress sites (--alias-domains, --add/--delete-alias-domains)

  Scenario: Create a site with alias domains
    When I run "bin/ee site create bh-site-type-wp-alias.test --type=wp --alias-domains='bh-site-type-wp-alias-a.test, bh-site-type-wp-alias-b.test'"
    Then the command should succeed
    And the site 'bh-site-type-wp-alias.test' info should contain:
      | field         | value                                                                    |
      | alias_domains | bh-site-type-wp-alias.test,bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test |
    And the file '{SITES_DIR}/bh-site-type-wp-alias.test/docker-compose.yml' should contain:
      """
      VIRTUAL_HOST=${VIRTUAL_HOST},bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test
      CERT_NAME=${VIRTUAL_HOST}
      """
    And the file '{SITES_DIR}/bh-site-type-wp-alias.test/config/nginx/conf.d/main.conf' should contain:
      """
      server_name bh-site-type-wp-alias.test bh-site-type-wp-alias-a.test bh-site-type-wp-alias-b.test;
      """
    And the hosts file should contain 'bh-site-type-wp-alias.test'
    And the hosts file should not contain 'bh-site-type-wp-alias-a.test'
    And a GET request to 'http://bh-site-type-wp-alias.test/' should return 200
    And a GET request to 'http://bh-site-type-wp-alias-a.test/' should not return 503
    And a GET request to 'http://bh-site-type-wp-alias-b.test/' should not return 503
    When I run 'bin/ee site info bh-site-type-wp-alias.test'
    Then STDOUT should contain:
      """
      bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test
      """

  Scenario: An alias of another site cannot be created as a site
    Given a WordPress site 'bh-site-type-wp-alias.test' exists created with "--alias-domains='bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test'"
    When I run 'bin/ee site create bh-site-type-wp-alias-a.test --type=wp'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-alias-a.test already exists as an alias domain for site: bh-site-type-wp-alias.test.
      """
    And the site 'bh-site-type-wp-alias-a.test' should not exist

  Scenario: An existing alias or site cannot be reused as an alias on create
    Given a WordPress site 'bh-site-type-wp-alias.test' exists created with "--alias-domains='bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test'"
    When I run "bin/ee site create bh-site-type-wp-alias2.test --type=wp --alias-domains='bh-site-type-wp-alias-b.test'"
    Then the command should fail
    And STDERR should contain:
      """
      already exists as an alias domain for site: bh-site-type-wp-alias.test
      """
    When I run "bin/ee site create bh-site-type-wp-alias2.test --type=wp --alias-domains='bh-site-type-wp-alias.test'"
    Then the command should fail
    And the site 'bh-site-type-wp-alias2.test' should not exist

  Scenario: Add and delete alias domains on a WordPress site
    Given a WordPress site 'bh-site-type-wp-alias.test' exists created with "--alias-domains='bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test'"
    When I run 'bin/ee site update bh-site-type-wp-alias.test --add-alias-domains=bh-site-type-wp-alias-c.test'
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: Alias domains updated on site bh-site-type-wp-alias.test.
      """
    And the site 'bh-site-type-wp-alias.test' info should contain:
      | field         | value                                    |
      | alias_domains | /,bh-site-type-wp-alias-c\.test$/        |
    And the file '{SITES_DIR}/bh-site-type-wp-alias.test/docker-compose.yml' should contain:
      """
      bh-site-type-wp-alias-c.test
      """
    And a GET request to 'http://bh-site-type-wp-alias-c.test/' should not return 503
    When I run 'bin/ee site update bh-site-type-wp-alias.test --add-alias-domains=bh-site-type-wp-alias-c.test'
    Then the command should fail
    And STDERR should contain:
      """
      Alias domains: bh-site-type-wp-alias-c.test is/are already present on the site.
      """
    When I run 'bin/ee site update bh-site-type-wp-alias.test --delete-alias-domains=bh-site-type-wp-alias-zzz.test'
    Then the command should fail
    And STDERR should contain:
      """
      Domains to delete is/are not a subset of already existing alias domains.
      """
    When I run 'bin/ee site update bh-site-type-wp-alias.test --delete-alias-domains=bh-site-type-wp-alias.test'
    Then the command should fail
    And STDERR should contain:
      """
      Primary site domain: `bh-site-type-wp-alias.test` can not be deleted.
      """
    When I run 'bin/ee site update bh-site-type-wp-alias.test --delete-alias-domains=bh-site-type-wp-alias-c.test'
    Then the command should succeed
    And the site 'bh-site-type-wp-alias.test' info should contain:
      | field         | value                                    |
      | alias_domains | /^((?!alias-c).)*$/                      |
    And a GET request to 'http://bh-site-type-wp-alias-c.test/' should return 503 within 30 seconds

  Scenario: Another site cannot be added as an alias
    Given a WordPress site 'bh-site-type-wp-alias.test' exists created with "--alias-domains='bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test'"
    And a WordPress site 'bh-site-type-wp-alias3.test' exists created with '--skip-install'
    When I run 'bin/ee site update bh-site-type-wp-alias.test --add-alias-domains=bh-site-type-wp-alias3.test'
    Then the command should fail
    And STDERR should contain:
      """
      bh-site-type-wp-alias3.test already exists as a site.
      """
    When I run 'bin/ee site update bh-site-type-wp-alias3.test --add-alias-domains=bh-site-type-wp-alias-a.test'
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-site-type-wp-alias-a.test already exists as an alias domain for site: bh-site-type-wp-alias.test.
      """

  Scenario: Deleting the site frees its alias domains
    Given a WordPress site 'bh-site-type-wp-alias.test' exists created with "--alias-domains='bh-site-type-wp-alias-a.test,bh-site-type-wp-alias-b.test'"
    When I run 'bin/ee site delete bh-site-type-wp-alias.test --yes'
    Then the command should succeed
    And a GET request to 'http://bh-site-type-wp-alias-a.test/' should return 503 within 30 seconds
    When I run 'bin/ee site create bh-site-type-wp-alias-a.test --type=wp --skip-install'
    Then the command should succeed

  Scenario: Subdomain multisite with a wildcard alias domain
    When I run "bin/ee site create bh-site-type-wp-aliasmu.test --type=wp --mu=subdom --skip-install --alias-domains='bh-site-type-wp-aliasmu-x.test,*.bh-site-type-wp-aliasmu-x.test'"
    Then the command should succeed
    And the site 'bh-site-type-wp-aliasmu.test' info should contain:
      | field         | value                                                                                                        |
      | alias_domains | bh-site-type-wp-aliasmu.test,*.bh-site-type-wp-aliasmu.test,bh-site-type-wp-aliasmu-x.test,*.bh-site-type-wp-aliasmu-x.test |
    And the file '{SITES_DIR}/bh-site-type-wp-aliasmu.test/docker-compose.yml' should contain:
      """
      VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST},
      *.bh-site-type-wp-aliasmu-x.test
      """
    And a GET request to 'http://foo.bh-site-type-wp-aliasmu-x.test/' should not return 503

  # AUTH-1: an alias like `_wildcard.<site>` passes validation and can clobber the site's `_wildcard` proxy files (fixed in site-command #502 / site-type-wp #239, not on develop).
  @known-bug
  Scenario: Reserved alias names are rejected on create
    When I run "bin/ee site create bh-site-type-wp-auth1.test --type=wp --skip-install --alias-domains='_wildcard.bh-site-type-wp-auth1.test'"
    Then the command should fail
    And the site 'bh-site-type-wp-auth1.test' should not exist

  # Fixed in site-type-wp #239 (not on develop): a trailing comma stores an empty alias and a dangling `,` in VIRTUAL_HOST.
  @known-bug
  Scenario: Blank alias domains are dropped on create
    When I run "bin/ee site create bh-site-type-wp-blank.test --type=wp --skip-install --alias-domains='bh-site-type-wp-blank-a.test,'"
    Then the command should succeed
    And the site 'bh-site-type-wp-blank.test' info should contain:
      | field         | value                                                    |
      | alias_domains | bh-site-type-wp-blank.test,bh-site-type-wp-blank-a.test  |

  # Suspected bug (new, since #488): update_alias_domains() dumps docker-compose.yml with nohttps only for LE sites, so a site without SSL loses HTTPS_METHOD=nohttps until the next refresh.
  @known-bug
  Scenario: Alias changes keep HTTPS disabled on a site without SSL
    Given a WordPress site 'bh-site-type-wp-aliasnossl.test' exists created with '--skip-install'
    When I run 'bin/ee site update bh-site-type-wp-aliasnossl.test --add-alias-domains=bh-site-type-wp-aliasnossl-a.test'
    Then the command should succeed
    And the file '{SITES_DIR}/bh-site-type-wp-aliasnossl.test/docker-compose.yml' should contain:
      """
      HTTPS_METHOD=nohttps
      """
