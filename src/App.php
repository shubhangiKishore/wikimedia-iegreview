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

namespace Wikimedia\IEGReview;

/**
 * Grants review application.
 *
 * @author Bryan Davis <bd808@wikimedia.org>
 * @copyright © 2014 Bryan Davis, Wikimedia Foundation and contributors.
 */
class App {

	/**
	 * @var string $deployDir
	 */
	protected $deployDir;

	/**
	 * @var \Slim\Slim $slim
	 */
	protected $slim;

	/**
	 * @param string $deployDir Full path to code deployment
	 */
	public function __construct( $deployDir ) {
		$this->deployDir = $deployDir;

		// Common configuration
		$this->slim = new \Slim\Slim( array(
			'mode' => 'production',
			'debug' => false,
			'log.level' => Config::getStr( 'LOG_LEVEL',
				\Psr\Log\LogLevel::NOTICE
			),
			'log.file' => Config::getStr( 'LOG_FILE', 'php://stderr' ),
			'view' => new \Slim\Views\Twig(),
			'view.cache' => Config::getStr( 'CACHE_DIR',
				"{$this->deployDir}/data/cache"
			),
			'smtp.host' => Config::getStr( 'SMTP_HOST', 'localhost' ),
			'templates.path' => "{$this->deployDir}/data/templates",
			'i18n.path' => "{$this->deployDir}/data/i18n",
			'i18n.default' => 'en',
			'db.dsn' => Config::getStr( 'DB_DSN' ),
			'db.user' => Config::getStr( 'DB_USER' ),
			'db.pass' => Config::getStr( 'DB_PASS' ),
			'parsoid.url' => Config::getStr( 'PARSOID_URL',
				'https://en.wikipedia.org/api/rest_v1/transform/wikitext/to/html'
			),
			'parsoid.cache' => Config::getStr( 'CACHE_DIR',
				"{$this->deployDir}/data/cache"
			),
		) );

		$slim = $this->slim;

		// Production configuration that should not be shared with development
		// Enabled by default or SLIM_MODE=production in environment
		$this->slim->configureMode( 'production', function () use ( $slim ) {
			// Install a custom error handler
			$slim->error( function ( \Exception $e ) use ( $slim ) {
				$errorId = substr( session_id(), 0, 8 ) . '-' . substr( uniqid(), -8 );
				$slim->log->critical( $e->getMessage(), array(
					'exception' => $e,
					'ua' => $slim->request->getUserAgent(),
					'errorId' => $errorId,
				) );
				$slim->view->set( 'errorId', $errorId );
				$slim->render( 'error.html' );
			} );
		} );

		// Development configuration
		// Enable by setting SLIM_MODE=development in environment
		$this->slim->configureMode( 'development', function () use ( $slim ) {
			$slim->config( array(
				'debug' => true,
				'log.level' => Config::getStr( 'LOG_LEVEL', \Psr\Log\LogLevel::DEBUG ),
				'view.cache' => false,
			) );
		} );

		// Slim does not natively understand being behind a proxy
		// If not corrected template links created via siteUrl() may use the wrong
		// Protocol (http instead of https).
		if ( getenv( 'HTTP_X_FORWARDED_PROTO' ) ) {
			$proto = getenv( 'HTTP_X_FORWARDED_PROTO' );
			$this->slim->environment['slim.url_scheme'] = $proto;

			$port = getenv( 'HTTP_X_FORWARDED_PORT' );
			if ( $port === false ) {
				$port = ( $proto == 'https' ) ? '443' : '80';
			}
			$this->slim->environment['SERVER_PORT'] = $port;
		}

		$this->configureIoc();
		$this->configureView();
		$this->configureRoutes();
	}

	/**
	 * Main entry point for all requests.
	 */
	public function run() {
		session_name( '_s' );
		session_cache_limiter( false );
		ini_set( 'session.cookie_httponly', true );
		session_start();
		register_shutdown_function( 'session_write_close' );
		$this->slim->run();
	}

	/**
	 * Configure inversion of control/dependency injection container.
	 */
	protected function configureIoc() {
		$container = $this->slim->container;

		$container->singleton( 'usersDao', function ( $c ) {
			return new \Wikimedia\IEGReview\Dao\Users(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$c->log );
		} );

		$container->singleton( 'settingsDao', function ( $c ) {
			return new \Wikimedia\IEGReview\Dao\Settings(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$c->log
			);
		} );

		$container->singleton( 'proposalsDao', function ( $c ) {
			$uid = $c->authManager->getUserId();
			return new \Wikimedia\IEGReview\Dao\Proposals(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$uid, $c->log
			);
		} );

		$container->singleton( 'reviewsDao', function ( $c ) {
			$uid = $c->authManager->getUserId();
			return new \Wikimedia\IEGReview\Dao\Reviews(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$uid, $c->log
			);
		} );

		$container->singleton( 'reportsDao', function ( $c ) {
			$uid = $c->authManager->getUserId();
			return new \Wikimedia\IEGReview\Dao\Reports(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$uid, $c->log
			);
		} );

		$container->singleton( 'campaignsDao', function ( $c ) {
			$uid = $c->authManager->getUserId();
			return new \Wikimedia\IEGReview\Dao\Campaigns(
				$c->settings['db.dsn'],
				$c->settings['db.user'], $c->settings['db.pass'],
				$uid, $c->log
			);
		} );

		$container->singleton( 'authManager', function ( $c ) {
			return new \Wikimedia\IEGReview\AuthManager( $c->usersDao );
		} );

		$container->singleton( 'i18nCache', function ( $c ) {
			return new \Wikimedia\SimpleI18n\JsonCache(
				$c->settings['i18n.path'], $c->log
			);
		} );

		$container->singleton( 'i18nContext', function ( $c ) {
			return new \Wikimedia\SimpleI18n\I18nContext(
				$c->i18nCache, $c->settings['i18n.default'], $c->log
			);
		} );

		$container->singleton( 'mailer',  function ( $c ) {
			return new \Wikimedia\IEGReview\Mailer(
				array(
					'Host' => $c->settings['smtp.host'],
				),
				$c->log
			);
		} );

		$container->singleton( 'parsoid', function ( $c ) {
			return new \Wikimedia\IEGReview\ParsoidClient(
				$c->settings['parsoid.url'],
				$c->settings['parsoid.cache'],
				$c->log
			);
		} );

		// Replace default logger with monolog
		$container->singleton( 'log', function ( $c ) {
			// Convert string level to Monolog integer value
			$level = strtoupper( $c->settings['log.level'] );
			$level = constant( "\Monolog\Logger::{$level}" );

			$log = new \Monolog\Logger( 'iegreview' );
			$handler = new \Monolog\Handler\Udp2logHandler(
				$c->settings['log.file'],
				$level
			);
			$handler->setFormatter( new \Monolog\Formatter\LogstashFormatter(
				'iegreview', null, null, '',
				\Monolog\Formatter\LogstashFormatter::V1
			) );
			$handler->pushProcessor( new \Monolog\Processor\PsrLogMessageProcessor() );
			$handler->pushProcessor( new \Monolog\Processor\ProcessIdProcessor() );
			$handler->pushProcessor( new \Monolog\Processor\UidProcessor() );
			$handler->pushProcessor( new \Monolog\Processor\WebProcessor() );
			$log->pushHandler( $handler );
			return $log;
		} );
	}

	/**
	 * Configure view behavior.
	 */
	protected function configureView() {
		// Configure twig views
		$view = $this->slim->view;

		$view->parserOptions = array(
			'charset' => 'utf-8',
			'cache' => $this->slim->config( 'view.cache' ),
			'debug' => $this->slim->config( 'debug' ),
			'auto_reload' => true,
			'strict_variables' => false,
			'autoescape' => true,
		);

		// Install twig parser extensions
		$view->parserExtensions = array(
			new \Slim\Views\TwigExtension(),
			new TwigExtension( $this->slim->parsoid ),
			new \Wikimedia\SimpleI18n\TwigExtension( $this->slim->i18nContext ),
			new \Twig_Extension_StringLoader(),
		);

		// Set default view data
		$view->replace( array(
			'app' => $this->slim,
			'i18nCtx' => $this->slim->i18nContext,
		) );
	}

	/**
	 * Configure routes to be handled by application.
	 */
	protected function configureRoutes() {
		$slim = $this->slim;

		// Add a Vary: Cookie header to all responses
		$headerMiddleware = new HeaderMiddleware( array(
			'Vary' => 'Cookie',
			'X-Frame-Options' => 'DENY',
			'Content-Security-Policy' =>
				"default-src 'self'; " .
				"frame-src 'none'; " .
				"object-src 'none'; " .
				// Needed for css data:... sprites
				"img-src 'self' data:; " .
				// Needed for jQuery and Modernizr feature detection
				"style-src 'self' 'unsafe-inline'",
			// Don't forget to override this for any content that is not
			// actually HTML (e.g. json)
			'Content-Type' => 'text/html; charset=UTF-8',
		) );
		$slim->add( $headerMiddleware );
		// Add CSRF protection
		$slim->add( new CsrfMiddleware() );

		$middleware = array(
			'must-revalidate' => function () use ( $slim ) {
				// We want clients to cache if they can, but force them to
				// check for updates on subsequent hits
				$slim->response->headers->set(
					'Cache-Control', 'private, must-revalidate, max-age=0'
				);
				$slim->response->headers->set(
					'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT'
				);
			},

			'inject-user' => function () use ( $slim ) {
				$user = $slim->authManager->getUser();
				$slim->view->set( 'user', $user );
				$slim->view->set( 'isadmin', $slim->authManager->isAdmin() );
				$slim->view->set( 'isreviewer',
					$slim->authManager->isReviewer()
				);
				$slim->view->set( 'viewreports',
					$slim->authManager->canViewReports()
				);
			},

			'require-user' => function () use ( $slim ) {
				if ( $slim->authManager->isAnonymous() ) {
					// Redirect to login form if not authenticated
					if ( $slim->request->isGet() ) {
						$uri = $slim->request->getUrl() . $slim->request->getPath();
						$qs = \Wikimedia\IEGReview\Form::qsMerge();
						if ( $qs ) {
							$uri = "{$uri}?{$qs}";
						}
						$_SESSION[AuthManager::NEXTPAGE_SESSION_KEY] = $uri;
					}
					$slim->flash( 'error', 'Login required' );
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'login' ) );
				}
			},

			'require-admin' => function () use ( $slim ) {
				if ( !$slim->authManager->isAdmin() ) {
					// Redirect to login form if not an admin user
					if ( $slim->request->isGet() ) {
						$uri = $slim->request->getUrl() . $slim->request->getPath();
						$qs = \Wikimedia\IEGReview\Form::qsMerge();
						if ( $qs ) {
							$uri = "{$uri}?{$qs}";
						}
						$_SESSION[AuthManager::NEXTPAGE_SESSION_KEY] = $uri;
					}
					$slim->flash( 'error', 'Admin rights required' );
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'login' ) );
				}
			},

			'require-viewreports' => function () use ( $slim ) {
				if ( !$slim->authManager->canViewReports() ) {
					// Redirect to login form if not a report viewer
					if ( $slim->request->isGet() ) {
						$uri = $slim->request->getUrl() . $slim->request->getPath();
						$qs = \Wikimedia\IEGReview\Form::qsMerge();
						if ( $qs ) {
							$uri = "{$uri}?{$qs}";
						}
						$_SESSION[AuthManager::NEXTPAGE_SESSION_KEY] = $uri;
					}
					$slim->flash( 'error', 'Report view rights required' );
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'login' ) );
				}
			},

			'require-viewcampaign' => function ( $route ) use ( $slim ) {
				$user = $slim->authManager->getUserId();
				$campaign = $route->getParam( 'campaign' );
				$campaigninfo = $slim->campaignsDao->getCampaign( $campaign );
				$name = $campaigninfo['name'];
				$slim->view->set( 'campaignname', $name );
				if ( $slim->campaignsDao->isReviewer( $campaign, $user ) === false ) {
					// Redirect to home page
					$slim->flash( 'error', 'You cannot access this campaign' );
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'campaigns' ) );
				}
			}
		);

		// "Root" routes for non-autenticated users
		$slim->group( '/',
			$middleware['inject-user'],
			function () use ( $slim, $middleware ) {
				App::redirect( $slim, '', 'campaigns', 'home' );
				App::redirect( $slim, 'index', 'campaigns' );

				$slim->get( 'campaigns', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$page = new Controllers\Campaigns( $slim );
						$page->setDao( $slim->campaignsDao );
						$page();
					}
				)->name( 'campaigns' );

				App::template( $slim, 'credits' );
				App::template( $slim, 'privacy' );

				$slim->get( 'login', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$page = new Controllers\Login( $slim );
						$page();
					}
				)->name( 'login' );

				$slim->post( 'login.post', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$page = new Controllers\Login( $slim );
						$page();
					}
				)->name( 'login_post' );

				$slim->get( 'logout', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$slim->authManager->logout();
						$slim->redirect( $slim->urlFor( 'home' ) );
					}
				)->name( 'logout' );
			}
		);

		// Account management helpers
		$slim->group( '/account/',
			$middleware['inject-user'],
			function () use ( $slim, $middleware ) {
				$slim->get( 'recover', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$page = new Controllers\Account\Recover( $slim );
						$page->setDao( $slim->usersDao );
						$page();
					}
				)->name( 'account_recover' );

				$slim->post( 'recover.post', $middleware['must-revalidate'],
					function () use ( $slim ) {
						$page = new Controllers\Account\Recover( $slim );
						$page->setDao( $slim->usersDao );
						$page->setMailer( $slim->mailer );
						$page();
					}
				)->name( 'account_recover_post' );

				$slim->get( 'reset/:token/:uid', $middleware['must-revalidate'],
					function ( $token, $uid ) use ( $slim ) {
						$page = new Controllers\Account\Reset( $slim );
						$page->setDao( $slim->usersDao );
						$page( $uid, $token );
					}
				)->name( 'account_reset' );

				$slim->post( 'reset.post/:token/:uid', $middleware['must-revalidate'],
					function ( $token, $uid ) use ( $slim ) {
						$page = new Controllers\Account\Reset( $slim );
						$page->setDao( $slim->usersDao );
						$page( $uid, $token );
					}
				)->name( 'account_reset_post' );
			}
		);

		// Routes for authenticated users
		$slim->group( '/user/',
			$middleware['must-revalidate'],
			$middleware['inject-user'],
			$middleware['require-user'],
			function () use ( $slim, $middleware ) {
				$slim->get( '', function () use ( $slim ) {
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'user_changepassword' ) );
				} )->name( 'user_home' );

				$slim->get( 'manageAccount', function () use ( $slim ) {
					$page = new Controllers\User\ManageAccount( $slim );
					$page->setDao( $slim->usersDao );
					$page();
				} )->name( 'user_manageaccount' );

				$slim->post( 'manageAccount', function () use ( $slim ) {
					$page = new Controllers\User\ManageAccount( $slim );
					$page->setDao( $slim->usersDao );
					$page();
				} )->name( 'user_manageaccount_post' );

				$slim->get( 'changePassword', function () use ( $slim ) {
					$page = new Controllers\User\ChangePassword( $slim );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page();
				} )->name( 'user_changepassword' );

				$slim->post( 'changePassword.post', function () use ( $slim ) {
					$page = new Controllers\User\ChangePassword( $slim );
					$page->setDao( $slim->usersDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page();
				} )->name( 'user_changepassword_post' );
			}
		);

		// Routes for proposals
		$slim->group( '/campaign/:campaign/proposals/',
			$middleware['must-revalidate'],
			$middleware['inject-user'],
			$middleware['require-user'],
			$middleware['require-viewcampaign'],
			function () use ( $slim, $middleware ) {
				$slim->get( '', function ( $campaign ) use ( $slim ) {
					$slim->flashKeep();
					$slim->redirect(
						$slim->urlFor( 'proposals_queue', array( 'campaign' => $campaign ) )
					);
				} )->name( 'proposals_home' );

				$slim->get( 'queue', function ( $campaign ) use ( $slim ) {
					$page = new Controllers\Proposals\Queue( $slim );
					$page->setDao( $slim->proposalsDao );
					$page( $campaign );
				} )->name( 'proposals_queue' );

				$slim->get( 'search', function ( $campaign ) use ( $slim ) {
					$page = new Controllers\Proposals\Search( $slim );
					$page->setDao( $slim->proposalsDao );
					$page( $campaign );
				} )->name( 'proposals_search' );

				$slim->get( ':id/edit', function ( $campaign, $id ) use ( $slim ) {
					$page = new Controllers\Proposals\Edit( $slim );
					$page->setDao( $slim->proposalsDao );
					$page( $campaign, $id );
				} )->name( 'proposals_edit' );

				$slim->post( ':id/edit/post', function ( $campaign, $id ) use ( $slim ) {
					$page = new Controllers\Proposals\Edit( $slim );
					$page->setDao( $slim->proposalsDao );
					$page( $campaign, $id );
				} )->name( 'proposals_edit_post' );

				$slim->post( ':id/review', function ( $campaign, $id ) use ( $slim ) {
					$page = new Controllers\Proposals\Review( $slim );
					$page->setDao( $slim->reviewsDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page( $campaign, $id );
				} )->name( 'proposals_review_post' );

				$slim->get( ':id', function ( $campaign, $id ) use ( $slim ) {
					$page = new Controllers\Proposals\View( $slim );
					$page->setDao( $slim->proposalsDao );
					$page->setReviewsDao( $slim->reviewsDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page( $campaign, $id );
				} )->name( 'proposals_view' );
			}
		);

		// Routes for reports
		$slim->group( '/campaign/:campaign/reports/',
			$middleware['must-revalidate'],
			$middleware['inject-user'],
			$middleware['require-user'],
			$middleware['require-viewreports'],
			$middleware['require-viewcampaign'],
			function () use ( $slim, $middleware ) {
				$slim->get( '', function ( $campaign ) use ( $slim ) {
					$slim->flashKeep();
					$slim->redirect( $slim->urlFor( 'reports_aggregated',
						array( 'campaign' => $campaign )
					) );
				} )->name( 'reports_home' );

				$slim->get( 'aggregated', function ( $campaign ) use ( $slim ) {
					$page = new Controllers\Reports\Aggregated( $slim );
					$page->setDao( $slim->reportsDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page( $campaign );
				} )->name( 'reports_aggregated' );

				$slim->get( 'wikitext', function ( $campaign ) use ( $slim ) {
					$page = new Controllers\Reports\Wikitext( $slim );
					$page->setDao( $slim->reportsDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page( $campaign );
				} )->name( 'reports_wikitext' );
			}
		);

		$slim->group( '/admin/',
			$middleware['must-revalidate'],
			$middleware['inject-user'],
			$middleware['require-user'],
			$middleware['require-admin'],
			function () use ( $slim ) {
				$slim->get( 'users', function () use ( $slim ) {
					$page = new Controllers\Admin\Users( $slim );
					$page->setDao( $slim->usersDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page();
				} )->name( 'admin_users' );

				$slim->get( 'user/:id', function ( $id ) use ( $slim ) {
					$page = new Controllers\Admin\User( $slim );
					$page->setDao( $slim->usersDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page( $id );
				} )->name( 'admin_user' );

				$slim->post( 'user.post', function () use ( $slim ) {
					$page = new Controllers\Admin\User( $slim );
					$page->setDao( $slim->usersDao );
					$page->setCampaignsDao( $slim->campaignsDao );
					$page->setMailer( $slim->mailer );
					$page();
				} )->name( 'admin_user_post' );

				$slim->get( 'campaigns', function () use ( $slim ) {
					$page = new Controllers\Admin\Campaigns( $slim );
					$page->setDao( $slim->campaignsDao );
					$page();
				} )->name( 'admin_campaigns' );

				$slim->get( 'campaign/:id', function ( $id ) use ( $slim ) {
					$page = new Controllers\Admin\Campaign( $slim );
					$page->setDao( $slim->campaignsDao );
					$page( $id );
				} )->name( 'admin_campaign' );

				$slim->get( 'campaign/:id/reviewers', function ( $id ) use ( $slim ) {
					$page = new Controllers\Admin\Campaign\Reviewers( $slim );
					$page->setDao( $slim->campaignsDao );
					$page( $id );
				} )->name( 'admin_campaign_reviewers' );

				$slim->get( 'campaign/:id/:user/proposals', function ( $id, $user ) use ( $slim ) {
					$page = new Controllers\Admin\Campaign\Proposals( $slim );
					$page->setDao( $slim->campaignsDao );
					$page( $id, $user );
				} )->name( 'admin_campaign_proposals' );

				$slim->post( 'campaign.post', function () use ( $slim ) {
					$page = new Controllers\Admin\Campaign( $slim );
					$page->setDao( $slim->campaignsDao );
					$page();
				} )->name( 'admin_campaign_post' );

				$slim->post( 'campaign/end/:id', function ( $id ) use ( $slim ) {
					$page = new Controllers\Admin\CampaignEnd( $slim );
					$page->setDao( $slim->campaignsDao );
					$page( $id );
				} )->name( 'admin_campaign_end' );
			}
		);

		$slim->notFound( function () use ( $slim, $middleware ) {
			$slim->render( '404.html' );
		} );
	}

	/**
	 * Add a redirect route to the app.
	 * @param \Slim\Slim $slim App
	 * @param string $name Page name
	 * @param string $to Redirect target route name
	 * @param string $routeName Name for the route
	 */
	public static function redirect( $slim, $name, $to, $routeName = null ) {
		$routeName = $routeName ?: $name;

		$slim->get( $name, function () use ( $slim, $name, $to ) {
			$slim->flashKeep();
			$slim->redirect( $slim->urlFor( $to ) );
		} )->name( $routeName );
	}

	/**
	 * Add a static template route to the app.
	 * @param \Slim\Slim $slim App
	 * @param string $name Page name
	 * @param string $routeName Name for the route
	 */
	public static function template( $slim, $name, $routeName = null ) {
		$routeName = $routeName ?: $name;

		$slim->get( $name, function () use ( $slim, $name ) {
			$slim->render( "{$name}.html" );
		} )->name( $routeName );
	}
}
