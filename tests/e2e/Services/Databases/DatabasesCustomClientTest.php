<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabasesCustomClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideClient;

    public function testUpdateWithoutPermission(): array
    {
        // If document has been created by server and client tried to update it without adjusting permissions, permission validation should be skipped

        // As a part of preparation, we get ID of currently logged-in user
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);

        $userId = $response['body']['$id'];

        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => 'permissionCheckDatabase',
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Test Database', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        // Create collection
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'permissionCheck',
            'name' => 'permissionCheck',
            'read' => [],
            'write' => [],
            'permission' => 'document'
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Add attribute to collection
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/permissionCheck/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Wait for database worker to finish creating attributes
        sleep(2);

        // Creating document by server, give read permission to our user + some other user
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/permissionCheck/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'permissionCheckDocument',
            'data' => [
                'name' => 'AppwriteBeginner',
            ],
            'read' => ['user:' . $userId, 'user:user2'],
            'write' => ['user:' . $userId],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Update document
        // This is the point of this test. We should be allowed to do this action, and it should not fail on permission check
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'AppwriteExpert',
            ]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Get name of the document, should be the new one
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("AppwriteExpert", $response['body']['name']);

        // Cleanup to prevent collision with other tests
        // Delete collection
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);


        // Wait for database worker to finish deleting collection
        sleep(2);

        // Make sure collection has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }
}
