<?php
/**
 * Implements the Password class for the MediaWiki software.
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
 */

declare( strict_types = 1 );

use MediaWiki\MainConfigNames;

/**
 * Factory class for creating and checking Password objects
 *
 * @since 1.24
 */
final class PasswordFactory {
	/**
	 * The default PasswordHash type
	 *
	 * @var string
	 * @see PasswordFactory::setDefaultType
	 */
	private $default = '';

	/**
	 * Mapping of password types to classes
	 *
	 * @var array[]
	 * @see PasswordFactory::register
	 * @see Setup.php
	 */
	private $types = [
		'' => [ 'type' => '', 'class' => InvalidPassword::class ],
	];

	private const MIN_RANDOM_PASSWORD_LENGTH = 10;

	/**
	 * Most of the time you'll want to use MediaWikiServices::getInstance()->getPasswordFactory
	 * instead.
	 * @param array $config Mapping of password type => config
	 * @param string $default Default password type
	 * @see PasswordFactory::register
	 * @see PasswordFactory::setDefaultType
	 */
	public function __construct( array $config = [], string $default = '' ) {
		foreach ( $config as $type => $options ) {
			$this->register( $type, $options );
		}

		if ( $default !== '' ) {
			$this->setDefaultType( $default );
		}
	}

	/**
	 * Register a new type of password hash
	 *
	 * @param string $type Unique type name for the hash. Will be prefixed to the password hashes
	 *   to identify what hashing method was used.
	 * @param array $config Array of configuration options. 'class' is required (the Password
	 *   subclass name), everything else is passed to the constructor of that class.
	 */
	public function register( string $type, array $config ): void {
		$config['type'] = $type;
		$this->types[$type] = $config;
	}

	/**
	 * Set the default password type
	 *
	 * This type will be used for creating new passwords when the type is not specified.
	 * Passwords of a different type will be considered outdated and in need of update.
	 *
	 * @param string $type Password hash type
	 * @throws InvalidArgumentException If the type is not registered
	 */
	public function setDefaultType( string $type ): void {
		if ( !isset( $this->types[$type] ) ) {
			throw new InvalidArgumentException( "Invalid password type $type." );
		}
		$this->default = $type;
	}

	/**
	 * Get the default password type
	 *
	 * @return string
	 */
	public function getDefaultType(): string {
		return $this->default;
	}

	/**
	 * @deprecated since 1.32 Initialize settings using the constructor
	 *
	 * Initialize the internal static variables using the global variables
	 *
	 * @param Config $config Configuration object to load data from
	 */
	public function init( Config $config ): void {
		foreach ( $config->get( MainConfigNames::PasswordConfig ) as $type => $options ) {
			$this->register( $type, $options );
		}

		$this->setDefaultType( $config->get( MainConfigNames::PasswordDefault ) );
	}

	/**
	 * Get the list of types of passwords
	 *
	 * @return array[]
	 */
	public function getTypes(): array {
		return $this->types;
	}

	/**
	 * Create a new Hash object from an existing string hash
	 *
	 * Parse the type of a hash and create a new hash object based on the parsed type.
	 * Pass the raw hash to the constructor of the new object. Use InvalidPassword type
	 * if a null hash is given.
	 *
	 * @param string|null $hash Existing hash or null for an invalid password
	 * @return Password
	 * @throws PasswordError If hash is invalid or type is not recognized
	 */
	public function newFromCiphertext( ?string $hash ): Password {
		if ( $hash === null || $hash === '' ) {
			return new InvalidPassword( $this, [ 'type' => '' ], null );
		} elseif ( $hash[0] !== ':' ) {
			throw new PasswordError( 'Invalid hash given' );
		}

		$type = substr( $hash, 1, strpos( $hash, ':', 1 ) - 1 );
		if ( !isset( $this->types[$type] ) ) {
			throw new PasswordError( "Unrecognized password hash type $type." );
		}

		$config = $this->types[$type];

		return new $config['class']( $this, $config, $hash );
	}

	/**
	 * Make a new default password of the given type.
	 *
	 * @param string $type Existing type
	 * @return Password
	 * @throws PasswordError If hash is invalid or type is not recognized
	 */
	public function newFromType( string $type ): Password {
		if ( !isset( $this->types[$type] ) ) {
			throw new PasswordError( "Unrecognized password hash type $type." );
		}

		$config = $this->types[$type];

		return new $config['class']( $this, $config );
	}

	/**
	 * Create a new Hash object from a plaintext password
	 *
	 * If no existing object is given, make a new default object. If one is given, clone that
	 * object. Then pass the plaintext to Password::crypt().
	 *
	 * @param string|null $password Plaintext password, or null for an invalid password
	 * @param Password|null $existing Optional existing hash to get options from
	 * @return Password
	 */
	public function newFromPlaintext( ?string $password, Password $existing = null ): Password {
		if ( $password === null ) {
			return new InvalidPassword( $this, [ 'type' => '' ], null );
		}

		if ( $existing === null ) {
			$config = $this->types[$this->default];
			$obj = new $config['class']( $this, $config );
		} else {
			$obj = clone $existing;
		}

		$obj->crypt( $password );

		return $obj;
	}

	/**
	 * Determine whether a password object needs updating
	 *
	 * Check whether the given password is of the default type. If it is,
	 * pass off further needsUpdate checks to Password::needsUpdate.
	 *
	 * @param Password $password
	 *
	 * @return bool True if needs update, false otherwise
	 */
	public function needsUpdate( Password $password ): bool {
		if ( $password->getType() !== $this->default ) {
			return true;
		} else {
			return $password->needsUpdate();
		}
	}

	/**
	 * Generate a random string suitable for a password
	 *
	 * @param int $minLength Minimum length of password to generate
	 * @return string
	 */
	public static function generateRandomPasswordString( int $minLength = 10 ): string {
		// Decide the final password length based on our min password length,
		// requiring at least a minimum of self::MIN_RANDOM_PASSWORD_LENGTH chars.
		$length = max( self::MIN_RANDOM_PASSWORD_LENGTH, $minLength );
		// Multiply by 1.25 to get the number of hex characters we need
		// Generate random hex chars
		$hex = MWCryptRand::generateHex( ceil( $length * 1.25 ) );
		// Convert from base 16 to base 32 to get a proper password like string
		return substr( Wikimedia\base_convert( $hex, 16, 32, $length ), -$length );
	}

	/**
	 * Create an InvalidPassword
	 *
	 * @return InvalidPassword
	 */
	public static function newInvalidPassword(): InvalidPassword {
		static $password = null;

		if ( $password === null ) {
			$factory = new self();
			$password = new InvalidPassword( $factory, [ 'type' => '' ], null );
		}

		return $password;
	}
}
