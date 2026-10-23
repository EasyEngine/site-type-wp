<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// Core's init-ee.php (pulled in by the includes above) may already have defined it.
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	/**
	 * Every site, docker object, DB name/user, hosts line and temp path created by this suite contains this marker.
	 * Cleanup only ever touches things that contain it, so suites of other packages are never affected.
	 */
	const TEST_PREFIX = 'bh-site-type-wp-';

	/** Global DB/user names are derived from site names with `.` and `-` replaced by `_`. */
	const TEST_DB_PREFIX = 'bh_site_type_wp_';

	/** Scratch directory for generated certificates and similar fixtures. */
	const TEST_TMP_DIR = '/var/tmp/bh-site-type-wp';

	const GLOBAL_DB_CONTAINER    = 'services_global-db_1';
	const GLOBAL_REDIS_CONTAINER = 'services_global-redis_1';

	public $command;
	public $webroot_path;
	public $ee_path;

	/** @var array Results (EE\ProcessRun) of every command run in the current scenario. */
	public $commands = [];

	/** @var array|null Last HTTP response: code, headers, body, url. */
	private $response;

	/** @var array Lines appended to /etc/hosts by the current scenario. */
	private $added_hosts_lines = [];

	/** @var array Container start times noted by the current scenario, keyed by "site/service". */
	private $noted_start_times = [];

	/** @var array Sites removed by a successful `bin/ee site delete` in the current feature. */
	private static $deleted_sites = [];

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * The upstream pattern `/I run '(.*)'|"(.*)"/` is unanchored, so it matched every step containing a double-quoted
	 * string and passed an empty command for the double-quoted form. Split into two anchored definitions.
	 *
	 * @When /^I run '(.*)'$/
	 * @When /^I run "(.*)"$/
	 */
	public function iRun($command)
	{
		$result           = EE::launch( $this->replace_placeholders( $command ), false, true );
		$this->commands[] = $result;
		if ( 0 === $result->return_code && preg_match( '#^bin/ee site delete (\S+)#', $command, $matches ) ) {
			self::$deleted_sites[] = $matches[1];
		}
	}

	/**
	 * @When I create subsite :subsite in :site
	 */
	public function iCreateSubsiteInOfType($subsite, $site )
	{
		// Upstream swapped the arguments and used `docker exec -it`, so it never created a subsite.
		$this->run_wp_cli( $site, 'site create --slug=' . escapeshellarg( $subsite ) );
		$this->assert_last_command_succeeded();
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->describe_command( $this->commands[0] ));
		}
	}

	/**
	 * @Then /^return value of command (\d+) should be 0$/
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->describe_command( $this->commands[ $index - 1 ] ));
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return exactly$/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return exactly$/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return something like$/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return something like$/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = $this->run_wp_cli( $site, 'config get SUBDOMAIN_INSTALL' );

		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not read SUBDOMAIN_INSTALL:\n" . $this->describe_command( $result ) );
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		if ( $this->site_row( $site ) ) {
			throw new Exception("$site db entry not been removed!");
		}
	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		// Upstream looked up `<site>_<service>_1`, which never matches compose v2 names, so it always passed.
		foreach ( $table->getHash() as $container ) {
			$id = $this->site_container_id( $site, $container['container'] );
			if ( '' !== $id ) {
				throw new Exception( "The {$container['container']} container of $site has not been removed (id $id)." );
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$response = $this->http_request( 'http://' . $site . '/', null, true );
		$this->assert_headers_contain_rows( $response['headers'], $table );
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{
		$url      = preg_match( '#^https?://#', $host ) ? $host : 'http://' . $host . '/';
		$response = $this->http_request( $url, null, false, [ $header ] );
		$this->assert_headers_contain_rows( $response['headers'], $table );
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$this->assert_headers_contain_rows( (string) $headers, $table );
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->remove_added_hosts_lines();
		$this->commands          = [];
		$this->response          = null;
		$this->noted_start_times = [];
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		// Upstream compared a string to an int with `===` and threw on equality, so it could never fail.
		$filters = '';
		foreach ( $pyStringNode->getStrings() as $label ) {
			$filters .= ' --filter ' . escapeshellarg( 'label=' . trim( $label ) );
		}

		$result             = EE::launch( "docker ps -q $filters | wc -l", false, true );
		$running_containers = (int) trim( $result->stdout );

		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception( "Expected $expected_running_containers running containers. Found: $running_containers" );
		}
	}

	/**
	 * Removes leftovers of an aborted earlier run before a feature starts.
	 *
	 * @BeforeFeature
	 */
	public static function prepareFeature(BeforeFeatureScope $scope)
	{
		self::$deleted_sites = [];
		self::cleanup_test_artifacts();
	}

	/**
	 * On CI, EE's hardcoded Let's Encrypt host must point at Pebble or a blackhole (.github/behat/pebble-setup.sh), never the real service.
	 *
	 * @BeforeSuite
	 */
	public static function refuseRealAcmeOnCi()
	{
		if ( getenv( 'CI' ) && ! self::acme_host_is_local() ) {
			throw new Exception( 'acme-v02.api.letsencrypt.org resolves to ' . gethostbyname( 'acme-v02.api.letsencrypt.org' ) . ' on CI; run `.github/behat/pebble-setup.sh setup` (le lanes) or `block` (every other lane) first.' );
		}
	}

	/**
	 * @le issues certificates: only against Pebble, or on a real test host with EE_BEHAT_LE_REAL=1 (ignored on CI).
	 *
	 * @BeforeScenario @le
	 */
	public function requireTestAcme()
	{
		if ( getenv( 'EE_BEHAT_LE_ISSUER' ) && self::acme_host_is_local() ) {
			return;
		}
		if ( '1' === getenv( 'EE_BEHAT_LE_REAL' ) && ! getenv( 'CI' ) ) {
			return;
		}
		throw new Exception( '@le needs Pebble (.github/behat/pebble-setup.sh setup), or EE_BEHAT_LE_REAL=1 on a host with real DNS for *.ee4-test.rt.gw.' );
	}

	/**
	 * True when the LE host resolves to a private, loopback or TEST-NET address (Pebble's front proxy or the blackhole).
	 */
	private static function acme_host_is_local()
	{
		$ip = gethostbyname( 'acme-v02.api.letsencrypt.org' );

		return (bool) preg_match( '/^(10\.|127\.|192\.168\.|192\.0\.2\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip );
	}

	/**
	 * Deletes only this suite's sites (upstream deleted a fixed list of generic names) and restores shared state.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		self::cleanup_test_artifacts();

		if(file_exists('ee.phar')) {
			unlink('ee.phar');
		}
		if(file_exists('ee-old.phar')) {
			unlink('ee-old.phar');
		}
	}

	/* ------------------------------------------------------------------------------------------------------------
	 * Shared generic steps.
	 *
	 * Generic steps meant to be shared across the EasyEngine package suites. Assertions operate on the LAST command
	 * run in the scenario (the upstream steps above only look at the first one) and print the actual output on failure.
	 * Placeholders usable in commands, paths and expected values: {EE_ROOT_DIR}, {SITES_DIR}, {TMP_DIR}, {DOCKER_BRIDGE_IP}.
	 * --------------------------------------------------------------------------------------------------------- */

	/**
	 * @Given a WordPress site :site exists
	 */
	public function aWordpressSiteExists( $site )
	{
		$this->aWordpressSiteExistsCreatedWith( $site, '' );
	}

	/**
	 * Creates the site only when it is missing, so scenarios stay independent but can reuse a site within a feature.
	 *
	 * @Given a WordPress site :site exists created with :flags
	 */
	public function aWordpressSiteExistsCreatedWith( $site, $flags )
	{
		if ( $this->site_row( $site ) ) {
			return;
		}
		$command = sprintf( 'bin/ee site create %s --type=wp %s', escapeshellarg( $site ), $this->replace_placeholders( $flags ) );
		$result  = EE::launch( $command, false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not create prerequisite site $site:\n" . $this->describe_command( $result ) );
		}
	}

	/**
	 * Runs a command with the given working directory; a leading `bin/ee` is resolved against the EE checkout.
	 *
	 * @When From the directory :dir I run :command
	 */
	public function fromTheDirectoryIRun( $dir, $command )
	{
		$command = $this->replace_placeholders( $command );
		if ( 0 === strpos( $command, 'bin/ee' ) ) {
			$command = escapeshellarg( realpath( EE_ROOT ) . '/bin/ee' ) . substr( $command, strlen( 'bin/ee' ) );
		}
		$this->commands[] = EE::launch( 'cd ' . escapeshellarg( $this->replace_placeholders( $dir ) ) . ' && ' . $command, false, true );
	}

	/**
	 * @When I run WP-CLI :command on site :site
	 */
	public function iRunWpCliOnSite( $command, $site )
	{
		$this->run_wp_cli( $site, $this->replace_placeholders( $command ) );
	}

	/**
	 * @When In the :service container of site :site I run :command
	 */
	public function inTheContainerOfSiteIRun( $service, $site, $command )
	{
		$id = $this->site_container_id( $site, $service, false );
		if ( '' === $id ) {
			throw new Exception( "No running $service container found for $site." );
		}
		$this->commands[] = EE::launch( sprintf( 'docker exec %s sh -c %s', $id, escapeshellarg( $this->replace_placeholders( $command ) ) ), false, true );
	}

	/**
	 * @When In the docker container :name I run :command
	 */
	public function inTheDockerContainerIRun( $name, $command )
	{
		$this->commands[] = EE::launch( sprintf( 'docker exec %s sh -c %s', escapeshellarg( $name ), escapeshellarg( $this->replace_placeholders( $command ) ) ), false, true );
	}

	/**
	 * @When I wait until :command succeeds within :seconds seconds
	 */
	public function iWaitUntilSucceedsWithin( $command, $seconds )
	{
		$command  = $this->replace_placeholders( $command );
		$deadline = time() + (int) $seconds;
		do {
			$result = EE::launch( $command, false, true );
			if ( 0 === $result->return_code ) {
				return;
			}
			sleep( 2 );
		} while ( time() < $deadline );

		throw new Exception( "`$command` did not succeed within $seconds seconds. Last attempt:\n" . $this->describe_command( $result ) );
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$this->assert_last_command_succeeded();
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$result = $this->last_command();
		if ( 0 === $result->return_code ) {
			throw new Exception( "Expected a non-zero exit code.\n" . $this->describe_command( $result ) );
		}
	}

	/**
	 * Every non-empty line of the PyString must appear in the stream (order-insensitive substring match).
	 *
	 * @Then /^(STDOUT|STDERR) should contain:$/
	 */
	public function streamShouldContain( $stream, PyStringNode $expected )
	{
		$this->assert_output_lines( $this->stream_output( $stream ), $expected, true, $stream );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should not contain:$/
	 */
	public function streamShouldNotContain( $stream, PyStringNode $expected )
	{
		$this->assert_output_lines( $this->stream_output( $stream ), $expected, false, $stream );
	}

	/**
	 * STDOUT and STDERR combined; use it where EE's choice of stream is not what is being tested.
	 *
	 * @Then the output should contain:
	 */
	public function theOutputShouldContain( PyStringNode $expected )
	{
		$this->assert_output_lines( $this->stream_output( 'BOTH' ), $expected, true, 'output' );
	}

	/**
	 * @Then the output should not contain:
	 */
	public function theOutputShouldNotContain( PyStringNode $expected )
	{
		$this->assert_output_lines( $this->stream_output( 'BOTH' ), $expected, false, 'output' );
	}

	/**
	 * @Then STDOUT should be valid json
	 */
	public function stdoutShouldBeValidJson()
	{
		json_decode( trim( $this->last_command()->stdout ) );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'STDOUT is not valid JSON (' . json_last_error_msg() . "):\n" . $this->last_command()->stdout );
		}
	}

	/**
	 * @Then STDOUT should match :regex
	 */
	public function stdoutShouldMatch( $regex )
	{
		if ( ! preg_match( $regex, $this->last_command()->stdout ) ) {
			throw new Exception( "STDOUT does not match $regex:\n" . $this->last_command()->stdout );
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist( $site )
	{
		if ( ! $this->site_row( $site ) ) {
			throw new Exception( "Site $site has no row in the EE database." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist( $site )
	{
		if ( $this->site_row( $site ) ) {
			throw new Exception( "Site $site still has a row in the EE database." );
		}
	}

	/**
	 * @Then the site :site should be enabled
	 */
	public function theSiteShouldBeEnabled( $site )
	{
		$this->assert_site_field( $site, 'site_enabled', '1' );
	}

	/**
	 * @Then the site :site should be disabled
	 */
	public function theSiteShouldBeDisabled( $site )
	{
		$this->assert_site_field( $site, 'site_enabled', '0' );
	}

	/**
	 * Compares `ee site info <site> --format=json` fields. Values: literal, `<empty>`, `<not empty>` or `/regex/`.
	 *
	 * @Then the site :site info should contain:
	 */
	public function theSiteInfoShouldContain( $site, TableNode $table )
	{
		$result = EE::launch( 'bin/ee site info ' . escapeshellarg( $site ) . ' --format=json', false, true );
		$info   = json_decode( trim( $result->stdout ), true );
		if ( ! is_array( $info ) ) {
			throw new Exception( "Could not read site info of $site:\n" . $this->describe_command( $result ) );
		}

		$errors = [];
		foreach ( $table->getHash() as $row ) {
			$field = $row['field'];
			if ( ! array_key_exists( $field, $info ) ) {
				$errors[] = "field `$field` is missing";
				continue;
			}
			$actual = $this->scalar_to_string( $info[ $field ] );
			if ( ! $this->value_matches( $actual, $this->replace_placeholders( $row['value'] ) ) ) {
				$errors[] = "`$field` is " . var_export( $actual, true ) . ', expected ' . $row['value'];
			}
		}

		if ( $errors ) {
			throw new Exception( "Site info mismatch for $site:\n - " . implode( "\n - ", $errors ) . "\nFull info: " . trim( $result->stdout ) );
		}
	}

	/**
	 * @Then the EE database table :table should have :count rows where :column is :value
	 */
	public function theEeDatabaseTableShouldHaveRowsWhere( $table, $count, $column, $value )
	{
		$this->assert_identifier( $table );
		$this->assert_identifier( $column );
		$statement = $this->ee_db()->prepare( "SELECT COUNT(*) FROM $table WHERE $column = ?" );
		$statement->execute( [ $this->replace_placeholders( $value ) ] );
		$actual = (int) $statement->fetchColumn();
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected $count rows in `$table` where $column = $value, found $actual." );
		}
	}

	/**
	 * @Then the site :site should have the site meta :key set to :value
	 */
	public function theSiteShouldHaveTheSiteMetaSetTo( $site, $key, $value )
	{
		$statement = $this->ee_db()->prepare( 'SELECT m.meta_value FROM site_meta m JOIN sites s ON s.id = m.site_id WHERE s.site_url = ? AND m.meta_key = ?' );
		$statement->execute( [ $site, $key ] );
		$values = $statement->fetchAll( PDO::FETCH_COLUMN );
		if ( 1 !== count( $values ) || ! $this->value_matches( (string) $values[0], $value ) ) {
			throw new Exception( "Site meta `$key` of $site is " . var_export( $values, true ) . ", expected $value." );
		}
	}

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist( $path )
	{
		$path = $this->replace_placeholders( $path );
		if ( ! is_file( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist( $path )
	{
		$path = $this->replace_placeholders( $path );
		if ( file_exists( $path ) ) {
			throw new Exception( "File $path exists." );
		}
	}

	/**
	 * @Then the directory :path should exist
	 */
	public function theDirectoryShouldExist( $path )
	{
		$path = $this->replace_placeholders( $path );
		if ( ! is_dir( $path ) ) {
			throw new Exception( "Directory $path does not exist." );
		}
	}

	/**
	 * @Then the directory :path should not exist
	 */
	public function theDirectoryShouldNotExist( $path )
	{
		$path = $this->replace_placeholders( $path );
		if ( file_exists( $path ) ) {
			throw new Exception( "Directory $path exists." );
		}
	}

	/**
	 * @Then the file :path should contain:
	 */
	public function theFileShouldContain( $path, PyStringNode $expected )
	{
		$this->assert_output_lines( $this->read_file( $path ), $expected, true, "file $path" );
	}

	/**
	 * @Then the file :path should not contain:
	 */
	public function theFileShouldNotContain( $path, PyStringNode $expected )
	{
		$this->assert_output_lines( $this->read_file( $path ), $expected, false, "file $path" );
	}

	/**
	 * @Then the files :first and :second should be identical
	 */
	public function theFilesShouldBeIdentical( $first, $second )
	{
		if ( $this->read_file( $first ) !== $this->read_file( $second ) ) {
			throw new Exception( "$first and $second differ." );
		}
	}

	/**
	 * @Given a self-signed certificate for :cn exists in :dir
	 */
	public function aSelfSignedCertificateForExistsIn( $cn, $dir )
	{
		$dir = $this->replace_placeholders( $dir );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
			throw new Exception( "Could not create $dir." );
		}
		$command = sprintf(
			'openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj %s -addext %s -keyout %s -out %s',
			escapeshellarg( '/CN=' . $cn ),
			escapeshellarg( 'subjectAltName=DNS:' . $cn ),
			escapeshellarg( "$dir/$cn.key" ),
			escapeshellarg( "$dir/$cn.crt" )
		);
		$result = EE::launch( $command, false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not generate a certificate:\n" . $this->describe_command( $result ) );
		}
	}

	/**
	 * @Then the :service container of site :site should be running
	 */
	public function theContainerOfSiteShouldBeRunning( $service, $site )
	{
		$id = $this->site_container_id( $site, $service );
		if ( '' === $id ) {
			throw new Exception( "No $service container exists for $site." );
		}
		$state = trim( EE::launch( "docker inspect -f '{{.State.Status}}' $id", false, true )->stdout );
		if ( 'running' !== $state ) {
			throw new Exception( "The $service container of $site is $state, expected running." );
		}
	}

	/**
	 * @Then the :service container of site :site should not exist
	 */
	public function theContainerOfSiteShouldNotExist( $service, $site )
	{
		$id = $this->site_container_id( $site, $service );
		if ( '' !== $id ) {
			throw new Exception( "The $service container of $site exists (id $id)." );
		}
	}

	/**
	 * @Then the site :site should have :count running containers
	 */
	public function theSiteShouldHaveRunningContainers( $site, $count )
	{
		$result = EE::launch( 'docker ps --format "{{.Names}}" --filter ' . escapeshellarg( "label=io.easyengine.site=$site" ), false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not list site containers:\n" . $this->describe_command( $result ) );
		}
		$names  = array_filter( explode( "\n", trim( $result->stdout ) ) );
		if ( (int) $count !== count( $names ) ) {
			throw new Exception( "Expected $count running containers for $site, found " . count( $names ) . ': ' . implode( ', ', $names ) );
		}
	}

	/**
	 * @Then the :service container of site :site should use image :image
	 */
	public function theContainerOfSiteShouldUseImage( $service, $site, $image )
	{
		$actual = $this->inspect_site_container( $site, $service, '{{.Config.Image}}' );
		if ( 0 !== strpos( $actual, $image ) ) {
			throw new Exception( "The $service container of $site uses image $actual, expected $image*." );
		}
	}

	/**
	 * @Then the :service container of site :site should have restart policy :policy
	 */
	public function theContainerOfSiteShouldHaveRestartPolicy( $service, $site, $policy )
	{
		$actual = $this->inspect_site_container( $site, $service, '{{.HostConfig.RestartPolicy.Name}}' );
		if ( $policy !== $actual ) {
			throw new Exception( "The $service container of $site has restart policy '$actual', expected '$policy'." );
		}
	}

	/**
	 * @Then the :service container of site :site should be connected to network :network
	 */
	public function theContainerOfSiteShouldBeConnectedToNetwork( $service, $site, $network )
	{
		$networks = $this->container_networks( $site, $service );
		if ( ! in_array( $network, $networks, true ) ) {
			throw new Exception( "The $service container of $site is not on $network; networks: " . implode( ', ', $networks ) );
		}
	}

	/**
	 * @Then the :service container of site :site should not be connected to network :network
	 */
	public function theContainerOfSiteShouldNotBeConnectedToNetwork( $service, $site, $network )
	{
		$networks = $this->container_networks( $site, $service );
		if ( in_array( $network, $networks, true ) ) {
			throw new Exception( "The $service container of $site is connected to $network." );
		}
	}

	/**
	 * @Given I note the start time of the :service container of site :site
	 */
	public function iNoteTheStartTimeOfTheContainerOfSite( $service, $site )
	{
		$this->noted_start_times[ "$site/$service" ] = $this->inspect_site_container( $site, $service, '{{.State.StartedAt}}' );
	}

	/**
	 * @Then the :service container of site :site should have been restarted
	 */
	public function theContainerOfSiteShouldHaveBeenRestarted( $service, $site )
	{
		if ( ! isset( $this->noted_start_times[ "$site/$service" ] ) ) {
			throw new Exception( "No start time was noted for the $service container of $site." );
		}
		$now = $this->inspect_site_container( $site, $service, '{{.State.StartedAt}}' );
		if ( $now === $this->noted_start_times[ "$site/$service" ] ) {
			throw new Exception( "The $service container of $site was not restarted (started at $now)." );
		}
	}

	/**
	 * @Then the docker network :network should exist
	 */
	public function theDockerNetworkShouldExist( $network )
	{
		if ( 0 !== EE::launch( 'docker network inspect ' . escapeshellarg( $network ), false, true )->return_code ) {
			throw new Exception( "Docker network $network does not exist." );
		}
	}

	/**
	 * @Then the docker network :network should not exist
	 */
	public function theDockerNetworkShouldNotExist( $network )
	{
		$result = EE::launch( 'docker network ls --format "{{.Name}}"', false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not list Docker networks:\n" . $this->describe_command( $result ) );
		}
		$networks = array_filter( explode( "\n", trim( $result->stdout ) ) );
		if ( in_array( $network, $networks, true ) ) {
			throw new Exception( "Docker network $network exists." );
		}
	}

	/**
	 * @Then the site :site should have :count docker volumes
	 */
	public function theSiteShouldHaveDockerVolumes( $site, $count )
	{
		$volumes = $this->site_volumes( $site );
		if ( (int) $count !== count( $volumes ) ) {
			throw new Exception( "Expected $count volumes labelled for $site, found " . count( $volumes ) . ': ' . implode( ', ', $volumes ) );
		}
	}

	/**
	 * Runs a throwaway MariaDB (the image EE uses) on the host network, bound to one address only.
	 *
	 * @Given a MariaDB server :name is listening on :address port :port with root password :password
	 */
	public function aMariadbServerIsListening( $name, $address, $port, $password )
	{
		$this->assert_test_name( $name );
		$address = $this->replace_placeholders( $address );
		EE::launch( 'docker rm -f -v ' . escapeshellarg( $name ), false, true );

		$versions = json_decode( file_get_contents( EE_ROOT . '/img-versions.json' ), true );
		$command  = sprintf(
			'docker run -d --name %s --network host -e MYSQL_ROOT_PASSWORD=%s easyengine/mariadb:%s --port=%d --bind-address=%s',
			escapeshellarg( $name ),
			escapeshellarg( $password ),
			escapeshellarg( $versions['easyengine/mariadb'] ),
			(int) $port,
			escapeshellarg( $address )
		);
		$result = EE::launch( $command, false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not start $name:\n" . $this->describe_command( $result ) );
		}

		$this->iWaitUntilSucceedsWithin(
			sprintf( 'docker exec %s mysql -h%s -P%d -uroot -p%s -e "SELECT 1"', escapeshellarg( $name ), escapeshellarg( $address ), (int) $port, escapeshellarg( $password ) ),
			120
		);
	}

	/**
	 * @Then the global database should have a database named :name
	 */
	public function theGlobalDatabaseShouldHaveADatabaseNamed( $name )
	{
		if ( ! in_array( $name, $this->global_db_lines( 'SHOW DATABASES' ), true ) ) {
			throw new Exception( "The global database has no database named $name." );
		}
	}

	/**
	 * @Then the global database should not have a database named :name
	 */
	public function theGlobalDatabaseShouldNotHaveADatabaseNamed( $name )
	{
		if ( in_array( $name, $this->global_db_lines( 'SHOW DATABASES' ), true ) ) {
			throw new Exception( "The global database still has a database named $name." );
		}
	}

	/**
	 * @Then the global database should have a user named :user
	 */
	public function theGlobalDatabaseShouldHaveAUserNamed( $user )
	{
		if ( ! in_array( $user, $this->global_db_lines( 'SELECT User FROM mysql.user' ), true ) ) {
			throw new Exception( "The global database has no user named $user." );
		}
	}

	/**
	 * @Then the global database should not have a user named :user
	 */
	public function theGlobalDatabaseShouldNotHaveAUserNamed( $user )
	{
		if ( in_array( $user, $this->global_db_lines( 'SELECT User FROM mysql.user' ), true ) ) {
			throw new Exception( "The global database still has a user named $user." );
		}
	}

	/**
	 * @Then the global database :database should have a table named :table
	 */
	public function theGlobalDatabaseShouldHaveATableNamed( $database, $table )
	{
		$tables = $this->global_db_lines( sprintf( 'SHOW TABLES FROM `%s`', str_replace( '`', '', $database ) ) );
		if ( ! in_array( $table, $tables, true ) ) {
			throw new Exception( "Database $database has no table $table; tables: " . implode( ', ', $tables ) );
		}
	}

	/**
	 * Site DB users are `<site>-<random>`, so a deleted site's user can only be matched by prefix.
	 *
	 * @Then the global database should not have users starting with :prefix
	 */
	public function theGlobalDatabaseShouldNotHaveUsersStartingWith( $prefix )
	{
		$users = array_filter( $this->global_db_lines( 'SELECT User FROM mysql.user' ), function ( $user ) use ( $prefix ) {
			return 0 === strpos( $user, $prefix );
		} );
		if ( $users ) {
			throw new Exception( "The global database still has users starting with $prefix: " . implode( ', ', $users ) );
		}
	}

	/**
	 * Guards scenarios that inspect what an earlier scenario left behind, so they can't pass when run alone.
	 *
	 * @Given the site :site was deleted earlier in this feature
	 */
	public function theSiteWasDeletedEarlierInThisFeature( $site )
	{
		if ( ! in_array( $site, self::$deleted_sites, true ) ) {
			throw new Exception( "$site was not deleted by an earlier scenario of this feature; run the whole feature." );
		}
	}

	/**
	 * The DB user of a site is random unless --dbuser was passed, so it is read from the EE database.
	 *
	 * @Then the global database should have the user of site :site
	 */
	public function theGlobalDatabaseShouldHaveTheUserOfSite( $site )
	{
		$this->theGlobalDatabaseShouldHaveAUserNamed( $this->site_field( $site, 'db_user' ) );
	}

	/**
	 * @Given I store the key :key in the global redis
	 */
	public function iStoreTheKeyInTheGlobalRedis( $key )
	{
		$result = EE::launch( sprintf( 'docker exec %s redis-cli SET %s bh-value', self::GLOBAL_REDIS_CONTAINER, escapeshellarg( $key ) ), false, true );
		if ( 0 !== $result->return_code || false === strpos( $result->stdout, 'OK' ) ) {
			throw new Exception( "Could not store $key in global redis:\n" . $this->describe_command( $result ) );
		}
	}

	/**
	 * @Then the global redis should have keys matching :pattern
	 */
	public function theGlobalRedisShouldHaveKeysMatching( $pattern )
	{
		if ( ! $this->global_redis_keys( $pattern ) ) {
			throw new Exception( "Global redis has no key matching $pattern." );
		}
	}

	/**
	 * @Then the global redis should not have keys matching :pattern
	 */
	public function theGlobalRedisShouldNotHaveKeysMatching( $pattern )
	{
		$keys = $this->global_redis_keys( $pattern );
		if ( $keys ) {
			throw new Exception( "Global redis still has keys matching $pattern: " . implode( ', ', array_slice( $keys, 0, 10 ) ) );
		}
	}

	/**
	 * Requests go to 127.0.0.1 (the host part of the URL is resolved there), so no /etc/hosts entry is needed.
	 *
	 * @When I send a GET request to :url
	 */
	public function iSendAGetRequestTo( $url )
	{
		$this->http_request( $this->replace_placeholders( $url ), null, true );
	}

	/**
	 * @Then a GET request to :url should return :code
	 */
	public function aGetRequestToShouldReturn( $url, $code )
	{
		$this->assert_response_code( $this->http_request( $this->replace_placeholders( $url ), null, true ), $code );
	}

	/**
	 * @Then a GET request to :url with Host :host should return :code
	 */
	public function aGetRequestToWithHostShouldReturn( $url, $host, $code )
	{
		$this->assert_response_code( $this->http_request( $this->replace_placeholders( $url ), $host, false ), $code );
	}

	/**
	 * @Then a GET request to :url should return :code within :seconds seconds
	 */
	public function aGetRequestToShouldReturnWithin( $url, $code, $seconds )
	{
		$url      = $this->replace_placeholders( $url );
		$deadline = time() + (int) $seconds;
		do {
			$response = $this->http_request( $url, null, true );
			if ( (int) $code === $response['code'] ) {
				return;
			}
			sleep( 2 );
		} while ( time() < $deadline );

		$this->assert_response_code( $response, $code );
	}

	/**
	 * @Then a GET request to :url should not return :code
	 */
	public function aGetRequestToShouldNotReturn( $url, $code )
	{
		$response = $this->http_request( $this->replace_placeholders( $url ), null, true );
		// Code 0 is a transport error (no HTTP response), never a pass.
		if ( 0 === $response['code'] || (int) $code === $response['code'] ) {
			throw new Exception( "GET $url returned {$response['code']}.\n" . $response['headers'] );
		}
	}

	/**
	 * Alias updates return before the proxy serves the new name, so poll.
	 *
	 * @Then a GET request to :url should not return :code within :seconds seconds
	 */
	public function aGetRequestToShouldNotReturnWithin( $url, $code, $seconds )
	{
		$url      = $this->replace_placeholders( $url );
		$deadline = time() + (int) $seconds;
		do {
			$response = $this->http_request( $url, null, true );
			// Keep polling on a transport error (code 0), e.g. during a proxy reload.
			if ( 0 !== $response['code'] && (int) $code !== $response['code'] ) {
				return;
			}
			sleep( 2 );
		} while ( time() < $deadline );

		throw new Exception( "GET $url still returned {$response['code']} after $seconds seconds.\n" . $response['headers'] );
	}

	/**
	 * Polls until a response carries the header line, e.g. `X-SRCache-Fetch-Status: HIT`.
	 *
	 * @Then a GET request to :url should have header :header within :seconds seconds
	 */
	public function aGetRequestToShouldHaveHeaderWithin( $url, $header, $seconds )
	{
		$url      = $this->replace_placeholders( $url );
		$deadline = time() + (int) $seconds;
		do {
			$response = $this->http_request( $url, null, true );
			if ( $this->headers_contain( $response['headers'], $header ) ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );

		throw new Exception( "GET $url never returned header `$header` within $seconds seconds. Last headers:\n" . $response['headers'] );
	}

	/**
	 * @Then the response should have header :header
	 */
	public function theResponseShouldHaveHeader( $header )
	{
		$response = $this->last_response();
		if ( ! $this->headers_contain( $response['headers'], $header ) ) {
			throw new Exception( "Header `$header` not found in:\n" . $response['headers'] );
		}
	}

	/**
	 * @Then the response should not have header :name
	 */
	public function theResponseShouldNotHaveHeader( $name )
	{
		$response = $this->last_response();
		if ( preg_match( '/^' . preg_quote( $name, '/' ) . '\s*:/mi', $response['headers'] ) ) {
			throw new Exception( "Header `$name` found in:\n" . $response['headers'] );
		}
	}

	/**
	 * @Then the response should redirect to :location
	 */
	public function theResponseShouldRedirectTo( $location )
	{
		$response = $this->last_response();
		if ( ! in_array( $response['code'], [ 301, 302, 307, 308 ], true ) || ! preg_match( '/^Location:\s*' . preg_quote( $location, '/' ) . '\s*$/mi', $response['headers'] ) ) {
			throw new Exception( "Expected a redirect to $location, got:\n" . $response['headers'] );
		}
	}

	/**
	 * @Then the response body should contain :text
	 */
	public function theResponseBodyShouldContain( $text )
	{
		$response = $this->last_response();
		if ( false === strpos( $response['body'], $text ) ) {
			throw new Exception( "`$text` not found in the body of {$response['url']}:\n" . substr( $response['body'], 0, 2000 ) );
		}
	}

	/**
	 * @Given I add a hosts entry for :host
	 */
	public function iAddAHostsEntryFor( $host )
	{
		$this->assert_test_name( $host );
		$line = "127.0.0.1\t$host";
		if ( false === file_put_contents( '/etc/hosts', PHP_EOL . $line . PHP_EOL, FILE_APPEND ) ) {
			throw new Exception( 'Could not write /etc/hosts.' );
		}
		$this->added_hosts_lines[] = $line;
	}

	/**
	 * @Then the hosts file should contain :host
	 */
	public function theHostsFileShouldContain( $host )
	{
		if ( ! preg_match( '/\s' . preg_quote( $host, '/' ) . '$/m', file_get_contents( '/etc/hosts' ) ) ) {
			throw new Exception( "/etc/hosts has no entry for $host." );
		}
	}

	/**
	 * @Then the hosts file should not contain :host
	 */
	public function theHostsFileShouldNotContain( $host )
	{
		if ( preg_match( '/\s' . preg_quote( $host, '/' ) . '$/m', file_get_contents( '/etc/hosts' ) ) ) {
			throw new Exception( "/etc/hosts still has an entry for $host." );
		}
	}

	/* ------------------------------------------------------------------------------------------------------------
	 * Helpers.
	 * --------------------------------------------------------------------------------------------------------- */

	private function replace_placeholders( $text ) {
		$map = [
			'{EE_ROOT_DIR}' => EE_ROOT_DIR,
			'{SITES_DIR}'   => EE_SITE_ROOT,
			'{TMP_DIR}'     => self::TEST_TMP_DIR,
			'{HOME}'        => self::home_dir(),
		];
		if ( false !== strpos( $text, '{DOCKER_BRIDGE_IP}' ) ) {
			$map['{DOCKER_BRIDGE_IP}'] = self::docker_bridge_ip();
		}

		return strtr( $text, $map );
	}

	/**
	 * HOME as ee's shells see it (sudo -E keeps the caller's, e.g. /home/runner on CI).
	 */
	private static function home_dir() {
		$home = getenv( 'HOME' );

		return $home ? rtrim( $home, '/' ) : '/root';
	}

	private static function docker_bridge_ip() {
		$result = EE::launch( "docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}'", false, true );
		$ip     = trim( $result->stdout );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			throw new Exception( "Could not determine the docker bridge gateway:\n" . $result->stdout . $result->stderr );
		}

		return $ip;
	}

	private function last_command() {
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private function assert_last_command_succeeded() {
		$result = $this->last_command();
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Expected exit code 0.\n" . $this->describe_command( $result ) );
		}
	}

	private function describe_command( $result ) {
		return sprintf( "Command: %s\nExit code: %s\nSTDOUT:\n%s\nSTDERR:\n%s", $result->command, $result->return_code, $result->stdout, $result->stderr );
	}

	private function stream_output( $stream ) {
		$result = $this->last_command();
		if ( 'STDOUT' === $stream ) {
			$output = $result->stdout;
		} elseif ( 'STDERR' === $stream ) {
			$output = $result->stderr;
		} else {
			$output = $result->stdout . "\n" . $result->stderr;
		}

		return str_replace( [ "\033[1;31m", "\033[0m", "\033[32m", "\033[33m", "\033[1;33m", "\033[1;32m" ], '', $output );
	}

	private function assert_output_lines( $haystack, PyStringNode $expected, $should_contain, $label ) {
		foreach ( $expected->getStrings() as $line ) {
			$line = $this->replace_placeholders( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$found = false !== strpos( $haystack, $line );
			if ( $found !== $should_contain ) {
				$context = empty( $this->commands ) ? '' : "\n\n" . $this->describe_command( $this->last_command() );
				throw new Exception( sprintf( "%s %s `%s`. Actual %s:\n%s%s", ucfirst( $label ), $should_contain ? 'does not contain' : 'contains', $line, $label, $haystack, 0 === strpos( $label, 'file' ) ? '' : $context ) );
			}
		}
	}

	private function read_file( $path ) {
		$path = $this->replace_placeholders( $path );
		if ( ! is_file( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}

		return file_get_contents( $path );
	}

	private function scalar_to_string( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return null === $value ? '' : (string) $value;
	}

	private function value_matches( $actual, $expected ) {
		if ( '<empty>' === $expected ) {
			return '' === $actual || '0' === $actual;
		}
		if ( '<not empty>' === $expected ) {
			return '' !== $actual;
		}
		if ( strlen( $expected ) > 2 && '/' === $expected[0] && '/' === substr( $expected, -1 ) ) {
			return (bool) preg_match( $expected, $actual );
		}

		return $expected === $actual;
	}

	private function ee_db() {
		$pdo = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		return $pdo;
	}

	private function assert_identifier( $name ) {
		if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/i', $name ) ) {
			throw new Exception( "Invalid SQL identifier: $name" );
		}
	}

	private function site_row( $site ) {
		$statement = $this->ee_db()->prepare( 'SELECT * FROM sites WHERE site_url = ?' );
		$statement->execute( [ $site ] );

		return $statement->fetch( PDO::FETCH_ASSOC );
	}

	private function site_field( $site, $field ) {
		$row = $this->site_row( $site );
		if ( ! $row || ! array_key_exists( $field, $row ) ) {
			throw new Exception( "Site $site or its field $field not found in the EE database." );
		}

		return $this->scalar_to_string( $row[ $field ] );
	}

	private function assert_site_field( $site, $field, $expected ) {
		$actual = $this->site_field( $site, $field );
		if ( $expected !== $actual ) {
			throw new Exception( "`$field` of $site is '$actual', expected '$expected'." );
		}
	}

	private function run_wp_cli( $site, $command ) {
		$command = sprintf(
			'cd %s && docker-compose exec -T --user=www-data php sh -c %s',
			escapeshellarg( EE_SITE_ROOT . '/' . $site ),
			escapeshellarg( 'wp ' . $command )
		);
		$result           = EE::launch( $command, false, true );
		$this->commands[] = $result;

		return $result;
	}

	private function site_container_id( $site, $service, $include_stopped = true ) {
		$command = sprintf(
			'docker ps %s -q --filter %s --filter %s',
			$include_stopped ? '-a' : '',
			escapeshellarg( "label=io.easyengine.site=$site" ),
			escapeshellarg( "label=com.docker.compose.service=$service" )
		);
		$result = EE::launch( $command, false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not list site containers:\n" . $this->describe_command( $result ) );
		}
		$ids = array_filter( explode( "\n", trim( $result->stdout ) ) );

		return $ids ? reset( $ids ) : '';
	}

	private function inspect_site_container( $site, $service, $format ) {
		$id = $this->site_container_id( $site, $service );
		if ( '' === $id ) {
			throw new Exception( "No $service container exists for $site." );
		}

		$result = EE::launch( 'docker inspect -f ' . escapeshellarg( $format ) . " $id", false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not inspect the $service container of $site:\n" . $this->describe_command( $result ) );
		}

		return trim( $result->stdout );
	}

	private function container_networks( $site, $service ) {
		$networks = json_decode( $this->inspect_site_container( $site, $service, '{{json .NetworkSettings.Networks}}' ), true );

		return is_array( $networks ) ? array_keys( $networks ) : [];
	}

	private static function site_volumes( $site ) {
		$result = EE::launch( 'docker volume ls -q --filter ' . escapeshellarg( "label=io.easyengine.site=$site" ), false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not list site volumes:\n" . $result->stdout . $result->stderr );
		}

		return array_values( array_filter( explode( "\n", trim( $result->stdout ) ) ) );
	}

	private static function global_db_query( $sql ) {
		$script = 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e ' . escapeshellarg( $sql );

		return EE::launch( sprintf( 'docker exec %s sh -c %s', self::GLOBAL_DB_CONTAINER, escapeshellarg( $script ) ), false, true );
	}

	private function global_db_lines( $sql ) {
		$result = self::global_db_query( $sql );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Global DB query failed:\n" . $this->describe_command( $result ) );
		}

		return array_map( 'trim', array_filter( explode( "\n", trim( $result->stdout ) ) ) );
	}

	private function global_redis_keys( $pattern ) {
		$result = EE::launch( sprintf( 'docker exec %s redis-cli --scan --pattern %s', self::GLOBAL_REDIS_CONTAINER, escapeshellarg( $pattern ) ), false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not scan global redis:\n" . $this->describe_command( $result ) );
		}

		return array_values( array_filter( explode( "\n", trim( $result->stdout ) ) ) );
	}

	/**
	 * @param string      $url       Full URL. With $resolve the URL's host is pinned to 127.0.0.1 (keeps SNI correct).
	 * @param string|null $host      Explicit Host header (for URLs pointing at an IP).
	 * @param bool        $resolve   Resolve the URL host to 127.0.0.1.
	 * @param array       $headers   Extra raw request headers.
	 */
	private function http_request( $url, $host = null, $resolve = true, $headers = [] ) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		if ( $resolve ) {
			$url_host = parse_url( $url, PHP_URL_HOST );
			$port     = parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 );
			curl_setopt( $ch, CURLOPT_RESOLVE, [ "$url_host:$port:127.0.0.1" ] );
		}
		if ( null !== $host ) {
			$headers[] = 'Host: ' . $host;
		}
		if ( $headers ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}
		$raw         = curl_exec( $ch );
		$code        = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error       = curl_error( $ch );
		curl_close( $ch );

		$this->response = [
			'url'     => $url,
			'code'    => $code,
			'headers' => false === $raw ? "(request failed: $error)" : substr( $raw, 0, $header_size ),
			'body'    => false === $raw ? '' : (string) substr( $raw, $header_size ),
		];

		return $this->response;
	}

	private function last_response() {
		if ( null === $this->response ) {
			throw new Exception( 'No HTTP request has been sent in this scenario.' );
		}

		return $this->response;
	}

	private function assert_response_code( $response, $code ) {
		if ( (int) $code !== $response['code'] ) {
			throw new Exception( "GET {$response['url']} returned {$response['code']}, expected $code.\n" . $response['headers'] . substr( $response['body'], 0, 1000 ) );
		}
	}

	private function headers_contain( $headers, $header ) {
		return false !== stripos( $headers, $header );
	}

	private function assert_headers_contain_rows( $headers, TableNode $table ) {
		foreach ( $table->getHash() as $row ) {
			if ( ! $this->headers_contain( $headers, $row['header'] ) ) {
				throw new Exception( 'Unable to find ' . $row['header'] . "\nActual output is : " . $headers );
			}
		}
	}

	private function assert_test_name( $name ) {
		if ( false === strpos( $name, self::TEST_PREFIX ) ) {
			throw new Exception( "Refusing to touch `$name`: test fixtures must contain " . self::TEST_PREFIX );
		}
	}

	private function remove_added_hosts_lines() {
		if ( empty( $this->added_hosts_lines ) ) {
			return;
		}
		$lines = file( '/etc/hosts', FILE_IGNORE_NEW_LINES );
		foreach ( $this->added_hosts_lines as $added ) {
			$index = array_search( $added, $lines, true );
			if ( false !== $index ) {
				unset( $lines[ $index ] );
			}
		}
		file_put_contents( '/etc/hosts', implode( PHP_EOL, $lines ) . PHP_EOL );
		$this->added_hosts_lines = [];
	}

	/**
	 * Deletes everything this suite may have left behind; each item is matched by the test prefix.
	 */
	private static function cleanup_test_artifacts() {
		$ee = escapeshellarg( realpath( EE_ROOT ) . '/bin/ee' );

		$pdo   = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
		$sites = $pdo->query( 'SELECT site_url FROM sites' )->fetchAll( PDO::FETCH_COLUMN );
		foreach ( $sites as $site ) {
			if ( false !== strpos( $site, self::TEST_PREFIX ) ) {
				// --foreground: in a background process group the `docker exec -it` of a cache purge stops on SIGTTOU (with timeout itself) and hangs forever.
				EE::launch( "timeout --foreground -k 30 600 $ee site delete " . escapeshellarg( $site ) . ' --yes', false, true );
			}
		}

		// Webroots left without a DB row (failed creates, fixtures).
		foreach ( glob( EE_SITE_ROOT . '/*' . self::TEST_PREFIX . '*', GLOB_NOSORT ) ?: [] as $dir ) {
			EE::launch( 'rm -rf ' . escapeshellarg( $dir ), false, true );
		}

		// Containers (external DB fixtures and orphans of failed creates), then volumes and networks.
		$containers = EE::launch( 'docker ps -a --format "{{.ID}} {{.Names}} {{.Label \"io.easyengine.site\"}}"', false, true )->stdout;
		foreach ( array_filter( explode( "\n", trim( $containers ) ) ) as $line ) {
			if ( false !== strpos( $line, self::TEST_PREFIX ) || false !== strpos( $line, str_replace( '-', '', self::TEST_PREFIX ) ) ) {
				EE::launch( 'docker rm -f -v ' . strtok( $line, ' ' ), false, true );
			}
		}
		$volumes = EE::launch( 'docker volume ls --filter label=org.label-schema.vendor=EasyEngine --format "{{.Name}} {{.Label \"io.easyengine.site\"}}"', false, true )->stdout;
		foreach ( array_filter( explode( "\n", trim( $volumes ) ) ) as $line ) {
			if ( false !== strpos( $line, self::TEST_PREFIX ) ) {
				EE::launch( 'docker volume rm ' . escapeshellarg( strtok( $line, ' ' ) ), false, true );
			}
		}
		$networks = EE::launch( 'docker network ls --format "{{.Name}}"', false, true )->stdout;
		foreach ( array_filter( explode( "\n", trim( $networks ) ) ) as $network ) {
			if ( false !== strpos( $network, self::TEST_PREFIX ) ) {
				EE::launch( 'docker network rm ' . escapeshellarg( $network ), false, true );
			}
		}

		// Global DB databases and users (also leaked by failed creates).
		$databases = self::global_db_query( 'SHOW DATABASES' );
		foreach ( array_filter( explode( "\n", trim( $databases->stdout ) ) ) as $database ) {
			if ( false !== strpos( $database, self::TEST_DB_PREFIX ) ) {
				self::global_db_query( sprintf( 'DROP DATABASE `%s`', $database ) );
			}
		}
		$users = self::global_db_query( 'SELECT User FROM mysql.user' );
		foreach ( array_filter( explode( "\n", trim( $users->stdout ) ) ) as $user ) {
			if ( false !== strpos( $user, self::TEST_PREFIX ) || false !== strpos( $user, self::TEST_DB_PREFIX ) ) {
				self::global_db_query( sprintf( "DROP USER '%s'@'%%'", str_replace( "'", '', $user ) ) );
			}
		}

		// Global redis keys written by cache scenarios.
		EE::launch( sprintf( 'docker exec %s sh -c %s', self::GLOBAL_REDIS_CONTAINER, escapeshellarg( "redis-cli --scan --pattern '" . self::TEST_PREFIX . "*' | xargs -r redis-cli DEL" ) ), false, true );

		// Hosts entries (EE's own and the ones added by scenarios).
		$hosts = file( '/etc/hosts', FILE_IGNORE_NEW_LINES );
		if ( false !== $hosts ) {
			$kept = array_filter( $hosts, function ( $line ) {
				return false === strpos( $line, self::TEST_PREFIX );
			} );
			if ( count( $kept ) !== count( $hosts ) ) {
				file_put_contents( '/etc/hosts', implode( PHP_EOL, $kept ) . PHP_EOL );
			}
		}

		// Proxy certs and ACME state: `--ssl=off` and LE deletes leave them behind.
		$proxy_dir = EE_ROOT_DIR . '/services/nginx-proxy';
		foreach ( [ 'certs', 'acme-conf/certs', 'acme-conf/var' ] as $sub ) {
			foreach ( glob( "$proxy_dir/$sub/*" . self::TEST_PREFIX . '*', GLOB_NOSORT ) ?: [] as $path ) {
				EE::launch( 'rm -rf ' . escapeshellarg( $path ), false, true );
			}
		}

		// Backups written by `ee site update --ssl` search-replace, fixtures and injection markers.
		foreach ( glob( EE_ROOT_DIR . '/.backup/*' . self::TEST_PREFIX . '*', GLOB_NOSORT ) ?: [] as $dir ) {
			EE::launch( 'rm -rf ' . escapeshellarg( $dir ), false, true );
		}
		EE::launch( 'rm -rf ' . escapeshellarg( self::TEST_TMP_DIR ) . ' ' . escapeshellarg( self::home_dir() . '/bhsitetypewpsec1pwned' ) . ' /root/bhsitetypewpsec1pwned', false, true );
	}
}
