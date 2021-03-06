<?php
/**
 * @section LICENSE
 * This file is part of Wikimedia Grants Review application.
 *
 * Wikimedia Grants Review application is free software: you can
 * redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * Wikimedia Grants Review application is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Wikimedia Grants Review application.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 * @file
 * @copyright © 2014 Bryan Davis, Wikimedia Foundation and contributors.
 */

namespace Wikimedia\IEGReview\Controllers\Admin;

use Wikimedia\IEGReview\Controller;
use Wikimedia\IEGReview\Password;

/**
 * View/edit a user.
 *
 * @author Bryan Davis <bd808@wikimedia.org>
 * @copyright © 2014 Bryan Davis, Wikimedia Foundation and contributors.
 */
class User extends Controller {

	protected $campaignsDao;

	public function setCampaignsDao( $dao ) {
		$this->campaignsDao = $dao;
	}

	protected function handleGet( $id ) {
		if ( $id === 'new' ) {
			$user = array(
				'username' => '',
				'password' => Password::randomPassword( 12 ),
				'email' => '',
				'reviewer' => 0,
				'isvalid' => 1,
				'isadmin' => 0,
				'viewreports' => 0,
				'blocked' => 0,
			);

		} else {
			$user = $this->dao->getUserInfo( $id );
		}

		$this->view->set( 'id', $id );
		$this->view->set( 'u', $user );
		$this->view->set( 'listcampaigns', $this->campaignsDao->getUserCampaigns() );
		$this->render( 'admin/user.html' );
	}

	protected function handlePost() {
		$id = $this->request->post( 'id' );

		$this->form->requireString( 'username' );
		$this->form->expectString( 'password',
			array( 'required' => ( $id == 'new' ) )
		);
		$this->form->requireEmail( 'email' );
		$this->form->expectBool( 'reviewer' );
		$this->form->expectBool( 'isvalid' );
		$this->form->expectBool( 'isadmin' );
		$this->form->expectBool( 'viewreports' );
		$this->form->expectBool( 'blocked' );

		if ( $this->form->validate() ) {
			$user = array(
				'username' => $this->form->get( 'username' ),
				'email' => $this->form->get( 'email' ),
				'reviewer' => $this->form->get( 'reviewer' ),
				'isvalid' => $this->form->get( 'isvalid' ),
				'isadmin' => $this->form->get( 'isadmin' ),
				'viewreports' => $this->form->get( 'viewreports' ),
				'blocked' => $this->form->get( 'blocked' ),
			);

			if ( $id == 'new' ) {
				$user['password'] = Password::encodePassword(
					$this->form->get( 'password' )
				);
				$newId = $this->dao->newUserCreate( $user );
				if ( $newId !== false ) {
					$this->flash( 'info', "User {$newId} created." );
					$id = $newId;

					$sent = $this->mailer->mail(
						$user['email'],
						$this->i18nContext->message( 'new-account-subject' ),
						$this->i18nContext->message( 'new-account-email', array(
							$user['username'],
							$this->form->get( 'password' ),
							$this->request->getUrl() . $this->urlFor( 'login' ),
							$this->request->getUrl() . $this->urlFor( 'user_changepassword' ),
						) )
					);
					if ( !$sent ) {
						$this->flash(
							'error',
							'Failed to send account creation message. Check logs.'
						);
					}

				} else {
					$this->flash( 'error', 'User creation failed. Check logs.' );
				}

			} else {
				if ( $this->dao->updateUserInfo( $user, $id ) ) {
					$this->flash( 'info', 'Changes saved.' );
				} else {
					$this->flash( 'error', 'Save failed. Check logs.' );
				}
			}

		} else {
			$this->flash( 'error', 'Invalid submission.' );
			$this->flash( 'form_errors', $this->form->getErrors() );
			$user = array(
				'username' => $this->form->get( 'username' ),
				'email' => $this->form->get( 'email' ),
				'password' => $this->form->get( 'password' ),
				'reviewer' => $this->form->get( 'reviewer' ),
				'isvalid' => $this->form->get( 'isvalid' ),
				'isadmin' => $this->form->get( 'isadmin' ),
				'viewreports' => $this->form->get( 'viewreports' ),
				'blocked' => $this->form->get( 'blocked' ),
			);
			$this->flash( 'form_defaults', $user );
		}

		$this->redirect( $this->urlFor( 'admin_user', array( 'id' => $id ) ) );
	}

}
