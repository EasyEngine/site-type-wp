# site-type-wp Behat coverage

Scope: `ee site create --type=wp` and everything `EE\Site\Type\WordPress` defines or overrides (`create`, `info`, `restart`, `reload`, `share`, `update_ssl`, `dump_docker_compose_yml`, rollback), plus the inherited site-command subcommands where their behaviour depends on the WordPress site type (enable/disable/refresh, delete, list, clean, update of php/proxy-cache/alias domains). Generic site-command features (backup/restore, clone/sync, ssl-verify/renew/info) belong to the site-command suite.

Site names all start with `bh-site-type-wp-` (`*.test`, and `bh-site-type-wp-le1.ee4-test.rt.gw` for LE). `@BeforeFeature`/`@AfterFeature` delete only sites, webroots, containers, volumes, networks, global DB databases/users, redis keys, nginx-proxy certs and ACME state, `/etc/hosts` lines and `.backup` dirs that contain that prefix; `/etc/hosts` lines added by a scenario are removed after it.

## Inventory

Legend: file names are under `features/`. Scenarios that assert the correct behaviour of an open bug (they fail until it is fixed) are left out of the suite for now; their rows say "not covered yet" and the scenario comes back with the fix.

### `ee site create --type=wp` arguments and flags

| Item | Covered by |
|---|---|
| `<site-name>` | site: Create a default WordPress site; missing name: site: Create without a site name prints the usage; `http://` prefix, trailing slash, upper case: install-options: Custom title and administrator…; name without TLD gets `.test`: install-options: Skip the WordPress install; shell metacharacters: install-options: Shell metacharacters in the site name are rejected |
| existing site / existing webroot / name that is an alias | site: Creating an existing site fails…; database: An existing webroot aborts the create…; alias-domains: An alias of another site cannot be created as a site; an existing site's name as an alias on create: not covered yet: it is accepted because create only checks other sites' aliases (its scenario comes back with the fix) |
| `--cache` | cache: Create a site with page and object cache…, The page cache is served from redis; subdom + cache: cache: Subdomain multisite with cache…; EE3 `--wpredis`: install-options |
| `--vip`, `--vip=<repo>` | vip: Create a VIP site from the default skeleton with cache; inaccessible repo: vip: An inaccessible VIP repository is rejected… |
| `--mu=subdir` / `--mu=subdom` | multisite: Create a subdirectory multisite / Create a subdomain multisite; invalid value: Unknown multisite type…; no value: not covered yet: `--mu` without a value only warns and creates a single site (its scenario comes back with the fix); subdom + inherit: Subdomain multisite cannot inherit SSL, A rejected inherit + subdom create leaves nothing in the global database; subdom wildcard that is another site's alias: multisite regression scenario |
| `--alias-domains` | alias-domains: Create a site with alias domains (incl. spaces), conflicts on create, subdom + wildcard alias; `_wildcard.<site>` alias and blank alias: regression scenarios (fixed by EasyEngine/site-command#502 and EasyEngine/site-type-wp#239) |
| `--title` | install-options: Custom title…; apostrophe: not covered yet: an apostrophe in the title breaks the unescaped `wp core install` command (its scenario comes back with the fix); default (= site name): site: Create a default WordPress site |
| `--admin-user`, `--admin-email`, `--admin-pass` | install-options: Custom title…; defaults (random `wp-user-*`, 18-char password, `admin@<site>`): site: Create a default WordPress site |
| password policy / `--allow-insecure-pass` | install-options: Weak administrator password… (3 rules); Insecure password allowed… |
| `--local-db` | database: Create a site with a local database container, restart `--db`, disable/enable, delete |
| `--with-local-redis` | cache: Create a site with cache on a local redis container; alone: not covered yet: `--with-local-redis` without `--cache` is silently ignored (its scenario comes back with the fix); restart policy: not covered yet: the local redis service has no restart policy (its scenario comes back with the fix) |
| `--public-dir` | install-options: Serve WordPress from a public directory…; without value: not covered yet: a bare `--public-dir` makes the public dir `htdocs/1` (its scenario comes back with the fix) |
| `--php` (all 12 options) | 8.4 default and update to 8.2: php-version: Default PHP version and update…; 8.3, 8.5, latest: Create a site on PHP <php>; 5.6, 7.0, 7.2, 7.3, 7.4, 8.0, 8.1: `@slow` outlines; invalid (7.1, 9.9, foo): Unsupported PHP version…; loose matches (8, 8.10): not covered yet: versions are compared loosely, so `--php=8` passes validation (its outline comes back with the fix) |
| `--dbname`, `--dbuser`, `--dbpass` | database: Custom database name…, Deleting the site drops its custom database and user; remote: external-db; collisions: database ×3 |
| `--dbhost` (+ `:port`) | database: Remote database host requires credentials, Unreachable remote database rolls the site back; external-db: all scenarios |
| `--dbprefix`, `--dbcharset`, `--dbcollate`, `--skip-check` | database: Custom database name, user, password, prefix, charset and collation… |
| `--version` | install-options: Install a specific WordPress version; php-version legacy outlines (6.2.6, 6.8.3) |
| `--skip-content` | install-options: Install WordPress without the default themes and plugins |
| `--skip-install` | install-options: Skip the WordPress install; used as a fast fixture in many scenarios |
| `--skip-status-check` | install-options: Insecure password allowed, status check skipped… |
| `--ssl=self` | ssl: Create a site with a self-signed certificate; turning it off again: ssl (regression check) |
| `--ssl=custom`, `--ssl-key`, `--ssl-crt` | ssl: Create a site with a custom certificate; missing flags; missing files; rollback dropping another site's DB: database |
| `--ssl=inherit` | ssl: A subdomain site inherits…, Inherit without a parent site rolls back, with `--wildcard`; parent without SSL: not covered yet: the error dumps the parent site object with `var_dump()` (its scenario comes back with the fix) |
| `--ssl=le` | ssl: Create a WordPress site with a Let's Encrypt certificate (`@le`); leftovers after delete: ssl (regression check) |
| unknown `--ssl` value | ssl: An unknown --ssl value rolls the whole create back (late rejection: the value is only checked after the site was provisioned) |
| `--ssl` without value (= le) | not covered: same code path as `--ssl=le`, LE budget |
| `--wildcard` | ssl: Inherit together with --wildcard is rejected; update `--ssl=le --wildcard` rejection; `--ssl=le --wildcard` itself not covered (needs Cloudflare DNS-01 credentials) |
| `--proxy-cache=on\|off` | proxy-cache: Create a site with proxy cache…; invalid value: not covered yet: `on`/`off` are parsed as booleans, so any value passes validation (its scenario comes back with the fix); default stored as `''`: not covered yet: a new site stores `''` instead of `off` (its scenario comes back with the fix) |
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
| `update --ssl=self\|off` (WP search-replace + DB backup) | ssl: Enabling and disabling SSL… ; invalid/`--wildcard`: Invalid SSL updates are rejected; off after a self-signed create: ssl (regression check) |
| `update --php` | php-version: Default PHP version and update…, same version, unsupported version |
| `update --proxy-cache[=on\|off]`, `--proxy-cache-max-*`, `--proxy-cache-key-zone-size` | proxy-cache: Proxy cache can be turned off…, Enable proxy cache on an existing site with update; invalid value: not covered yet (same boolean parsing as on create; its scenario comes back with the fix) |
| `update --add-alias-domains` / `--delete-alias-domains` | alias-domains: Add and delete alias domains…, Another site cannot be added as an alias; nohttps kept on sites without SSL: ×2 (regression checks) |
| `update` of unknown / disabled site | site: Update of an unknown site fails, Disable and enable… |
| `enable` / `disable` / `refresh` | site: Disable and enable…, Refresh regenerates docker-compose.yml…; local-db: database |
| `delete` (+ confirmation prompt) | site: Delete removes every trace… (incl. the random DB user), Declining the delete confirmation…, Deleting a missing site fails; redirect/proxy/alias/local-db/custom-cert/LE cleanup in their features |
| `list [--format] [--enabled] [--disabled]` | site: List shows the site in every format; disabled: site: Disable and enable… |
| `clean [--page] [--object] [--proxy]` | cache and proxy-cache (regression checks: `ee site clean` failed without a TTY yet printed Success, fixed in v4.13.2 by EasyEngine/site-command#510); errors: site: Cache clean on a site without cache fails, cache: Cache clean without proxy cache… |

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
| Rollback on SIGINT/SIGTERM | not covered: the signal handlers are never dispatched; needs killing ee mid-create |
| macOS (`IS_DARWIN`) branches | not covered: Linux-only host |

Estimated coverage: 61 of 67 inventory rows above are covered (about 90%; `--wildcard` only partly); the gaps are the external-service/DNS-01 paths (`--ssl` bare, `--ssl=le --wildcard`, ngrok share, `--yes`), upgrade-only migrations, signal rollback and macOS branches.

## Known bugs and regression scenarios

The alias-name scenarios (an alias that could clobber the site's `_wildcard` proxy files, a subdomain multisite whose wildcard is another site's alias, a trailing comma in `--alias-domains`) are regression checks for EasyEngine/site-command#502 and EasyEngine/site-type-wp#239. The name-collision scenarios (database: name collision, user collision, failed custom-SSL create, existing webroot; multisite: rejected inherit + subdom) check EasyEngine/site-command#503, EasyEngine/site-type-wp#240 and EasyEngine/site-type-php#115. The alias-change, www-redirect-after-delete, `--ssl=off` after a self-signed create and LE-leftover scenarios check EasyEngine/site-command#505; the alias-change scenario waits for the new alias to be served before checking, because the alias update returns before docker-gen has the recreated container.

Scenarios for open bugs are left out of this suite until their fixes land; each comes back as a regular scenario with its fix:

- alias-domains: An existing site cannot be reused as an alias on create
- cache: The local redis container restarts with the site
- cache: --with-local-redis without --cache enables the local redis cache
- multisite: --mu without a value is rejected
- proxy-cache: Invalid proxy-cache value on update / on create is rejected
- proxy-cache: A new site stores proxy_cache off and refuses to turn it off again
- php-version: Loosely matching PHP version <php> is rejected (2 examples)
- ssl: Inherit from a parent without SSL fails with a clean message
- install-options: --public-dir without a value keeps the default webroot
- install-options: A site title with an apostrophe

Regression scenarios for fixed bugs: install-options: Shell metacharacters in the site name are rejected (fixed in v4.13.2 by EasyEngine/site-type-wp#243 and EasyEngine/site-command#509); install-options: The latest WordPress download passes its checksums (fixed in v4.13.1 by EasyEngine/site-type-wp#241); cache: ee site clean purges…, proxy-cache: ee site clean --proxy empties… (fixed in v4.13.2 by EasyEngine/site-command#510).

## Tags and runtime

| Tag | Scenarios | Meaning |
|---|---|---|
| (all) | 116 | including outline examples (main lane 107, `@slow` 7, `@le` 2) |
| `@destructive` | 4 | proxy-cache enable/disable restarts the global nginx-proxy (all sites blip) |
| `@le` | 2 | one real Let's Encrypt issuance (`bh-site-type-wp-le1.ee4-test.rt.gw`); the second scenario only inspects its leftovers and refuses to run without the first. On CI both run against Pebble (`.github/behat/pebble-setup.sh`); the leftovers one is a regression check for EasyEngine/site-command#505 |
| `@le-real` | 0 | needs the public LE service itself; none, both `@le` scenarios work on Pebble |
| `@slow` | 7 | legacy PHP images (5.6–8.1), pulled on first use |
| `@external-db` | 3 | throwaway MariaDB on the host network bound to the docker bridge gateway (read from `docker network inspect bridge`); reachability from the default bridge and from a user-defined network was checked with a throwaway listener |
| `@network` | 2 | VIP: clones from github.com |
| `@remote` | 0 | no rclone use in this package |

Rough timings: full WP create 60–90 s, `--skip-install` create 25–40 s, delete 10–15 s. Default run (`--tags='~@slow&&~@le&&~@external-db&&~@network'`, ~26 full and ~12 skip-install creates): about 50 minutes. `@slow` adds 10–15 minutes plus image pulls, `@external-db` ~5, `@network` ~5, `@le` ~3.

## Context changes (`bootstrap/FeatureContext.php`)

Based on site-command's context. Fixed upstream steps that could never fail: the unanchored `I run` pattern (matched any step with a double-quoted string and passed an empty command), `There should be N containers with labels` (inverted, int vs string), `Following containers … should be removed` (compose v1 container names), `I create subsite` (swapped arguments, `docker exec -it`), the `of command :index` regex steps. `EE_ROOT_DIR` is only defined when core has not defined it. The cleanup hooks no longer delete a fixed list of generic site names. New generic steps are in the "Shared generic steps" section; they assert on the last command and print the full command output on failure. `the site :site was deleted earlier in this feature` guards scenarios that inspect another scenario's leftovers, so they fail instead of passing vacuously when run alone.
