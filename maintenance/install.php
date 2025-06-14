<?php
/**
 * CLI-based MediaWiki installation and configuration.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

// NO_AUTOLOAD -- file-scope define() used to modify behaviour

use Wikimedia\AtEase\AtEase;

require_once __DIR__ . '/Maintenance.php';

define( 'MW_CONFIG_CALLBACK', 'Installer::overrideConfig' );
define( 'MEDIAWIKI_INSTALL', true );

/**
 * Maintenance script to install and configure MediaWiki
 *
 * Default values for the options are defined in MainConfigSchema.php
 * (see the mapping in CliInstaller.php)
 * Default for --dbpath (SQLite-specific) is defined in SqliteInstaller::getGlobalDefaults
 *
 * @ingroup Maintenance
 */
class CommandLineInstaller extends Maintenance {
	public function __construct() {
		parent::__construct();
		global $IP;

		$this->addDescription( "CLI-based MediaWiki installation and configuration.\n" .
			"Default options are indicated in parentheses." );

		$this->addArg( 'name', 'The name of the wiki (MediaWiki)', false );

		$this->addArg( 'admin', 'The username of the wiki administrator.' );
		$this->addOption( 'pass', 'The password for the wiki administrator.', false, true );
		$this->addOption(
			'passfile',
			'An alternative way to provide pass option, as the contents of this file',
			false,
			true
		);
		/* $this->addOption( 'email', 'The email for the wiki administrator', false, true ); */
		$this->addOption(
			'scriptpath',
			'The relative path of the wiki in the web server (/wiki)',
			false,
			true
		);
		$this->addOption(
			'server',
			'The base URL of the web server the wiki will be on (http://localhost)',
			false,
			true
		);

		$this->addOption( 'lang', 'The language to use (en)', false, true );
		/* $this->addOption( 'cont-lang', 'The content language (en)', false, true ); */

		$this->addOption( 'dbtype', 'The type of database (mysql)', false, true );
		$this->addOption( 'dbserver', 'The database host (localhost)', false, true );
		$this->addOption( 'dbssl', 'Connect to the database over SSL' );
		$this->addOption( 'dbport', 'The database port; only for PostgreSQL (5432)', false, true );
		$this->addOption( 'dbname', 'The database name (my_wiki)', false, true );
		$this->addOption( 'dbpath', 'The path for the SQLite DB ($IP/data)', false, true );
		$this->addOption( 'dbprefix', 'Optional database table name prefix', false, true );
		$this->addOption( 'installdbuser', 'The user to use for installing (root)', false, true );
		$this->addOption( 'installdbpass', 'The password for the DB user to install as.', false, true );
		$this->addOption( 'dbuser', 'The user to use for normal operations (wikiuser)', false, true );
		$this->addOption( 'dbpass', 'The password for the DB user for normal operations', false, true );
		$this->addOption(
			'dbpassfile',
			'An alternative way to provide dbpass option, as the contents of this file',
			false,
			true
		);
		$this->addOption( 'confpath', "Path to write LocalSettings.php to ($IP)", false, true );
		$this->addOption( 'dbschema', 'The schema for the MediaWiki DB in '
			. 'PostgreSQL (mediawiki)', false, true );
		/*
		$this->addOption( 'namespace', 'The project namespace (same as the "name" argument)',
			false, true );
		*/
		$this->addOption( 'env-checks', "Run environment checks only, don't change anything" );

		$this->addOption( 'with-extensions', "Detect and include extensions" );
		$this->addOption( 'extensions', 'Comma-separated list of extensions to install',
			false, true, false, true );
		$this->addOption( 'skins', 'Comma-separated list of skins to install (default: all)',
			false, true, false, true );
	}

	public function getDbType() {
		if ( $this->hasOption( 'env-checks' ) ) {
			return Maintenance::DB_NONE;
		}
		return parent::getDbType();
	}

	public function execute() {
		global $IP;

		$siteName = $this->getArg( 0, 'MediaWiki' ); // Will not be set if used with --env-checks
		$adminName = $this->getArg( 1 );
		$envChecksOnly = $this->hasOption( 'env-checks' );

		$this->setDbPassOption();
		if ( !$envChecksOnly ) {
			$this->setPassOption();
		}

		try {
			$installer = InstallerOverrides::getCliInstaller( $siteName, $adminName, $this->mOptions );
		} catch ( \MediaWiki\Installer\InstallException $e ) {
			$this->output( $e->getStatus()->getMessage( false, false, 'en' )->text() . "\n" );
			return false;
		}

		$status = $installer->doEnvironmentChecks();
		if ( $status->isGood() ) {
			$installer->showMessage( 'config-env-good' );
		} else {
			$installer->showStatusMessage( $status );

			return false;
		}
		if ( !$envChecksOnly ) {
			$status = $installer->execute();
			if ( !$status->isGood() ) {
				$installer->showStatusMessage( $status );

				return false;
			}
			$installer->writeConfigurationFile( $this->getOption( 'confpath', $IP ) );
			$installer->showMessage(
				'config-install-success',
				$installer->getVar( 'wgServer' ),
				$installer->getVar( 'wgScriptPath' )
			);
		}
		return true;
	}

	private function setDbPassOption() {
		$dbpassfile = $this->getOption( 'dbpassfile' );
		if ( $dbpassfile !== null ) {
			if ( $this->getOption( 'dbpass' ) !== null ) {
				$this->error( 'WARNING: You have provided the options "dbpass" and "dbpassfile". '
					. 'The content of "dbpassfile" overrides "dbpass".' );
			}
			AtEase::suppressWarnings();
			$dbpass = file_get_contents( $dbpassfile ); // returns false on failure
			AtEase::restoreWarnings();
			if ( $dbpass === false ) {
				$this->fatalError( "Couldn't open $dbpassfile" );
			}
			$this->mOptions['dbpass'] = trim( $dbpass, "\r\n" );
		}
	}

	private function setPassOption() {
		$passfile = $this->getOption( 'passfile' );
		if ( $passfile !== null ) {
			if ( $this->getOption( 'pass' ) !== null ) {
				$this->error( 'WARNING: You have provided the options "pass" and "passfile". '
					. 'The content of "passfile" overrides "pass".' );
			}
			AtEase::suppressWarnings();
			$pass = file_get_contents( $passfile ); // returns false on failure
			AtEase::restoreWarnings();
			if ( $pass === false ) {
				$this->fatalError( "Couldn't open $passfile" );
			}
			$this->mOptions['pass'] = trim( $pass, "\r\n" );
		} elseif ( $this->getOption( 'pass' ) === null ) {
			$this->fatalError( 'You need to provide the option "pass" or "passfile"' );
		}
	}

	public function validateParamsAndArgs() {
		if ( !$this->hasOption( 'env-checks' ) ) {
			parent::validateParamsAndArgs();
		}
	}
}

$maintClass = CommandLineInstaller::class;

require_once RUN_MAINTENANCE_IF_MAIN;
