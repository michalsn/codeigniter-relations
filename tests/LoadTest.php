<?php

declare(strict_types=1);

namespace Tests;

use CodeIgniter\Test\DatabaseTestTrait;
use Michalsn\CodeIgniterRelations\Exceptions\RelationException;
use Tests\Support\Database\Seeds\SeedTests;
use Tests\Support\Entities\Country;
use Tests\Support\Entities\User;
use Tests\Support\Models\PostModel;
use Tests\Support\Models\UserModel;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class LoadTest extends TestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace;
    protected $seed = SeedTests::class;

    public function testLoadSingleRelation()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $loadedRelations = $user->getLoadedRelations();
        $this->assertArrayNotHasKey('posts', $loadedRelations);

        $user->load('posts');

        $loadedRelations = $user->getLoadedRelations();
        $this->assertArrayHasKey('posts', $loadedRelations);
        $this->assertSame('eager', $loadedRelations['posts']['type']);
        $this->assertNotEmpty($user->posts);
    }

    public function testLoadMultipleRelations()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Load multiple relations
        $user->load(['posts', 'country']);

        $loadedRelations = $user->getLoadedRelations();
        $this->assertArrayHasKey('posts', $loadedRelations);
        $this->assertArrayHasKey('country', $loadedRelations);
        $this->assertNotEmpty($user->posts);
        $this->assertInstanceOf(Country::class, $user->country);
    }

    public function testLoadWithCallback()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Load all posts first
        $user->load('posts');
        $allPostsCount = count($user->posts);

        // Reload with a filter
        $user->load('posts', static fn ($q) => $q->where('status', 'published'));

        $publishedCount = count($user->posts);
        $this->assertLessThanOrEqual($allPostsCount, $publishedCount);

        // Verify all loaded posts are published
        foreach ($user->posts as $post) {
            $this->assertSame('published', $post->status);
        }
    }

    public function testLoadNestedRelations()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Load with nested relations
        $user->load(['posts', 'posts.comments']);

        // Verify posts loaded
        $this->assertNotEmpty($user->posts);

        // Verify comments loaded on each post
        foreach ($user->posts as $post) {
            $loadedRelations = $post->getLoadedRelations();
            $this->assertArrayHasKey('comments', $loadedRelations);
            $this->assertSame('eager', $loadedRelations['comments']['type']);
        }
    }

    public function testLoadDoesNotReloadEntityAttributes()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $originalUsername = $user->name;

        // Change username in database
        model(UserModel::class)->update(1, ['name' => 'changed_username']);

        // Load relations (should NOT reload entity attributes)
        $user->load('posts');

        // Username should still be the original value
        $this->assertSame($originalUsername, $user->name);

        // But posts should be loaded
        $this->assertNotEmpty($user->posts);
    }

    public function testLoadReloadsExistingRelation()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->with('posts')->find(1);
        $this->assertInstanceOf(User::class, $user);

        $originalPostCount = count($user->posts);

        // Add a new post
        model(PostModel::class)->insert([
            'user_id' => $user->id,
            'title'   => 'New Post via Load Test',
            'content' => 'Test content',
            'status'  => 'published',
        ]);

        // Posts in memory should still be the old count
        $this->assertCount($originalPostCount, $user->posts);

        // Reload posts
        $user->load('posts');

        // Now should have the new post
        $this->assertCount($originalPostCount + 1, $user->posts);
    }

    public function testLoadAfterRelationSave()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Save a new post via relation
        $post = $user->posts()->save([
            'title'   => 'Post via Relation Save',
            'content' => 'Test content',
            'status'  => 'draft',
        ]);

        $this->assertNotFalse($post);

        // Posts not loaded yet
        $loadedRelations = $user->getLoadedRelations();
        $this->assertArrayNotHasKey('posts', $loadedRelations);

        // Load posts
        $user->load('posts');

        // Should include the newly saved post
        $postIds = array_column($user->posts, 'id');
        $this->assertContains($post->id, $postIds);
    }

    public function testLoadWithNestedCallbacks()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Load posts with nested comments filtered
        $user->load([
            'posts',
            'posts.comments' => static fn ($q) => $q->limit(1),
        ]);

        // Verify posts loaded
        $this->assertNotEmpty($user->posts);

        // Verify each post has at most 1 comment
        foreach ($user->posts as $post) {
            $this->assertLessThanOrEqual(1, count($post->comments));
        }
    }

    public function testLoadWithArrayRelationsRejectsSeparateCallback()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $this->expectException(RelationException::class);
        $this->expectExceptionMessage('The callback argument cannot be used when loading relations with array syntax');

        $user->load(['posts'], static fn ($q) => $q->where('status', 'published'));
    }

    public function testLoadPreservesLoadedRelationMetadata()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        // Load with callback
        $callback = static fn ($q) => $q->where('status', 'published');
        $user->load(['posts' => $callback]);

        $loadedRelations = $user->getLoadedRelations();
        $this->assertArrayHasKey('posts', $loadedRelations);
        $this->assertSame('eager', $loadedRelations['posts']['type']);
        $this->assertSame($callback, $loadedRelations['posts']['callback']);
    }
}
