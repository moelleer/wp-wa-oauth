<?php

namespace Bonnier\WP\WaOauth\Http\Routes;

use Bonnier\WP\WaOauth\Admin\PostMetaBox;
use Bonnier\WP\WaOauth\Http\Exceptions\HttpException;
use Exception;
use Bonnier\WP\WaOauth\Services\ServiceOAuth;
use Bonnier\WP\WaOauth\Settings\SettingsPage;
use WP_REST_Request;
use WP_REST_Response;

class OauthLoginRoute
{
    const BASE_PREFIX = 'wp-json';

    /**
     * The namespace prefix.
     */
    const PLUGIN_PREFIX = 'bp-wa-oauth';

    /**
     * The namespace version.
     */
    const VERSION = 'v1';

    /**
     * The login route.
     */
    const LOGIN_ROUTE = '/oauth/login';

    /**
     * The get user route.
     */
    const GET_USER_ROUTE = '/oauth/user';

    /**
     * The access token cookie lifetime.
     */
    const ACCESS_TOKEN_LIFETIME_HOURS = 24;

    /**
     * The access token cookie key.
     */
    const ACCESS_TOKEN_COOKIE_KEY = 'bp_wa_oauth_token';

    /**
     * The auth destination cookie key.
     */
    const AUTH_DESTINATION_COOKIE_KEY = 'bp_wa_oauth_auth_destination';

    /* @var SettingsPage $settings */
    private $settings;

    /* @var ServiceOAuth $service */
    private $service;

    /**
     * OauthLoginRoute constructor.
     * @param SettingsPage $settings
     */
    public function __construct(SettingsPage $settings)
    {
        $this->settings = $settings;

        add_action('rest_api_init', function () {
            register_rest_route($this->get_route_namespace(), self::LOGIN_ROUTE, [
                'methods' => 'GET, POST',
                'callback' => [$this, 'login'],
            ]);
        });
    }

    /**
     * The function that handles the user login request
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function login(WP_REST_Request $request)
    {
        $redirectUri = $request->get_param('redirectUri');
        $postRequiredRole = null;

        // Check for overriding settings from post
        if($postId = url_to_postid($redirectUri)) {
            if(PostMetaBox::post_is_unlocked($postId)) {
                $this->redirect($redirectUri);
            }
            if($requiredRole = PostMetaBox::post_required_role($postId)) {
                $postRequiredRole = $requiredRole;
            }
        }

        // Persist auth destination
        $this->set_auth_destination($redirectUri);

        // Get user from admin service
        try {
            $waUser = $this->get_wa_user($request);
        } catch (HttpException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], $e->getCode());
        }

        // If the user is not logged in, we redirect to the login screen.
        if (!$waUser) {
            $this->trigger_login_flow($postRequiredRole);
        }

        // Check if auth destination has been set
        $redirect = $this->get_auth_destination();

        if ($redirect) {
            //redirect to auth destination
            $this->redirect($redirect);
        }

        return new WP_REST_Response($waUser, 200);
    }

    /**
     * Check if the current request is authenticated
     *
     * @return bool
     */
    public function is_authenticated($postId = null)
    {
        if(!$postId) {
            $postId = get_the_ID();
        }

        $this->service = $this->get_oauth_service();
        if ($user = $this->get_wa_user()) {
            if($postId && !PostMetaBox::post_is_unlocked($postId)) {
                if(!in_array(PostMetaBox::post_required_role($postId), $user->roles)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }


    /**
     * Triggers the login flow by redirecting the user to the login Url
     * @param null $requiredRole
     */
    private function trigger_login_flow($requiredRole = null)
    {
        $currentLang = $this->settings->get_current_language();

        if(!$requiredRole) {
            $requiredRole = $this->settings->get_required_user_role($currentLang->locale);
        }

        $this->redirect(
            $this->service->getLoginUrl(
                $this->get_redirect_uri(),
                $requiredRole)
        );

    }

    /**
     *
     *
     * @param WP_REST_Request|null $request
     * @return mixed
     * @throws Exception|HttpException
     */
    public function get_wa_user($request = null)
    {
        $this->service = $this->get_oauth_service();

        $redirectUri = $this->get_redirect_uri();

        if ($accessToken = $this->get_access_token()) {

            $this->service->setAccessToken($accessToken);

        } elseif ($request && $grantToken = $request->get_param('code')) {

            $this->service->setGrantToken($redirectUri, $grantToken);
            $this->persist_access_token($this->service->getAccessToken());
        }

        return $this->service->getUser();

    }

    /**
     * Redirect the user to provided path
     *
     * @param $to
     */
    private function redirect($to)
    {
        header("Location: " . $to);
        exit();
    }

    /**
     * Returns the route namespace
     *
     * @return string
     */
    private function get_route_namespace()
    {
        return self::PLUGIN_PREFIX . DIRECTORY_SEPARATOR . self::VERSION;
    }

    /**
     * Returns the persisted access token or false
     *
     * @return string|bool
     */
    private function get_access_token()
    {
        return isset($_COOKIE[self::ACCESS_TOKEN_COOKIE_KEY]) ? $_COOKIE[self::ACCESS_TOKEN_COOKIE_KEY] : false;
    }

    /**
     * Persists the Access token for later use
     *
     * @param $token
     */
    private function persist_access_token($token)
    {
        setcookie(self::ACCESS_TOKEN_COOKIE_KEY, $token, $this->get_access_token_lifetime(), '/');
    }

    /**
     * Gets the cookie lifetime
     *
     * @return int
     */
    private function get_access_token_lifetime()
    {
        return time() + (self::ACCESS_TOKEN_LIFETIME_HOURS * 60 * 60);
    }

    /**
     * Persist the auth destination in a cookie
     *
     * @param $destination
     */
    private function set_auth_destination($destination)
    {
        setcookie(self::AUTH_DESTINATION_COOKIE_KEY, $destination, time() + (1 * 60 * 60), '/');
    }

    /**
     * Get the auth destination from the cookie
     *
     * @return bool
     */
    private function get_auth_destination()
    {
        return isset($_COOKIE[self::AUTH_DESTINATION_COOKIE_KEY]) ? $_COOKIE[self::AUTH_DESTINATION_COOKIE_KEY] : false;
    }

    /**
     * Returns the currently used HTTP protocol
     *
     * @return string
     */
    private function get_http_protocol()
    {
        return strpos('HTTP', getenv('SERVER_PROTOCOL')) === false ? 'http://' : 'https://';
    }

    /**
     * Returns the host including the HTTP protocol
     *
     * @return string
     */
    private function get_host()
    {
        return $this->get_http_protocol() . getenv('HTTP_HOST');
    }

    /**
     * Gets the redirect uri that matches the login route
     *
     * @return string
     */
    private function get_redirect_uri()
    {
        return $this->get_host()
        . DIRECTORY_SEPARATOR
        . self::BASE_PREFIX
        . DIRECTORY_SEPARATOR
        . $this->get_route_namespace()
        . self::LOGIN_ROUTE;
    }

    /**
     * Returns an instance of ServiceOauth
     *
     * @return ServiceOAuth
     */
    private function get_oauth_service()
    {
        if ($this->service) {
            return $this->service;
        }

        $locale = null;

        if ($currentLang = $this->settings->get_current_language()) {
            $locale = $currentLang->locale;
        }

        return new ServiceOAuth(
            $this->settings->get_api_user($locale),
            $this->settings->get_api_secret($locale),
            $this->settings->get_api_endpoint($locale)
        );
    }

}