<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Detector\Detector;
use Appwrite\Network\Validator\Email;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\Boolean;

App::post('/v1/users')
    ->desc('Create User')
    ->groups(['api', 'users'])
    ->label('event', 'users.create')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $email, $password, $name, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $email = \strtolower($email);

        try {
            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;
            $user = $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:'.$userId],
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
            ]));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
        }

        $usage
            ->setParam('users.create', 1)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users')
    ->desc('List Users')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of users to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the user used as the starting point for the query, excluding the user itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        if (!empty($cursor)) {
            $cursorUser = $dbForProject->getDocument('users', $cursor);

            if ($cursorUser->isEmpty()) {
                throw new Exception("User '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [
            new Query('deleted', Query::TYPE_EQUAL, [false])
        ];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $usage
            ->setParam('users.read', 1)
        ;

        $response->dynamic(new Document([
            'users' => $dbForProject->find('users', $queries, $limit, $offset, [], [$orderType], $cursorUser ?? null, $cursorDirection),
            'total' => $dbForProject->count('users', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_USER_LIST);
    });

App::get('/v1/users/:userId')
    ->desc('Get User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/users/get-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */ 

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users/:userId/prefs')
    ->desc('Get User Preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/users/get-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/users/:userId/sessions')
    ->desc('Get User Sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/users/get-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('usage')
    ->action(function ($userId, $response, $dbForProject, $locale, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { 
            /** @var Document $session */

            $countryName = $locale->getText('countries.'.strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));
            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', false);

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

App::get('/v1/users/:userId/logs')
    ->desc('Get User Logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/users/get-user-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('usage')
    ->action(function ($userId, $limit, $offset, $response, $dbForProject, $locale, $geodb, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

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
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

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

App::patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.status')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('status', null, new Boolean(true), 'User Status. To activate the user pass `true` and to block the user pass `false`.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $status, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update Email Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.verification')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/users/update-user-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('emailVerification', false, new Boolean(), 'User email verification status.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $emailVerification, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/name')
    ->desc('Update Name')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.name')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/users/update-user-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->action(function ($userId, $name, $response, $dbForProject, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('name', $name));

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.name')
            ->setParam('resource', 'user/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/password')
    ->desc('Update Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.password')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/users/update-user-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->action(function ($userId, $password, $response, $dbForProject, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('password', Auth::passwordHash($password))
            ->setAttribute('passwordUpdate', \time());

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.password')
            ->setParam('resource', 'user/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/email')
    ->desc('Update Email')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.email')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/users/update-user-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('email', '', new Email(), 'User email.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->action(function ($userId, $email, $response, $dbForProject, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password')); // Check if request is from an anonymous account for converting
        if (!$isAnonymousUser) {
            //TODO: Remove previous unique ID.
        }

        $email = \strtolower($email);

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('email', $email));
        } catch(Duplicate $th) {
            throw new Exception('Email already exists', 409, Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.email')
            ->setParam('resource', 'user/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.prefs')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function ($userId, $prefs, $response, $dbForProject, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->groups(['api', 'users'])
    ->label('event', 'users.sessions.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('sessionId', null, new UID(), 'Session ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('usage')
    ->action(function ($userId, $sessionId, $response, $dbForProject, $events, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { /** @var Document $session */

            if ($sessionId == $session->getId()) {
                unset($sessions[$key]);

                $dbForProject->deleteDocument('sessions', $session->getId());

                $user->setAttribute('sessions', $sessions);

                $events
                    ->setParam('eventData', $response->output($user, Response::MODEL_USER))
                ;

                $dbForProject->updateDocument('users', $user->getId(), $user);
            }
        }

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.delete', 1)
        ;

        $response->noContent();
    });

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->groups(['api', 'users'])
    ->label('event', 'users.sessions.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('usage')
    ->action(function ($userId, $response, $dbForProject, $events, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { /** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());
        }

        $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('sessions', []));

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.delete', 1)
        ;
        $response->noContent();
    });

App::delete('/v1/users/:userId')
    ->desc('Delete User')
    ->groups(['api', 'users'])
    ->label('event', 'users.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/users/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', function () {return new UID();}, 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->inject('usage')
    ->action(function ($userId, $response, $dbForProject, $events, $deletes, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $deletes */
        /** @var Appwrite\Stats\Stats $usage */
        
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        /**
         * DO NOT DELETE THE USER RECORD ITSELF. 
         * WE RETAIN THE USER RECORD TO RESERVE THE USER ID AND ENSURE THAT THE USER ID IS NOT REUSED.
         */
        
        // clone user object to send to workers
        $clone = clone $user;

        $user
            ->setAttribute("name", null)
            ->setAttribute("email", null)
            ->setAttribute("password", null)
            ->setAttribute("deleted", true)
            ->setAttribute("tokens", [])
            ->setAttribute("search", null)
        ;

        $dbForProject->updateDocument('users', $userId, $user);

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $clone)
        ;

        $events
            ->setParam('eventData', $response->output($clone, Response::MODEL_USER))
        ;

        $usage
            ->setParam('users.delete', 1)
        ;

        $response->noContent();
    });

App::get('/v1/users/usage')
    ->desc('Get usage stats for the users API')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_USERS)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->param('provider', '', new WhiteList(\array_merge(['email', 'anonymous'], \array_map(fn($value) => "oauth-".$value, \array_keys(Config::getParam('providers', [])))), true), 'Provider Name.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('register')
    ->action(function ($range, $provider, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                "users.count",
                "users.create",
                "users.read",
                "users.update",
                "users.delete",
                "users.sessions.create",
                "users.sessions.$provider.create",
                "users.sessions.delete"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {

                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'usersCount' => $stats["users.count"],
                'usersCreate' => $stats["users.create"],
                'usersRead' => $stats["users.read"],
                'usersUpdate' => $stats["users.update"],
                'usersDelete' => $stats["users.delete"],
                'sessionsCreate' => $stats["users.sessions.create"],
                'sessionsProviderCreate' => $stats["users.sessions.$provider.create"],
                'sessionsDelete' => $stats["users.sessions.delete"]
            ]);

        }

        $response->dynamic($usage, Response::MODEL_USAGE_USERS);
    });