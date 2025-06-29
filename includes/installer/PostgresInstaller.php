<?php
/**
 * PostgreSQL-specific installer.
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
 * @ingroup Installer
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\DatabasePostgres;
use Wikimedia\Rdbms\DBConnectionError;
use Wikimedia\Rdbms\DBQueryError;

/**
 * Class for setting up the MediaWiki database using Postgres.
 *
 * @ingroup Installer
 * @since 1.17
 */
class PostgresInstaller extends DatabaseInstaller {

	protected $globalNames = [
		'wgDBserver',
		'wgDBport',
		'wgDBname',
		'wgDBuser',
		'wgDBpassword',
		'wgDBssl',
		'wgDBmwschema',
	];

	protected $internalDefaults = [
		'_InstallUser' => 'postgres',
	];

	public static $minimumVersion = '10';
	protected static $notMinimumVersionMessage = 'config-postgres-old';
	public $maxRoleSearchDepth = 5;

	protected $pgConns = [];

	public function getName() {
		return 'postgres';
	}

	public function isCompiled() {
		return self::checkExtension( 'pgsql' );
	}

	public function getConnectForm() {
		return $this->getTextBox(
			'wgDBserver',
			'config-db-host',
			[],
			$this->parent->getHelpBox( 'config-db-host-help' )
		) .
			$this->getTextBox( 'wgDBport', 'config-db-port' ) .
			$this->getCheckBox( 'wgDBssl', 'config-db-ssl' ) .
			Html::openElement( 'fieldset' ) .
			Html::element( 'legend', [], wfMessage( 'config-db-wiki-settings' )->text() ) .
			$this->getTextBox(
				'wgDBname',
				'config-db-name',
				[],
				$this->parent->getHelpBox( 'config-db-name-help' )
			) .
			$this->getTextBox(
				'wgDBmwschema',
				'config-db-schema',
				[],
				$this->parent->getHelpBox( 'config-db-schema-help' )
			) .
			Html::closeElement( 'fieldset' ) .
			$this->getInstallUserBox();
	}

	public function submitConnectForm() {
		// Get variables from the request
		$newValues = $this->setVarsFromRequest( [
			'wgDBserver',
			'wgDBport',
			'wgDBssl',
			'wgDBname',
			'wgDBmwschema'
		] );

		// Validate them
		$status = Status::newGood();
		if ( !strlen( $newValues['wgDBname'] ) ) {
			$status->fatal( 'config-missing-db-name' );
		} elseif ( !preg_match( '/^[a-zA-Z0-9_]+$/', $newValues['wgDBname'] ) ) {
			$status->fatal( 'config-invalid-db-name', $newValues['wgDBname'] );
		}
		if ( !preg_match( '/^[a-zA-Z0-9_]*$/', $newValues['wgDBmwschema'] ) ) {
			$status->fatal( 'config-invalid-schema', $newValues['wgDBmwschema'] );
		}

		// Submit user box
		if ( $status->isOK() ) {
			$status->merge( $this->submitInstallUserBox() );
		}
		if ( !$status->isOK() ) {
			return $status;
		}

		$status = $this->getPgConnection( 'create-db' );
		if ( !$status->isOK() ) {
			return $status;
		}
		/**
		 * @var Database $conn
		 */
		$conn = $status->value;

		// Check version
		$status = static::meetsMinimumRequirement( $conn );
		if ( !$status->isOK() ) {
			return $status;
		}

		$this->setVar( 'wgDBuser', $this->getVar( '_InstallUser' ) );
		$this->setVar( 'wgDBpassword', $this->getVar( '_InstallPassword' ) );

		return Status::newGood();
	}

	public function getConnection() {
		$status = $this->getPgConnection( 'create-tables' );
		if ( $status->isOK() ) {
			$this->db = $status->value;
		}

		return $status;
	}

	public function openConnection() {
		return $this->openPgConnection( 'create-tables' );
	}

	/**
	 * Open a PG connection with given parameters
	 * @param string $user User name
	 * @param string $password
	 * @param string $dbName Database name
	 * @param string $schema Database schema
	 * @return Status
	 */
	protected function openConnectionWithParams( $user, $password, $dbName, $schema ) {
		$status = Status::newGood();
		try {
			$db = MediaWikiServices::getInstance()->getDatabaseFactory()->create( 'postgres', [
				'host' => $this->getVar( 'wgDBserver' ),
				'port' => $this->getVar( 'wgDBport' ),
				'user' => $user,
				'password' => $password,
				'ssl' => $this->getVar( 'wgDBssl' ),
				'dbname' => $dbName,
				'schema' => $schema,
			] );
			$status->value = $db;
		} catch ( DBConnectionError $e ) {
			$status->fatal( 'config-connection-error', $e->getMessage() );
		}

		return $status;
	}

	/**
	 * Get a special type of connection
	 * @param string $type See openPgConnection() for details.
	 * @return Status
	 */
	protected function getPgConnection( $type ) {
		if ( isset( $this->pgConns[$type] ) ) {
			return Status::newGood( $this->pgConns[$type] );
		}
		$status = $this->openPgConnection( $type );

		if ( $status->isOK() ) {
			/**
			 * @var Database $conn
			 */
			$conn = $status->value;
			$conn->clearFlag( DBO_TRX );
			$conn->commit( __METHOD__ );
			$this->pgConns[$type] = $conn;
		}

		return $status;
	}

	/**
	 * Get a connection of a specific PostgreSQL-specific type. Connections
	 * of a given type are cached.
	 *
	 * PostgreSQL lacks cross-database operations, so after the new database is
	 * created, you need to make a separate connection to connect to that
	 * database and add tables to it.
	 *
	 * New tables are owned by the user that creates them, and MediaWiki's
	 * PostgreSQL support has always assumed that the table owner will be
	 * $wgDBuser. So before we create new tables, we either need to either
	 * connect as the other user or to execute a SET ROLE command. Using a
	 * separate connection for this allows us to avoid accidental cross-module
	 * dependencies.
	 *
	 * @param string $type The type of connection to get:
	 *    - create-db:     A connection for creating DBs, suitable for pre-
	 *                     installation.
	 *    - create-schema: A connection to the new DB, for creating schemas and
	 *                     other similar objects in the new DB.
	 *    - create-tables: A connection with a role suitable for creating tables.
	 *
	 * @throws MWException
	 * @return Status On success, a connection object will be in the value member.
	 */
	protected function openPgConnection( $type ) {
		switch ( $type ) {
			case 'create-db':
				return $this->openConnectionToAnyDB(
					$this->getVar( '_InstallUser' ),
					$this->getVar( '_InstallPassword' ) );
			case 'create-schema':
				return $this->openConnectionWithParams(
					$this->getVar( '_InstallUser' ),
					$this->getVar( '_InstallPassword' ),
					$this->getVar( 'wgDBname' ),
					$this->getVar( 'wgDBmwschema' ) );
			case 'create-tables':
				$status = $this->openPgConnection( 'create-schema' );
				if ( $status->isOK() ) {
					/**
					 * @var Database $conn
					 */
					$conn = $status->value;
					$safeRole = $conn->addIdentifierQuotes( $this->getVar( 'wgDBuser' ) );
					$conn->query( "SET ROLE $safeRole", __METHOD__ );
				}

				return $status;
			default:
				throw new MWException( "Invalid special connection type: \"$type\"" );
		}
	}

	public function openConnectionToAnyDB( $user, $password ) {
		$dbs = [
			'template1',
			'postgres',
		];
		if ( !in_array( $this->getVar( 'wgDBname' ), $dbs ) ) {
			array_unshift( $dbs, $this->getVar( 'wgDBname' ) );
		}
		$conn = false;
		$status = Status::newGood();
		foreach ( $dbs as $db ) {
			try {
				$p = [
					'host' => $this->getVar( 'wgDBserver' ),
					'port' => $this->getVar( 'wgDBport' ),
					'user' => $user,
					'password' => $password,
					'ssl' => $this->getVar( 'wgDBssl' ),
					'dbname' => $db
				];
				$conn = Database::factory( 'postgres', $p );
			} catch ( DBConnectionError $error ) {
				$conn = false;
				$status->fatal( 'config-pg-test-error', $db,
					$error->getMessage() );
			}
			if ( $conn !== false ) {
				break;
			}
		}
		if ( $conn !== false ) {
			return Status::newGood( $conn );
		} else {
			return $status;
		}
	}

	protected function getInstallUserPermissions() {
		$status = $this->getPgConnection( 'create-db' );
		if ( !$status->isOK() ) {
			return false;
		}
		/**
		 * @var Database $conn
		 */
		$conn = $status->value;
		$superuser = $this->getVar( '_InstallUser' );

		$row = $conn->selectRow( '"pg_catalog"."pg_roles"', '*',
			[ 'rolname' => $superuser ], __METHOD__ );

		return $row;
	}

	protected function canCreateAccounts() {
		$perms = $this->getInstallUserPermissions();
		if ( !$perms ) {
			return false;
		}

		return $perms->rolsuper === 't' || $perms->rolcreaterole === 't';
	}

	protected function isSuperUser() {
		$perms = $this->getInstallUserPermissions();
		if ( !$perms ) {
			return false;
		}

		return $perms->rolsuper === 't';
	}

	public function getSettingsForm() {
		if ( $this->canCreateAccounts() ) {
			$noCreateMsg = false;
		} else {
			$noCreateMsg = 'config-db-web-no-create-privs';
		}
		$s = $this->getWebUserBox( $noCreateMsg );

		return $s;
	}

	public function submitSettingsForm() {
		$status = $this->submitWebUserBox();
		if ( !$status->isOK() ) {
			return $status;
		}

		$same = $this->getVar( 'wgDBuser' ) === $this->getVar( '_InstallUser' );

		if ( $same ) {
			$exists = true;
		} else {
			// Check if the web user exists
			// Connect to the database with the install user
			$status = $this->getPgConnection( 'create-db' );
			if ( !$status->isOK() ) {
				return $status;
			}
			// @phan-suppress-next-line PhanUndeclaredMethod
			$exists = $status->value->roleExists( $this->getVar( 'wgDBuser' ) );
		}

		// Validate the create checkbox
		if ( $this->canCreateAccounts() && !$same && !$exists ) {
			$create = $this->getVar( '_CreateDBAccount' );
		} else {
			$this->setVar( '_CreateDBAccount', false );
			$create = false;
		}

		if ( !$create && !$exists ) {
			if ( $this->canCreateAccounts() ) {
				$msg = 'config-install-user-missing-create';
			} else {
				$msg = 'config-install-user-missing';
			}

			return Status::newFatal( $msg, $this->getVar( 'wgDBuser' ) );
		}

		if ( !$exists ) {
			// No more checks to do
			return Status::newGood();
		}

		// Existing web account. Test the connection.
		$status = $this->openConnectionToAnyDB(
			$this->getVar( 'wgDBuser' ),
			$this->getVar( 'wgDBpassword' ) );
		if ( !$status->isOK() ) {
			return $status;
		}

		// The web user is conventionally the table owner in PostgreSQL
		// installations. Make sure the install user is able to create
		// objects on behalf of the web user.
		if ( $same || $this->canCreateObjectsForWebUser() ) {
			return Status::newGood();
		} else {
			return Status::newFatal( 'config-pg-not-in-role' );
		}
	}

	/**
	 * Returns true if the install user is able to create objects owned
	 * by the web user, false otherwise.
	 * @return bool
	 */
	protected function canCreateObjectsForWebUser() {
		if ( $this->isSuperUser() ) {
			return true;
		}

		$status = $this->getPgConnection( 'create-db' );
		if ( !$status->isOK() ) {
			return false;
		}
		$conn = $status->value;
		$installerId = $conn->selectField( '"pg_catalog"."pg_roles"', 'oid',
			[ 'rolname' => $this->getVar( '_InstallUser' ) ], __METHOD__ );
		$webId = $conn->selectField( '"pg_catalog"."pg_roles"', 'oid',
			[ 'rolname' => $this->getVar( 'wgDBuser' ) ], __METHOD__ );

		return $this->isRoleMember( $conn, $installerId, $webId, $this->maxRoleSearchDepth );
	}

	/**
	 * Recursive helper for canCreateObjectsForWebUser().
	 * @param Database $conn
	 * @param int $targetMember Role ID of the member to look for
	 * @param int $group Role ID of the group to look for
	 * @param int $maxDepth Maximum recursive search depth
	 * @return bool
	 */
	protected function isRoleMember( $conn, $targetMember, $group, $maxDepth ) {
		if ( $targetMember === $group ) {
			// A role is always a member of itself
			return true;
		}
		// Get all members of the given group
		$res = $conn->select( '"pg_catalog"."pg_auth_members"', [ 'member' ],
			[ 'roleid' => $group ], __METHOD__ );
		foreach ( $res as $row ) {
			if ( $row->member == $targetMember ) {
				// Found target member
				return true;
			}
			// Recursively search each member of the group to see if the target
			// is a member of it, up to the given maximum depth.
			if ( $maxDepth > 0 &&
				$this->isRoleMember( $conn, $targetMember, $row->member, $maxDepth - 1 )
			) {
				// Found member of member
				return true;
			}
		}

		return false;
	}

	public function preInstall() {
		$createDbAccount = [
			'name' => 'user',
			'callback' => [ $this, 'setupUser' ],
		];
		$commitCB = [
			'name' => 'pg-commit',
			'callback' => [ $this, 'commitChanges' ],
		];
		$plpgCB = [
			'name' => 'pg-plpgsql',
			'callback' => [ $this, 'setupPLpgSQL' ],
		];
		$schemaCB = [
			'name' => 'schema',
			'callback' => [ $this, 'setupSchema' ]
		];

		if ( $this->getVar( '_CreateDBAccount' ) ) {
			$this->parent->addInstallStep( $createDbAccount, 'database' );
		}
		$this->parent->addInstallStep( $commitCB, 'interwiki' );
		$this->parent->addInstallStep( $plpgCB, 'database' );
		$this->parent->addInstallStep( $schemaCB, 'database' );
	}

	public function setupDatabase() {
		$status = $this->getPgConnection( 'create-db' );
		if ( !$status->isOK() ) {
			return $status;
		}
		$conn = $status->value;

		$dbName = $this->getVar( 'wgDBname' );

		$exists = (bool)$conn->selectField( '"pg_catalog"."pg_database"', '1',
			[ 'datname' => $dbName ], __METHOD__ );
		if ( !$exists ) {
			$safedb = $conn->addIdentifierQuotes( $dbName );
			$conn->query( "CREATE DATABASE $safedb", __METHOD__ );
		}

		return Status::newGood();
	}

	public function setupSchema() {
		// Get a connection to the target database
		$status = $this->getPgConnection( 'create-schema' );
		if ( !$status->isOK() ) {
			return $status;
		}
		/** @var DatabasePostgres $conn */
		$conn = $status->value;
		'@phan-var DatabasePostgres $conn';

		// Create the schema if necessary
		$schema = $this->getVar( 'wgDBmwschema' );
		$safeschema = $conn->addIdentifierQuotes( $schema );
		$safeuser = $conn->addIdentifierQuotes( $this->getVar( 'wgDBuser' ) );
		if ( !$conn->schemaExists( $schema ) ) {
			try {
				$conn->query( "CREATE SCHEMA $safeschema AUTHORIZATION $safeuser", __METHOD__ );
			} catch ( DBQueryError $e ) {
				return Status::newFatal( 'config-install-pg-schema-failed',
					$this->getVar( '_InstallUser' ), $schema );
			}
		}

		// Select the new schema in the current connection
		$conn->determineCoreSchema( $schema );

		return Status::newGood();
	}

	public function commitChanges() {
		$this->db->commit( __METHOD__ );

		return Status::newGood();
	}

	public function setupUser() {
		if ( !$this->getVar( '_CreateDBAccount' ) ) {
			return Status::newGood();
		}

		$status = $this->getPgConnection( 'create-db' );
		if ( !$status->isOK() ) {
			return $status;
		}
		/** @var DatabasePostgres $conn */
		$conn = $status->value;
		'@phan-var DatabasePostgres $conn';

		$safeuser = $conn->addIdentifierQuotes( $this->getVar( 'wgDBuser' ) );
		$safepass = $conn->addQuotes( $this->getVar( 'wgDBpassword' ) );

		// Check if the user already exists
		$userExists = $conn->roleExists( $this->getVar( 'wgDBuser' ) );
		if ( !$userExists ) {
			// Create the user
			try {
				$sql = "CREATE ROLE $safeuser NOCREATEDB LOGIN PASSWORD $safepass";

				// If the install user is not a superuser, we need to make the install
				// user a member of the new user's group, so that the install user will
				// be able to create a schema and other objects on behalf of the new user.
				if ( !$this->isSuperUser() ) {
					$sql .= ' ROLE' . $conn->addIdentifierQuotes( $this->getVar( '_InstallUser' ) );
				}

				$conn->query( $sql, __METHOD__ );
			} catch ( DBQueryError $e ) {
				return Status::newFatal( 'config-install-user-create-failed',
					$this->getVar( 'wgDBuser' ), $e->getMessage() );
			}
		}

		return Status::newGood();
	}

	public function getLocalSettings() {
		$port = $this->getVar( 'wgDBport' );
		$useSsl = $this->getVar( 'wgDBssl' ) ? 'true' : 'false';
		$schema = $this->getVar( 'wgDBmwschema' );

		return "# Postgres specific settings
\$wgDBport = \"{$port}\";
\$wgDBssl = {$useSsl};
\$wgDBmwschema = \"{$schema}\";";
	}

	public function preUpgrade() {
		global $wgDBuser, $wgDBpassword;

		# Normal user and password are selected after this step, so for now
		# just copy these two
		$wgDBuser = $this->getVar( '_InstallUser' );
		$wgDBpassword = $this->getVar( '_InstallPassword' );
	}

	public function createTables() {
		$schema = $this->getVar( 'wgDBmwschema' );

		$status = $this->getConnection();
		if ( !$status->isOK() ) {
			return $status;
		}

		/** @var DatabasePostgres $conn */
		$conn = $status->value;
		'@phan-var DatabasePostgres $conn';

		if ( $conn->tableExists( 'archive', __METHOD__ ) ) {
			$status->warning( 'config-install-tables-exist' );
			$this->enableLB();

			return $status;
		}

		$conn->begin( __METHOD__ );

		if ( !$conn->schemaExists( $schema ) ) {
			$status->fatal( 'config-install-pg-schema-not-exist' );

			return $status;
		}

		$error = $conn->sourceFile( $this->getGeneratedSchemaPath( $conn ) );
		if ( $error !== true ) {
			$conn->reportQueryError( $error, 0, '', __METHOD__ );
			$conn->rollback( __METHOD__ );
			$status->fatal( 'config-install-tables-failed', $error );
		} else {
			$error = $conn->sourceFile( $this->getSchemaPath( $conn ) );
			if ( $error !== true ) {
				$conn->reportQueryError( $error, 0, '', __METHOD__ );
				$conn->rollback( __METHOD__ );
				$status->fatal( 'config-install-tables-manual-failed', $error );
			} else {
				$conn->commit( __METHOD__ );
			}
		}
		// Resume normal operations
		if ( $status->isOK() ) {
			$this->enableLB();
		}

		return $status;
	}

	public function createManualTables() {
		// Already handled above. Do nothing.
		return Status::newGood();
	}

	public function getGlobalDefaults() {
		// The default $wgDBmwschema is null, which breaks Postgres and other DBMSes that require
		// the use of a schema, so we need to set it here
		return array_merge( parent::getGlobalDefaults(), [
			'wgDBmwschema' => 'mediawiki',
		] );
	}

	public function setupPLpgSQL() {
		// Connect as the install user, since it owns the database and so is
		// the user that needs to run "CREATE LANGUAGE"
		$status = $this->getPgConnection( 'create-schema' );
		if ( !$status->isOK() ) {
			return $status;
		}
		/**
		 * @var Database $conn
		 */
		$conn = $status->value;

		$exists = (bool)$conn->selectField( '"pg_catalog"."pg_language"', '1',
			[ 'lanname' => 'plpgsql' ], __METHOD__ );
		if ( $exists ) {
			// Already exists, nothing to do
			return Status::newGood();
		}

		// plpgsql is not installed, but if we have a pg_pltemplate table, we
		// should be able to create it
		$exists = (bool)$conn->selectField(
			[ '"pg_catalog"."pg_class"', '"pg_catalog"."pg_namespace"' ],
			'1',
			[
				'pg_namespace.oid=relnamespace',
				'nspname' => 'pg_catalog',
				'relname' => 'pg_pltemplate',
			],
			__METHOD__ );
		if ( $exists ) {
			try {
				$conn->query( 'CREATE LANGUAGE plpgsql', __METHOD__ );
			} catch ( DBQueryError $e ) {
				return Status::newFatal( 'config-pg-no-plpgsql', $this->getVar( 'wgDBname' ) );
			}
		} else {
			return Status::newFatal( 'config-pg-no-plpgsql', $this->getVar( 'wgDBname' ) );
		}

		return Status::newGood();
	}
}
