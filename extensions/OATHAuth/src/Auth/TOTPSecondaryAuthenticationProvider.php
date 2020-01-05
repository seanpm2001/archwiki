<?php
/**
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
 */

namespace MediaWiki\Extension\OATHAuth\Auth;

use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\OATHAuth\Module\TOTP;
use Message;
use User;

/**
 * AuthManager secondary authentication provider for TOTP second-factor authentication.
 *
 * After a successful primary authentication, requests a time-based one-time password
 * (typically generated by a mobile app such as Google Authenticator) from the user.
 *
 * @see AuthManager
 * @see https://en.wikipedia.org/wiki/Time-based_One-time_Password_Algorithm
 */
class TOTPSecondaryAuthenticationProvider extends AbstractSecondaryAuthenticationProvider {

	/**
	 * @param string $action
	 * @param array $options
	 *
	 * @return array
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		switch ( $action ) {
			case AuthManager::ACTION_LOGIN:
				// don't ask for anything initially so the second factor is on a separate screen
				return [];
			default:
				return [];
		}
	}

	/**
	 * If the user has enabled two-factor authentication, request a second factor.
	 *
	 * @param User $user
	 * @param array $reqs
	 *
	 * @return AuthenticationResponse
	 */
	public function beginSecondaryAuthentication( $user, array $reqs ) {
		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$authUser = $userRepo->findByUser( $user );

		if ( !( $authUser->getModule() instanceof TOTP ) ) {
			return AuthenticationResponse::newAbstain();
		} else {
			return AuthenticationResponse::newUI( [ new TOTPAuthenticationRequest() ],
				wfMessage( 'oathauth-auth-ui' ), 'warning' );
		}
	}

	/**
	 * Verify the second factor.
	 * @inheritDoc
	 */
	public function continueSecondaryAuthentication( $user, array $reqs ) {
		/** @var TOTPAuthenticationRequest $request */
		$request = AuthenticationRequest::getRequestByClass( $reqs, TOTPAuthenticationRequest::class );
		if ( !$request ) {
			return AuthenticationResponse::newUI( [ new TOTPAuthenticationRequest() ],
				wfMessage( 'oathauth-login-failed' ), 'error' );
		}

		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$authUser = $userRepo->findByUser( $user );
		$token = $request->OATHToken;

		if ( !( $authUser->getModule() instanceof TOTP ) ) {
			$this->logger->warning( 'Two-factor authentication was disabled mid-authentication for '
				. $user->getName() );
			return AuthenticationResponse::newAbstain();
		}

		// Don't increase pingLimiter, just check for limit exceeded.
		if ( $user->pingLimiter( 'badoath', 0 ) ) {
			return AuthenticationResponse::newUI(
				[ new TOTPAuthenticationRequest() ],
				new Message(
					'oathauth-throttled',
					// Arbitrary duration given here
					[ Message::durationParam( 60 ) ]
				), 'error' );
		}

		if ( $authUser->getModule()->verify( $authUser, [ 'token' => $token ] ) ) {
			return AuthenticationResponse::newPass();
		} else {
			return AuthenticationResponse::newUI( [ new TOTPAuthenticationRequest() ],
				wfMessage( 'oathauth-login-failed' ), 'error' );
		}
	}

	/**
	 * @param User $user
	 * @param User $creator
	 * @param array $reqs
	 *
	 * @return AuthenticationResponse
	 */
	public function beginSecondaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}
}