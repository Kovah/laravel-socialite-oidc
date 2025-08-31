# OpenID Connect (OIDC) Provider for Laravel Socialite

![Laravel Support: v9, v10, v11](https://img.shields.io/badge/Laravel%20Support-v9%2C%20v10%2C%20v11-blue) ![PHP Support: 8.1, 8.2, 8.3](https://img.shields.io/badge/PHP%20Support-8.1%2C%208.2%2C%208.3-blue)

## Installation & Basic Usage

```bash
composer require kovah/laravel-socialite-oidc
```

Please see the [Base Installation Guide](https://socialiteproviders.com/usage/), then follow the provider specific instructions below.

### Add configuration to `config/services.php`

```php
'oidc' => [
    'base_url' => env('OIDC_BASE_URL'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'redirect' => env('OIDC_REDIRECT_URI'),
    
    // Optional: Enable JWT signature verification (default: false)
    'verify_jwt' => env('OIDC_VERIFY_JWT', false),
    
    // Optional: Provide a specific public key for JWT verification
    // If not provided, the key will be fetched from the OIDC provider's JWKS endpoint
    'jwt_public_key' => env('OIDC_JWT_PUBLIC_KEY'),
],
```

The base URL must be set to the URL of your OIDC endpoint excluding the `.well-known/openid-configuration` part. For example:
If `https://auth.company.com/application/linkace/.well-known/openid-configuration` is your OIDC configuration URL, then `https://auth.company.com/application/linkace` must be your base URL.

### JWT Signature Verification

By default, this package does not verify the JWT signature of the `id_token`. According to the OpenID Connect specification, signature verification is not required when TLS is used and the token is transmitted directly from the authorization server to the client.

However, for enhanced security, you can enable JWT signature verification by setting `verify_jwt` to `true` in your configuration:

```php
'oidc' => [
    // ... other configuration
    'verify_jwt' => true,
],
```

When JWT verification is enabled:

1. **Automatic JWKS fetching**: The provider will automatically fetch the JSON Web Key Set (JWKS) from your OIDC provider's `.well-known/openid-configuration` endpoint
2. **Caching**: JWKS data is cached for 1 hour to improve performance
3. **Custom public key**: Alternatively, you can provide a specific public key using the `jwt_public_key` option

**Example with custom public key:**

```php
'oidc' => [
    // ... other configuration
    'verify_jwt' => true,
    'jwt_public_key' => '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...
-----END PUBLIC KEY-----',
],
```

### Add provider event listener

Configure the package's listener to listen for `SocialiteWasCalled` events.

#### Laravel 11+

In Laravel 11, the default `EventServiceProvider` provider was removed. Instead, add the listener using the `listen` method on the `Event` facade, in your `AppServiceProvider` `boot` method.

```php
Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
    $event->extendSocialite('oidc', \SocialiteProviders\OIDC\Provider::class);
});
```

#### Laravel 10 or below

Add the event to your listen[] array in `app/Providers/EventServiceProvider`. See the [Base Installation Guide](https://socialiteproviders.com/usage/) for detailed instructions.

```php
protected $listen = [
    \SocialiteProviders\Manager\SocialiteWasCalled::class => [
        // ... other providers
        \SocialiteProviders\OIDC\OIDCExtendSocialite::class.'@handle',
    ],
];
```

### Usage

You should now be able to use the provider like you would regularly use Socialite (assuming you have the facade
installed):

```php
return Socialite::driver('oidc')->redirect();
```

### Returned User fields

- `id`
- `name`
- `email`

More fields are available under the `user` subkey:

```php
$user = Socialite::driver('oidc')->user();

$locale = $user->user['locale'];
$email_verified = $user->user['email_verified'];
```

### Customizing the scopes

You may extend the default scopes (`openid email profile`) by adding a `scopes` option to your OIDC service configuration and separate multiple scopes with a space:

```php
'oidc' => [
    'base_url' => env('OIDC_BASE_URL'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'redirect' => env('OIDC_REDIRECT_URI'),
    
    'scopes' => 'groups roles',
    // or
    'scopes' => env('OIDC_SCOPES'),
],
```

---

Based on the work of [jp-gauthier](https://github.com/jp-gauthier)
