<?php
/**
 * Holds shared logic for login and account creation pages.
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
 * @ingroup SpecialPage
 */

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use Wikimedia\ScopedCallback;

/**
 * Holds shared logic for login and account creation pages.
 *
 * @ingroup SpecialPage
 */
abstract class LoginSignupSpecialPage extends AuthManagerSpecialPage {
	protected $mReturnTo;
	protected $mPosted;
	protected $mAction;
	protected $mLanguage;
	protected $mReturnToQuery;
	protected $mToken;
	protected $mStickHTTPS;
	protected $mFromHTTP;
	protected $mEntryError = '';
	protected $mEntryErrorType = 'error';

	protected $mLoaded = false;
	protected $mLoadedRequest = false;
	protected $mSecureLoginUrl;
	private $reasonValidatorResult = null;

	/** @var string */
	protected $securityLevel;

	/** @var bool True if the user if creating an account for someone else. Flag used for internal
	 * communication, only set at the very end.
	 */
	protected $proxyAccountCreation;
	/** @var User FIXME another flag for passing data. */
	protected $targetUser;

	/** @var HTMLForm|null */
	protected $authForm;

	abstract protected function isSignup();

	/**
	 * @param bool $direct True if the action was successful just now; false if that happened
	 *    pre-redirection (so this handler was called already)
	 * @param StatusValue|null $extraMessages
	 * @return void
	 */
	abstract protected function successfulAction( $direct = false, $extraMessages = null );

	/**
	 * Logs to the authmanager-stats channel.
	 * @param bool $success
	 * @param string|null $status Error message key
	 */
	abstract protected function logAuthResult( $success, $status = null );

	public function __construct( $name, $restriction = '' ) {
		// phpcs:ignore MediaWiki.Usage.ExtendClassUsage.FunctionConfigUsage
		global $wgUseMediaWikiUIEverywhere;
		parent::__construct( $name, $restriction );

		// Override UseMediaWikiEverywhere to true, to force login and create form to use mw ui
		$wgUseMediaWikiUIEverywhere = true;
	}

	protected function setRequest( array $data, $wasPosted = null ) {
		parent::setRequest( $data, $wasPosted );
		$this->mLoadedRequest = false;
	}

	/**
	 * Load basic request parameters for this Special page.
	 */
	private function loadRequestParameters() {
		if ( $this->mLoadedRequest ) {
			return;
		}
		$this->mLoadedRequest = true;
		$request = $this->getRequest();

		$this->mPosted = $request->wasPosted();
		$this->mAction = $request->getRawVal( 'action' );
		$this->mFromHTTP = $request->getBool( 'fromhttp', false )
			|| $request->getBool( 'wpFromhttp', false );
		$this->mStickHTTPS = $this->getConfig()->get( MainConfigNames::ForceHTTPS )
			|| ( !$this->mFromHTTP && $request->getProtocol() === 'https' )
			|| $request->getBool( 'wpForceHttps', false );
		$this->mLanguage = $request->getText( 'uselang' );
		$this->mReturnTo = $request->getVal( 'returnto', '' );
		$this->mReturnToQuery = $request->getVal( 'returntoquery', '' );
	}

	/**
	 * Load data from request.
	 * @internal
	 * @param string $subPage Subpage of Special:Userlogin
	 */
	protected function load( $subPage ) {
		$this->loadRequestParameters();
		if ( $this->mLoaded ) {
			return;
		}
		$this->mLoaded = true;
		$request = $this->getRequest();

		$securityLevel = $this->getRequest()->getText( 'force' );
		if (
			$securityLevel &&
				MediaWikiServices::getInstance()->getAuthManager()->securitySensitiveOperationStatus(
					$securityLevel ) === AuthManager::SEC_REAUTH
		) {
			$this->securityLevel = $securityLevel;
		}

		$this->loadAuth( $subPage );

		$this->mToken = $request->getVal( $this->getTokenName() );

		// Show an error or warning passed on from a previous page
		$entryError = $this->msg( $request->getVal( 'error', '' ) );
		$entryWarning = $this->msg( $request->getVal( 'warning', '' ) );
		// bc: provide login link as a parameter for messages where the translation
		// was not updated
		$loginreqlink = $this->getLinkRenderer()->makeKnownLink(
			$this->getPageTitle(),
			$this->msg( 'loginreqlink' )->text(),
			[],
			[
				'returnto' => $this->mReturnTo,
				'returntoquery' => $this->mReturnToQuery,
				'uselang' => $this->mLanguage ?: null,
				'fromhttp' => $this->getConfig()->get( MainConfigNames::SecureLogin ) &&
					$this->mFromHTTP ? '1' : null,
			]
		);

		// Only show valid error or warning messages.
		if ( $entryError->exists()
			&& in_array( $entryError->getKey(), LoginHelper::getValidErrorMessages(), true )
		) {
			$this->mEntryErrorType = 'error';
			$this->mEntryError = $entryError->rawParams( $loginreqlink )->parse();

		} elseif ( $entryWarning->exists()
			&& in_array( $entryWarning->getKey(), LoginHelper::getValidErrorMessages(), true )
		) {
			$this->mEntryErrorType = 'warning';
			$this->mEntryError = $entryWarning->rawParams( $loginreqlink )->parse();
		}

		# 1. When switching accounts, it sucks to get automatically logged out
		# 2. Do not return to PasswordReset after a successful password change
		#    but goto Wiki start page (Main_Page) instead ( T35997 )
		$returnToTitle = Title::newFromText( $this->mReturnTo );
		if ( is_object( $returnToTitle )
			&& ( $returnToTitle->isSpecial( 'Userlogout' )
				|| $returnToTitle->isSpecial( 'PasswordReset' ) )
		) {
			$this->mReturnTo = '';
			$this->mReturnToQuery = '';
		}
	}

	protected function getPreservedParams( $withToken = false ) {
		$params = parent::getPreservedParams( $withToken );
		$params += [
			'returnto' => $this->mReturnTo ?: null,
			'returntoquery' => $this->mReturnToQuery ?: null,
		];
		if ( $this->getConfig()->get( MainConfigNames::SecureLogin ) && !$this->isSignup() ) {
			$params['fromhttp'] = $this->mFromHTTP ? '1' : null;
		}
		return $params;
	}

	protected function beforeExecute( $subPage ) {
		// finish initializing the class before processing the request - T135924
		$this->loadRequestParameters();
		return parent::beforeExecute( $subPage );
	}

	/**
	 * @param string|null $subPage
	 * @suppress PhanTypeObjectUnsetDeclaredProperty
	 */
	public function execute( $subPage ) {
		if ( $this->mPosted ) {
			$time = microtime( true );
			$profilingScope = new ScopedCallback( function () use ( $time ) {
				$time = microtime( true ) - $time;
				$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();
				$statsd->timing( "timing.login.ui.{$this->authAction}", $time * 1000 );
			} );
		}

		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$session = SessionManager::getGlobalSession();

		// Session data is used for various things in the authentication process, so we must make
		// sure a session cookie or some equivalent mechanism is set.
		$session->persist();
		// Explicitly disable cache to ensure cookie blocks may be set (T152462).
		// (Technically redundant with sessions persisting from this page.)
		$this->getOutput()->disableClientCache();

		$this->load( $subPage );
		$this->setHeaders();
		$this->checkPermissions();

		// Make sure the system configuration allows log in / sign up
		if ( !$this->isSignup() && !$authManager->canAuthenticateNow() ) {
			if ( !$session->canSetUser() ) {
				throw new ErrorPageError( 'cannotloginnow-title', 'cannotloginnow-text', [
					$session->getProvider()->describe( $this->getLanguage() )
				] );
			}
			throw new ErrorPageError( 'cannotlogin-title', 'cannotlogin-text' );
		} elseif ( $this->isSignup() && !$authManager->canCreateAccounts() ) {
			throw new ErrorPageError( 'cannotcreateaccount-title', 'cannotcreateaccount-text' );
		}

		/*
		 * In the case where the user is already logged in, and was redirected to
		 * the login form from a page that requires login, do not show the login
		 * page. The use case scenario for this is when a user opens a large number
		 * of tabs, is redirected to the login page on all of them, and then logs
		 * in on one, expecting all the others to work properly.
		 *
		 * However, do show the form if it was visited intentionally (no 'returnto'
		 * is present). People who often switch between several accounts have grown
		 * accustomed to this behavior.
		 *
		 * For temporary users, the form is always shown, since the UI presents
		 * temporary users as not logged in and offers to discard their temporary
		 * account by logging in.
		 *
		 * Also make an exception when force=<level> is set in the URL, which means the user must
		 * reauthenticate for security reasons.
		 */
		if ( !$this->isSignup() && !$this->mPosted && !$this->securityLevel &&
			( $this->mReturnTo !== '' || $this->mReturnToQuery !== '' ) &&
			!$this->getUser()->isTemp() && $this->getUser()->isRegistered()
		) {
			$this->successfulAction();
			return;
		}

		// If logging in and not on HTTPS, either redirect to it or offer a link.
		if ( $this->getRequest()->getProtocol() !== 'https' ) {
			$title = $this->getFullTitle();
			$query = $this->getPreservedParams( false ) + [
					'title' => null,
					( $this->mEntryErrorType === 'error' ? 'error'
						: 'warning' ) => $this->mEntryError,
				] + $this->getRequest()->getQueryValues();
			$url = $title->getFullURL( $query, false, PROTO_HTTPS );
			if ( $this->getConfig()->get( MainConfigNames::SecureLogin ) && !$this->mFromHTTP ) {
				// Avoid infinite redirect
				$url = wfAppendQuery( $url, 'fromhttp=1' );
				$this->getOutput()->redirect( $url );
				// Since we only do this redir to change proto, always vary
				$this->getOutput()->addVaryHeader( 'X-Forwarded-Proto' );

				return;
			} else {
				// A wiki without HTTPS login support should set $wgServer to
				// http://somehost, in which case the secure URL generated
				// above won't actually start with https://
				if ( substr( $url, 0, 8 ) === 'https://' ) {
					$this->mSecureLoginUrl = $url;
				}
			}
		}

		if ( !$this->isActionAllowed( $this->authAction ) ) {
			// FIXME how do we explain this to the user? can we handle session loss better?
			// messages used: authpage-cannot-login, authpage-cannot-login-continue,
			// authpage-cannot-create, authpage-cannot-create-continue
			$this->mainLoginForm( [], 'authpage-cannot-' . $this->authAction );
			return;
		}

		if ( $this->canBypassForm( $button_name ) ) {
			$this->setRequest( [], true );
			$this->getRequest()->setVal( $this->getTokenName(), $this->getToken() );
			if ( $button_name ) {
				$this->getRequest()->setVal( $button_name, true );
			}
		}

		$status = $this->trySubmit();

		if ( !$status || !$status->isGood() ) {
			$this->mainLoginForm( $this->authRequests, $status ? $status->getMessage() : '', 'error' );
			return;
		}

		/** @var AuthenticationResponse $response */
		$response = $status->getValue();

		$returnToUrl = $this->getPageTitle( 'return' )
			->getFullURL( $this->getPreservedParams( true ), false, PROTO_HTTPS );
		switch ( $response->status ) {
			case AuthenticationResponse::PASS:
				$this->logAuthResult( true );
				$this->proxyAccountCreation = $this->isSignup() && $this->getUser()->isNamed();
				$this->targetUser = User::newFromName( $response->username );

				if (
					!$this->proxyAccountCreation
					&& $response->loginRequest
					&& $authManager->canAuthenticateNow()
				) {
					// successful registration; log the user in instantly
					$response2 = $authManager->beginAuthentication( [ $response->loginRequest ],
						$returnToUrl );
					if ( $response2->status !== AuthenticationResponse::PASS ) {
						LoggerFactory::getInstance( 'login' )
							->error( 'Could not log in after account creation' );
						$this->successfulAction( true, Status::newFatal( 'createacct-loginerror' ) );
						break;
					}
				}

				if ( !$this->proxyAccountCreation ) {
					// Ensure that the context user is the same as the session user.
					$this->setSessionUserForCurrentRequest();
				}

				$this->successfulAction( true );
				break;
			case AuthenticationResponse::FAIL:
				// fall through
			case AuthenticationResponse::RESTART:
				unset( $this->authForm );
				if ( $response->status === AuthenticationResponse::FAIL ) {
					$action = $this->getDefaultAction( $subPage );
					$messageType = 'error';
				} else {
					$action = $this->getContinueAction( $this->authAction );
					$messageType = 'warning';
				}
				$this->logAuthResult( false, $response->message ? $response->message->getKey() : '-' );
				$this->loadAuth( $subPage, $action, true );
				$this->mainLoginForm( $this->authRequests, $response->message, $messageType );
				break;
			case AuthenticationResponse::REDIRECT:
				unset( $this->authForm );
				$this->getOutput()->redirect( $response->redirectTarget );
				break;
			case AuthenticationResponse::UI:
				unset( $this->authForm );
				$this->authAction = $this->isSignup() ? AuthManager::ACTION_CREATE_CONTINUE
					: AuthManager::ACTION_LOGIN_CONTINUE;
				$this->authRequests = $response->neededRequests;
				$this->mainLoginForm( $response->neededRequests, $response->message, $response->messageType );
				break;
			default:
				throw new LogicException( 'invalid AuthenticationResponse' );
		}
	}

	/**
	 * Determine if the login form can be bypassed. This will be the case when no more than one
	 * button is present and no other user input fields that are not marked as 'skippable' are
	 * present. If the login form were not bypassed, the user would be presented with a
	 * superfluous page on which they must press the single button to proceed with login.
	 * Not only does this cause an additional mouse click and page load, it confuses users,
	 * especially since there are a help link and forgotten password link that are
	 * provided on the login page that do not apply to this situation.
	 *
	 * @param string|null &$button_name if the form has a single button, returns
	 *   the name of the button; otherwise, returns null
	 * @return bool
	 */
	private function canBypassForm( &$button_name ) {
		$button_name = null;
		if ( $this->isContinued() ) {
			return false;
		}
		$fields = AuthenticationRequest::mergeFieldInfo( $this->authRequests );
		foreach ( $fields as $fieldname => $field ) {
			if ( !isset( $field['type'] ) ) {
				return false;
			}
			if ( !empty( $field['skippable'] ) ) {
				continue;
			}
			if ( $field['type'] === 'button' ) {
				if ( $button_name !== null ) {
					$button_name = null;
					return false;
				} else {
					$button_name = $fieldname;
				}
			} elseif ( $field['type'] !== 'null' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Show the success page.
	 *
	 * @param string $type Condition of return to; see `executeReturnTo`
	 * @param string|Message $title Page's title
	 * @param string $msgname
	 * @param string $injected_html
	 * @param StatusValue|null $extraMessages
	 */
	protected function showSuccessPage(
		$type, $title, $msgname, $injected_html, $extraMessages
	) {
		$out = $this->getOutput();
		$out->setPageTitle( $title );
		if ( $msgname ) {
			$out->addWikiMsg( $msgname, wfEscapeWikiText( $this->getUser()->getName() ) );
		}
		if ( $extraMessages ) {
			$extraMessages = Status::wrap( $extraMessages );
			$out->addWikiTextAsInterface(
				$extraMessages->getWikiText( false, false, $this->getLanguage() )
			);
		}

		$out->addHTML( $injected_html );

		$helper = new LoginHelper( $this->getContext() );
		$helper->showReturnToPage( $type, $this->mReturnTo, $this->mReturnToQuery, $this->mStickHTTPS );
	}

	/**
	 * Add a "return to" link or redirect to it.
	 * Extensions can use this to reuse the "return to" logic after
	 * inject steps (such as redirection) into the login process.
	 *
	 * @param string $type One of the following:
	 *    - error: display a return to link ignoring $wgRedirectOnLogin
	 *    - signup: display a return to link using $wgRedirectOnLogin if needed
	 *    - success: display a return to link using $wgRedirectOnLogin if needed
	 *    - successredirect: send an HTTP redirect using $wgRedirectOnLogin if needed
	 * @param string $returnTo
	 * @param array|string $returnToQuery
	 * @param bool $stickHTTPS Keep redirect link on HTTPS
	 * @since 1.22
	 */
	public function showReturnToPage(
		$type, $returnTo = '', $returnToQuery = '', $stickHTTPS = false
	) {
		$helper = new LoginHelper( $this->getContext() );
		$helper->showReturnToPage( $type, $returnTo, $returnToQuery, $stickHTTPS );
	}

	/**
	 * Replace some globals to make sure the fact that the user has just been logged in is
	 * reflected in the current request.
	 */
	protected function setSessionUserForCurrentRequest() {
		global $wgLang;

		$context = RequestContext::getMain();
		$localContext = $this->getContext();
		if ( $context !== $localContext ) {
			// remove AuthManagerSpecialPage context hack
			$this->setContext( $context );
		}

		$user = $context->getRequest()->getSession()->getUser();

		StubGlobalUser::setUser( $user );
		$context->setUser( $user );

		$wgLang = $context->getLanguage();
	}

	/**
	 * @param AuthenticationRequest[] $requests A list of AuthorizationRequest objects,
	 *   used to generate the form fields. An empty array means a fatal error
	 *   (authentication cannot continue).
	 * @param string|Message $msg
	 * @param string $msgtype
	 * @throws ErrorPageError
	 * @throws Exception
	 * @throws FatalError
	 * @throws MWException
	 * @throws PermissionsError
	 * @throws ReadOnlyError
	 * @internal
	 */
	protected function mainLoginForm( array $requests, $msg = '', $msgtype = 'error' ) {
		$user = $this->getUser();
		$out = $this->getOutput();

		// FIXME how to handle empty $requests - restart, or no form, just an error message?
		// no form would be better for no session type errors, restart is better when can* fails.
		if ( !$requests ) {
			$this->authAction = $this->getDefaultAction( $this->subPage );
			$this->authForm = null;
			$requests = MediaWikiServices::getInstance()->getAuthManager()
				->getAuthenticationRequests( $this->authAction, $user );
		}

		// Generic styles and scripts for both login and signup form
		$out->addModuleStyles( [
			'mediawiki.ui',
			'mediawiki.ui.button',
			'mediawiki.ui.checkbox',
			'mediawiki.ui.input',
			'mediawiki.special.userlogin.common.styles'
		] );
		if ( $this->isSignup() ) {
			// XXX hack pending RL or JS parse() support for complex content messages T27349
			$out->addJsConfigVars( 'wgCreateacctImgcaptchaHelp',
				$this->msg( 'createacct-imgcaptcha-help' )->parse() );

			// Additional styles and scripts for signup form
			$out->addModules( 'mediawiki.special.createaccount' );
			$out->addModuleStyles( [
				'mediawiki.special.userlogin.signup.styles'
			] );
		} else {
			// Additional styles for login form
			$out->addModuleStyles( [
				'mediawiki.special.userlogin.login.styles'
			] );
		}
		$out->disallowUserJs(); // just in case...

		$form = $this->getAuthForm( $requests, $this->authAction, $msg, $msgtype );
		$form->prepareForm();

		$submitStatus = Status::newGood();
		if ( $msg && $msgtype === 'warning' ) {
			$submitStatus->warning( $msg );
		} elseif ( $msg && $msgtype === 'error' ) {
			$submitStatus->fatal( $msg );
		}

		// warning header for non-standard workflows (e.g. security reauthentication)
		if (
			!$this->isSignup() &&
			$this->getUser()->isRegistered() &&
			!$this->getUser()->isTemp() &&
			$this->authAction !== AuthManager::ACTION_LOGIN_CONTINUE
		) {
			$reauthMessage = $this->securityLevel ? 'userlogin-reauth' : 'userlogin-loggedin';
			$submitStatus->warning( $reauthMessage, $this->getUser()->getName() );
		}

		$formHtml = $form->getHTML( $submitStatus );

		$out->addHTML( $this->getPageHtml( $formHtml ) );
	}

	/**
	 * Add page elements which are outside the form.
	 * FIXME this should probably be a template, but use a sensible language (handlebars?)
	 * @param string $formHtml
	 * @return string
	 */
	protected function getPageHtml( $formHtml ) {
		$loginPrompt = $this->isSignup() ? '' : Html::rawElement( 'div',
			[ 'id' => 'userloginprompt' ], $this->msg( 'loginprompt' )->parseAsBlock() );
		$languageLinks = $this->getConfig()->get( MainConfigNames::LoginLanguageSelector )
			? $this->makeLanguageSelector() : '';
		$signupStartMsg = $this->msg( 'signupstart' );
		$signupStart = ( $this->isSignup() && !$signupStartMsg->isDisabled() )
			? Html::rawElement( 'div', [ 'id' => 'signupstart' ], $signupStartMsg->parseAsBlock() ) : '';
		if ( $languageLinks ) {
			$languageLinks = Html::rawElement( 'div', [ 'id' => 'languagelinks' ],
				Html::rawElement( 'p', [], $languageLinks )
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'mw-ui-container' ],
			$loginPrompt
			. $languageLinks
			. $signupStart
			. Html::rawElement( 'div', [ 'id' => 'userloginForm' ], $formHtml )
			. $this->getBenefitsContainerHtml()
		);
	}

	/**
	 * The HTML to be shown in the "benefits to signing in / creating an account" section of the signup/login page.
	 *
	 * @unstable Experimental method added in 1.38. As noted in the comment from 2015 for getPageHtml,
	 *   this should use a template.
	 * @return string
	 */
	protected function getBenefitsContainerHtml(): string {
		$benefitsContainer = '';
		if ( $this->isSignup() && $this->showExtraInformation() ) {
			// The following messages are used here:
			// * createacct-benefit-icon1 createacct-benefit-head1 createacct-benefit-body1
			// * createacct-benefit-icon2 createacct-benefit-head2 createacct-benefit-body2
			// * createacct-benefit-icon3 createacct-benefit-head3 createacct-benefit-body3
			$benefitCount = 3;
			$benefitList = '';
			for ( $benefitIdx = 1; $benefitIdx <= $benefitCount; $benefitIdx++ ) {
				$headUnescaped = $this->msg( "createacct-benefit-head$benefitIdx" )->text();
				$iconClass = $this->msg( "createacct-benefit-icon$benefitIdx" )->text();
				$benefitList .= Html::rawElement( 'div', [ 'class' => "mw-number-text $iconClass" ],
					Html::rawElement( 'h3', [],
						$this->msg( "createacct-benefit-head$benefitIdx" )->escaped()
					)
					. Html::rawElement( 'p', [],
						$this->msg( "createacct-benefit-body$benefitIdx" )->params( $headUnescaped )->escaped()
					)
				);
			}
			$benefitsContainer = Html::rawElement( 'div', [ 'class' => 'mw-createacct-benefits-container' ],
				Html::rawElement( 'h2', [], $this->msg( 'createacct-benefit-heading' )->escaped() )
				. Html::rawElement( 'div', [ 'class' => 'mw-createacct-benefits-list' ], $benefitList )
			);
		}
		return $benefitsContainer;
	}

	/**
	 * Generates a form from the given request.
	 * @param AuthenticationRequest[] $requests
	 * @param string $action AuthManager action name
	 * @param string|Message $msg
	 * @param string $msgType
	 * @return HTMLForm
	 */
	protected function getAuthForm( array $requests, $action, $msg = '', $msgType = 'error' ) {
		// FIXME merge this with parent

		if ( isset( $this->authForm ) ) {
			return $this->authForm;
		}

		$usingHTTPS = $this->getRequest()->getProtocol() === 'https';

		// get basic form description from the auth logic
		$fieldInfo = AuthenticationRequest::mergeFieldInfo( $requests );
		// this will call onAuthChangeFormFields()
		$formDescriptor = $this->fieldInfoToFormDescriptor( $requests, $fieldInfo, $this->authAction );
		$this->postProcessFormDescriptor( $formDescriptor, $requests );

		$context = $this->getContext();
		if ( $context->getRequest() !== $this->getRequest() ) {
			// We have overridden the request, need to make sure the form uses that too.
			$context = new DerivativeContext( $this->getContext() );
			$context->setRequest( $this->getRequest() );
		}
		$form = HTMLForm::factory( 'vform', $formDescriptor, $context );

		$form->addHiddenField( 'authAction', $this->authAction );
		if ( $this->mLanguage ) {
			$form->addHiddenField( 'uselang', $this->mLanguage );
		}
		$form->addHiddenField( 'force', $this->securityLevel );
		$form->addHiddenField( $this->getTokenName(), $this->getToken()->toString() );
		$config = $this->getConfig();
		if ( $config->get( MainConfigNames::SecureLogin ) &&
		!$config->get( MainConfigNames::ForceHTTPS ) ) {
			// If using HTTPS coming from HTTP, then the 'fromhttp' parameter must be preserved
			if ( !$this->isSignup() ) {
				$form->addHiddenField( 'wpForceHttps', (int)$this->mStickHTTPS );
				$form->addHiddenField( 'wpFromhttp', $usingHTTPS );
			}
		}

		// set properties of the form itself
		$form->setAction( $this->getPageTitle()->getLocalURL( $this->getReturnToQueryStringFragment() ) );
		$form->setName( 'userlogin' . ( $this->isSignup() ? '2' : '' ) );
		if ( $this->isSignup() ) {
			$form->setId( 'userlogin2' );
		}

		$form->suppressDefaultSubmit();

		$this->authForm = $form;

		return $form;
	}

	/** @inheritDoc */
	public function onAuthChangeFormFields(
		array $requests, array $fieldInfo, array &$formDescriptor, $action
	) {
		$formDescriptor = self::mergeDefaultFormDescriptor( $fieldInfo, $formDescriptor,
			$this->getFieldDefinitions( $fieldInfo ) );
	}

	/**
	 * Show extra information such as password recovery information, link from login to signup,
	 * CTA etc? Such information should only be shown on the "landing page", ie. when the user
	 * is at the first step of the authentication process.
	 * @return bool
	 */
	protected function showExtraInformation() {
		return $this->authAction !== $this->getContinueAction( $this->authAction )
			&& !$this->securityLevel;
	}

	/**
	 * Create a HTMLForm descriptor for the core login fields.
	 * @param array $fieldInfo
	 * @return array
	 */
	protected function getFieldDefinitions( array $fieldInfo ) {
		$isLoggedIn = $this->getUser()->isRegistered();
		$continuePart = $this->isContinued() ? 'continue-' : '';
		$anotherPart = $isLoggedIn ? 'another-' : '';
		// @phan-suppress-next-line PhanUndeclaredMethod
		$expiration = $this->getRequest()->getSession()->getProvider()->getRememberUserDuration();
		$expirationDays = ceil( $expiration / ( 3600 * 24 ) );
		$secureLoginLink = '';
		if ( $this->mSecureLoginUrl ) {
			$secureLoginLink = Html::element( 'a', [
				'href' => $this->mSecureLoginUrl,
				'class' => 'mw-ui-flush-right mw-secure',
			], $this->msg( 'userlogin-signwithsecure' )->text() );
		}
		$usernameHelpLink = '';
		if ( !$this->msg( 'createacct-helpusername' )->isDisabled() ) {
			$usernameHelpLink = Html::rawElement( 'span', [
				'class' => 'mw-ui-flush-right',
			], $this->msg( 'createacct-helpusername' )->parse() );
		}

		if ( $this->isSignup() ) {
			$config = $this->getConfig();
			$hideIf = isset( $fieldInfo['mailpassword'] ) ? [ 'hide-if' => [ '===', 'mailpassword', '1' ] ] : [];
			$fieldDefinitions = [
				'statusarea' => [
					// Used by the mediawiki.special.createaccount module for error display.
					// FIXME: Merge this with HTMLForm's normal status (error) area
					'type' => 'info',
					'raw' => true,
					'default' => Html::element( 'div', [ 'id' => 'mw-createacct-status-area' ] ),
					'weight' => -105,
				],
				'username' => [
					'label-raw' => $this->msg( 'userlogin-yourname' )->escaped() . $usernameHelpLink,
					'id' => 'wpName2',
					'placeholder-message' => $isLoggedIn ? 'createacct-another-username-ph'
						: 'userlogin-yourname-ph',
				],
				'mailpassword' => [
					// create account without providing password, a temporary one will be mailed
					'type' => 'check',
					'label-message' => 'createaccountmail',
					'name' => 'wpCreateaccountMail',
					'id' => 'wpCreateaccountMail',
				],
				'password' => [
					'id' => 'wpPassword2',
					'autocomplete' => 'new-password',
					'placeholder-message' => 'createacct-yourpassword-ph',
					'help-message' => 'createacct-useuniquepass',
				] + $hideIf,
				'domain' => [],
				'retype' => [
					'type' => 'password',
					'label-message' => 'createacct-yourpasswordagain',
					'id' => 'wpRetype',
					'cssclass' => 'loginPassword',
					'size' => 20,
					'autocomplete' => 'new-password',
					'validation-callback' => function ( $value, $alldata ) {
						if ( empty( $alldata['mailpassword'] ) && !empty( $alldata['password'] ) ) {
							if ( !$value ) {
								return $this->msg( 'htmlform-required' );
							} elseif ( $value !== $alldata['password'] ) {
								return $this->msg( 'badretype' );
							}
						}
						return true;
					},
					'placeholder-message' => 'createacct-yourpasswordagain-ph',
				] + $hideIf,
				'email' => [
					'type' => 'email',
					'label-message' => $config->get( MainConfigNames::EmailConfirmToEdit )
						? 'createacct-emailrequired' : 'createacct-emailoptional',
					'id' => 'wpEmail',
					'cssclass' => 'loginText',
					'size' => '20',
					'maxlength' => 255,
					'autocomplete' => 'email',
					// FIXME will break non-standard providers
					'required' => $config->get( MainConfigNames::EmailConfirmToEdit ),
					'validation-callback' => function ( $value, $alldata ) {
						// AuthManager will check most of these, but that will make the auth
						// session fail and this won't, so nicer to do it this way
						if ( !$value &&
							$this->getConfig()->get( MainConfigNames::EmailConfirmToEdit )
						) {
							// no point in allowing registration without email when email is
							// required to edit
							return $this->msg( 'noemailtitle' );
						} elseif ( !$value && !empty( $alldata['mailpassword'] ) ) {
							// cannot send password via email when there is no email address
							return $this->msg( 'noemailcreate' );
						} elseif ( $value && !Sanitizer::validateEmail( $value ) ) {
							return $this->msg( 'invalidemailaddress' );
						} elseif ( is_string( $value ) && strlen( $value ) > 255 ) {
							return $this->msg( 'changeemail-maxlength' );
						}
						return true;
					},
					// The following messages are used here:
					// * createacct-email-ph
					// * createacct-another-email-ph
					'placeholder-message' => 'createacct-' . $anotherPart . 'email-ph',
				],
				'realname' => [
					'type' => 'text',
					'help-message' => $isLoggedIn ? 'createacct-another-realname-tip'
						: 'prefs-help-realname',
					'label-message' => 'createacct-realname',
					'cssclass' => 'loginText',
					'size' => 20,
					'id' => 'wpRealName',
					'autocomplete' => 'name',
				],
				'reason' => [
					// comment for the user creation log
					'type' => 'text',
					'label-message' => 'createacct-reason',
					'cssclass' => 'loginText',
					'id' => 'wpReason',
					'size' => '20',
					'validation-callback' => function ( $value, $alldata ) {
						// if the user sets an email address as the user creation reason, confirm that
						// that was their intent
						if ( $value && Sanitizer::validateEmail( $value ) ) {
							if ( $this->reasonValidatorResult !== null ) {
								return $this->reasonValidatorResult;
							}
							$this->reasonValidatorResult = true;
							$authManager = MediaWikiServices::getInstance()->getAuthManager();
							if ( !$authManager->getAuthenticationSessionData( 'reason-retry', false ) ) {
								$authManager->setAuthenticationSessionData( 'reason-retry', true );
								$this->reasonValidatorResult = $this->msg( 'createacct-reason-confirm' );
							}
							return $this->reasonValidatorResult;
						}
						return true;
					},
					'placeholder-message' => 'createacct-reason-ph',
				],
				'createaccount' => [
					// submit button
					'type' => 'submit',
					// The following messages are used here:
					// * createacct-submit
					// * createacct-another-submit
					// * createacct-continue-submit
					// * createacct-another-continue-submit
					'default' => $this->msg( 'createacct-' . $anotherPart . $continuePart .
						'submit' )->text(),
					'name' => 'wpCreateaccount',
					'id' => 'wpCreateaccount',
					'weight' => 100,
				],
			];
			if ( !$this->msg( 'createacct-username-help' )->isDisabled() ) {
				$fieldDefinitions['username']['help-message'] = 'createacct-username-help';
			}
		} else {
			// When the user's password is too weak, they might be asked to provide a stronger one
			// as a followup step. That is a form with only two fields, 'password' and 'retype',
			// and they should behave more like account creation.
			$passwordRequest = AuthenticationRequest::getRequestByClass( $this->authRequests,
				PasswordAuthenticationRequest::class );
			$changePassword = $passwordRequest && $passwordRequest->action == AuthManager::ACTION_CHANGE;
			$fieldDefinitions = [
				'username' => (
					[
						'label-raw' => $this->msg( 'userlogin-yourname' )->escaped() . $secureLoginLink,
						'id' => 'wpName1',
						'placeholder-message' => 'userlogin-yourname-ph',
					] + ( $changePassword ? [
						// There is no username field on the AuthManager level when changing
						// passwords. Fake one because password
						'baseField' => 'password',
						'nodata' => true,
						'readonly' => true,
						'cssclass' => 'mw-htmlform-hidden-field',
					] : [] )
				),
				'password' => (
					$changePassword ? [
						'autocomplete' => 'new-password',
						'placeholder-message' => 'createacct-yourpassword-ph',
						'help-message' => 'createacct-useuniquepass',
					] : [
						'id' => 'wpPassword1',
						'autocomplete' => 'current-password',
						'placeholder-message' => 'userlogin-yourpassword-ph',
					]
				),
				'retype' => [
					'type' => 'password',
					'autocomplete' => 'new-password',
					'placeholder-message' => 'createacct-yourpasswordagain-ph',
				],
				'domain' => [],
				'rememberMe' => [
					// option for saving the user token to a cookie
					'type' => 'check',
					'cssclass' => 'mw-userlogin-rememberme',
					'name' => 'wpRemember',
					'label-message' => $this->msg( 'userlogin-remembermypassword' )
						->numParams( $expirationDays ),
					'id' => 'wpRemember',
				],
				'loginattempt' => [
					// submit button
					'type' => 'submit',
					// The following messages are used here:
					// * pt-login-button
					// * pt-login-continue-button
					'default' => $this->msg( 'pt-login-' . $continuePart . 'button' )->text(),
					'id' => 'wpLoginAttempt',
					'weight' => 100,
				],
				'linkcontainer' => [
					// help link
					'type' => 'info',
					'cssclass' => 'mw-form-related-link-container mw-userlogin-help',
					// 'id' => 'mw-userlogin-help', // FIXME HTMLInfoField ignores this
					'raw' => true,
					'default' => Html::element( 'a', [
						'href' => Skin::makeInternalOrExternalUrl( $this->msg( 'helplogin-url' )
							->inContentLanguage()
							->text() ),
					], $this->msg( 'userlogin-helplink2' )->text() ),
					'weight' => 200,
				],
				// button for ResetPasswordSecondaryAuthenticationProvider
				'skipReset' => [
					'weight' => 110,
					'flags' => [],
				],
			];
		}

		$fieldDefinitions['username'] += [
			'type' => 'text',
			'name' => 'wpName',
			'cssclass' => 'loginText',
			'size' => 20,
			'autocomplete' => 'username',
			// 'required' => true,
		];
		$fieldDefinitions['password'] += [
			'type' => 'password',
			// 'label-message' => 'userlogin-yourpassword', // would override the changepassword label
			'name' => 'wpPassword',
			'cssclass' => 'loginPassword',
			'size' => 20,
			// 'required' => true,
		];

		if ( $this->mEntryError ) {
			$defaultHtml = '';
			if ( $this->mEntryErrorType === 'error' ) {
				$defaultHtml = Html::errorBox( $this->mEntryError );
			} elseif ( $this->mEntryErrorType === 'warning' ) {
				$defaultHtml = Html::warningBox( $this->mEntryError );
			}
			$fieldDefinitions['entryError'] = [
				'type' => 'info',
				'default' => $defaultHtml,
				'raw' => true,
				'rawrow' => true,
				'weight' => -100,
			];
		}
		if ( $this->isSignup() && $this->getUser()->isTemp() ) {
			$fieldDefinitions['tempWarning'] = [
				'type' => 'info',
				'default' => Html::warningBox(
					$this->msg( 'createacct-temp-warning' )->parse()
				),
				'raw' => true,
				'rawrow' => true,
				'weight' => -90,
			];
		}
		if ( !$this->showExtraInformation() ) {
			unset( $fieldDefinitions['linkcontainer'], $fieldDefinitions['signupend'] );
		}
		if ( $this->isSignup() && $this->showExtraInformation() ) {
			// blank signup footer for site customization
			// uses signupend-https for HTTPS requests if it's not blank, signupend otherwise
			$signupendMsg = $this->msg( 'signupend' );
			$signupendHttpsMsg = $this->msg( 'signupend-https' );
			if ( !$signupendMsg->isDisabled() ) {
				$usingHTTPS = $this->getRequest()->getProtocol() === 'https';
				$signupendText = ( $usingHTTPS && !$signupendHttpsMsg->isBlank() )
					? $signupendHttpsMsg->parse() : $signupendMsg->parse();
				$fieldDefinitions['signupend'] = [
					'type' => 'info',
					'raw' => true,
					'default' => Html::rawElement( 'div', [ 'id' => 'signupend' ], $signupendText ),
					'weight' => 225,
				];
			}
		}
		if ( !$this->isSignup() && $this->showExtraInformation() ) {
			$passwordReset = MediaWikiServices::getInstance()->getPasswordReset();
			if ( $passwordReset->isAllowed( $this->getUser() )->isGood() ) {
				$fieldDefinitions['passwordReset'] = [
					'type' => 'info',
					'raw' => true,
					'cssclass' => 'mw-form-related-link-container',
					'default' => $this->getLinkRenderer()->makeLink(
						SpecialPage::getTitleFor( 'PasswordReset' ),
						$this->msg( 'userlogin-resetpassword-link' )->text()
					),
					'weight' => 230,
				];
			}

			// Don't show a "create account" link if the user can't.
			if ( $this->showCreateAccountLink() ) {
				// link to the other action
				$linkTitle = $this->getTitleFor( $this->isSignup() ? 'Userlogin' : 'CreateAccount' );
				$linkq = $this->getReturnToQueryStringFragment();
				// Pass any language selection on to the mode switch link
				if ( $this->mLanguage ) {
					$linkq .= '&uselang=' . urlencode( $this->mLanguage );
				}
				$isLoggedIn = $this->getUser()->isRegistered()
					&& !$this->getUser()->isTemp();

				$fieldDefinitions['createOrLogin'] = [
					'type' => 'info',
					'raw' => true,
					'linkQuery' => $linkq,
					'default' => function ( $params ) use ( $isLoggedIn, $linkTitle ) {
						return Html::rawElement( 'div',
							[ 'id' => 'mw-createaccount' . ( !$isLoggedIn ? '-cta' : '' ),
								'class' => ( $isLoggedIn ? 'mw-form-related-link-container' : 'mw-ui-vform-field' ) ],
							( $isLoggedIn ? '' : $this->msg( 'userlogin-noaccount' )->escaped() )
							. Html::element( 'a',
								[
									'id' => 'mw-createaccount-join' . ( $isLoggedIn ? '-loggedin' : '' ),
									'href' => $linkTitle->getLocalURL( $params['linkQuery'] ),
									'class' => ( $isLoggedIn ? '' : 'mw-ui-button' ),
									'tabindex' => 100,
								],
								$this->msg(
									$isLoggedIn ? 'userlogin-createanother' : 'userlogin-joinproject'
								)->text()
							)
						);
					},
					'weight' => 235,
				];
			}
		}

		return $fieldDefinitions;
	}

	/**
	 * Check if a session cookie is present.
	 *
	 * This will not pick up a cookie set during _this_ request, but is meant
	 * to ensure that the client is returning the cookie which was set on a
	 * previous pass through the system.
	 *
	 * @return bool
	 */
	protected function hasSessionCookie() {
		$config = $this->getConfig();
		return $config->get( MainConfigNames::DisableCookieCheck ) || (
			$config->get( 'InitialSessionId' ) &&
			$this->getRequest()->getSession()->getId() === (string)$config->get( 'InitialSessionId' )
		);
	}

	/**
	 * Returns a string that can be appended to the URL (without encoding) to preserve the
	 * return target. Does not include leading '?'/'&'.
	 * @return string
	 */
	protected function getReturnToQueryStringFragment() {
		$returnto = '';
		if ( $this->mReturnTo !== '' ) {
			$returnto = 'returnto=' . wfUrlencode( $this->mReturnTo );
			if ( $this->mReturnToQuery !== '' ) {
				$returnto .= '&returntoquery=' . wfUrlencode( $this->mReturnToQuery );
			}
		}
		return $returnto;
	}

	/**
	 * Whether the login/create account form should display a link to the
	 * other form (in addition to whatever the skin provides).
	 * @return bool
	 */
	private function showCreateAccountLink() {
		return $this->isSignup() ||
			$this->getContext()->getAuthority()->isAllowed( 'createaccount' );
	}

	protected function getTokenName() {
		return $this->isSignup() ? 'wpCreateaccountToken' : 'wpLoginToken';
	}

	/**
	 * Produce a bar of links which allow the user to select another language
	 * during login/registration but retain "returnto"
	 *
	 * @return string
	 */
	protected function makeLanguageSelector() {
		$msg = $this->msg( 'loginlanguagelinks' )->inContentLanguage();
		if ( $msg->isBlank() ) {
			return '';
		}
		$langs = explode( "\n", $msg->text() );
		$links = [];
		foreach ( $langs as $lang ) {
			$lang = trim( $lang, '* ' );
			$parts = explode( '|', $lang );
			if ( count( $parts ) >= 2 ) {
				$links[] = $this->makeLanguageSelectorLink( $parts[0], trim( $parts[1] ) );
			}
		}

		return count( $links ) > 0 ? $this->msg( 'loginlanguagelabel' )->rawParams(
			$this->getLanguage()->pipeList( $links ) )->escaped() : '';
	}

	/**
	 * Create a language selector link for a particular language
	 * Links back to this page preserving type and returnto
	 *
	 * @param string $text Link text
	 * @param string $lang Language code
	 * @return string
	 */
	protected function makeLanguageSelectorLink( $text, $lang ) {
		$services = MediaWikiServices::getInstance();

		if ( $this->getLanguage()->getCode() == $lang
			|| !$services->getLanguageNameUtils()->isValidCode( $lang )
		) {
			// no link for currently used language
			// or invalid language code
			return htmlspecialchars( $text );
		}
		$query = [ 'uselang' => $lang ];
		if ( $this->mReturnTo !== '' ) {
			$query['returnto'] = $this->mReturnTo;
			$query['returntoquery'] = $this->mReturnToQuery;
		}

		$attr = [];
		$targetLanguage = $services->getLanguageFactory()->getLanguage( $lang );
		$attr['lang'] = $attr['hreflang'] = $targetLanguage->getHtmlCode();

		return $this->getLinkRenderer()->makeKnownLink(
			$this->getPageTitle(),
			$text,
			$attr,
			$query
		);
	}

	protected function getGroupName() {
		return 'login';
	}

	/**
	 * @param array &$formDescriptor
	 * @param array $requests
	 */
	protected function postProcessFormDescriptor( &$formDescriptor, $requests ) {
		// Pre-fill username (if not creating an account, T46775).
		if (
			isset( $formDescriptor['username'] ) &&
			!isset( $formDescriptor['username']['default'] ) &&
			!$this->isSignup()
		) {
			$user = $this->getUser();
			if ( $user->isRegistered() && !$user->isTemp() ) {
				$formDescriptor['username']['default'] = $user->getName();
			} else {
				$formDescriptor['username']['default'] =
					$this->getRequest()->getSession()->suggestLoginUsername();
			}
		}

		// don't show a submit button if there is nothing to submit (i.e. the only form content
		// is other submit buttons, for redirect flows)
		if ( !$this->needsSubmitButton( $requests ) ) {
			unset( $formDescriptor['createaccount'], $formDescriptor['loginattempt'] );
		}

		if ( !$this->isSignup() ) {
			// FIXME HACK don't focus on non-empty field
			// maybe there should be an autofocus-if similar to hide-if?
			if (
				isset( $formDescriptor['username'] )
				&& empty( $formDescriptor['username']['default'] )
				&& !$this->getRequest()->getCheck( 'wpName' )
			) {
				$formDescriptor['username']['autofocus'] = true;
			} elseif ( isset( $formDescriptor['password'] ) ) {
				$formDescriptor['password']['autofocus'] = true;
			}
		}

		$this->addTabIndex( $formDescriptor );
	}
}
