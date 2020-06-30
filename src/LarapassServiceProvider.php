<?php

namespace DarkGhostHunter\Larapass;

use Psr\Log\LoggerInterface;
use Illuminate\Support\ServiceProvider;
use Webauthn\PublicKeyCredentialLoader;
use Illuminate\Contracts\Hashing\Hasher;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\AuthenticatorSelectionCriteria;
use Illuminate\Contracts\Auth\Authenticatable;
use Webauthn\TokenBinding\TokenBindingHandler;
use Webauthn\PublicKeyCredentialSourceRepository;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Webauthn\TokenBinding\IgnoreTokenBindingHandler;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\MetadataService\MetadataStatementRepository;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use DarkGhostHunter\Larapass\Auth\EloquentWebAuthnProvider;
use DarkGhostHunter\Larapass\WebAuthn\WebAuthnAttestCreator;
use DarkGhostHunter\Larapass\WebAuthn\WebAuthnAssertValidator;
use DarkGhostHunter\Larapass\WebAuthn\WebAuthnAttestValidator;
use DarkGhostHunter\Larapass\Contracts\WebAuthnAuthenticatable;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use DarkGhostHunter\Larapass\WebAuthn\PublicKeyCredentialParametersCollection;
use DarkGhostHunter\Larapass\Eloquent\WebAuthnCredential as WebAuthnAuthenticationModel;

class LarapassServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larapass.php', 'larapass');

        $this->app->alias(Authenticatable::class, WebAuthnAuthenticatable::class);

        $this->bindWebAuthnBasePackage();

        $this->app->bind(WebAuthnAttestCreator::class);
        $this->app->bind(WebAuthnAttestValidator::class);
        $this->app->bind(WebAuthnAssertValidator::class);
    }

    /**
     * Bind all the WebAuthn package services to the Service Container.
     *
     * @return void
     */
    protected function bindWebAuthnBasePackage()
    {

        // And from here the shit hits the fan. But it's needed to make the package modular,
        // testable and catchable by the developer when he needs to override anything.
        $this->app->singleton(AttestationStatementSupportManager::class, static function () {
            return tap(new AttestationStatementSupportManager)->add(new NoneAttestationStatementSupport());
        });

        $this->app->singleton(MetadataStatementRepository::class, static function () {
            return null;
        });

        $this->app->singleton(AttestationObjectLoader::class, static function ($app) {
            return new AttestationObjectLoader(
                $app[AttestationStatementSupportManager::class],
                $app[MetadataStatementRepository::class],
                $app[LoggerInterface::class]
            );
        });

        $this->app->singleton(PublicKeyCredentialLoader::class, static function ($app) {
            return new PublicKeyCredentialLoader(
                $app[AttestationObjectLoader::class],
                $app['log']
            );
        });

        $this->app->bind(PublicKeyCredentialSourceRepository::class, static function () {
            return new WebAuthnAuthenticationModel;
        });
        $this->app->alias(PublicKeyCredentialSourceRepository::class, 'webauthn.repository');

        $this->app->bind(TokenBindingHandler::class, static function () {
            return new IgnoreTokenBindingHandler;
        });

        $this->app->bind(ExtensionOutputCheckerHandler::class, static function () {
            return new ExtensionOutputCheckerHandler;
        });

        $this->app->bind(CoseAlgorithmManager::class, static function () {
            return new CoseAlgorithmManager;
        });

        $this->app->bind(AuthenticatorAttestationResponseValidator::class, static function ($app) {
            return new AuthenticatorAttestationResponseValidator(
                $app[AttestationStatementSupportManager::class],
                $app[PublicKeyCredentialSourceRepository::class],
                $app[TokenBindingHandler::class],
                $app[ExtensionOutputCheckerHandler::class],
                null,
                $app['log']
            );
        });

        $this->app->bind(AuthenticatorAssertionResponseValidator::class, static function ($app) {
            return new AuthenticatorAssertionResponseValidator(
                $app[PublicKeyCredentialSourceRepository::class],
                $app[TokenBindingHandler::class],
                $app[ExtensionOutputCheckerHandler::class],
                $app[CoseAlgorithmManager::class],
                null,
                $app['log']
            );
        });

        $this->app->bind(PublicKeyCredentialRpEntity::class, static function ($app) {
            return new PublicKeyCredentialRpEntity(
                ...array_values($app['config']->get('larapass.relaying_party'))
            );
        });

        $this->app->bind(AuthenticatorSelectionCriteria::class, static function ($app) {
            $selection = new WebAuthn\AuthenticatorSelectionCriteria(
                $app['config']->get('larapass.cross-plataform')
            );

            if ($userless = $app['config']->get('larapass.userless')) {
                $selection->setResidentKey($userless);
            }

            return $selection;
        });

        $this->app->bind(PublicKeyCredentialParametersCollection::class, static function ($app) {
            return PublicKeyCredentialParametersCollection::make($app['config']['larapass.algorithms'])
                ->map(static function ($algorithm) {
                    return new PublicKeyCredentialParameters('public-key', $algorithm);
                });
        });

        $this->app->bind(AuthenticationExtensionsClientInputs::class, static function () {
            return new AuthenticationExtensionsClientInputs;
        });
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['auth']->provider('eloquent-webauthn', static function ($app, $config) {
            return new EloquentWebAuthnProvider(
                $app['config'],
                $app[WebAuthnAssertValidator::class],
                $app[Hasher::class],
                $config['model']
            );
        });

        if ($this->app->runningInConsole()) {
            $this->publishFiles();
        }
    }

    /**
     * Publish config, view and migrations files.
     *
     * @return void
     */
    protected function publishFiles()
    {
        $this->publishes([
            __DIR__ . '/../config/larapass.php' => config_path('larapass.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../stubs' => app_path('Http/Controllers/Auth'),
        ], 'controllers');

        if (! class_exists('CreateWebAuthnCredentialsTable')) {
            $this->publishes([
                __DIR__ .
                '/../database/migrations/2020_04_02_000000_create_web_authn_credentials_table.php' => database_path('migrations/' .
                    now()->format('Y_m_d_His') .
                    '_create_web_authn_credentials_table.php'),
            ], 'migrations');
        }
    }
}