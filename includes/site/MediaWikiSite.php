<?php

use MediaWiki\Site\MediaWikiPageNameNormalizer;

/**
 * Class representing a MediaWiki site.
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
 * @ingroup Site
 * @license GPL-2.0-or-later
 * @author John Erling Blad < jeblad@gmail.com >
 * @author Daniel Kinzler
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */

/**
 * Class representing a MediaWiki site.
 *
 * @since 1.21
 *
 * @ingroup Site
 */
class MediaWikiSite extends Site {
	public const PATH_FILE = 'file_path';
	public const PATH_PAGE = 'page_path';

	/**
	 * @since 1.21
	 *
	 * @param string $type
	 */
	public function __construct( $type = self::TYPE_MEDIAWIKI ) {
		parent::__construct( $type );
	}

	/**
	 * Returns the database form of the given title.
	 *
	 * @since 1.21
	 *
	 * @param string $title The target page's title, in normalized form.
	 *
	 * @return string
	 */
	public function toDBKey( $title ) {
		return str_replace( ' ', '_', $title );
	}

	/**
	 * Returns the normalized form of the given page title, using the
	 * normalization rules of the given site. If $followRedirect is set to true
	 * and the given title is a redirect, the redirect will be resolved and
	 * the redirect target is returned.
	 * Only titles of existing pages will be returned.
	 *
	 * @note This actually makes an API request to the remote site, so beware
	 *   that this function is slow and depends on an external service.
	 *
	 * @note If MW_PHPUNIT_TEST is defined, the call to the external site is
	 *   skipped, and the title is normalized using the local normalization
	 *   rules as implemented by the Title class.
	 *
	 * @see Site::normalizePageName
	 *
	 * @since 1.21
	 * @since 1.37 Added $followRedirect
	 *
	 * @param string $pageName
	 * @param int $followRedirect either MediaWikiPageNameNormalizer::FOLLOW_REDIRECT or
	 * MediaWikiPageNameNormalizer::NOFOLLOW_REDIRECT
	 *
	 * @return string|false The normalized form of the title,
	 * or false to indicate an invalid title, a missing page,
	 * or some other kind of error.
	 * @throws MWException
	 */
	public function normalizePageName( $pageName, $followRedirect = MediaWikiPageNameNormalizer::FOLLOW_REDIRECT ) {
		if ( defined( 'MW_PHPUNIT_TEST' ) || defined( 'MW_DEV_ENV' ) ) {
			// If the code is under test, don't call out to other sites, just
			// normalize locally.
			// Note: this may cause results to be inconsistent with the actual
			// normalization used by the respective remote site!

			$t = Title::newFromText( $pageName );
			return $t->getPrefixedText();
		} else {
			static $mediaWikiPageNameNormalizer = null;

			if ( $mediaWikiPageNameNormalizer === null ) {
				$mediaWikiPageNameNormalizer = new MediaWikiPageNameNormalizer();
			}

			return $mediaWikiPageNameNormalizer->normalizePageName(
				$pageName,
				$this->getFileUrl( 'api.php' ),
				$followRedirect
			);
		}
	}

	/**
	 * @see Site::getLinkPathType
	 * Returns Site::PATH_PAGE
	 *
	 * @since 1.21
	 *
	 * @return string
	 */
	public function getLinkPathType() {
		return self::PATH_PAGE;
	}

	/**
	 * Returns the relative page path.
	 *
	 * @since 1.21
	 *
	 * @return string
	 */
	public function getRelativePagePath() {
		return parse_url( $this->getPath( self::PATH_PAGE ), PHP_URL_PATH );
	}

	/**
	 * Returns the relative file path.
	 *
	 * @since 1.21
	 *
	 * @return string
	 */
	public function getRelativeFilePath() {
		return parse_url( $this->getPath( self::PATH_FILE ), PHP_URL_PATH );
	}

	/**
	 * Sets the relative page path.
	 *
	 * @since 1.21
	 *
	 * @param string $path
	 */
	public function setPagePath( $path ) {
		$this->setPath( self::PATH_PAGE, $path );
	}

	/**
	 * Sets the relative file path.
	 *
	 * @since 1.21
	 *
	 * @param string $path
	 */
	public function setFilePath( $path ) {
		$this->setPath( self::PATH_FILE, $path );
	}

	/**
	 * @see Site::getPageUrl
	 *
	 * This implementation returns a URL constructed using the path returned by getLinkPath().
	 * In addition to the default behavior implemented by Site::getPageUrl(), this
	 * method converts the $pageName to DBKey-format by replacing spaces with underscores
	 * before using it in the URL.
	 *
	 * @since 1.21
	 *
	 * @param string|bool $pageName Page name or false (default: false)
	 *
	 * @return string|null
	 */
	public function getPageUrl( $pageName = false ) {
		$url = $this->getLinkPath();

		if ( $url === null ) {
			return null;
		}

		if ( $pageName !== false ) {
			$pageName = $this->toDBKey( trim( $pageName ) );
			$url = str_replace( '$1', wfUrlencode( $pageName ), $url );
		}

		return $url;
	}

	/**
	 * Returns the full file path (ie site url + relative file path).
	 * The path should go at the $1 marker. If the $path
	 * argument is provided, the marker will be replaced by it's value.
	 *
	 * @since 1.21
	 *
	 * @param string|bool $path
	 *
	 * @return string
	 * @throws MWException If the file path cannot be determined.
	 */
	public function getFileUrl( $path = false ) {
		$filePath = $this->getPath( self::PATH_FILE );
		if ( $filePath === null ) {
			throw new MWException( "PATH_FILE for site {$this->getGlobalId()} not known" );
		}

		if ( $path !== false ) {
			$filePath = str_replace( '$1', $path, $filePath );
		}

		return $filePath;
	}
}
