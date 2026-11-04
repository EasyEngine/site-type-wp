Feature: SSL of WordPress sites (--ssl=self|custom|inherit|le and ssl updates)

  Scenario: Create a site with a self-signed certificate
    When I run 'bin/ee site create bh-site-type-wp-self.test --type=wp --ssl=self'
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: https://bh-site-type-wp-self.test has been created successfully!
      """
    # Self-signed certs cover *.site, so the site is recorded as wildcard.
    And the site 'bh-site-type-wp-self.test' info should contain:
      | field             | value |
      | site_ssl          | self  |
      | site_ssl_wildcard | 1     |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-self.test.crt' should exist
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-self.test.key' should exist
    And the hosts file should contain 'bh-site-type-wp-self.test'
    And the file '{SITES_DIR}/bh-site-type-wp-self.test/docker-compose.yml' should not contain:
      """
      HTTPS_METHOD=nohttps
      """
    And a GET request to 'https://bh-site-type-wp-self.test/' should return 200 within 30 seconds
    And a GET request to 'http://bh-site-type-wp-self.test/' should return 301
    And the response should redirect to 'https://bh-site-type-wp-self.test/'
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-self.test'
    Then STDOUT should contain:
      """
      https://bh-site-type-wp-self.test
      """
    When I run 'bin/ee site info bh-site-type-wp-self.test'
    Then STDOUT should contain:
      """
      https://bh-site-type-wp-self.test
      SSL Wildcard
      Yes
      """
    When I run 'bin/ee site share bh-site-type-wp-self.test'
    Then the command should fail
    And STDERR should contain:
      """
      site share is not yet supported for ssl sites.
      """

  Scenario: A subdomain site inherits the wildcard certificate of its parent
    Given a WordPress site 'bh-site-type-wp-self.test' exists created with '--ssl=self'
    When I run 'bin/ee site create child.bh-site-type-wp-self.test --type=wp --ssl=inherit'
    Then the command should succeed
    And STDOUT should contain:
      """
      Inherited certs from parent
      """
    And the site 'child.bh-site-type-wp-self.test' info should contain:
      | field             | value   |
      | site_ssl          | inherit |
      | site_ssl_wildcard | 0       |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/child.bh-site-type-wp-self.test.crt' should not exist
    And a GET request to 'https://child.bh-site-type-wp-self.test/' should return 200 within 30 seconds
    When I run 'bin/ee site delete child.bh-site-type-wp-self.test --yes'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-self.test.crt' should exist

  Scenario: Inherit together with --wildcard is rejected
    When I run 'bin/ee site create child2.bh-site-type-wp-self.test --type=wp --ssl=inherit --wildcard'
    Then the command should fail
    And STDERR should contain:
      """
      --wildcard or --mu=subdom flag can not be passed together with --ssl=inherit flag.
      """
    And the site 'child2.bh-site-type-wp-self.test' should not exist

  Scenario: Inherit without a parent site rolls back
    When I run 'bin/ee site create orphan.bh-site-type-wp-noparent.test --type=wp --ssl=inherit'
    Then the command should fail
    And STDERR should contain:
      """
      Unable to find existing site: bh-site-type-wp-noparent.test
      Initiating clean-up.
      """
    And the site 'orphan.bh-site-type-wp-noparent.test' should not exist
    And the directory '{SITES_DIR}/orphan.bh-site-type-wp-noparent.test' should not exist
    And the global database should not have a database named 'orphan_bh_site_type_wp_noparent_test'

  Scenario: Create a site with a custom certificate
    Given a self-signed certificate for 'bh-site-type-wp-custom.test' exists in '{TMP_DIR}/certs'
    When I run 'bin/ee site create bh-site-type-wp-custom.test --type=wp --ssl=custom --ssl-key={TMP_DIR}/certs/bh-site-type-wp-custom.test.key --ssl-crt={TMP_DIR}/certs/bh-site-type-wp-custom.test.crt'
    Then the command should succeed
    And the site 'bh-site-type-wp-custom.test' info should contain:
      | field    | value  |
      | site_ssl | custom |
    And the files '{TMP_DIR}/certs/bh-site-type-wp-custom.test.crt' and '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-custom.test.crt' should be identical
    And the files '{TMP_DIR}/certs/bh-site-type-wp-custom.test.key' and '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-custom.test.key' should be identical
    And a GET request to 'https://bh-site-type-wp-custom.test/' should return 200 within 30 seconds
    When I run 'bin/ee site delete bh-site-type-wp-custom.test --yes'
    Then the command should succeed
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-custom.test.crt' should not exist

  Scenario: Custom SSL without key and certificate is rejected
    When I run 'bin/ee site create bh-site-type-wp-custom2.test --type=wp --ssl=custom'
    Then the command should fail
    And STDERR should contain:
      """
      Pass --ssl-key and --ssl-crt for custom SSL
      """
    And the site 'bh-site-type-wp-custom2.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-custom2.test' should not exist

  Scenario: Custom SSL with missing files is rejected
    When I run 'bin/ee site create bh-site-type-wp-custom2.test --type=wp --ssl=custom --ssl-key=/nonexistent/bh.key --ssl-crt=/nonexistent/bh.crt'
    Then the command should fail
    And STDERR should contain:
      """
      ssl-key OR ssl-crt path does not exist
      """
    And the site 'bh-site-type-wp-custom2.test' should not exist

  # Create's `[--ssl]` is a flag, so the value is only rejected in init_ssl(), after the site was provisioned.
  Scenario: An unknown --ssl value rolls the whole create back
    When I run 'bin/ee site create bh-site-type-wp-sslbad.test --type=wp --ssl=bogus'
    Then the command should fail
    And STDERR should contain:
      """
      Unrecognized value in --ssl flag: bogus
      Initiating clean-up.
      """
    And the site 'bh-site-type-wp-sslbad.test' should not exist
    And the directory '{SITES_DIR}/bh-site-type-wp-sslbad.test' should not exist
    And the site 'bh-site-type-wp-sslbad.test' should have 0 docker volumes
    And the global database should not have a database named 'bh_site_type_wp_sslbad_test'
    And the hosts file should not contain 'bh-site-type-wp-sslbad.test'

  Scenario: Enabling and disabling SSL on a WordPress site runs search-replace with a DB backup
    Given a WordPress site 'bh-site-type-wp-sslupd.test' exists
    When I run 'bin/ee site update bh-site-type-wp-sslupd.test --ssl=self'
    Then the command should succeed
    And STDOUT should contain:
      """
      Starting SSL update for: bh-site-type-wp-sslupd.test
      Running search-replace.
      Taking database backup before search-replace.
      Success: Successfully completed search-replace.
      Success: Enabled SSL for bh-site-type-wp-sslupd.test
      """
    And the file '{EE_ROOT_DIR}/.backup/bh-site-type-wp-sslupd.test/bh-site-type-wp-sslupd.test.db' should exist
    And the file '{SITES_DIR}/bh-site-type-wp-sslupd.test/app/htdocs/bh-site-type-wp-sslupd.test.db' should not exist
    And the site 'bh-site-type-wp-sslupd.test' info should contain:
      | field    | value |
      | site_ssl | self  |
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-sslupd.test'
    Then STDOUT should contain:
      """
      https://bh-site-type-wp-sslupd.test
      """
    And a GET request to 'https://bh-site-type-wp-sslupd.test/' should return 200 within 30 seconds
    When I run 'bin/ee site update bh-site-type-wp-sslupd.test --ssl=self'
    Then the command should fail
    And STDERR should contain:
      """
      SSL is already enabled on bh-site-type-wp-sslupd.test
      """
    When I run 'bin/ee site update bh-site-type-wp-sslupd.test --ssl=off'
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: Disabled SSL for bh-site-type-wp-sslupd.test
      """
    And the site 'bh-site-type-wp-sslupd.test' info should contain:
      | field    | value   |
      | site_ssl | <empty> |
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-sslupd.test'
    Then STDOUT should contain:
      """
      http://bh-site-type-wp-sslupd.test
      """
    And a GET request to 'http://bh-site-type-wp-sslupd.test/' should return 200 within 30 seconds
    When I run 'bin/ee site update bh-site-type-wp-sslupd.test --ssl=off'
    Then the command should fail
    And STDERR should contain:
      """
      SSL is already disabled on bh-site-type-wp-sslupd.test
      """

  # Regression check (fixed by EasyEngine/site-command#505): update refused `--ssl=off` for self-signed creates, which store site_ssl_wildcard=1.
  Scenario: SSL of a site created with --ssl=self can be turned off
    Given a WordPress site 'bh-site-type-wp-selfoff.test' exists created with '--ssl=self --skip-install'
    When I run 'bin/ee site update bh-site-type-wp-selfoff.test --ssl=off'
    Then the command should succeed
    And the site 'bh-site-type-wp-selfoff.test' info should contain:
      | field    | value   |
      | site_ssl | <empty> |

  Scenario: Invalid SSL updates are rejected
    Given a WordPress site 'bh-site-type-wp-sslupd.test' exists
    When I run 'bin/ee site update bh-site-type-wp-sslupd.test --ssl=bogus'
    Then the command should fail
    And STDERR should contain:
      """
      Invalid value specified for 'ssl'
      """
    # A site that never had SSL: sslupd had --ssl=self above, which keeps site_ssl_wildcard=1 after --ssl=off (EasyEngine/site-command#505), so it would request a wildcard certificate.
    Given a WordPress site 'bh-site-type-wp-nossl.test' exists created with '--skip-install'
    When I run 'bin/ee site update bh-site-type-wp-nossl.test --ssl=le --wildcard'
    Then the command should fail
    And STDERR should contain:
      """
      Update from normal SSL to wildcard SSL is not supported yet.
      """

  # Uses one Let's Encrypt issuance (the name resolves to this host through *.ee4-test.rt.gw).
  @le
  Scenario: Create a WordPress site with a Let's Encrypt certificate
    When I run 'bin/ee site create bh-site-type-wp-le1.ee4-test.rt.gw --type=wp --ssl=le'
    Then the command should succeed
    And the site 'bh-site-type-wp-le1.ee4-test.rt.gw' info should contain:
      | field             | value |
      | site_ssl          | le    |
      | site_ssl_wildcard | 0     |
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-le1.ee4-test.rt.gw.crt' should exist
    And the hosts file should not contain 'bh-site-type-wp-le1.ee4-test.rt.gw'
    # The proxy reload is asynchronous: right after create the old workers can still answer, or drop the TLS connection.
    When I wait until "curl -sS -o /dev/null https://bh-site-type-wp-le1.ee4-test.rt.gw/" succeeds within 60 seconds
    And I run 'curl -sS -o /dev/null -w "%{http_code} %{ssl_verify_result}" https://bh-site-type-wp-le1.ee4-test.rt.gw/'
    Then STDOUT should contain:
      """
      200 0
      """
    When I run WP-CLI 'option get home' on site 'bh-site-type-wp-le1.ee4-test.rt.gw'
    Then STDOUT should contain:
      """
      https://bh-site-type-wp-le1.ee4-test.rt.gw
      """
    When I run 'bin/ee site delete bh-site-type-wp-le1.ee4-test.rt.gw --yes'
    Then the command should succeed
    And STDOUT should contain:
      """
      Revoking certificate.
      Removing ssl certs and other config files.
      """
    And the file '{EE_ROOT_DIR}/services/nginx-proxy/certs/bh-site-type-wp-le1.ee4-test.rt.gw.crt' should not exist
    And the directory '{EE_ROOT_DIR}/services/nginx-proxy/acme-conf/certs/bh-site-type-wp-le1.ee4-test.rt.gw' should not exist

  # Regression check (fixed by EasyEngine/site-command#505): deleting an LE site left acme-conf/var entries for www. Checks the leftovers of the scenario above (no new issuance).
  @le
  Scenario: Deleting a Let's Encrypt site leaves no ACME leftovers
    Given the site 'bh-site-type-wp-le1.ee4-test.rt.gw' was deleted earlier in this feature
    Then the directory '{EE_ROOT_DIR}/services/nginx-proxy/acme-conf/var/bh-site-type-wp-le1.ee4-test.rt.gw' should not exist
    And the directory '{EE_ROOT_DIR}/services/nginx-proxy/acme-conf/var/www.bh-site-type-wp-le1.ee4-test.rt.gw' should not exist
