<?php

declare(strict_types=1);

namespace Tests;

use CodeIgniter\Test\DatabaseTestTrait;
use Michalsn\CodeIgniterRelations\Exceptions\RelationException;
use stdClass;
use Tests\Support\Database\Seeds\SeedTests;
use Tests\Support\Entities\Profile;
use Tests\Support\Entities\User;
use Tests\Support\Models\ProfileModel;
use Tests\Support\Models\UserModel;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class HasOneTest extends TestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;
    protected $namespace;
    protected $seed = SeedTests::class;

    public function testEagerLoadHasOneWithFind()
    {
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $isset = isset($user->profile);
        $this->assertFalse($isset);

        $user = model(UserModel::class)->with('profile')->find(1);
        $this->assertInstanceOf(User::class, $user);

        $isset = isset($user->profile);
        $this->assertTrue($isset);

        /** @var mixed $profile */
        $profile = $user->profile;
        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame('1', $profile->user_id);
    }

    public function testEagerLoadHasOneWithFindAll()
    {
        $users = model(UserModel::class)->findAll();
        $this->assertInstanceOf(User::class, $users[0]);

        $isset = isset($users[0]->profile);
        $this->assertFalse($isset);

        $users = model(UserModel::class)->with('profile')->findAll();
        $this->assertInstanceOf(User::class, $users[0]);

        $isset = isset($users[0]->profile);
        $this->assertTrue($isset);

        /** @var mixed $profile */
        $profile = $users[0]->profile;
        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame($users[0]->id, $profile->user_id);
    }

    public function testEntityCanBeSerializedAfterLazyLoadingRelation()
    {
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        /** @var mixed $profile */
        $profile = $user->profile;
        $this->assertInstanceOf(Profile::class, $profile);

        $this->assertIsString(serialize($user));
    }

    public function testEagerLoadHasOneAsArray()
    {
        $user = model(UserModel::class)
            ->with('profile', static function ($model) {
                $model->asArray();
            })
            ->find(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(isset($user->profile));

        $this->assertSame('1', $user->profile['user_id']);
    }

    public function testEagerLoadHasOneWithModelAsArray()
    {
        $users = model(UserModel::class)
            ->asArray()
            ->with('profile', static function ($model) {
                $model->asArray();
            })
            ->findAll();

        $this->assertIsArray($users[0]);
        $this->assertArrayHasKey('id', $users[0]);
        $this->assertArrayHasKey('name', $users[0]);

        $this->assertArrayHasKey('profile', $users[0]);
        /** @var mixed $profile */
        $profile = $users[0]['profile'];
        $this->assertIsArray($profile);
        $this->assertArrayHasKey('user_id', $profile);
        $this->assertSame($users[0]['id'], $profile['user_id']);
    }

    public function testEagerLoadHasOneWithModelAsObject()
    {
        $users = model(UserModel::class)
            ->asObject()
            ->with('profile', static function ($model) {
                $model->asObject();
            })
            ->findAll();

        $this->assertInstanceOf(stdClass::class, $users[0]);
        $this->assertObjectHasProperty('id', $users[0]);
        $this->assertObjectHasProperty('name', $users[0]);

        $this->assertObjectHasProperty('profile', $users[0]);
        $this->assertInstanceOf(stdClass::class, $users[0]->profile);
        $this->assertSame($users[0]->id, $users[0]->profile->user_id);
    }

    public function testEagerLoadNestedHasOne()
    {
        $user = model(UserModel::class)
            ->with('profile')
            ->find(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(isset($user->profile));
    }

    public function testHasOneDoesNotLoadWithoutWith()
    {
        $user = model(UserModel::class)->find(1);

        $this->assertInstanceOf(User::class, $user);

        $isset = isset($user->profile);
        $this->assertFalse($isset);
    }

    public function testHasOneWithCallback()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)
            ->with('profile', static function ($model) {
                $model->where('user_id >', 0); // Always true, just testing callback works
            })
            ->find(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(Profile::class, $user->profile);
    }

    public function testLazyLoadHasOne()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $profile = $user->profile;

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame('1', $profile->user_id);
    }

    public function testSaveUpdatesExistingProfile()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);
        $this->assertInstanceOf(User::class, $user);

        $currentProfile = $user->profile;
        $this->assertInstanceOf(Profile::class, $currentProfile);

        $updatedProfile = $user->profile()->save([
            'id'      => $currentProfile->id,
            'bio'     => 'Updated bio through save',
            'avatar'  => $currentProfile->avatar,
            'website' => $currentProfile->website,
        ]);

        $this->assertInstanceOf(Profile::class, $updatedProfile);
        $this->assertSame($currentProfile->id, $updatedProfile->id);
        $this->assertSame('Updated bio through save', $updatedProfile->bio);

        $this->seeInDatabase('profiles', [
            'id'  => $currentProfile->id,
            'bio' => 'Updated bio through save',
        ]);
    }

    public function testSaveCreatesNewProfileWhenNoneExists()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(3);

        $profile = $user->profile()->save([
            'bio'     => 'Created via save',
            'avatar'  => 'newsave.jpg',
            'website' => 'https://newsave.com',
        ]);

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('Created via save', $profile->bio);

        $this->seeInDatabase('profiles', [
            'user_id' => $user->id,
            'bio'     => 'Created via save',
        ]);
    }

    public function testSaveReturnsEntityOnSuccess()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);

        $profile = $user->profile()->save([
            'id'      => 1,
            'bio'     => 'Success test',
            'avatar'  => 'success.jpg',
            'website' => 'https://success.com',
        ]);

        $this->assertInstanceOf(Profile::class, $profile);
    }

    public function testSaveReturnsFalseOnValidationFailure()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);

        $profile = $user->profile()->save([
            'id'      => 1,
            'bio'     => '', // Invalid if required
            'avatar'  => '',
            'website' => '',
        ]);

        $this->assertFalse($profile);

        $errors = model(ProfileModel::class)->errors();
        $this->assertNotEmpty($errors);
    }

    public function testSaveThrowsExceptionWithoutParentContext()
    {
        $this->expectException(RelationException::class);
        $this->expectExceptionMessage('Cannot call save() without parent context');

        $userModel = model(UserModel::class);
        $relation  = $userModel->profile();

        $relation->save(['bio' => 'Test']);
    }

    public function testSaveWithArrayData()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);

        $profileData = [
            'id'      => 1,
            'bio'     => 'Array save bio',
            'avatar'  => 'arraysave.jpg',
            'website' => 'https://arraysave.com',
        ];

        $profile = $user->profile()->save($profileData);

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertSame('Array save bio', $profile->bio);
    }

    public function testSaveWithEntityData()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(1);

        $profile      = $user->profile;
        $profile->bio = 'Entity save bio';

        $savedProfile = $user->profile()->save($profile);

        $this->assertInstanceOf(Profile::class, $savedProfile);
        $this->assertSame('Entity save bio', $savedProfile->bio);

        $this->seeInDatabase('profiles', [
            'id'  => $profile->id,
            'bio' => 'Entity save bio',
        ]);
    }

    public function testLazyLoadThenave()
    {
        /** @var User|null $user */
        $user = model(UserModel::class)->find(4);

        $profile = $user->profile;
        $this->assertNotInstanceOf(Profile::class, $profile);

        $newProfile = $user->profile()->save([
            'bio'     => 'Lazy then save',
            'avatar'  => 'lazy.jpg',
            'website' => 'https://lazy.com',
        ]);

        $this->assertInstanceOf(Profile::class, $newProfile);
        $this->assertSame($user->id, $newProfile->user_id);
    }
}
