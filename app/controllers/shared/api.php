<?php

use Appwrite\Auth\Auth;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\App;
use Appwrite\Extend\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Document;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Database\Validator\Authorization;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;

App::init(function ($utopia, $request, $response, $project, $user, $events, $audits, $usage, $deletes, $database, $dbForProject, $mode) {
    /** @var Utopia\App $utopia */
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Document $user */
    /** @var Utopia\Registry\Registry $register */
    /** @var Appwrite\Event\Event $events */
    /** @var Appwrite\Event\Event $audits */
    /** @var Appwrite\Stats\Stats $usage */
    /** @var Appwrite\Event\Event $deletes */
    /** @var Appwrite\Event\Event $database */
    /** @var Appwrite\Event\Event $functions */
    /** @var Utopia\Database\Database $dbForProject */

    $route = $utopia->match($request);

    if ($project->isEmpty() && $route->getLabel('abuse-limit', 0) > 0) { // Abuse limit requires an active project scope
        throw new Exception('Missing or unknown project ID', 400, Exception::PROJECT_UNKNOWN);
    }

    /*
     * Abuse Check
     */
    $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
    $timeLimitArray = [];

    $abuseKeyLabel = (!is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

    foreach ($abuseKeyLabel as $abuseKey) {
        $timeLimit = new TimeLimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $dbForProject);
        $timeLimit
            ->setParam('{userId}', $user->getId())
            ->setParam('{userAgent}', $request->getUserAgent(''))
            ->setParam('{ip}', $request->getIP())
            ->setParam('{url}', $request->getHostname().$route->getPath());
        $timeLimitArray[] = $timeLimit;
    }

    $closestLimit = null;

    $roles = Authorization::getRoles();
    $isPrivilegedUser = Auth::isPrivilegedUser($roles);
    $isAppUser = Auth::isAppUser($roles);

    foreach ($timeLimitArray as $timeLimit) {
        foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
            if(!empty($value)) {
                $timeLimit->setParam('{param-'.$key.'}', (\is_array($value)) ? \json_encode($value) : $value);
            }
        }

        $abuse = new Abuse($timeLimit);

        if ($timeLimit->limit() && ($timeLimit->remaining() < $closestLimit || is_null($closestLimit))) {
            $closestLimit = $timeLimit->remaining();
            $response
                ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
                ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
                ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600))
            ;
        }

        if ((App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled' // Route is rate-limited
        && $abuse->check()) // Abuse is not disabled
        && (!$isAppUser && !$isPrivilegedUser)) // User is not an admin or API key
        {
            throw new Exception('Too many requests', 429, Exception::GENERAL_RATE_LIMIT_EXCEEDED);
        }
    }

    /*
     * Background Jobs
     */
    $events
        ->setParam('projectId', $project->getId())
        ->setParam('webhooks', $project->getAttribute('webhooks', []))
        ->setParam('userId', $user->getId())
        ->setParam('event', $route->getLabel('event', ''))
        ->setParam('eventData', [])
        ->setParam('functionId', null)	
        ->setParam('executionId', null)	
        ->setParam('trigger', 'event')
    ;

    $audits
        ->setParam('projectId', $project->getId())
        ->setParam('userId', $user->getId())
        ->setParam('userEmail', $user->getAttribute('email'))
        ->setParam('userName', $user->getAttribute('name'))
        ->setParam('mode', $mode)
        ->setParam('event', '')
        ->setParam('resource', '')
        ->setParam('userAgent', $request->getUserAgent(''))
        ->setParam('ip', $request->getIP())
        ->setParam('data', [])
    ;

    $usage
        ->setParam('projectId', $project->getId())
        ->setParam('httpRequest', 1)
        ->setParam('httpUrl', $request->getHostname().$request->getURI())
        ->setParam('httpMethod', $request->getMethod())
        ->setParam('httpPath', $route->getPath())
        ->setParam('networkRequestSize', 0)
        ->setParam('networkResponseSize', 0)
        ->setParam('storage', 0)
    ;
    
    $deletes
        ->setParam('projectId', $project->getId())
    ;

    $database
        ->setParam('projectId', $project->getId())
    ;
}, ['utopia', 'request', 'response', 'project', 'user', 'events', 'audits', 'usage', 'deletes', 'database', 'dbForProject', 'mode'], 'api');

App::init(function ($utopia, $request, $project) {
    /** @var Utopia\App $utopia */
    /** @var Appwrite\Utopia\Request $request */
    /** @var Utopia\Database\Document $project */

    $route = $utopia->match($request);

    $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
    $isAppUser = Auth::isAppUser(Authorization::getRoles());

    if($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
        return;
    }

    $auths = $project->getAttribute('auths', []);
    switch ($route->getLabel('auth.type', '')) {
        case 'emailPassword':
            if(($auths['emailPassword'] ?? true) === false) {
                throw new Exception('Email / Password authentication is disabled for this project', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            }
            break;

        case 'magic-url':
            if($project->getAttribute('usersAuthMagicURL', true) === false) {
                throw new Exception('Magic URL authentication is disabled for this project', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            }
            break;

        case 'anonymous':
            if(($auths['anonymous'] ?? true) === false) {
                throw new Exception('Anonymous authentication is disabled for this project', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            }
            break;

        case 'invites':
            if(($auths['invites'] ?? true) === false) {
                throw new Exception('Invites authentication is disabled for this project', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            }
            break;

        case 'jwt':
            if(($auths['JWT'] ?? true) === false) {
                throw new Exception('JWT authentication is disabled for this project', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            }
            break;

        default:
            throw new Exception('Unsupported authentication route', 501, Exception::USER_AUTH_METHOD_UNSUPPORTED);
            break;
    }

}, ['utopia', 'request', 'project'], 'auth');

App::shutdown(function ($utopia, $request, $response, $project, $events, $audits, $usage, $deletes, $database, $mode) {
    /** @var Utopia\App $utopia */
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $project */
    /** @var Appwrite\Event\Event $events */
    /** @var Appwrite\Event\Event $audits */
    /** @var Appwrite\Stats\Stats $usage */
    /** @var Appwrite\Event\Event $deletes */
    /** @var Appwrite\Event\Event $database */
    /** @var bool $mode */

    if (!empty($events->getParam('event'))) {
        if (empty($events->getParam('eventData'))) {
            $events->setParam('eventData', $response->getPayload());
        }

        $webhooks = clone $events;
        $functions = clone $events;

        $webhooks
            ->setQueue('v1-webhooks')
            ->setClass('WebhooksV1')
            ->trigger();

        $functions
            ->setQueue('v1-functions')
            ->setClass('FunctionsV1')
            ->trigger();

        if ($project->getId() !== 'console') {
            $payload = new Document($response->getPayload());
            $collection = new Document($events->getParam('collection') ?? []);
            $bucket = new Document($events->getParam('bucket') ?? []);

            $target = Realtime::fromPayload(
                event: $events->getParam('event'), 
                payload: $payload, 
                project: $project, 
                collection: $collection,
                bucket: $bucket,
            );

            Realtime::send(
                $target['projectId'] ?? $project->getId(),
                $response->getPayload(),
                $events->getParam('event'),
                $target['channels'],
                $target['roles'],
                [
                    'permissionsChanged' => $target['permissionsChanged'], 
                    'userId' => $events->getParam('userId')
                ]
            );
        }
    }

    if (!empty($audits->getParam('event'))) {
        $audits->trigger();
    }

    if (!empty($deletes->getParam('type')) && !empty($deletes->getParam('document'))) {
        $deletes->trigger();
    }

    if (!empty($database->getParam('type')) && !empty($database->getParam('document'))) {
        $database->trigger();
    }

    $route = $utopia->match($request);
    if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled' 
        && $project->getId()
        && $mode !== APP_MODE_ADMIN // TODO: add check to make sure user is admin
        && !empty($route->getLabel('sdk.namespace', null))) { // Don't calculate console usage on admin mode

        $usage
            ->setParam('networkRequestSize', $request->getSize() + $usage->getParam('storage'))
            ->setParam('networkResponseSize', $response->getSize())
            ->submit()
        ;
    }

}, ['utopia', 'request', 'response', 'project', 'events', 'audits', 'usage', 'deletes', 'database', 'mode'], 'api');