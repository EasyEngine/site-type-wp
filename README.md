easyengine/site-type-wp
=======================

EasyEngine site type package for WordPress site creation.

[![Build Status](https://travis-ci.org/easyengine/site-type-wp.svg?branch=master)](https://travis-ci.org/easyengine/site-type-wp)

Quick links: [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### ee site create --type=wp

Runs the standard WordPress Site installation.

~~~
ee site create --type=wp <site-name> [--cache] [--vip] [--mu=<subdir>] [--mu=<subdom>] [--title=<title>] [--admin-user=<admin-user>] [--admin-pass=<admin-pass>] [--admin-email=<admin-email>] [--local-db] [--with-local-redis] [--php=<php-version>] [--dbname=<dbname>] [--dbuser=<dbuser>] [--dbpass=<dbpass>] [--dbhost=<dbhost>] [--dbprefix=<dbprefix>] [--dbcharset=<dbcharset>] [--dbcollate=<dbcollate>] [--skip-check] [--version=<version>] [--skip-content] [--skip-install] [--skip-status-check] [--ssl=<value>] [--wildcard] [--yes] [--force]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--cache]
		Use redis cache for WordPress.

	[--vip]
		Create WordPress VIP GO site using your vip repo which contains wp-content dir. Default it will use skeleton repo.
		---
		default: https://github.com/Automattic/vip-go-skeleton.git
		---

	[--mu=<subdir>]
		WordPress sub-dir Multi-site.

	[--mu=<subdom>]
		WordPress sub-domain Multi-site.

	[--title=<title>]
		Title of your site.

	[--admin-user=<admin-user>]
		Username of the administrator.

	[--admin-pass=<admin-pass>]
		Password for the the administrator.

	[--admin-email=<admin-email>]
		E-Mail of the administrator.

	[--local-db]
		Create separate db container instead of using global db.

	[--with-local-redis]
		Enable cache with local redis container.

	[--php=<php-version>]
		PHP version for site. Currently only supports PHP 5.6 and latest.
		---
		default: latest
		options:
			- 5.6
			- 7.2
			- latest
		---

	[--dbname=<dbname>]
		Set the database name.

	[--dbuser=<dbuser>]
		Set the database user.

	[--dbpass=<dbpass>]
		Set the database password.

	[--dbhost=<dbhost>]
		Set the database host. Pass value only when remote dbhost is required.

	[--dbprefix=<dbprefix>]
		Set the database table prefix.

	[--dbcharset=<dbcharset>]
		Set the database charset.
		---
		default: utf8mb4
		---

	[--dbcollate=<dbcollate>]
		Set the database collation.

	[--skip-check]
		If set, the database connection is not checked.

	[--version=<version>]
		Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.

	[--skip-content]
		Download WP without the default themes and plugins.

	[--skip-install]
		Skips wp-core install.

	[--skip-status-check]
		Skips site status check.

	[--ssl=<value>]
		Enables ssl on site.

	[--wildcard]
		Gets wildcard SSL .

	[--yes]
		Do not prompt for confirmation.

	[--force]
		Resets the remote database if it is not empty.

**EXAMPLES**

    # Create WordPress site
    $ ee site create example.com --type=wp

    # Create WordPress multisite subdir site
    $ ee site create example.com --type=wp --mu=subdir

    # Create WordPress multisite subdom site
    $ ee site create example.com --type=wp --mu=subdom

    # Create WordPress site with ssl from letsencrypt
    $ ee site create example.com --type=wp --ssl=le

    # Create WordPress site with wildcard ssl
    $ ee site create example.com --type=wp --ssl=le --wildcard

    # Create WordPress site with self signed certificate
    $ ee site create example.com --type=wp --ssl=self

    # Create WordPress site with remote database
    $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password

    # Create WordPress site with custom site title, locale, admin user, admin email and admin password
    $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine



### ee site delete

Deletes a website.

~~~
ee site delete <site-name> [--yes]
~~~

**OPTIONS**

	<site-name>
		Name of website to be deleted.

	[--yes]
		Do not prompt for confirmation.

**EXAMPLES**

    # Delete site
    $ ee site delete example.com



### ee site update

Supports updating and upgrading site.

~~~
ee site update [<site-name>] [--ssl=<ssl>] [--wildcard]
~~~

	[<site-name>]
		Name of the site.

	[--ssl=<ssl>]
		Enable ssl on site

	[--wildcard]
		Enable wildcard SSL on site.

**EXAMPLES**

    # Add SSL to non-ssl site
    $ ee site update example.com --ssl=le

    # Add SSL to non-ssl site
    $ ee site update example.com --ssl=le --wildcard

    # Add self-signed SSL to non-ssl site
    $ ee site update example.com --ssl=self



### ee site info --type=wp

Runs the standard WordPress Site installation.

~~~
ee site info --type=wp <site-name> [--cache] [--vip] [--mu=<subdir>] [--mu=<subdom>] [--title=<title>] [--admin-user=<admin-user>] [--admin-pass=<admin-pass>] [--admin-email=<admin-email>] [--local-db] [--with-local-redis] [--php=<php-version>] [--dbname=<dbname>] [--dbuser=<dbuser>] [--dbpass=<dbpass>] [--dbhost=<dbhost>] [--dbprefix=<dbprefix>] [--dbcharset=<dbcharset>] [--dbcollate=<dbcollate>] [--skip-check] [--version=<version>] [--skip-content] [--skip-install] [--skip-status-check] [--ssl=<value>] [--wildcard] [--yes] [--force]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--cache]
		Use redis cache for WordPress.

	[--vip]
		Create WordPress VIP GO site using your vip repo which contains wp-content dir. Default it will use skeleton repo.
		---
		default: https://github.com/Automattic/vip-go-skeleton.git
		---

	[--mu=<subdir>]
		WordPress sub-dir Multi-site.

	[--mu=<subdom>]
		WordPress sub-domain Multi-site.

	[--title=<title>]
		Title of your site.

	[--admin-user=<admin-user>]
		Username of the administrator.

	[--admin-pass=<admin-pass>]
		Password for the the administrator.

	[--admin-email=<admin-email>]
		E-Mail of the administrator.

	[--local-db]
		Create separate db container instead of using global db.

	[--with-local-redis]
		Enable cache with local redis container.

	[--php=<php-version>]
		PHP version for site. Currently only supports PHP 5.6 and latest.
		---
		default: latest
		options:
			- 5.6
			- 7.2
			- latest
		---

	[--dbname=<dbname>]
		Set the database name.

	[--dbuser=<dbuser>]
		Set the database user.

	[--dbpass=<dbpass>]
		Set the database password.

	[--dbhost=<dbhost>]
		Set the database host. Pass value only when remote dbhost is required.

	[--dbprefix=<dbprefix>]
		Set the database table prefix.

	[--dbcharset=<dbcharset>]
		Set the database charset.
		---
		default: utf8mb4
		---

	[--dbcollate=<dbcollate>]
		Set the database collation.

	[--skip-check]
		If set, the database connection is not checked.

	[--version=<version>]
		Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.

	[--skip-content]
		Download WP without the default themes and plugins.

	[--skip-install]
		Skips wp-core install.

	[--skip-status-check]
		Skips site status check.

	[--ssl=<value>]
		Enables ssl on site.

	[--wildcard]
		Gets wildcard SSL .

	[--yes]
		Do not prompt for confirmation.

	[--force]
		Resets the remote database if it is not empty.

**EXAMPLES**

    # Create WordPress site
    $ ee site create example.com --type=wp

    # Create WordPress multisite subdir site
    $ ee site create example.com --type=wp --mu=subdir

    # Create WordPress multisite subdom site
    $ ee site create example.com --type=wp --mu=subdom

    # Create WordPress site with ssl from letsencrypt
    $ ee site create example.com --type=wp --ssl=le

    # Create WordPress site with wildcard ssl
    $ ee site create example.com --type=wp --ssl=le --wildcard

    # Create WordPress site with self signed certificate
    $ ee site create example.com --type=wp --ssl=self

    # Create WordPress site with remote database
    $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password

    # Create WordPress site with custom site title, locale, admin user, admin email and admin password
    $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine



### ee site enable

Enables a website. It will start the docker containers of the website if they are stopped.

~~~
ee site enable [<site-name>] [--force] [--verify]
~~~

**OPTIONS**

	[<site-name>]
		Name of website to be enabled.

	[--force]
		Force execution of site enable.

	[--verify]
		Verify if required global services are working.

**EXAMPLES**

    # Enable site
    $ ee site enable example.com

    # Enable site with verification of dependent global services. (Note: This takes longer time to enable the
    site.)
    $ ee site enable example.com --verify

    # Force enable a site.
    $ ee site enable example.com --force



### ee site disable

Disables a website. It will stop and remove the docker containers of the website if they are running.

~~~
ee site disable [<site-name>]
~~~

**OPTIONS**

	[<site-name>]
		Name of website to be disabled.

**EXAMPLES**

    # Disable site
    $ ee site disable example.com



### ee site info

Display all the relevant site information, credentials and useful links.

~~~
ee site info [<site-name>]
~~~

	[<site-name>]
		Name of the website whose info is required.

**EXAMPLES**

    # Display site info
    $ ee site info example.com



### ee site ssl

Verifies ssl challenge and also renews certificates(if expired).

~~~
ee site ssl <site-name> [--force]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--force]
		Force renewal.



### ee site list

Lists the created websites.

~~~
ee site list [--enabled] [--disabled] [--format=<format>]
~~~

abstract list

	[--enabled]
		List only enabled sites.

	[--disabled]
		List only disabled sites.

	[--format=<format>]
		Render output in a particular format.
		---
		default: table
		options:
		  - table
		  - csv
		  - yaml
		  - json
		  - count
		  - text
		---

**EXAMPLES**

    # List all sites
    $ ee site list

    # List enabled sites
    $ ee site list --enabled

    # List disabled sites
    $ ee site list --disabled

    # List all sites in JSON
    $ ee site list --format=json

    # Count all sites
    $ ee site list --format=count



### ee site reload --type=wp

Runs the standard WordPress Site installation.

~~~
ee site reload --type=wp <site-name> [--cache] [--vip] [--mu=<subdir>] [--mu=<subdom>] [--title=<title>] [--admin-user=<admin-user>] [--admin-pass=<admin-pass>] [--admin-email=<admin-email>] [--local-db] [--with-local-redis] [--php=<php-version>] [--dbname=<dbname>] [--dbuser=<dbuser>] [--dbpass=<dbpass>] [--dbhost=<dbhost>] [--dbprefix=<dbprefix>] [--dbcharset=<dbcharset>] [--dbcollate=<dbcollate>] [--skip-check] [--version=<version>] [--skip-content] [--skip-install] [--skip-status-check] [--ssl=<value>] [--wildcard] [--yes] [--force]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--cache]
		Use redis cache for WordPress.

	[--vip]
		Create WordPress VIP GO site using your vip repo which contains wp-content dir. Default it will use skeleton repo.
		---
		default: https://github.com/Automattic/vip-go-skeleton.git
		---

	[--mu=<subdir>]
		WordPress sub-dir Multi-site.

	[--mu=<subdom>]
		WordPress sub-domain Multi-site.

	[--title=<title>]
		Title of your site.

	[--admin-user=<admin-user>]
		Username of the administrator.

	[--admin-pass=<admin-pass>]
		Password for the the administrator.

	[--admin-email=<admin-email>]
		E-Mail of the administrator.

	[--local-db]
		Create separate db container instead of using global db.

	[--with-local-redis]
		Enable cache with local redis container.

	[--php=<php-version>]
		PHP version for site. Currently only supports PHP 5.6 and latest.
		---
		default: latest
		options:
			- 5.6
			- 7.2
			- latest
		---

	[--dbname=<dbname>]
		Set the database name.

	[--dbuser=<dbuser>]
		Set the database user.

	[--dbpass=<dbpass>]
		Set the database password.

	[--dbhost=<dbhost>]
		Set the database host. Pass value only when remote dbhost is required.

	[--dbprefix=<dbprefix>]
		Set the database table prefix.

	[--dbcharset=<dbcharset>]
		Set the database charset.
		---
		default: utf8mb4
		---

	[--dbcollate=<dbcollate>]
		Set the database collation.

	[--skip-check]
		If set, the database connection is not checked.

	[--version=<version>]
		Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.

	[--skip-content]
		Download WP without the default themes and plugins.

	[--skip-install]
		Skips wp-core install.

	[--skip-status-check]
		Skips site status check.

	[--ssl=<value>]
		Enables ssl on site.

	[--wildcard]
		Gets wildcard SSL .

	[--yes]
		Do not prompt for confirmation.

	[--force]
		Resets the remote database if it is not empty.

**EXAMPLES**

    # Create WordPress site
    $ ee site create example.com --type=wp

    # Create WordPress multisite subdir site
    $ ee site create example.com --type=wp --mu=subdir

    # Create WordPress multisite subdom site
    $ ee site create example.com --type=wp --mu=subdom

    # Create WordPress site with ssl from letsencrypt
    $ ee site create example.com --type=wp --ssl=le

    # Create WordPress site with wildcard ssl
    $ ee site create example.com --type=wp --ssl=le --wildcard

    # Create WordPress site with self signed certificate
    $ ee site create example.com --type=wp --ssl=self

    # Create WordPress site with remote database
    $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password

    # Create WordPress site with custom site title, locale, admin user, admin email and admin password
    $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine



### ee site restart --type=wp

Runs the standard WordPress Site installation.

~~~
ee site restart --type=wp <site-name> [--cache] [--vip] [--mu=<subdir>] [--mu=<subdom>] [--title=<title>] [--admin-user=<admin-user>] [--admin-pass=<admin-pass>] [--admin-email=<admin-email>] [--local-db] [--with-local-redis] [--php=<php-version>] [--dbname=<dbname>] [--dbuser=<dbuser>] [--dbpass=<dbpass>] [--dbhost=<dbhost>] [--dbprefix=<dbprefix>] [--dbcharset=<dbcharset>] [--dbcollate=<dbcollate>] [--skip-check] [--version=<version>] [--skip-content] [--skip-install] [--skip-status-check] [--ssl=<value>] [--wildcard] [--yes] [--force]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--cache]
		Use redis cache for WordPress.

	[--vip]
		Create WordPress VIP GO site using your vip repo which contains wp-content dir. Default it will use skeleton repo.
		---
		default: https://github.com/Automattic/vip-go-skeleton.git
		---

	[--mu=<subdir>]
		WordPress sub-dir Multi-site.

	[--mu=<subdom>]
		WordPress sub-domain Multi-site.

	[--title=<title>]
		Title of your site.

	[--admin-user=<admin-user>]
		Username of the administrator.

	[--admin-pass=<admin-pass>]
		Password for the the administrator.

	[--admin-email=<admin-email>]
		E-Mail of the administrator.

	[--local-db]
		Create separate db container instead of using global db.

	[--with-local-redis]
		Enable cache with local redis container.

	[--php=<php-version>]
		PHP version for site. Currently only supports PHP 5.6 and latest.
		---
		default: latest
		options:
			- 5.6
			- 7.2
			- latest
		---

	[--dbname=<dbname>]
		Set the database name.

	[--dbuser=<dbuser>]
		Set the database user.

	[--dbpass=<dbpass>]
		Set the database password.

	[--dbhost=<dbhost>]
		Set the database host. Pass value only when remote dbhost is required.

	[--dbprefix=<dbprefix>]
		Set the database table prefix.

	[--dbcharset=<dbcharset>]
		Set the database charset.
		---
		default: utf8mb4
		---

	[--dbcollate=<dbcollate>]
		Set the database collation.

	[--skip-check]
		If set, the database connection is not checked.

	[--version=<version>]
		Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.

	[--skip-content]
		Download WP without the default themes and plugins.

	[--skip-install]
		Skips wp-core install.

	[--skip-status-check]
		Skips site status check.

	[--ssl=<value>]
		Enables ssl on site.

	[--wildcard]
		Gets wildcard SSL .

	[--yes]
		Do not prompt for confirmation.

	[--force]
		Resets the remote database if it is not empty.

**EXAMPLES**

    # Create WordPress site
    $ ee site create example.com --type=wp

    # Create WordPress multisite subdir site
    $ ee site create example.com --type=wp --mu=subdir

    # Create WordPress multisite subdom site
    $ ee site create example.com --type=wp --mu=subdom

    # Create WordPress site with ssl from letsencrypt
    $ ee site create example.com --type=wp --ssl=le

    # Create WordPress site with wildcard ssl
    $ ee site create example.com --type=wp --ssl=le --wildcard

    # Create WordPress site with self signed certificate
    $ ee site create example.com --type=wp --ssl=self

    # Create WordPress site with remote database
    $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password

    # Create WordPress site with custom site title, locale, admin user, admin email and admin password
    $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine



### ee site share

Share a site online using ngrok.

~~~
ee site share <site-name> [--disable] [--refresh] [--token=<token>]
~~~

**OPTIONS**

	<site-name>
		Name of website.

	[--disable]
		Take online link down.

	[--refresh]
		Refresh site share if link has expired.

	[--token=<token>]
		ngrok token.

**EXAMPLES**

    # Share a site online
    $ ee site share example.com

    # Refresh shareed link if expired
    $ ee site share example.com --refresh

    # Disable online link
    $ ee site share example.com --disable



### ee site clean

Clears Object and Page cache for site.

~~~
ee site clean [<site-name>] [--page] [--object]
~~~

**OPTIONS**

	[<site-name>]
		Name of website to be enabled.

	[--page]
		Clear page cache.

	[--object]
		Clear object cache.

**EXAMPLES**

    # Clear Both cache type for site.
    $ ee site clean example.com

    # Clear Object cache for site.
    $ ee site clean example.com --object

    # Clear Page cache for site.
    $ ee site clean example.com --page

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/easyengine/site-type-wp/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/easyengine/site-type-wp/issues/new). Include as much detail as you can, and clear steps to reproduce if possible.

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/easyengine/site-type-wp/issues/new) to discuss whether the feature is a good fit for the project.

## Support

Github issues aren't for general support questions, but there are other venues you can try: https://easyengine.io/support/


*This README.md is generated dynamically from the project's codebase using `ee scaffold package-readme` ([doc](https://github.com/EasyEngine/scaffold-command)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
