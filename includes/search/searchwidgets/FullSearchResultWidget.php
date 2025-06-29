<?php

namespace MediaWiki\Search\SearchWidgets;

use Category;
use Html;
use HtmlArmor;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use Sanitizer;
use SearchResult;
use SpecialSearch;
use Title;

/**
 * Renders a 'full' multi-line search result with metadata.
 *
 *  The Title
 *  some *highlighted* *text* about the search result
 *  5 KiB (651 words) - 12:40, 6 Aug 2016
 */
class FullSearchResultWidget implements SearchResultWidget {
	/** @var SpecialSearch */
	protected $specialPage;
	/** @var LinkRenderer */
	protected $linkRenderer;
	/** @var HookRunner */
	private $hookRunner;

	public function __construct( SpecialSearch $specialPage, LinkRenderer $linkRenderer,
		HookContainer $hookContainer
	) {
		$this->specialPage = $specialPage;
		$this->linkRenderer = $linkRenderer;
		$this->hookRunner = new HookRunner( $hookContainer );
	}

	/**
	 * @param SearchResult $result The result to render
	 * @param int $position The result position, including offset
	 * @return string HTML
	 */
	public function render( SearchResult $result, $position ) {
		// If the page doesn't *exist*... our search index is out of date.
		// The least confusing at this point is to drop the result.
		// You may get less results, but... on well. :P
		if ( $result->isBrokenTitle() || $result->isMissingRevision() ) {
			return '';
		}

		$link = $this->generateMainLinkHtml( $result, $position );
		// If page content is not readable, just return ths title.
		// This is not quite safe, but better than showing excerpts from
		// non-readable pages. Note that hiding the entry entirely would
		// screw up paging (really?).
		if ( !$this->specialPage->getAuthority()->definitelyCan( 'read', $result->getTitle() ) ) {
			return Html::rawElement( 'li', [], $link );
		}

		$redirect = $this->generateRedirectHtml( $result );
		$section = $this->generateSectionHtml( $result );
		$category = $this->generateCategoryHtml( $result );
		$date = htmlspecialchars(
			$this->specialPage->getLanguage()->userTimeAndDate(
				$result->getTimestamp(),
				$this->specialPage->getUser()
			)
		);
		list( $file, $desc, $thumb ) = $this->generateFileHtml( $result );
		$snippet = $result->getTextSnippet();
		if ( $snippet ) {
			$extract = Html::rawElement( 'div', [ 'class' => 'searchresult' ], $snippet );
		} else {
			$extract = '';
		}

		if ( $thumb === null ) {
			// If no thumb, then the description is about size
			$desc = $this->generateSizeHtml( $result );

			// Let hooks do their own final construction if desired.
			// FIXME: Not sure why this is only for results without thumbnails,
			// but keeping it as-is for now to prevent breaking hook consumers.
			$html = null;
			$score = '';
			$related = '';
			// TODO: remove this instanceof and always pass [], let implementors do the cast if
			// they want to be SearchDatabase specific
			$terms = $result instanceof \SqlSearchResult ? $result->getTermMatches() : [];
			if ( !$this->hookRunner->onShowSearchHit( $this->specialPage, $result,
				$terms, $link, $redirect, $section, $extract, $score,
				// @phan-suppress-next-line PhanTypeMismatchArgument Type mismatch on pass-by-ref args
				$desc, $date, $related, $html )
			) {
				return $html;
			}
		}

		// All the pieces have been collected. Now generate the final HTML
		$joined = "{$link} {$redirect} {$category} {$section} {$file}";
		$meta = $this->buildMeta( $desc, $date );

		if ( $thumb === null ) {
			$html = Html::rawElement(
				'div',
				[ 'class' => 'mw-search-result-heading' ],
				$joined
			);
			$html .= $extract . ' ' . $meta;
		} else {
			$tableCells = Html::rawElement(
				'td',
				[ 'style' => 'width: 120px; text-align: center; vertical-align: top' ],
				$thumb
			) . Html::rawElement(
				'td',
				[ 'style' => 'vertical-align: top' ],
				"$joined $extract $meta"
			);
			$html = Html::rawElement(
				'table',
				[ 'class' => 'searchResultImage' ],
				Html::rawElement(
					'tr',
					[],
					$tableCells
				)
			);
		}

		return Html::rawElement( 'li', [ 'class' => 'mw-search-result' ], $html );
	}

	/**
	 * Generates HTML for the primary call to action. It is
	 * typically the article title, but the search engine can
	 * return an exact snippet to use (typically the article
	 * title with highlighted words).
	 *
	 * @param SearchResult $result
	 * @param int $position
	 * @return string HTML
	 */
	protected function generateMainLinkHtml( SearchResult $result, $position ) {
		$snippet = $result->getTitleSnippet();
		if ( $snippet === '' ) {
			$snippet = null;
		} else {
			$snippet = new HtmlArmor( $snippet );
		}

		// clone to prevent hook from changing the title stored inside $result
		$title = clone $result->getTitle();
		$query = [];

		$attributes = [ 'data-serp-pos' => $position ];
		$this->hookRunner->onShowSearchHitTitle( $title, $snippet, $result,
			$result instanceof \SqlSearchResult ? $result->getTermMatches() : [],
			// @phan-suppress-next-line PhanTypeMismatchArgument Type mismatch on pass-by-ref args
			$this->specialPage, $query, $attributes );

		$link = $this->linkRenderer->makeLink(
			$title,
			$snippet,
			$attributes,
			$query
		);

		return $link;
	}

	/**
	 * Generates an alternate title link, such as (redirect from <a>Foo</a>).
	 *
	 * @param string $msgKey i18n message  used to wrap title
	 * @param Title|null $title The title to link to, or null to generate
	 *  the message without a link. In that case $text must be non-null.
	 * @param string|null $text The text snippet to display, or null
	 *  to use the title
	 * @return string HTML
	 */
	protected function generateAltTitleHtml( $msgKey, ?Title $title, $text ) {
		$inner = $title === null
			? $text
			: $this->linkRenderer->makeLink( $title, $text ? new HtmlArmor( $text ) : null );

		return "<span class='searchalttitle'>" .
				$this->specialPage->msg( $msgKey )->rawParams( $inner )->parse()
			. "</span>";
	}

	/**
	 * @param SearchResult $result
	 * @return string HTML
	 */
	protected function generateRedirectHtml( SearchResult $result ) {
		$title = $result->getRedirectTitle();
		return $title === null
			? ''
			: $this->generateAltTitleHtml( 'search-redirect', $title, $result->getRedirectSnippet() );
	}

	/**
	 * @param SearchResult $result
	 * @return string HTML
	 */
	protected function generateSectionHtml( SearchResult $result ) {
		$title = $result->getSectionTitle();
		return $title === null
			? ''
			: $this->generateAltTitleHtml( 'search-section', $title, $result->getSectionSnippet() );
	}

	/**
	 * @param SearchResult $result
	 * @return string HTML
	 */
	protected function generateCategoryHtml( SearchResult $result ) {
		$snippet = $result->getCategorySnippet();
		return $snippet
			? $this->generateAltTitleHtml( 'search-category', null, $snippet )
			: '';
	}

	/**
	 * @param SearchResult $result
	 * @return string HTML
	 */
	protected function generateSizeHtml( SearchResult $result ) {
		$title = $result->getTitle();
		if ( $title->getNamespace() === NS_CATEGORY ) {
			$cat = Category::newFromTitle( $title );
			return $this->specialPage->msg( 'search-result-category-size' )
				->numParams( $cat->getMemberCount(), $cat->getSubcatCount(), $cat->getFileCount() )
				->escaped();
		// TODO: This is a bit odd...but requires changing the i18n message to fix
		} elseif ( $result->getByteSize() !== null || $result->getWordCount() > 0 ) {
			return $this->specialPage->msg( 'search-result-size' )
				->sizeParams( $result->getByteSize() )
				->numParams( $result->getWordCount() )
				->escaped();
		}

		return '';
	}

	/**
	 * @param SearchResult $result
	 * @return array Three element array containing the main file html,
	 *  a text description of the file, and finally the thumbnail html.
	 *  If no thumbnail is available the second and third will be null.
	 */
	protected function generateFileHtml( SearchResult $result ) {
		$title = $result->getTitle();
		if ( $title->getNamespace() !== NS_FILE ) {
			return [ '', null, null ];
		}

		if ( $result->isFileMatch() ) {
			$html = Html::rawElement(
				'span',
				[ 'class' => 'searchalttitle' ],
				$this->specialPage->msg( 'search-file-match' )->escaped()
			);
		} else {
			$html = '';
		}

		$descHtml = null;
		$thumbHtml = null;

		$img = $result->getFile() ?: MediaWikiServices::getInstance()->getRepoGroup()
			->findFile( $title );
		if ( $img ) {
			$thumb = $img->transform( [ 'width' => 120, 'height' => 120 ] );
			if ( $thumb ) {
				// File::getShortDesc() is documented to return HTML, but many handlers used to incorrectly
				// return plain text (T395834), so sanitize it in case the same bug is present in extensions.
				$unsafeShortDesc = $img->getShortDesc();
				$shortDesc = Sanitizer::removeSomeTags( $unsafeShortDesc );

				$descHtml = $this->specialPage->msg( 'parentheses' )
					->rawParams( $shortDesc )
					->escaped();
				$thumbHtml = $thumb->toHtml( [ 'desc-link' => true ] );
			}
		}

		return [ $html, $descHtml, $thumbHtml ];
	}

	/**
	 * @param string $desc HTML description of result, ex: size in bytes, or empty string
	 * @param string $date HTML representation of last edit date, or empty string
	 * @return string HTML A div combining $desc and $date with a separator in a <div>.
	 *  If either is missing only one will be represented. If both are missing an empty
	 *  string will be returned.
	 */
	protected function buildMeta( $desc, $date ) {
		if ( $desc && $date ) {
			$meta = "{$desc} - {$date}";
		} elseif ( $desc ) {
			$meta = $desc;
		} elseif ( $date ) {
			$meta = $date;
		} else {
			return '';
		}

		return "<div class='mw-search-result-data'>{$meta}</div>";
	}
}
