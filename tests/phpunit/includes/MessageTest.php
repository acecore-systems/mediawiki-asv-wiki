<?php

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\UserGroupMembershipParam;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class MessageTest extends MediaWikiLangTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::ForceUIMsgAsContentMsg, [] );
		$this->setUserLang( 'en' );
	}

	/**
	 * @covers Message::__construct
	 * @dataProvider provideConstructor
	 */
	public function testConstructor( $expectedLang, $key, $params, $language ) {
		$message = new Message( $key, $params, $language );

		$this->assertSame( $key, $message->getKey() );
		$this->assertSame( $params, $message->getParams() );
		$this->assertSame( $expectedLang->getCode(), $message->getLanguage()->getCode() );

		$messageSpecifier = $this->getMockForAbstractClass( MessageSpecifier::class );
		$messageSpecifier->method( 'getKey' )->willReturn( $key );
		$messageSpecifier->method( 'getParams' )->willReturn( $params );
		$message = new Message( $messageSpecifier, [], $language );

		$this->assertSame( $key, $message->getKey() );
		$this->assertSame( $params, $message->getParams() );
		$this->assertSame( $expectedLang->getCode(), $message->getLanguage()->getCode() );
	}

	public static function provideConstructor() {
		$langDe = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'de' );
		$langEn = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'en' );

		return [
			[ $langDe, 'foo', [], $langDe ],
			[ $langDe, 'foo', [ 'bar' ], $langDe ],
			[ $langEn, 'foo', [ 'bar' ], null ]
		];
	}

	public static function provideConstructorParams() {
		return [
			[
				[],
				[],
			],
			[
				[],
				[ [] ],
			],
			[
				[ 'foo' ],
				[ 'foo' ],
			],
			[
				[ 'foo', 'bar' ],
				[ 'foo', 'bar' ],
			],
			[
				[ 'baz' ],
				[ [ 'baz' ] ],
			],
			[
				[ 'baz', 'foo' ],
				[ [ 'baz', 'foo' ] ],
			],
			[
				[ Message::rawParam( 'baz' ) ],
				[ Message::rawParam( 'baz' ) ],
			],
			[
				[ Message::rawParam( 'baz' ), 'foo' ],
				[ Message::rawParam( 'baz' ), 'foo' ],
			],
			[
				[ Message::rawParam( 'baz' ) ],
				[ [ Message::rawParam( 'baz' ) ] ],
			],
			[
				[ Message::rawParam( 'baz' ), 'foo' ],
				[ [ Message::rawParam( 'baz' ), 'foo' ] ],
			],

			// Test handling of erroneous input, to detect if it changes
			[
				[ [ 'baz', 'foo' ], 'hhh' ],
				[ [ 'baz', 'foo' ], 'hhh' ],
			],
			[
				[ [ 'baz', 'foo' ], 'hhh', [ 'ahahahahha' ] ],
				[ [ 'baz', 'foo' ], 'hhh', [ 'ahahahahha' ] ],
			],
			[
				[ [ 'baz', 'foo' ], [ 'ahahahahha' ] ],
				[ [ 'baz', 'foo' ], [ 'ahahahahha' ] ],
			],
			[
				[ [ 'baz' ], [ 'ahahahahha' ] ],
				[ [ 'baz' ], [ 'ahahahahha' ] ],
			],
		];
	}

	/**
	 * @covers Message::__construct
	 * @covers Message::getParams
	 * @dataProvider provideConstructorParams
	 */
	public function testConstructorParams( $expected, $args ) {
		$msg = new Message( 'imasomething' );

		$returned = $msg->params( ...$args );

		$this->assertSame( $msg, $returned );
		$this->assertSame( $expected, $msg->getParams() );
	}

	public static function provideConstructorLanguage() {
		return [
			[ 'foo', [ 'bar' ], 'en' ],
			[ 'foo', [ 'bar' ], 'de' ]
		];
	}

	/**
	 * @covers Message::__construct
	 * @covers Message::getLanguage
	 * @dataProvider provideConstructorLanguage
	 */
	public function testConstructorLanguage( $key, $params, $languageCode ) {
		$language = $this->getServiceContainer()->getLanguageFactory()
			->getLanguage( $languageCode );
		$message = new Message( $key, $params, $language );

		$this->assertEquals( $language, $message->getLanguage() );
	}

	public static function provideKeys() {
		return [
			'string' => [
				'key' => 'mainpage',
				'expected' => [ 'mainpage' ],
			],
			'single' => [
				'key' => [ 'mainpage' ],
				'expected' => [ 'mainpage' ],
			],
			'multi' => [
				'key' => [ 'mainpage-foo', 'mainpage-bar', 'mainpage' ],
				'expected' => [ 'mainpage-foo', 'mainpage-bar', 'mainpage' ],
			],
			'empty' => [
				'key' => [],
				'expected' => null,
				'exception' => InvalidArgumentException::class,
			],
			'null' => [
				'key' => null,
				'expected' => null,
				'exception' => InvalidArgumentException::class,
			],
			'bad type' => [
				'key' => 123,
				'expected' => null,
				'exception' => InvalidArgumentException::class,
			],
		];
	}

	/**
	 * @covers Message::__construct
	 * @covers Message::getKey
	 * @covers Message::isMultiKey
	 * @covers Message::getKeysToTry
	 * @dataProvider provideKeys
	 */
	public function testKeys( $key, $expected, $exception = null ) {
		if ( $exception ) {
			$this->expectException( $exception );
		}

		$msg = new Message( $key );
		$this->assertContains( $msg->getKey(), $expected );
		$this->assertSame( $expected, $msg->getKeysToTry() );
		$this->assertSame( count( $expected ) > 1, $msg->isMultiKey() );
	}

	/**
	 * @covers ::wfMessage
	 */
	public function testWfMessage() {
		$this->assertInstanceOf( Message::class, wfMessage( 'mainpage' ) );
		$this->assertInstanceOf( Message::class, wfMessage( 'i-dont-exist-evar' ) );
	}

	/**
	 * @covers Message::newFromKey
	 */
	public function testNewFromKey() {
		$this->assertInstanceOf( Message::class, Message::newFromKey( 'mainpage' ) );
		$this->assertInstanceOf( Message::class, Message::newFromKey( 'i-dont-exist-evar' ) );
	}

	/**
	 * @covers ::wfMessage
	 * @covers Message::__construct
	 */
	public function testWfMessageParams() {
		$this->assertSame( 'Return to $1.', wfMessage( 'returnto' )->text() );
		$this->assertSame( 'Return to $1.', wfMessage( 'returnto', [] )->text() );
		$this->assertSame(
			'Return to 1,024.',
			wfMessage( 'returnto', Message::numParam( 1024 ) )->text()
		);
		$this->assertSame(
			'Return to 1,024.',
			wfMessage( 'returnto', [ Message::numParam( 1024 ) ] )->text()
		);
		$this->assertSame(
			'You have foo (bar).',
			wfMessage( 'youhavenewmessages', 'foo', 'bar' )->text()
		);
		$this->assertSame(
			'You have foo (bar).',
			wfMessage( 'youhavenewmessages', [ 'foo', 'bar' ] )->text()
		);
		$this->assertSame(
			'You have 1,024 (bar).',
			wfMessage(
				'youhavenewmessages',
				Message::numParam( 1024 ), 'bar'
			)->text()
		);
		$this->assertSame(
			'You have foo (2,048).',
			wfMessage(
				'youhavenewmessages',
				'foo', Message::numParam( 2048 )
			)->text()
		);
		$this->assertSame(
			'You have 1,024 (2,048).',
			wfMessage(
				'youhavenewmessages',
				[ Message::numParam( 1024 ), Message::numParam( 2048 ) ]
			)->text()
		);
	}

	/**
	 * @covers Message::exists
	 */
	public function testExists() {
		$this->assertTrue( wfMessage( 'mainpage' )->exists() );
		$this->assertTrue( wfMessage( 'mainpage' )->params( [] )->exists() );
		$this->assertTrue( wfMessage( 'mainpage' )->rawParams( 'foo', 123 )->exists() );
		$this->assertFalse( wfMessage( 'i-dont-exist-evar' )->exists() );
		$this->assertFalse( wfMessage( 'i-dont-exist-evar' )->params( [] )->exists() );
		$this->assertFalse( wfMessage( 'i-dont-exist-evar' )->rawParams( 'foo', 123 )->exists() );
	}

	/**
	 * @covers Message::__construct
	 * @covers Message::text
	 * @covers Message::plain
	 * @covers Message::escaped
	 * @covers Message::toString
	 */
	public function testToStringKey() {
		$this->assertSame( 'Main Page', wfMessage( 'mainpage' )->text() );
		$this->assertSame( '⧼i-dont-exist-evar⧽', wfMessage( 'i-dont-exist-evar' )->text() );
		$this->assertSame( '⧼i&lt;dont&gt;exist-evar⧽', wfMessage( 'i<dont>exist-evar' )->text() );
		$this->assertSame( '⧼i-dont-exist-evar⧽', wfMessage( 'i-dont-exist-evar' )->plain() );
		$this->assertSame( '⧼i&lt;dont&gt;exist-evar⧽', wfMessage( 'i<dont>exist-evar' )->plain() );
		$this->assertSame( '⧼i-dont-exist-evar⧽', wfMessage( 'i-dont-exist-evar' )->escaped() );
		$this->assertSame(
			'⧼i&lt;dont&gt;exist-evar⧽',
			wfMessage( 'i<dont>exist-evar' )->escaped()
		);
	}

	public static function provideToString() {
		return [
			// key, transformation, transformed, transformed implicitly
			[ 'mainpage', 'plain', 'Main Page', 'Main Page' ],
			[ 'i-dont-exist-evar', 'plain', '⧼i-dont-exist-evar⧽', '⧼i-dont-exist-evar⧽' ],
			[ 'i-dont-exist-evar', 'escaped', '⧼i-dont-exist-evar⧽', '⧼i-dont-exist-evar⧽' ],
			[ 'script>alert(1)</script', 'escaped', '⧼script&gt;alert(1)&lt;/script⧽',
				'⧼script&gt;alert(1)&lt;/script⧽' ],
			[ 'script>alert(1)</script', 'plain', '⧼script&gt;alert(1)&lt;/script⧽',
				'⧼script&gt;alert(1)&lt;/script⧽' ],
			[ "\u{0338}isolated combining char", 'escaped', '⧼&#x338;isolated combining char⧽', '⧼&#x338;isolated combining char⧽' ],
		];
	}

	/**
	 * @covers Message::toString
	 * @covers Message::__toString
	 * @dataProvider provideToString
	 */
	public function testToString( $key, $format, $expect, $expectImplicit ) {
		$msg = new Message( $key );
		$this->assertSame( $expect, $msg->$format() );

		// This used to behave the same as toString() and was a security risk.
		// It now has a stable return value that is always parsed/sanitized. (T146416)
		$this->assertSame( $expectImplicit, $msg->__toString(), '__toString is not affected by format call' );
	}

	public static function provideToString_raw() {
		return [
			[ '<span>foo</span>', 'parse', '<span>foo</span>', '<span>foo</span>' ],
			[ '<span>foo</span>', 'escaped', '&lt;span&gt;foo&lt;/span&gt;',
				'<span>foo</span>' ],
			[ '<span>foo</span>', 'plain', '<span>foo</span>', '<span>foo</span>' ],
			[ '<script>alert(1)</script>', 'parse', '&lt;script&gt;alert(1)&lt;/script&gt;',
				'&lt;script&gt;alert(1)&lt;/script&gt;' ],
			[ '<script>alert(1)</script>', 'escaped', '&lt;script&gt;alert(1)&lt;/script&gt;',
				'&lt;script&gt;alert(1)&lt;/script&gt;' ],
			[ '<script>alert(1)</script>', 'plain', '<script>alert(1)</script>',
				'&lt;script&gt;alert(1)&lt;/script&gt;' ],
			[ "\u{0338}isolated combining char", 'escaped', '&#x338;isolated combining char', '&#x338;isolated combining char' ],
		];
	}

	/**
	 * @covers Message::toString
	 * @covers Message::__toString
	 * @dataProvider provideToString_raw
	 */
	public function testToString_raw( $message, $format, $expect, $expectImplicit ) {
		// make the message behave like RawMessage and use the key as-is
		$msg = $this->getMockBuilder( Message::class )->onlyMethods( [ 'fetchMessage' ] )
			->disableOriginalConstructor()
			->getMock();
		$msg->method( 'fetchMessage' )->willReturn( $message );
		/** @var Message $msg */

		$this->assertSame( $expect, $msg->$format() );

		$this->assertSame( $expectImplicit, $msg->__toString() );
	}

	/**
	 * @covers Message::inLanguage
	 */
	public function testInLanguage() {
		$this->assertSame( 'Main Page', wfMessage( 'mainpage' )->inLanguage( 'en' )->text() );
		$this->assertSame( 'Заглавная страница',
			wfMessage( 'mainpage' )->inLanguage( 'ru' )->text() );

		// NOTE: make sure internal caching of the message text is reset appropriately
		$msg = wfMessage( 'mainpage' );
		$this->assertSame( 'Main Page', $msg->inLanguage( 'en' )->text() );
		$this->assertSame(
			'Заглавная страница',
			$msg->inLanguage( 'ru' )->text()
		);
	}

	/**
	 * @covers Message::rawParam
	 * @covers Message::rawParams
	 */
	public function testRawParams() {
		$this->assertSame(
			'(Заглавная страница)',
			wfMessage( 'parentheses', 'Заглавная страница' )->plain()
		);
		$this->assertSame(
			'(Заглавная страница $1)',
			wfMessage( 'parentheses', 'Заглавная страница $1' )->plain()
		);
		$this->assertSame(
			'(Заглавная страница)',
			wfMessage( 'parentheses' )->rawParams( 'Заглавная страница' )->plain()
		);
		$this->assertSame(
			'(Заглавная страница $1)',
			wfMessage( 'parentheses' )->rawParams( 'Заглавная страница $1' )->plain()
		);
	}

	/**
	 * @covers RawMessage::__construct
	 * @covers RawMessage::fetchMessage
	 */
	public function testRawMessage() {
		$msg = new RawMessage( 'example &' );
		$this->assertSame( 'example &', $msg->plain() );
		$this->assertSame( 'example &amp;', $msg->escaped() );
	}

	/**
	 * @covers CoreTagHooks::html
	 */
	public function testRawHtmlInMsg() {
		$this->overrideConfigValue( MainConfigNames::RawHtml, true );

		$msg = new RawMessage( '<html><script>alert("xss")</script></html>' );
		$txt = '<span class="error">&lt;html&gt; tags cannot be' .
			' used outside of normal pages.</span>';
		$this->assertSame( $txt, $msg->parse() );
	}

	/**
	 * @covers Message::params
	 * @covers Message::toString
	 * @covers Message::replaceParameters
	 */
	public function testReplaceManyParams() {
		$msg = new RawMessage( '$1$2$3$4$5$6$7$8$9$10$11$12' );
		// One less than above has placeholders
		$params = [ 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k' ];
		$this->assertSame(
			'abcdefghijka2',
			$msg->params( $params )->plain(),
			'Params > 9 are replaced correctly'
		);
	}

	/**
	 * @covers Message::numParam
	 * @covers Message::numParams
	 */
	public function testNumParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$this->assertSame(
			$lang->formatNum( 123456.789 ),
			$msg->inLanguage( $lang )->numParams( 123456.789 )->plain(),
			'numParams is handled correctly'
		);
	}

	/**
	 * @covers Message::durationParam
	 * @covers Message::durationParams
	 */
	public function testDurationParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$this->assertSame(
			$lang->formatDuration( 1234 ),
			$msg->inLanguage( $lang )->durationParams( 1234 )->plain(),
			'durationParams is handled correctly'
		);
	}

	/**
	 * FIXME: This should not need database, but Language#formatExpiry does (T57912)
	 * @covers Message::expiryParam
	 * @covers Message::expiryParams
	 * @covers Message::extractParam
	 */
	public function testExpiryParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$ts = wfTimestampNow();
		$this->assertSame(
			$lang->formatExpiry( $ts ),
			$msg->inLanguage( $lang )->expiryParams( $ts )->plain(),
			'expiryParams is handled correctly'
		);
	}

	/**
	 * @covers Message::dateTimeParams
	 * @covers Message::dateTimeParam
	 * @covers Message::extractParam
	 */
	public function testDateTimeParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$ts = wfTimestampNow();
		$this->assertSame(
			$lang->timeanddate( $ts ),
			$msg->inLanguage( $lang )->dateTimeParams( $ts )->plain(),
			'dateTime is handled correctly'
		);
	}

	/**
	 * @covers Message::dateParams
	 * @covers Message::dateParam
	 * @covers Message::extractParam
	 */
	public function testDateParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$ts = wfTimestampNow();
		$this->assertSame(
			$lang->date( $ts ),
			$msg->inLanguage( $lang )->dateParams( $ts )->plain(),
			'date is handled correctly'
		);
	}

	/**
	 * @covers Message::timeParams
	 * @covers Message::timeParam
	 * @covers Message::extractParam
	 */
	public function testTimeParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$ts = wfTimestampNow();
		$this->assertSame(
			$lang->time( $ts ),
			$msg->inLanguage( $lang )->timeParams( $ts )->plain(),
			'time is handled correctly'
		);
	}

	/**
	 * @covers Message::userGroupParam
	 * @covers Message::userGroupParams
	 */
	public function testUserGroupParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );
		$msg = new RawMessage( '$1' );
		$this->setUserLang( $lang );
		$this->assertSame(
			'(group-bot)',
			$msg->userGroupParams( 'bot' )->plain(),
			'user group is handled correctly'
		);
	}

	/**
	 * @covers Message::objectParam
	 * @covers Message::objectParams
	 */
	public function testUserGroupMemberParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );
		$msg = new RawMessage( '$1' );
		$this->setUserLang( $lang );
		$this->assertSame(
			'(group-bot-member: user)',
			$msg->objectParams(
				new UserGroupMembershipParam( 'bot', new UserIdentityValue( 1, 'user' ) )
			)->plain(),
			'user group member is handled correctly'
		);
	}

	/**
	 * @covers Message::timeperiodParam
	 * @covers Message::timeperiodParams
	 */
	public function testTimeperiodParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$this->assertSame(
			$lang->formatTimePeriod( 1234 ),
			$msg->inLanguage( $lang )->timeperiodParams( 1234 )->plain(),
			'timeperiodParams is handled correctly'
		);
	}

	/**
	 * @covers Message::sizeParam
	 * @covers Message::sizeParams
	 */
	public function testSizeParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$this->assertSame(
			$lang->formatSize( 123456 ),
			$msg->inLanguage( $lang )->sizeParams( 123456 )->plain(),
			'sizeParams is handled correctly'
		);
	}

	/**
	 * @covers Message::bitrateParam
	 * @covers Message::bitrateParams
	 */
	public function testBitrateParams() {
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$msg = new RawMessage( '$1' );

		$this->assertSame(
			$lang->formatBitrate( 123456 ),
			$msg->inLanguage( $lang )->bitrateParams( 123456 )->plain(),
			'bitrateParams is handled correctly'
		);
	}

	public static function providePlaintextParams() {
		return [
			[
				"one $2 <div>\u{0338}foo</div> [[Bar]] {{Baz}} &lt;",
				'plain',
			],

			[
				// expect
				"one $2 <div>\u{0338}foo</div> [[Bar]] {{Baz}} &lt;",
				// format
				'text',
			],
			[
				'one $2 &lt;div&gt;&#x338;foo&lt;/div&gt; [[Bar]] {{Baz}} &amp;lt;',
				'escaped',
			],

			[
				'one $2 &lt;div&gt;&#x338;foo&lt;/div&gt; [[Bar]] {{Baz}} &amp;lt;',
				'parse',
			],

			[
				"<p>one $2 &lt;div&gt;&#x338;foo&lt;/div&gt; [[Bar]] {{Baz}} &amp;lt;\n</p>",
				'parseAsBlock',
			],
		];
	}

	/**
	 * @covers Message::plaintextParam
	 * @covers Message::plaintextParams
	 * @covers Message::formatPlaintext
	 * @covers Message::toString
	 * @covers Message::parse
	 * @covers Message::parseAsBlock
	 * @dataProvider providePlaintextParams
	 */
	public function testPlaintextParams( $expect, $format ) {
		$msg = new RawMessage( '$1 $2' );
		$params = [
			'one $2',
			"<div>\u{0338}foo</div> [[Bar]] {{Baz}} &lt;",
		];
		$this->assertSame(
			$expect,
			$msg->inLanguage( 'en' )->plaintextParams( $params )->$format(),
			"Fail formatting for $format"
		);
	}

	public static function provideListParam() {
		$lang = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( 'de' );
		$msg1 = new Message( 'mainpage', [], $lang );
		$msg2 = new RawMessage( "''link''", [], $lang );

		return [
			'Simple comma list' => [
				[ 'a', 'b', 'c' ],
				'comma',
				'text',
				'a, b, c'
			],

			'Simple semicolon list' => [
				[ 'a', 'b', 'c' ],
				'semicolon',
				'text',
				'a; b; c'
			],

			'Simple pipe list' => [
				[ 'a', 'b', 'c' ],
				'pipe',
				'text',
				'a | b | c'
			],

			'Simple text list' => [
				[ 'a', 'b', 'c' ],
				'text',
				'text',
				'a, b and c'
			],

			'Empty list' => [
				[],
				'comma',
				'text',
				''
			],

			'List with all "before" params, ->text()' => [
				[ "''link''", Message::numParam( 12345678 ) ],
				'semicolon',
				'text',
				'\'\'link\'\'; 12,345,678'
			],

			'List with all "before" params, ->parse()' => [
				[ "''link''", Message::numParam( 12345678 ) ],
				'semicolon',
				'parse',
				'<i>link</i>; 12,345,678'
			],

			'List with all "after" params, ->text()' => [
				[ $msg1, $msg2, Message::rawParam( '[[foo]]' ) ],
				'semicolon',
				'text',
				'Main Page; \'\'link\'\'; [[foo]]'
			],

			'List with all "after" params, ->parse()' => [
				[ $msg1, $msg2, Message::rawParam( '[[foo]]' ) ],
				'semicolon',
				'parse',
				'Main Page; <i>link</i>; [[foo]]'
			],

			'List with both "before" and "after" params, ->text()' => [
				[ $msg1, $msg2, Message::rawParam( '[[foo]]' ), "''link''", Message::numParam( 12345678 ) ],
				'semicolon',
				'text',
				'Main Page; \'\'link\'\'; [[foo]]; \'\'link\'\'; 12,345,678'
			],

			'List with both "before" and "after" params, ->parse()' => [
				[ $msg1, $msg2, Message::rawParam( '[[foo]]' ), "''link''", Message::numParam( 12345678 ) ],
				'semicolon',
				'parse',
				'Main Page; <i>link</i>; [[foo]]; <i>link</i>; 12,345,678'
			],
		];
	}

	/**
	 * @covers Message::listParam
	 * @covers Message::extractParam
	 * @covers Message::formatListParam
	 * @dataProvider provideListParam
	 */
	public function testListParam( $list, $type, $format, $expect ) {
		$msg = new RawMessage( '$1' );
		$msg->params( [ Message::listParam( $list, $type ) ] );
		$this->assertEquals(
			$expect,
			$msg->inLanguage( 'en' )->$format()
		);
	}

	/**
	 * @covers Message::extractParam
	 */
	public function testMessageAsParam() {
		$this->overrideConfigValues( [
			MainConfigNames::Script => '/wiki/index.php',
			MainConfigNames::ArticlePath => '/wiki/$1',
		] );

		$msg = new Message( 'returnto', [
			new Message( 'apihelp-link', [
				'foo', new Message( 'mainpage', [],
					$this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' ) )
			], $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'de' ) )
		], $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'es' ) );

		$this->assertEquals(
			'Volver a [[Special:ApiHelp/foo|Página principal]].',
			$msg->text(),
			'Process with ->text()'
		);
		$this->assertEquals(
			'<p>Volver a <a href="/wiki/Special:ApiHelp/foo" title="Special:ApiHelp/foo">Página '
				. "principal</a>.\n</p>",
			$msg->parseAsBlock(),
			'Process with ->parseAsBlock()'
		);
	}

	public static function provideParser() {
		return [
			[
				"''&'' <x><!-- x -->",
				'plain',
			],

			[
				"''&'' <x><!-- x -->",
				'text',
			],
			[
				'<i>&amp;</i> &lt;x&gt;',
				'parse',
			],

			[
				"<p><i>&amp;</i> &lt;x&gt;\n</p>",
				'parseAsBlock',
			],
		];
	}

	/**
	 * @covers Message::text
	 * @covers Message::parse
	 * @covers Message::parseAsBlock
	 * @covers Message::toString
	 * @covers Message::transformText
	 * @covers Message::parseText
	 * @dataProvider provideParser
	 */
	public function testParser( $expect, $format ) {
		$msg = new RawMessage( "''&'' <x><!-- x -->" );
		$this->assertSame(
			$expect,
			$msg->inLanguage( 'en' )->$format()
		);
	}

	/**
	 * @covers Message::format
	 * @covers LanguageQqx
	 */
	public function testQqxPlaceholders() {
		$this->assertSame(
			wfMessage( 'test' )->inLanguage( 'qqx' )->text(),
			'(test)'
		);
		$this->assertSame(
			wfMessage( 'test' )->params( 'a', 'b' )->inLanguage( 'qqx' )->text(),
			'(test: a, b)'
		);
		$this->assertSame(
			wfMessageFallback( 'test', 'other-test' )->inLanguage( 'qqx' )->text(),
			'(test / other-test)'
		);
		$this->assertSame(
			wfMessageFallback( 'test', 'other-test' )->params( 'a', 'b' )->inLanguage( 'qqx' )->text(),
			'(test / other-test: a, b)'
		);
	}

	/**
	 * @covers Message::inContentLanguage
	 */
	public function testInContentLanguage() {
		$this->setUserLang( 'fr' );

		// NOTE: make sure internal caching of the message text is reset appropriately
		$msg = wfMessage( 'mainpage' );
		$this->assertSame( 'Hauptseite', $msg->inLanguage( 'de' )->plain(), "inLanguage( 'de' )" );
		$this->assertSame( 'Main Page', $msg->inContentLanguage()->plain(), "inContentLanguage()" );
		$this->assertSame( 'Accueil', $msg->inLanguage( 'fr' )->plain(), "inLanguage( 'fr' )" );
	}

	/**
	 * @covers Message::inContentLanguage
	 */
	public function testInContentLanguageOverride() {
		$this->overrideConfigValue( MainConfigNames::ForceUIMsgAsContentMsg, [ 'mainpage' ] );
		$this->setUserLang( 'fr' );

		// NOTE: make sure internal caching of the message text is reset appropriately.
		// NOTE: wgForceUIMsgAsContentMsg forces the messages *current* language to be used.
		$msg = wfMessage( 'mainpage' );
		$this->assertSame(
			'Accueil',
			$msg->inContentLanguage()->plain(),
			'inContentLanguage() with ForceUIMsg override enabled'
		);
		$this->assertSame( 'Main Page', $msg->inLanguage( 'en' )->plain(), "inLanguage( 'en' )" );
		$this->assertSame(
			'Main Page',
			$msg->inContentLanguage()->plain(),
			'inContentLanguage() with ForceUIMsg override enabled'
		);
		$this->assertSame( 'Hauptseite', $msg->inLanguage( 'de' )->plain(), "inLanguage( 'de' )" );
	}

	/**
	 * @covers Message::inLanguage
	 */
	public function testInLanguageThrows() {
		$this->expectException( MWException::class );
		wfMessage( 'foo' )->inLanguage( 123 );
	}

	/**
	 * @covers Message::serialize
	 * @covers Message::unserialize
	 */
	public function testSerialization() {
		$msg = new Message( 'parentheses' );
		$msg->rawParams( '<a>foo</a>' );
		$msg->page( PageReferenceValue::localReference( NS_MAIN, 'Testing' ) );
		$this->assertSame( '(<a>foo</a>)', $msg->parse() );
		$msg = unserialize( serialize( $msg ) );
		$this->assertSame( '(<a>foo</a>)', $msg->parse() );
		$title = TestingAccessWrapper::newFromObject( $msg )->contextPage;
		$this->assertInstanceOf( PageReference::class, $title );
		$this->assertSame( 'Testing', $title->getDbKey() );

		$msg = new Message( 'mainpage' );
		$msg->inLanguage( 'de' );
		$this->assertSame( 'Hauptseite', $msg->plain() );
		$msg = unserialize( serialize( $msg ) );
		$this->assertSame( 'Hauptseite', $msg->plain() );
	}

	/**
	 * @covers Message::newFromSpecifier
	 * @dataProvider provideNewFromSpecifier
	 */
	public function testNewFromSpecifier( $value, $expectedText ) {
		$message = Message::newFromSpecifier( $value );
		$this->assertInstanceOf( Message::class, $message );
		if ( $value instanceof Message ) {
			$this->assertInstanceOf( get_class( $value ), $message );
			$this->assertEquals( $value, $message );
		}
		$this->assertSame( $expectedText, $message->text() );
	}

	public function provideNewFromSpecifier() {
		$messageSpecifier = $this->getMockForAbstractClass( MessageSpecifier::class );
		$messageSpecifier->method( 'getKey' )->willReturn( 'mainpage' );
		$messageSpecifier->method( 'getParams' )->willReturn( [] );

		return [
			'string' => [ 'mainpage', 'Main Page' ],
			'array' => [ [ 'youhavenewmessages', 'foo', 'bar' ], 'You have foo (bar).' ],
			'Message' => [ new Message( 'youhavenewmessages', [ 'foo', 'bar' ] ), 'You have foo (bar).' ],
			'RawMessage' => [ new RawMessage( 'foo ($1)', [ 'bar' ] ), 'foo (bar)' ],
			'ApiMessage' => [ new ApiMessage( [ 'mainpage' ], 'code', [ 'data' ] ), 'Main Page' ],
			'MessageSpecifier' => [ $messageSpecifier, 'Main Page' ],
			'nested RawMessage' => [ [ new RawMessage( 'foo ($1)', [ 'bar' ] ) ], 'foo (bar)' ],
		];
	}
}
