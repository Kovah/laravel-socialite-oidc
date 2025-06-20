<?php

namespace SocialiteProviders\OIDC;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use JsonException;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Generic OpenID Connect provider for Laravel Socialite
 *
 * @see https://docs.whmcs.com/OpenID_Connect_Developer_Guide
 */
class Provider extends AbstractProvider
{
    /**
     * Unique Provider Identifier.
     */
    public const IDENTIFIER = 'OIDC';

    public $configurations = null;

    /**
     * {@inheritdoc}
     */
    protected $scopes = [
        // required; to indicate that the application intends to use OIDC to verify the user's identity
        // Returns the sub claim, which uniquely identifies the user.
        // Also presents in an ID Token : iss, aud, exp, iat, c_hash.
        'openid',

        // Returns the email claim, which contains the user's email address
        // email, email_verified
        'email',

        // Returns claims that represent basic profile information
        // name, family_name, given_name, middle_name, nickname, picture, updated_at
        'profile',
    ];

    /**
     * {@inheritdoc}
     */
    protected $scopeSeparator = ' ';

    /**
     * Indicates if the nonce should be utilized.
     *
     * @var bool
     */
    protected bool $usesNonce = true;

    /**
     * {@inheritdoc}
     */
    public static function additionalConfigKeys()
    {
        return [
            'base_url',
            'scopes',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function redirect(): RedirectResponse
    {
        $state = null;

        if ($this->usesState()) {
            $this->request->session()->put('state', $state = $this->getState());
        }

        if ($this->usesNonce()) {
            $this->request->session()->put('nonce', $this->getNonce());
        }

        if ($this->usesPKCE()) {
            $this->request->session()->put('code_verifier', $this->getCodeVerifier());
        }

        return new RedirectResponse($this->getAuthUrl($state));
    }

    /**
     * {@inheritdoc}
     */
    public function getScopes(): array
    {
        if ($this->getConfig('scopes')) {
            return array_merge($this->scopes, explode(' ', $this->getConfig('scopes')));
        }

        return $this->scopes;
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return $this->getOpenIdConfig()['token_endpoint'];
    }

    /**
     * Get the user_info URL for the provider.
     *
     * @return string
     * @throws GuzzleException
     */
    protected function getUserInfoUrl()
    {
        return $this->getOpenIdConfig()['userinfo_endpoint'];
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(
            $this->getOpenIdConfig()['authorization_endpoint'],
            $state
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function buildAuthUrlFromBase($url, $state): string
    {
        return $url . '?' . http_build_query($this->getCodeFields($state), '', '&', $this->encodingType);
    }

    /**
     * {@inheritdoc}
     */
    protected function getCodeFields($state = null): array
    {
        $fields = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
        ];

        if ($this->usesState()) {
            $fields['state'] = $state;
        }

        if ($this->usesNonce()) {
            // Implicit flow nonce
            // https://auth0.com/docs/authorization/flows/mitigate-replay-attacks-when-using-the-implicit-flow
            $fields['nonce'] = $this->getCurrentNonce();
        }

        if ($this->usesPKCE()) {
            $fields['code_challenge'] = $this->getCodeChallenge();
            $fields['code_challenge_method'] = $this->getCodeChallengeMethod();
        }

        return array_merge($fields, $this->parameters);
    }

    /**
     * Determine if the provider is operating with nonce.
     *
     * @return bool
     */
    protected function usesNonce(): bool
    {
        return $this->usesNonce;
    }

    /**
     * Get the string used for nonce.
     *
     * @return string
     */
    protected function getNonce(): string
    {
        return Str::random(40);
    }

    /**
     * Get the current string used for nonce.
     *
     * @return string
     */
    protected function getCurrentNonce()
    {
        $nonce = null;

        if ($this->request->session()->has('nonce')) {
            $nonce = $this->request->session()->get('nonce');
        }

        return $nonce;
    }

    /**
     * @return array OpenID data for OIDC
     * @throws GuzzleException
     */
    protected function getOpenIdConfig()
    {
        if ($this->configurations === null) {
            try {
                $configUrl = rtrim($this->getConfig('base_url'), '/') . '/.well-known/openid-configuration';

                $response = $this->getHttpClient()->get($configUrl);

                $this->configurations = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (Exception $e) {
                throw new ConfigurationFetchingException('Unable to get the OIDC configuration from ' . $configUrl . ': ' . $e->getMessage());
            }
        }

        return $this->configurations;
    }

    /**
     * Receive data from auth/callback route
     * code, id_token, scope, state, session_state
     */
    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException("Callback: invalid state.", 401);
        }

        $tokenResponse = $this->getAccessTokenResponse($this->request->get('code'));

        // Decrypt JWT token
        $payload = $this->decodeJWT(
            $tokenResponse['id_token'],
            $this->request->get('code')
        );

        if ($this->hasEmptyEmail($payload)) {
            $payload = $this->getUserByToken($tokenResponse['access_token']);
            $email = $payload['email'] ?? null;
            if (! $email) {
                throw new EmptyEmailException('JWT: User has no email.', 401);
            }
        }

        $this->user = $this->mapUserToObject((array)$payload);

        return $this->user->setToken($tokenResponse['access_token'])
            ->setRefreshToken($tokenResponse['refresh_token'] ?? null)
            ->setExpiresIn($tokenResponse['expires_in']);
    }

    protected function decodeJWT($jwt, $code)
    {
        try {
            [$jwt_header, $jwt_payload, $jwt_signature] = explode(".", $jwt);
            $payload = json_decode($this->base64url_decode($jwt_payload));
        } catch (Exception $e) {
            throw new InvalidTokenException('JWT: Failed to parse.', 401);
        }

        if ($this->isInvalidNonce($payload->nonce)) {
            throw new InvalidNonceException('JWT: Contains an invalid nonce.', 401);
        }

        return $payload;
    }

    private function base64url_decode($data)
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Determine if the current token has a mismatching "nonce".
     * nonce must be validated to prevent replay attacks
     *
     * @return bool
     */
    protected function isInvalidNonce($nonce)
    {
        if (!$this->usesNonce()) {
            return false;
        }

        return !(strlen($nonce) > 0 && $nonce === $this->getCurrentNonce());
    }

    protected function hasEmptyEmail($payload)
    {
        return !isset($payload->email) || strlen($payload->email) == 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map(
            [
                'id' => $user['sub'],

                'email' => $user['email'] ?? null,
                'name' => $user['name'] ?? null,
                'nickname' => $user['nickname'] ?? null,
                'given_name' => $user['given_name'] ?? null,
                'family_name' => $user['family_name'] ?? null,

                'idp' => $user['idp'] ?? null,
                'role' => $user['role'] ?? null,
                'groups' => $user['groups'] ?? null,
            ]
        );
    }

    /**
     * {@inheritdoc}
     * @throws JsonException|GuzzleException
     */
    public function getAccessTokenResponse($code)
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::FORM_PARAMS => array_merge(
                $this->getTokenFields($code),
                [
                    'grant_type' => 'authorization_code',
                ]
            ),
        ]);

        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }


    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get(
            $this->getUserInfoUrl() . '?' . http_build_query([
                'access_token' => $token,
            ]),
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]
        );

        return json_decode((string)$response->getBody(), true);
    }

}
