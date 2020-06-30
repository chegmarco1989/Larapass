<?php

namespace DarkGhostHunter\Larapass\WebAuthn;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Webauthn\PublicKeyCredentialLoader;
use Psr\Http\Message\ServerRequestInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredentialRpEntity as RelyingParty;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;

class WebAuthnAssertValidator
{
    /**
     * Application cache.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Application as the Relying Party.
     *
     * @var \Webauthn\PublicKeyCredentialRpEntity
     */
    protected $relyingParty;

    /**
     * Custom extensions the user can accept from the client itself.
     *
     * @var \Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs
     */
    protected $extensions;

    /**
     * Validator for the Attestation response.
     *
     * @var \Webauthn\AuthenticatorAssertionResponseValidator
     */
    protected $validator;

    /**
     * Loader for the raw credentials.
     *
     * @var \Webauthn\PublicKeyCredentialLoader
     */
    protected $loader;

    /**
     * Server Request
     *
     * @var \Psr\Http\Message\ServerRequestInterface
     */
    protected $request;

    /**
     * HTTP Request fingerprint for the device.
     *
     * @var \Illuminate\Http\Request
     */
    protected $laravelRequest;

    /**
     * Challenge time-to-live, in milliseconds.
     *
     * @var int
     */
    protected $timeout;

    /**
     * Number of bytes to create for a random challenge.
     *
     * @var mixed
     */
    protected $bytes;

    /**
     * If the login should require explicit User verification.
     *
     * @var bool
     */
    protected $verifyLogin;

    /**
     * WebAuthnAttestation constructor.
     *
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @param  \Illuminate\Contracts\Cache\Factory  $cache
     * @param  \Webauthn\PublicKeyCredentialRpEntity  $relyingParty
     * @param  \Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs  $extensions
     * @param  \Webauthn\AuthenticatorAssertionResponseValidator  $validator
     * @param  \Webauthn\PublicKeyCredentialLoader  $loader
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     * @param  \Illuminate\Http\Request  $laravelRequest
     */
    public function __construct(ConfigContract $config,
                                CacheFactoryContract $cache,
                                RelyingParty $relyingParty,
                                AuthenticationExtensionsClientInputs $extensions,
                                AuthenticatorAssertionResponseValidator $validator,
                                PublicKeyCredentialLoader $loader,
                                ServerRequestInterface $request,
                                Request $laravelRequest)
    {
        $this->cache = $cache->store($config->get('larapass.cache'));
        $this->relyingParty = $relyingParty;
        $this->extensions = $extensions;
        $this->validator = $validator;
        $this->loader = $loader;
        $this->request = $request;

        $this->laravelRequest = $laravelRequest;
        $this->timeout = $config->get('larapass.timeout') * 1000;
        $this->bytes = $config->get('larapass.bytes');

        $this->verifyLogin = $config->get('larapass.login_verify')
            || in_array($config->get('larapass.userless'), ['required', 'preferred']);
    }

    /**
     * Retrieves a previously created assertion for a given request.
     *
     * @return \Webauthn\PublicKeyCredentialRequestOptions|null
     */
    public function retrieveAssertion()
    {
        return $this->cache->get($this->cacheKey());
    }

    /**
     * Returns a challenge for the given request fingerprint.
     *
     * @param  \DarkGhostHunter\Larapass\Contracts\WebAuthnAuthenticatable|null  $user
     * @return \Webauthn\PublicKeyCredentialRequestOptions
     */
    public function generateAssertion($user = null)
    {
        $assertion = $this->makeAssertionRequest($user);

        $this->cache->put($this->cacheKey(), $assertion, $this->timeout);

        return $assertion;
    }

    /**
     * Creates a new Assertion Request for the request, and user if issued.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|\DarkGhostHunter\Larapass\Contracts\WebAuthnAuthenticatable|null  $user
     * @return \Webauthn\PublicKeyCredentialRequestOptions
     */
    protected function makeAssertionRequest($user = null)
    {
        return new PublicKeyCredentialRequestOptions(
            random_bytes($this->bytes),
            $this->timeout,
            $this->relyingParty->getId(),
            $user ? $user->allCredentialDescriptors() : [],
            $this->verifyLogin,
            $this->extensions
        );
    }

    /**
     * Return the cache key for the given unique request.
     *
     * @return string
     */
    protected function cacheKey()
    {
        return 'larapass.assertation|' .
            sha1($this->laravelRequest->getHttpHost() . '|' . $this->laravelRequest->ip());
    }

    /**
     * Verifies if the assertion is correct.
     *
     * @param  array  $data
     * @return bool|\Webauthn\PublicKeyCredentialSource
     */
    public function validate(array $data)
    {
        try {
            if (! $assertion = $this->retrieveAssertion()) {
                return false;
            }

            $credentials = $this->loader->loadArray($data);
            $response = $credentials->getResponse();

            if (! $response instanceof AuthenticatorAssertionResponse) {
                return false;
            }

            return $this->validator->check(
                $credentials->getId(),
                $response,
                $this->retrieveAssertion(),
                $this->request,
                $response->getUserHandle()
            );
        }
        catch (InvalidArgumentException $exception) {
            return false;
        }
        finally {
            $this->cache->forget($this->cacheKey());
        }
    }
}