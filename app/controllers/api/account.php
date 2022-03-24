<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Detector\Detector;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Appwrite\Network\Validator\URL;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Template\Template;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

$oauthDefaultSuccess = '/v1/auth/oauth2/success';
$oauthDefaultFailure = '/v1/auth/oauth2/failure';

App::post('/v1/account')
    ->desc('Create Account')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'account.create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('abuse-limit', 10)
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($userId, $email, $password, $name, $request, $response, $project, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $email = \strtolower($email);
        if ('console' === $project->getId()) {
            $whitelistEmails = $project->getAttribute('authWhitelistEmails');
            $whitelistIPs = $project->getAttribute('authWhitelistIPs');

            if (!empty($whitelistEmails) && !\in_array($email, $whitelistEmails)) {
                throw new Exception('Console registration is restricted to specific emails. Contact your administrator for more information.', 401, Exception::USER_EMAIL_NOT_WHITELISTED);
            }

            if (!empty($whitelistIPs) && !\in_array($request->getIP(), $whitelistIPs)) {
                throw new Exception('Console registration is restricted to specific IPs. Contact your administrator for more information.', 401, Exception::USER_IP_NOT_WHITELISTED);
            }
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', [
                new Query('deleted', Query::TYPE_EQUAL, [false]),
            ], APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
            }
        }

        try {
            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;
            $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:' . $userId],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => Auth::passwordHash($password),
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => [],
                'tokens' => [],
                'memberships' => [],
                'search' => implode(' ', [$userId, $email, $name]),
                'deleted' => false
            ])));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
        }

        Authorization::unsetRole('role:' . Auth::USER_ROLE_GUEST);
        Authorization::setRole('user:' . $user->getId());
        Authorization::setRole('role:' . Auth::USER_ROLE_MEMBER);

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.create')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.create', 1)
        ;
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/account/sessions')
    ->desc('Create Account Session')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($email, $password, $request, $response, $dbForProject, $locale, $geodb, $audits, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $email = \strtolower($email);
        $protocol = $request->getProtocol();

        $profile = $dbForProject->findOne('users', [new Query('deleted', Query::TYPE_EQUAL, [false]), new Query('email', Query::TYPE_EQUAL, [$email])]); // Get user by email address

        if (!$profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {
            $audits
                //->setParam('userId', $profile->getId())
                ->setParam('event', 'account.sessions.failed')
                ->setParam('resource', 'user/'.($profile ? $profile->getId() : ''))
            ;

            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS); // Wrong password or username
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED); // User is in status blocked
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $profile->getId(),
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => $email,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()
        ));

        Authorization::setRole('user:' . $profile->getId());

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:' . $profile->getId()])
            ->setAttribute('$write', ['user:' . $profile->getId()])
        );

        $profile->setAttribute('sessions', $session, Document::SET_TYPE_APPEND);
        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile);

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'user/' . $profile->getId())
            ->setParam('userEmail', $profile->getAttribute('email', ''))
            ->setParam('userName', $profile->getAttribute('name', ''))
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($profile->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.'.strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.create', 1)
            ->setParam('provider', 'email')
        ;
        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::get('/v1/account/sessions/oauth2/:provider')
    ->desc('Create Account Session with OAuth2')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Session')
    ->label('sdk.description', '/docs/references/account/create-session-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('providers'), function($node) {return (!$node['mock']);}))).'.')
    ->param('success', '', function ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', '', function ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(128)), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function ($provider, $success, $failure, $scopes, $request, $response, $project) use ($oauthDefaultSuccess, $oauthDefaultFailure) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */

        $protocol = $request->getProtocol();
        $callback = $protocol.'://'.$request->getHostname().'/v1/account/sessions/oauth2/callback/'.$provider.'/'.$project->getId();
        $appId = $project->getAttribute('providers', [])[$provider.'Appid'] ?? '';
        $appSecret = $project->getAttribute('providers', [])[$provider.'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception('This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.', 412, Exception::PROJECT_PROVIDER_DISABLED);
        }

        $className = 'Appwrite\\Auth\\OAuth2\\'.\ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        if(empty($success)) {
            $success = $protocol . '://' . $request->getHostname() . $oauthDefaultSuccess;
        }

        if(empty($failure)) {
            $failure = $protocol . '://' . $request->getHostname() . $oauthDefaultFailure;
        }

        $oauth2 = new $className($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

App::get('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function ($projectId, $provider, $code, $state, $request, $response) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::post('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function ($projectId, $provider, $code, $state, $request, $response) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::get('/v1/account/sessions/oauth2/:provider/redirect')
    ->desc('OAuth2 Redirect')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'OAuth2 state params.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($provider, $code, $state, $request, $response, $project, $user, $dbForProject, $geodb, $audits, $events, $usage) use ($oauthDefaultSuccess) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $protocol = $request->getProtocol();
        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $validateURL = new URL();
        $appId = $project->getAttribute('providers', [])[$provider.'Appid'] ?? '';
        $appSecret = $project->getAttribute('providers', [])[$provider.'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $oauth2 = new $className($appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Exception$exception) {
                throw new Exception('Failed to parse login state params as passed from OAuth2 provider', 500, Exception::GENERAL_SERVER_ERROR);
            }
        } else {
            $state = $defaultState;
        }

        if (!$validateURL->isValid($state['success'])) {
            throw new Exception('Invalid redirect URL for success login', 400, Exception::PROJECT_INVALID_SUCCESS_URL);
        }

        if (!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
            throw new Exception('Invalid redirect URL for failure login', 400, Exception::PROJECT_INVALID_FAILURE_URL);
        }

        $state['failure'] = null;

        $accessToken = $oauth2->getAccessToken($code);
        $refreshToken =$oauth2->getRefreshToken($code);
        $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code);

        if (empty($accessToken)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Failed to obtain access token', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $oauth2ID = $oauth2->getUserID($accessToken);

        if (empty($oauth2ID)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Missing ID from OAuth2 provider', 400, Exception::PROJECT_MISSING_USER_ID);
        }

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        if ($current) { // Delete current session of new one.
            foreach ($sessions as $key => $session) {/** @var Document $session */
                if ($current === $session['$id']) {
                    unset($sessions[$key]);

                    $dbForProject->deleteDocument('sessions', $session->getId());
                    $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('sessions', $sessions));
                }
            }
        }

        $user = ($user->isEmpty()) ? $dbForProject->findOne('sessions', [ // Get user by provider id
            new Query('provider', QUERY::TYPE_EQUAL, [$provider]),
            new Query('providerUid', QUERY::TYPE_EQUAL, [$oauth2ID]),
        ]) : $user;

        if ($user === false || $user->isEmpty()) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            $name = $oauth2->getUserName($accessToken);
            $email = $oauth2->getUserEmail($accessToken);

            $user = $dbForProject->findOne('users', [new Query('deleted', Query::TYPE_EQUAL, [false]), new Query('email', Query::TYPE_EQUAL, [$email])]); // Get user by email address

            if ($user === false || $user->isEmpty()) { // Last option -> create the user, generate random password
                $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

                if ($limit !== 0) {
                    $total = $dbForProject->count('users', [ new Query('deleted', Query::TYPE_EQUAL, [false]),], APP_LIMIT_USERS);

                    if ($total >= $limit) {
                        throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
                    }
                }

                try {
                    $userId = $dbForProject->getId();
                    $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
                        '$id' => $userId,
                        '$read' => ['role:all'],
                        '$write' => ['user:' . $userId],
                        'email' => $email,
                        'emailVerification' => true,
                        'status' => true, // Email should already be authenticated by OAuth2 provider
                        'password' => Auth::passwordHash(Auth::passwordGenerator()),
                        'passwordUpdate' => 0,
                        'registration' => \time(),
                        'reset' => false,
                        'name' => $name,
                        'prefs' => new \stdClass(),
                        'sessions' => [],
                        'tokens' => [],
                        'memberships' => [],
                        'search' => implode(' ', [$userId, $email, $name]),
                        'deleted' => false
                    ])));
                } catch (Duplicate $th) {
                    throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
                }
            }
        }

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED); // User is in status blocked
        }

        // Create session token, verify user account and update OAuth2 ID and Access Token

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'provider' => $provider,
            'providerUid' => $oauth2ID,
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $refreshToken,
            'providerAccessTokenExpiry' => \time() + (int) $accessTokenExpiry,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password'));

        if ($isAnonymousUser) {
            $user
                ->setAttribute('name', $oauth2->getUserName($accessToken))
                ->setAttribute('email', $oauth2->getUserEmail($accessToken))
            ;
        }

        $user
            ->setAttribute('status', true)
            ->setAttribute('sessions', $session, Document::SET_TYPE_APPEND)
        ;

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()])
        );

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'user/' . $user->getId())
            ->setParam('data', ['provider' => $provider])
        ;

        $events->setParam('eventData', $response->output($session, Response::MODEL_SESSION));

        $usage
            ->setParam('users.sessions.create', 1)
            ->setParam('projectId', $project->getId())
            ->setParam('provider', 'oauth2-'.$provider)
        ;
        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        // Add keys for non-web platforms - TODO - add verification phase to aviod session sniffing
        if (parse_url($state['success'], PHP_URL_PATH) === $oauthDefaultSuccess) {
            $state['success'] = URLParser::parse($state['success']);
            $query = URLParser::parseQuery($state['success']['query']);
            $query['project'] = $project->getId();
            $query['domain'] = Config::getParam('cookieDomain');
            $query['key'] = Auth::$cookieName;
            $query['secret'] = Auth::encodeSession($user->getId(), $secret);
            $state['success']['query'] = URLParser::unparseQuery($query);
            $state['success'] = URLParser::unparse($state['success']);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->redirect($state['success'])
        ;
    });


App::post('/v1/account/sessions/magic-url')
    ->desc('Create Magic URL session')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('auth.type', 'magic-url')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMagicURLSession')
    ->label('sdk.description', '/docs/references/account/create-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the magic URL login. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('mails')
    ->action(function ($userId, $email, $url, $request, $response, $project, $dbForProject, $locale, $audits, $events, $mails) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $mails */

        if(empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $user = $dbForProject->findOne('users', [new Query('email', Query::TYPE_EQUAL, [$email])]);

        if (!$user) {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', [
                    new Query('deleted', Query::TYPE_EQUAL, [false]),
                ], APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;

            $user = Authorization::skip(fn () => $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:' . $userId],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => [],
                'tokens' => [],
                'memberships' => [],
                'search' => implode(' ', [$userId, $email]),
                'deleted' => false
            ])));

            $mails->setParam('event', 'users.create');
            $audits->setParam('event', 'users.create');
        }

        $loginSecret = Auth::tokenGenerator();

        $expire = \time() + Auth::TOKEN_EXPIRATION_CONFIRM;

        $token = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'type' => Auth::TOKEN_TYPE_MAGIC_URL,
            'secret' => Auth::hash($loginSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:'.$user->getId());

        $user->setAttribute('tokens', $token, Document::SET_TYPE_APPEND);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        if (false === $user) {
            throw new Exception('Failed to save user to DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        if(empty($url)) {
            $url = $request->getProtocol().'://'.$request->getHostname().'/auth/magic-url';
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $loginSecret, 'expire' => $expire, 'project' => $project->getId()]);
        $url = Template::unParseURL($url);

        $mails
            ->setParam('from', $project->getId())
            ->setParam('recipient', $user->getAttribute('email'))
            ->setParam('url', $url)
            ->setParam('locale', $locale->default)
            ->setParam('project', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('type', MAIL_TYPE_MAGIC_SESSION)
            ->trigger()
        ;

        $events
            ->setParam('eventData',
                $response->output($token->setAttribute('secret', $loginSecret),
                Response::MODEL_TOKEN
            ))
        ;

        $token  // Hide secret for clients
            ->setAttribute('secret',
                ($isPrivilegedUser || $isAppUser) ? $loginSecret : '');

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/sessions/magic-url')
    ->desc('Create Magic URL session (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.sessions.create')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMagicURLSession')
    ->label('sdk.description', '/docs/references/account/update-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($userId, $secret, $request, $response, $dbForProject, $locale, $geodb, $audits) {
        /** @var string $userId */
        /** @var string $secret */
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $token = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_MAGIC_URL, $secret);

        if (!$token) {
            throw new Exception('Invalid login token', 401, Exception::USER_INVALID_TOKEN);
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $user->getId(),
                'provider' => Auth::SESSION_PROVIDER_MAGIC_URL,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$read', ['user:' . $user->getId()])
                ->setAttribute('$write', ['user:' . $user->getId()])
        );

        $tokens = $user->getAttribute('tokens', []);

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        foreach ($tokens as $key => $singleToken) {
            if ($token === $singleToken->getId()) {
                unset($tokens[$key]);
            }
        }

        $user
            ->setAttribute('sessions', $session, Document::SET_TYPE_APPEND)
            ->setAttribute('tokens', $tokens);


        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = (isset($countries[strtoupper($session->getAttribute('countryCode'))]))
        ? $countries[strtoupper($session->getAttribute('countryCode'))]
        : $locale->getText('locale.country.unknown');

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/sessions/anonymous')
    ->desc('Create Anonymous Session')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('auth.type', 'anonymous')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAnonymousSession')
    ->label('sdk.description', '/docs/references/account/create-session-anonymous.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($request, $response, $locale, $user, $project, $dbForProject, $geodb, $audits, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $protocol = $request->getProtocol();

        if ('console' === $project->getId()) {
            throw new Exception('Failed to create anonymous user.', 401, Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED);
        }

        if (!$user->isEmpty()) {
            throw new Exception('Cannot create an anonymous user when logged in.', 401, Exception::USER_SESSION_ALREADY_EXISTS);
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', [
                new Query('deleted', Query::TYPE_EQUAL, [false]),
            ], APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
            }
        }

        $userId = $dbForProject->getId();
        $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
            '$id' => $userId,
            '$read' => ['role:all'],
            '$write' => ['user:' . $userId],
            'email' => null,
            'emailVerification' => false,
            'status' => true,
            'password' => null,
            'passwordUpdate' => \time(),
            'registration' => \time(),
            'reset' => false,
            'name' => null,
            'prefs' => new \stdClass(),
            'sessions' => [],
            'tokens' => [],
            'memberships' => [],
            'search' => $userId,
            'deleted' => false
        ])));

        // Create session token

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $user->getId(),
                'provider' => Auth::SESSION_PROVIDER_ANONYMOUS,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$read', ['user:' . $user->getId()])
                ->setAttribute('$write', ['user:' . $user->getId()])
        );

        $user = $dbForProject->updateDocument('users', $user->getId(),
            $user->setAttribute('sessions', $session, Document::SET_TYPE_APPEND));

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.sessions.create', 1)
            ->setParam('provider', 'anonymous')
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = (isset($countries[strtoupper($session->getAttribute('countryCode'))]))
        ? $countries[strtoupper($session->getAttribute('countryCode'))]
        : $locale->getText('locale.country.unknown');

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/jwt')
    ->desc('Create Account JWT')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'jwt')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createJWT')
    ->label('sdk.description', '/docs/references/account/create-jwt.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_JWT)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->inject('response')
    ->inject('user')
    ->action(function ($response, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */

        $sessions = $user->getAttribute('sessions', []);
        $current = new Document();

        foreach ($sessions as $session) {
            /** @var Utopia\Database\Document $session */

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $current = $session;
            }
        }

        if ($current->isEmpty()) {
            throw new Exception('No valid session found', 404, Exception::USER_SESSION_NOT_FOUND);
        }

        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic(new Document(['jwt' => $jwt->encode([
            // 'uid'    => 1,
            // 'aud'    => 'http://site.com',
            // 'scopes' => ['user'],
            // 'iss'    => 'http://api.mysite.com',
            'userId' => $user->getId(),
            'sessionId' => $current->getId(),
        ])]), Response::MODEL_JWT);
    });

App::get('/v1/account')
    ->desc('Get Account')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('response')
    ->inject('user')
    ->inject('usage')
    ->action(function ($response, $user, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Appwrite\Stats\Stats $usage */

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/account/prefs')
    ->desc('Get Account Preferences')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->inject('response')
    ->inject('user')
    ->inject('usage')
    ->action(function ($response, $user, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Appwrite\Stats\Stats $usage */

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/account/sessions')
    ->desc('Get Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/account/get-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('usage')
    ->action(function ($response, $user, $locale, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Stats\Stats $usage */

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            $countryName = $locale->getText('countries.'.strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', ($current == $session->getId()) ? true : false);

            $sessions[$key] = $session;
        }

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/account/logs')
    ->desc('Get Account Logs')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/account/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('geodb')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($limit, $offset, $response, $user, $locale, $geodb, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $audit = new Audit($dbForProject);
        $auditEvents = [
            'account.create',
            'account.delete',
            'account.update.name',
            'account.update.email',
            'account.update.password',
            'account.update.prefs',
            'account.sessions.create',
            'account.sessions.update',
            'account.sessions.delete',
            'account.recovery.create',
            'account.recovery.update',
            'account.verification.create',
            'account.verification.update',
            'teams.membership.create',
            'teams.membership.update',
            'teams.membership.delete',
        ];

        $logs = $audit->getLogsByUserAndEvents($user->getId(), $auditEvents, $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);

            $output[$i] = new Document(array_merge(
                $log->getArrayCopy(),
                $log['data'],
                $detector->getOS(),
                $detector->getClient(),
                $detector->getDevice()
            ));

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }

        }

        $usage
            ->setParam('users.read', 1)
        ;

        $response->dynamic(new Document([
            'total' => $audit->countLogsByUserAndEvents($user->getId(), $auditEvents),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/account/sessions/:sessionId')
    ->desc('Get Session By ID')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSession')
    ->label('sdk.description', '/docs/references/account/get-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to get the current device session.')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($sessionId, $response, $user, $locale, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $sessions = $user->getAttribute('sessions', []);
        $sessionId = ($sessionId === 'current')
        ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
        : $sessionId;

        foreach ($sessions as $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {

                $countryName = (isset($countries[strtoupper($session->getAttribute('countryCode'))]))
                ? $countries[strtoupper($session->getAttribute('countryCode'))]
                : $locale->getText('locale.country.unknown');

                $session
                    ->setAttribute('current', ($session->getAttribute('secret') == Auth::hash(Auth::$secret)))
                    ->setAttribute('countryName', $countryName)
                ;

                $usage
                    ->setParam('users.read', 1)
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/name')
    ->desc('Update Account Name')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.name')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($name, $response, $user, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->updateDocument('users', $user->getId(), $user
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$user->getId(), $name, $user->getAttribute('email')]))
        );

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.name')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/password')
    ->desc('Update Account Password')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.password')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->param('oldPassword', '', new Password(), 'Current user password. Must be at least 8 chars.', true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($password, $oldPassword, $response, $user, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        // Check old password only if its an existing user.
        if ($user->getAttribute('passwordUpdate') !== 0 && !Auth::passwordVerify($oldPassword, $user->getAttribute('password'))) { // Double check user password
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user
                ->setAttribute('password', Auth::passwordHash($password))
                ->setAttribute('passwordUpdate', \time())
        );

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.password')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/email')
    ->desc('Update Account Email')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.email')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($email, $password, $response, $user, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password')); // Check if request is from an anonymous account for converting

        if (
            !$isAnonymousUser &&
            !Auth::passwordVerify($password, $user->getAttribute('password'))
        ) { // Double check user password
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS);
        }

        $email = \strtolower($email);
        $profile = $dbForProject->findOne('users', [new Query('email', Query::TYPE_EQUAL, [$email])]); // Get user by email address

        if ($profile) {
            throw new Exception('User already registered', 409, Exception::USER_ALREADY_EXISTS);
        }

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user
                ->setAttribute('password', $isAnonymousUser ? Auth::passwordHash($password) : $user->getAttribute('password', ''))
                ->setAttribute('email', $email)
                ->setAttribute('emailVerification', false) // After this user needs to confirm mail again
                ->setAttribute('search', implode(' ', [$user->getId(), $user->getAttribute('name'), $user->getAttribute('email')]))
            );
        } catch(Duplicate $th) {
            throw new Exception('Email already exists', 409, Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.email')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/prefs')
    ->desc('Update Account Preferences')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.prefs')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($prefs, $response, $user, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $audits
            ->setParam('event', 'account.update.prefs')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::delete('/v1/account')
    ->desc('Delete Account')
    ->groups(['api', 'account'])
    ->label('event', 'account.delete')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/account/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($request, $response, $user, $dbForProject, $audits, $events, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $protocol = $request->getProtocol();
        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', false));

        // TODO Seems to be related to users.php/App::delete('/v1/users/:userId'). Can we share code between these two? Do todos below apply to users.php?

        // TODO delete all tokens or only current session?
        // TODO delete all user data according to GDPR. Make sure everything is backed up and backups are deleted later
        /*
     * Data to delete
     * * Tokens
     * * Memberships
     */

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.delete')
            ->setParam('resource', 'user/' . $user->getId())
            ->setParam('data', $user->getArrayCopy())
        ;

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([]))
            ;
        }

        $usage
            ->setParam('users.delete', 1)
        ;
        $response
            ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->noContent()
        ;
    });

App::delete('/v1/account/sessions/:sessionId')
    ->desc('Delete Account Session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.sessions.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to delete the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($sessionId, $request, $response, $user, $dbForProject, $locale, $audits, $events, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $protocol = $request->getProtocol();
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                unset($sessions[$key]);

                $dbForProject->deleteDocument('sessions', $session->getId());

                $audits
                    ->setParam('userId', $user->getId())
                    ->setParam('event', 'account.sessions.delete')
                    ->setParam('resource', 'user/' . $user->getId())
                ;

                $session->setAttribute('current', false);

                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $session
                        ->setAttribute('current', true)
                        ->setAttribute('countryName', (isset($countries[strtoupper($session->getAttribute('countryCode'))])) ? $countries[strtoupper($session->getAttribute('countryCode'))] : $locale->getText('locale.country.unknown'))
                    ;

                    if (!Config::getParam('domainVerification')) {
                        $response
                            ->addHeader('X-Fallback-Cookies', \json_encode([]))
                        ;
                    }

                    $response
                        ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                        ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                    ;
                }

                $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('sessions', $sessions));

                $events
                    ->setParam('eventData', $response->output($session, Response::MODEL_SESSION))
                ;

                $usage
                    ->setParam('users.sessions.delete', 1)
                    ->setParam('users.update', 1)
                ;
                return $response->noContent();
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/sessions/:sessionId')
    ->desc('Update Session (Refresh Tokens)')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.sessions.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateSession')
    ->label('sdk.description', '/docs/references/account/update-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to update the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($sessionId, $request, $response, $user, $dbForProject, $project, $locale, $audits, $events, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var boolean $force */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {

                // Comment below would skip re-generation if token is still valid
                // We decided to not include this because developer can get expiration date from the session
                // I kept code in comment because it might become relevant in the future

                // $expireAt = (int) $session->getAttribute('providerAccessTokenExpiry');
                // if(\time() < $expireAt - 5) { // 5 seconds time-sync and networking gap, to be safe
                //     return $response->noContent();
                // }

                $provider = $session->getAttribute('provider');
                $refreshToken = $session->getAttribute('providerRefreshToken');

                $appId = $project->getAttribute('providers', [])[$provider.'Appid'] ?? '';
                $appSecret = $project->getAttribute('providers', [])[$provider.'Secret'] ?? '{}';

                $className = 'Appwrite\\Auth\\OAuth2\\'.\ucfirst($provider);
             
                if (!\class_exists($className)) {
                    throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
                }

                $oauth2 = new $className($appId, $appSecret, '', [], []);

                $oauth2->refreshTokens($refreshToken);

                $session
                    ->setAttribute('providerAccessToken', $oauth2->getAccessToken(''))
                    ->setAttribute('providerRefreshToken', $oauth2->getRefreshToken(''))
                    ->setAttribute('providerAccessTokenExpiry', \time() + (int) $oauth2->getAccessTokenExpiry(''))
                    ;

                $dbForProject->updateDocument('sessions', $sessionId, $session);

                $user->setAttribute("sessions", $sessions);
                $user = $dbForProject->updateDocument('users', $user->getId(), $user);

                $audits
                    ->setParam('userId', $user->getId())
                    ->setParam('event', 'account.sessions.update')
                    ->setParam('resource', 'user/' . $user->getId())
                ;

                $events
                    ->setParam('eventData', $response->output($session, Response::MODEL_SESSION))
                ;

                $usage
                    ->setParam('users.sessions.update', 1)
                    ->setParam('users.update', 1)
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::delete('/v1/account/sessions')
    ->desc('Delete All Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.sessions.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/account/delete-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($request, $response, $user, $dbForProject, $locale, $audits, $events, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $protocol = $request->getProtocol();
        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) {/** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());

            $audits
                ->setParam('userId', $user->getId())
                ->setParam('event', 'account.sessions.delete')
                ->setParam('resource', 'user/' . $user->getId())
            ;

            if (!Config::getParam('domainVerification')) {
                $response
                    ->addHeader('X-Fallback-Cookies', \json_encode([]))
                ;
            }

            $session
                ->setAttribute('current', false)
                ->setAttribute('countryName', (isset($countries[strtoupper($session->getAttribute('countryCode'))])) ? $countries[strtoupper($session->getAttribute('countryCode'))] : $locale->getText('locale.country.unknown'))
            ;

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $session->setAttribute('current', true);
                $response
                    ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                ;
            }
        }

        $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('sessions', []));

        $numOfSessions = count($sessions);

        $events
            ->setParam('eventData', $response->output(new Document([
                'sessions' => $sessions,
                'total' => $numOfSessions,
            ]), Response::MODEL_SESSION_LIST))
        ;

        $usage
            ->setParam('users.sessions.delete', $numOfSessions)
            ->setParam('users.update', 1)
        ;
        $response->noContent();
    });

App::post('/v1/account/recovery')
    ->desc('Create Password Recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.recovery.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'ip:{ip}'])
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', function ($clients) {return new Host($clients);}, 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('mails')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function ($email, $url, $request, $response, $dbForProject, $project, $locale, $mails, $audits, $events, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $mails */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        if(empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $email = \strtolower($email);
        $profile = $dbForProject->findOne('users', [new Query('deleted', Query::TYPE_EQUAL, [false]), new Query('email', Query::TYPE_EQUAL, [$email])]); // Get user by email address

        if (!$profile) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED);
        }

        $expire = \time() + Auth::TOKEN_EXPIRATION_RECOVERY;

        $secret = Auth::tokenGenerator();
        $recovery = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $profile->getId(),
            'type' => Auth::TOKEN_TYPE_RECOVERY,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $profile->getId());

        $profile->setAttribute('tokens', $recovery, Document::SET_TYPE_APPEND);

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile);

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getId(), 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $mails
            ->setParam('event', 'account.recovery.create')
            ->setParam('from', $project->getId())
            ->setParam('recipient', $profile->getAttribute('email', ''))
            ->setParam('name', $profile->getAttribute('name', ''))
            ->setParam('url', $url)
            ->setParam('locale', $locale->default)
            ->setParam('project', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('type', MAIL_TYPE_RECOVERY)
            ->trigger();
        ;

        $events
            ->setParam('eventData',
                $response->output($recovery->setAttribute('secret', $secret),
                    Response::MODEL_TOKEN
                ))
        ;

        $recovery // Hide secret for clients, sp
            ->setAttribute('secret',
                ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.recovery.create')
            ->setParam('resource', 'user/' . $profile->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($recovery, Response::MODEL_TOKEN);
    });

App::put('/v1/account/recovery')
    ->desc('Create Password Recovery (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.recovery.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateRecovery')
    ->label('sdk.description', '/docs/references/account/update-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid reset token.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->param('passwordAgain', '', new Password(), 'Repeat new user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($userId, $secret, $password, $passwordAgain, $response, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        if ($password !== $passwordAgain) {
            throw new Exception('Passwords must match', 400, Exception::USER_PASSWORD_MISMATCH);
        }

        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty() || $profile->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $recovery = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_RECOVERY, $secret);

        if (!$recovery) {
            throw new Exception('Invalid recovery token', 401, Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole('user:' . $profile->getId());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile
                ->setAttribute('password', Auth::passwordHash($password))
                ->setAttribute('passwordUpdate', \time())
                ->setAttribute('emailVerification', true)
        );

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        foreach ($tokens as $key => $token) {
            if ($recovery === $token->getId()) {
                $recovery = $token;
                unset($tokens[$key]);
            }
        }

        $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('tokens', $tokens));

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.recovery.update')
            ->setParam('resource', 'user/' . $profile->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($recovery, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification')
    ->desc('Create Email Verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.verification.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createVerification')
    ->label('sdk.description', '/docs/references/account/create-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('mails')
    ->inject('usage')
    ->action(function ($url, $request, $response, $project, $user, $dbForProject, $locale, $audits, $events, $mails, $usage) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $mails */
        /** @var Appwrite\Stats\Stats $usage */

        if(empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $verificationSecret = Auth::tokenGenerator();

        $expire = \time() + Auth::TOKEN_EXPIRATION_CONFIRM;

        $verification = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'type' => Auth::TOKEN_TYPE_VERIFICATION,
            'secret' => Auth::hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $user->getId());

        $user->setAttribute('tokens', $verification, Document::SET_TYPE_APPEND);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $mails
            ->setParam('event', 'account.verification.create')
            ->setParam('from', $project->getId())
            ->setParam('recipient', $user->getAttribute('email'))
            ->setParam('name', $user->getAttribute('name'))
            ->setParam('url', $url)
            ->setParam('locale', $locale->default)
            ->setParam('project', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('type', MAIL_TYPE_VERIFICATION)
            ->trigger()
        ;

        $events
            ->setParam('eventData',
                $response->output($verification->setAttribute('secret', $verificationSecret),
                    Response::MODEL_TOKEN
                ))
        ;

        $verification // Hide secret for clients, sp
            ->setAttribute('secret',
                ($isPrivilegedUser || $isAppUser) ? $verificationSecret : '');

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.verification.create')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($verification, Response::MODEL_TOKEN);
    });

App::put('/v1/account/verification')
    ->desc('Create Email Verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.verification.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/account/update-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($userId, $secret, $response, $user, $dbForProject, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $verification = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_VERIFICATION, $secret);

        if (!$verification) {
            throw new Exception('Invalid verification token', 401, Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole('user:' . $profile->getId());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('emailVerification', true));

        /**
         * We act like we're updating and validating
         *  the verification token but actually we don't need it anymore.
         */
        foreach ($tokens as $key => $token) {
            if ($token->getId() === $verification) {
                $verification = $token;
                unset($tokens[$key]);
            }
        }

        $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('tokens', $tokens));

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.verification.update')
            ->setParam('resource', 'user/' . $user->getId())
        ;

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($verification, Response::MODEL_TOKEN);
    });
