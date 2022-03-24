<?php

namespace Tests\E2E\Services\Webhooks;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\CLI\Console;

class WebhooksCustomServerTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideServer;

    /**
     * @depends testCreateAttributes
     */
    public function testUpdateCollection($data): array
    {
        /**
         * Test for SUCCESS
         */
        $actors = $this->client->call(Client::METHOD_PUT, '/database/collections/'.$data['actorsId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Actors1',
            'permission' => 'document',
        ]);
        
        $this->assertEquals($actors['headers']['status-code'], 200);
        $this->assertNotEmpty($actors['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.collections.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Actors1');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

        return array_merge(['actorsId' => $actors['body']['$id']]);
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateDeleteIndexes($data): array
    {
        $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullname',
            'type' => 'key',
            'attributes' => ['lastName', 'firstName'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $this->assertEquals($index['headers']['status-code'], 201);
        $this->assertEquals($index['body']['key'], 'fullname');

        // wait for database worker to create index
        sleep(5);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.indexes.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);

        // Remove index
        $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/indexes/' . $index['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        // // wait for database worker to remove index
        $webhook = $this->getLastRequest();

        // $this->assertEquals($webhook['method'], 'DELETE');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.indexes.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);

        return $data;
    }

    public function testDeleteCollection(): array
    {
        /**
         * Test for SUCCESS
         */
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Demo',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document'
        ]);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNotEmpty($actors['body']['$id']);

        $actors = $this->client->call(Client::METHOD_DELETE, '/database/collections/'.$actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals($actors['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.collections.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Demo');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

        return [];
    }

    public function testCreateUser():array
    {
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertNotEmpty($user['body']['$id']);

        $id = $user['body']['$id'];

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'users.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $name);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], true);
        $this->assertEquals($webhook['data']['email'], $email);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs'], []);

        /**
         * Test for FAILURE
         */
        return ['userId' => $user['body']['$id'], 'name' => $user['body']['name'], 'email' => $user['body']['email']];
    }

     /**
     * @depends testCreateUser
     */
    public function testUpdateUserPrefs(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => ['a' => 'b']
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['a'], 'b');

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'users.update.prefs');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertEquals($webhook['data']['a'], 'b');

        return $data;
    }

    /**
     * @depends testUpdateUserPrefs
     */
    public function testUpdateUserStatus(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'status' => false,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertNotEmpty($user['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'users.update.status');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $data['name']);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], false);
        $this->assertEquals($webhook['data']['email'], $data['email']);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs']['a'], 'b');

        return $data;
    }
 
    /**
     * @depends testUpdateUserStatus
     */
    public function testDeleteUser(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'users.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], $data['name']);
        $this->assertIsInt($webhook['data']['registration']);
        $this->assertEquals($webhook['data']['status'], false);
        $this->assertEquals($webhook['data']['email'], $data['email']);
        $this->assertEquals($webhook['data']['emailVerification'], false);
        $this->assertEquals($webhook['data']['prefs']['a'], 'b');

        return $data;
    }

    public function testCreateFunction():array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['role:all'],
            'runtime' => 'php-8.0',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals($function['headers']['status-code'], 201);
        $this->assertNotEmpty($function['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        /**
         * Test for FAILURE 
         */

        return [
            'functionId' => $functionId,
        ];
    }

    /**
     * @depends testCreateFunction
     */
    public function testUpdateFunction($data):array
    {
       /**
        * Test for SUCCESS
        */
        $function = $this->client->call(Client::METHOD_PUT, '/functions/'.$data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'execute' => ['role:all'],
            'vars' => [
                'key1' => 'value1',
            ]
        ]);

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['$id'], $data['functionId']);
        $this->assertEquals($function['body']['vars'], ['key1' => 'value1']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        return $data;
    }
    
    /**
     * @depends testUpdateFunction
     */
    public function testCreateDeployment($data):array
    {
        /**
         * Test for SUCCESS
         */
        $stderr = '';
        $stdout= '';
        $folder = 'timeout';
        $code = realpath(__DIR__ . '/../../../resources/functions'). "/$folder/code.tar.gz";
        Console::execute('cd '.realpath(__DIR__ . "/../../../resources/functions") . "/$folder  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/'.$data['functionId'].'/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code))
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals($deployment['headers']['status-code'], 201);
        $this->assertNotEmpty($deployment['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.deployments.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        sleep(5);

        return array_merge($data, ['deploymentId' => $deploymentId]);
    }

    /**
     * @depends testCreateDeployment
     */
    public function testUpdateDeployment($data):array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/functions/'.$data['functionId'].'/deployments/'.$data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']['$id']);

        // Wait for deployment to be built.
        sleep(5);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.deployments.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        /**
         * Test for FAILURE 
         */

        return $data;
    }

    /**
     * @depends testUpdateDeployment
     */
    public function testExecutions($data):array
    {
        /**
         * Test for SUCCESS
         */

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$data['functionId'].'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals($execution['headers']['status-code'], 201);
        $this->assertNotEmpty($execution['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.executions.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        // wait for timeout function to complete (sleep(5);)
        sleep(10);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.executions.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        /**
         * Test for FAILURE 
         */

        return $data;
    }

    /**
     * @depends testExecutions 
     */
    public function testDeleteDeployment($data):array
    {
        /**
         * Test for SUCCESS
         */
        $deployment = $this->client->call(Client::METHOD_DELETE, '/functions/'.$data['functionId'].'/deployments/'.$data['deploymentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($deployment['headers']['status-code'], 204);
        $this->assertEmpty($deployment['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.deployments.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testDeleteDeployment
     */
    public function testDeleteFunction($data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/'.$data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $function['headers']['status-code']);
        $this->assertEmpty($function['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'functions.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);

        /**
         * Test for FAILURE
         */

        return $data;
    }
}
