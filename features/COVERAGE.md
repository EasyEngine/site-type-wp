# site-type-wp Behat coverage

Scope: `ee site create --type=wp` and everything `EE\Site\Type\WordPress` defines or overrides (`create`, `info`, `restart`, `reload`, `share`, `update_ssl`, `dump_docker_compose_yml`, rollback), plus the inherited site-command subcommands where their behaviour depends on the WordPress site type (enable/disable/refresh, delete, list, clean, update of php/proxy-cache/alias domains). Generic site-command features (backup/restore, clone/sync, ssl-verify/renew/info) belong to the site-command suite.

Site names all start with `bh-site-type-wp-` (`*.test`, and `bh-site-type-wp-le1.ee4-test.rt.gw` for LE). `@BeforeFeature`/`@AfterFeature` delete only sites, webroots, containers, volumes, networks, global DB databases/users, redis keys, nginx-proxy certs and ACME state, `/etc/hosts` lines and `.backup` dirs that contain that prefix; `/etc/hosts` lines added by a scenario are removed after it.

## Inventory

Legend: file names are under `features/`; "KB" = `@known-bug` scenario that documents the bug (expected to fail until fixed).

### `ee site create --type=wp` arguments and flags

| Item | Covered by |
|---|---|
| `<site-name>` | site: Create a default WordPress site; missing name: site: Create without a site name prints the usage; `http://` prefix, trailing slash, upper case: install-options: Custom title and administrator…; name without TLD gets `.test`: install-options: Skip the WordPress install; shell metacharacters: install-options KB (SEC-1) |
| existing site / existing webroot / name that is an alias | site: Creating an existing site fails…; database KB (webroot leak); alias-domains: An alias of another site cannot be created as a site |
| `--cache` | cache: Create a site with page and object cache…, The page cache is served from redis; subdom + cache: cache: Subdomain multisite with cache…; EE3 `--wpredis`: install-options |
| `--vip`, `--vip=<repo>` | vip: Create a VIP site from the default skeleton with cache; inaccessible repo: vip: An inaccessible VIP repository is rejected… |
| `--mu=subdir` / `--mu=subdom` | multisite: Create a subdirectory multisite / Create a subdomain multisite; invalid value: Unknown multisite type…; no value: multisite KB; subdom + inherit: Subdomain multisite cannot inherit SSL (+ KB for the DB leak); AUTH-5: multisite KB |
| `--alias-domains` | alias-domains: Create a site with alias domains (incl. spaces), conflicts on create, subdom + wildcard alias; AUTH-1 and blank alias: alias-domains KB |
| `--title` | install-options: Custom title…; apostrophe: install-options KB; default (= site name): site: Create a default WordPress site |
| `--admin-user`, `--admin-email`, `--admin-pass` | install-options: Custom title…; defaults (random `wp-user-*`, 18-char password, `admin@<site>`): site: Create a default WordPress site |
| password policy / `--allow-insecure-pass` | install-options: Weak administrator password… (3 rules); Insecure password allowed… |
| `--local-db` | database: Create a site with a local database container, restart `--db`, disable/enable, delete |
| `--with-local-redis` | cache: Create a site with cache on a local redis container; alone: cache KB; restart policy: cache KB (MIG-16) |
| `--public-dir` | install-options: Serve WordPress from a public directory…; without value: install-options KB |
| `--php` (all 12 options) | 8.4 default and update to 8.2: php-version: Default PHP version and update…; 8.3, 8.5, latest: Create a site on PHP <php>; 5.6, 7.0, 7.2, 7.3, 7.4, 8.0, 8.1: `@slow` outlines; invalid (7.1, 9.9, foo): Unsupported PHP version…; loose matches (8, 8.10): php-version KB |
| `--dbname`, `--dbuser`, `--dbpass` | database: Custom database name…, Deleting the site drops its custom database and user; remote: external-db; collisions: database KB ×3 (WPT-1) |
| `--dbhost` (+ `:port`) | database: Remote database host requires credentials, Unreachable remote database rolls the site back; external-db: all scenarios |
| `--dbprefix`, `--dbcharset`, `--dbcollate`, `--skip-check` | database: Custom database name, user, password, prefix, charset and collation… |
| `--version` | install-options: Install a specific WordPress version; php-version legacy outlines (6.2.6, 6.8.3) |
| `--skip-content` | install-options: Install WordPress without the default themes and plugins |
| `--skip-install` | install-options: Skip the WordPress install; used as a fast fixture in many scenarios |
| `--skip-status-check` | install-options: Insecure password allowed, status check skipped… |
| `--ssl=self` | ssl: Create a site with a self-signed certificate; turning it off again: ssl KB (PHPT-10) |
| `--ssl=custom`, `--ssl-key`, `--ssl-crt` | ssl: Create a site with a custom certificate; missing flags; missing files; rollback dropping another site's DB: database KB (WPT-1) |
| `--ssl=inherit` | ssl: A subdomain site inherits…, Inherit without a parent site rolls back, parent without SSL (KB), with `--wildcard` |
| `--ssl=le` | ssl: Create a WordPress site with a Let's Encrypt certificate (`@le`); leftovers after delete: ssl KB (SSL-5/SSL-9) |
| unknown `--ssl` value | ssl: An unknown --ssl value rolls the whole create back (late rejection, WPT-13) |
| `--ssl` without value (= le) | not covered: same code path as `--ssl=le`, LE budget |
| `--wildcard` | ssl: Inherit together with --wildcard is rejected; update `--ssl=le --wildcard` rejection; `--ssl=le --wildcard` itself not covered (needs Cloudflare DNS-01 credentials) |
| `--proxy-cache=on\|off` | proxy-cache: Create a site with proxy cache…; invalid value: proxy-cache KB; default stored as `''`: proxy-cache KB |
| `--proxy-cache-max-size`, `--proxy-cache-max-time` | proxy-cache: Create a site with proxy cache and custom limits (valid time, invalid size fallback) |
| `--force` | external-db: A remote database that already has tables needs --force |
| `--yes` | not covered: on create it only answers the VIP "move mu-plugins" prompt (needs a repo with `mu-plugins`) and the PHP fallback prompt, which the synopsis validation makes unreachable |
| global `--locale` | install-options: Localized install on a staging server… |
| global `--wp-mail` | install-options: Insecure password allowed… (lower-cased default e-mail) |
| global `--env` | install-options: production (WP_ENVIRONMENT_TYPE, no mailhog), staging (mailhog enabled) |
| EE3 flags `--wpredis --user --pass --email`, removed `--w3tc` | install-options: EasyEngine v3 flags…, Removed EasyEngine v3 cache flags… |

### Other subcommands defined or overridden by the wp type

| Item | Covered by |
|---|---|
| `info <site>` table | site: Site info as a table; cache/proxy/SSL/local-db/alias/skip-install variants in their features |
| `info --format=json` | site: Site info as JSON (and every `the site … info should contain` step) |
| `info` invalid format / unknown site / from site dir / disabled site | site: Invalid info format…, Site info of an unknown site fails, Site info detects the site from the current directory, Disable and enable… |
| `restart [--all] [--nginx] [--php] [--db]` | site: Restart and reload…; `--db`: database: Restart of a local-db site…; disabled site refused: site: Disable and enable… |
| `reload [--all] [--nginx] [--php]` | site: Restart and reload…; disabled site refused |
| `share --disable` (no share) / SSL site | site: Share without an active share…; ssl: self-signed scenario |
| `share` with ngrok, `--refresh`, `--token` | not covered: needs the external ngrok service and a token |
| `update --ssl=self\|off` (WP search-replace + DB backup) | ssl: Enabling and disabling SSL… ; invalid/`--wildcard`: Invalid SSL updates are rejected; off after a self-signed create: ssl KB (PHPT-10) |
| `update --php` | php-version: Default PHP version and update…, same version, unsupported version |
| `update --proxy-cache[=on\|off]`, `--proxy-cache-max-*`, `--proxy-cache-key-zone-size` | proxy-cache: Proxy cache can be turned off…, Enable proxy cache on an existing site with update; invalid value: KB |
| `update --add-alias-domains` / `--delete-alias-domains` | alias-domains: Add and delete alias domains…, Another site cannot be added as an alias; nohttps dropped on sites without SSL: KB ×2 (WPT-2) |
| `update` of unknown / disabled site | site: Update of an unknown site fails, Disable and enable… |
| `enable` / `disable` / `refresh` | site: Disable and enable…, Refresh regenerates docker-compose.yml…; local-db: database |
| `delete` (+ confirmation prompt) | site: Delete removes every trace… (incl. the random DB user), Declining the delete confirmation…, Deleting a missing site fails; redirect/proxy/alias/local-db/custom-cert/LE cleanup in their features |
| `list [--format] [--enabled] [--disabled]` | site: List shows the site in every format; disabled: site: Disable and enable… |
| `clean [--page] [--object] [--proxy]` | cache KB (CACHE-1), proxy-cache KB (CACHE-1); errors: site: Cache clean on a site without cache fails, cache: Cache clean without proxy cache… |

### Behaviours and side effects

| Behaviour | Covered by |
|---|---|
| Webroot layout, `wp-config.php` one level above the docroot, no leftover `check.html` | site: Create a default WordPress site; public-dir variant |
| Generated files: `.env`, `main.conf` (php / redis / subdir blocks, server_name, root), `custom.ini` (msmtp vs 5.6 sendmail), `user.conf`, `admin-tools.conf`, `docker-compose.yml`, `wp-cli.yml` | site, cache, multisite, php-version, install-options |
| Containers (3 running, mailhog/db/redis absent), images, restart policy, network membership (frontend/backend), site network, 8/11 labelled volumes | site, labels, database, cache |
| Global DB database + user created and dropped; nothing created on early validation errors | site, database, php-version, install-options |
| `/etc/hosts` entry for non-SSL/self sites only, removed on delete | site, ssl, install-options |
| Cron entry created and removed | site, install-options |
| WordPress install: home URL (http/https), permalinks, admin user, title, locale, version, content, `WP_ENVIRONMENT_TYPE`, `COOKIE_DOMAIN` for multisite, X-Forwarded-Proto/debug extras | site, ssl, install-options, multisite |
| nginx WordPress hardening (readme/license/wp-config.txt/dotfiles/.sql/php in uploads) | site: WordPress hardening rules… |
| www / non-www redirect config (only when the other name resolves) | redirect |
| Object/page cache: plugins, wp-config constants, nginx-helper options (option vs network meta), srcache HIT/BYPASS, redis keys | cache |
| Proxy cache files in nginx-proxy `conf.d`/`vhost.d`, `X-Proxy-Cache` header | proxy-cache |
| Rollback (`catch_clean`) at levels 0–4: webroot, containers, volumes, DB, hosts entry removed; remote DB never touched | database, external-db, ssl |
| VIP: skeleton clone, mu-plugins move, wp-config requires, site meta, ownership | vip |
| Container migrations (`set_mu_cookie_domain`, `update_env_file_with_newrelic_key`) | not covered: they only run on upgrade from an older EE; new sites get the same result directly (checked) |
| Rollback on SIGINT/SIGTERM | not covered: MIG-9 (handlers never dispatched); needs killing ee mid-create |
| macOS (`IS_DARWIN`) branches | not covered: Linux-only host |

Estimated coverage: 61 of 67 inventory rows above are covered (about 90%; `--wildcard` only partly); the gaps are the external-service/DNS-01 paths (`--ssl` bare, `--ssl=le --wildcard`, ngrok share, `--yes`), upgrade-only migrations, signal rollback and macOS branches.

## Known bugs exposed (`@known-bug`)

| Scenario | Bug |
|---|---|
| install-options: Shell metacharacters in the site name are rejected | SEC-1 |
| multisite: Subdomain multisite whose wildcard is already an alias… | AUTH-5 |
| alias-domains: Reserved alias names are rejected on create | AUTH-1 |
| alias-domains: Blank alias domains are dropped on create | fixed in site-type-wp #239 (not on develop) |
| install-options: The latest WordPress download passes its checksums | WP-1 |
| cache: The local redis container restarts with the site | MIG-16 |
| cache: ee site clean purges…; proxy-cache: ee site clean --proxy empties… | CACHE-1 |
| ssl: Deleting a Let's Encrypt site leaves no ACME leftovers | SSL-5, SSL-9 |
| database: A database name collision does not drop the other site's database | WPT-1: rollback DROPs another site's DB |
| database: A database user collision does not drop the other site's user | WPT-1: rollback DROPs another site's DB user |
| database: A failed custom-SSL create does not drop another site's database | WPT-1: level-0 rollback DROPs the colliding default DB name |
| database: An existing webroot aborts the create without leaking database objects | WPT-8: DB/user leak |
| multisite: A rejected inherit + subdom create leaves nothing in the global database | WPT-8: DB/user leak |
| multisite: --mu without a value is rejected | WPT-6 |
| cache: --with-local-redis without --cache enables the local redis cache | WPT-6 |
| proxy-cache: Invalid proxy-cache value on update / on create is rejected | WPT-3: YAML `on`/`off` parsed as booleans |
| proxy-cache: A new site stores proxy_cache off… | WPT-3: default stored as `''` |
| php-version: Loosely matching PHP version <php> is rejected | WPT-4: loose `in_array` |
| ssl: Inherit from a parent without SSL fails with a clean message | WPT-7: `var_dump()` in the error |
| ssl: SSL of a site created with --ssl=self can be turned off | PHPT-10 (site-command) |
| install-options: --public-dir without a value keeps the default webroot | WPT-6 |
| install-options: A site title with an apostrophe | WPT-5: unescaped shell quoting |
| alias-domains: Alias changes keep HTTPS disabled on a site without SSL | WPT-2 (pre-existing, not a #488 regression) |
| alias-domains: A site whose SSL was turned off keeps serving HTTP after an alias change | WPT-2 + WPT-12: user-visible redirect to HTTPS |

## Tags and runtime

| Tag | Scenarios | Meaning |
|---|---|---|
| (all) | 126 | including outline examples |
| `@known-bug` | 27 | expected to fail until the bug is fixed; exclude with `--tags='~@known-bug'` |
| `@destructive` | 6 | proxy-cache enable/disable restarts the global nginx-proxy (all sites blip) |
| `@le` | 2 | one real Let's Encrypt issuance (`bh-site-type-wp-le1.ee4-test.rt.gw`); the second scenario only inspects its leftovers and refuses to run without the first |
| `@slow` | 7 | legacy PHP images (5.6–8.1), pulled on first use |
| `@external-db` | 3 | throwaway MariaDB on the host network bound to the docker bridge gateway (read from `docker network inspect bridge`, 10.0.0.1 on this host); reachability from the default bridge and from a user-defined network was checked with a throwaway listener |
| `@network` | 2 | VIP: clones from github.com |
| `@remote` | 0 | no rclone use in this package |

Rough timings on this host class: full WP create 60–90 s, `--skip-install` create 25–40 s, delete 10–15 s. Default run (`--tags='~@known-bug&&~@slow&&~@le&&~@external-db&&~@network'`, 86 scenarios, ~26 full and ~12 skip-install creates): about 50 minutes. `@slow` adds 10–15 minutes plus image pulls, `@external-db` ~5, `@network` ~5, `@le` ~3, `@known-bug` ~15.

## Context changes (`bootstrap/FeatureContext.php`)

Based on site-command's context. Fixed upstream steps that could never fail: the unanchored `I run` pattern (matched any step with a double-quoted string and passed an empty command), `There should be N containers with labels` (inverted, int vs string), `Following containers … should be removed` (compose v1 container names), `I create subsite` (swapped arguments, `docker exec -it`), the `of command :index` regex steps. `EE_ROOT_DIR` is only defined when core has not defined it. The cleanup hooks no longer delete a fixed list of generic site names. New generic steps are in the "Shared generic steps" section; they assert on the last command and print the full command output on failure. `the site :site was deleted earlier in this feature` guards scenarios that inspect another scenario's leftovers, so they fail instead of passing vacuously when run alone.
