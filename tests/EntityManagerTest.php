<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use PHPUnit\Framework\TestCase;
use LiteORM\EntityManager;
use LiteORM\Metadata\AttributeReader;

require_once __DIR__ . '/Fixtures.php';

class EntityManagerTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        AttributeReader::clearCache();
        $this->em = new EntityManager('sqlite::memory:');
        $this->em->createTable(User::class);
        $this->em->createTable(Post::class);
        $this->em->createTable(Category::class);
    }

    // ─── CRUD ─────────────────────────────────────────────────────

    public function testInsertAndFind(): void
    {
        $user = new User();
        $user->name = 'John Doe';
        $user->email = 'john@example.com';
        $user->age = 30;

        $this->em->persist($user);
        $this->em->flush();

        $this->assertGreaterThan(0, $user->id);

        // Find by ID
        $this->em->clear();
        $found = $this->em->find(User::class, $user->id);
        $this->assertNotNull($found);
        $this->assertEquals('John Doe', $found->name);
        $this->assertEquals('john@example.com', $found->email);
        $this->assertEquals(30, $found->age);
    }

    public function testUpdateDirtyFieldsOnly(): void
    {
        $user = new User();
        $user->name = 'Original';
        $user->email = 'orig@test.com';
        $user->age = 25;
        $this->em->persist($user);
        $this->em->flush();

        // Modify only name
        $found = $this->em->find(User::class, $user->id);
        $found->name = 'Updated';
        $this->em->flush();

        // Verify
        $this->em->clear();
        $verify = $this->em->find(User::class, $user->id);
        $this->assertEquals('Updated', $verify->name);
        $this->assertEquals('orig@test.com', $verify->email);
        $this->assertEquals(25, $verify->age);
    }

    public function testDelete(): void
    {
        $user = new User();
        $user->name = 'Delete Me';
        $user->email = 'del@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $id = $user->id;

        $this->em->remove($user);
        $this->em->flush();

        $this->em->clear();
        $found = $this->em->find(User::class, $id);
        $this->assertNull($found);
    }

    public function testFindAll(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $user = new User();
            $user->name = "User {$i}";
            $user->email = "user{$i}@test.com";
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->em->clear();

        $all = $this->em->findAll(User::class);
        $this->assertCount(5, $all);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $found = $this->em->find(User::class, 9999);
        $this->assertNull($found);
    }

    public function testIdentityMap(): void
    {
        $user = new User();
        $user->name = 'Identity';
        $user->email = 'identity@test.com';
        $this->em->persist($user);
        $this->em->flush();

        // Same instance from identity map
        $found1 = $this->em->find(User::class, $user->id);
        $found2 = $this->em->find(User::class, $user->id);
        $this->assertSame($found1, $found2);
    }

    public function testAutoTimestamps(): void
    {
        $user = new User();
        $user->name = 'Timestamped';
        $user->email = 'ts@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $this->assertNotNull($user->createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->createdAt);
    }

    public function testClearResetsState(): void
    {
        $user = new User();
        $user->name = 'Clear';
        $user->email = 'clear@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $this->em->clear();

        // Find should create new instance (not from identity map)
        $found = $this->em->find(User::class, $user->id);
        $this->assertNotSame($user, $found);
    }

    public function testNullableFields(): void
    {
        $user = new User();
        $user->name = 'Nullable';
        $user->email = 'null@test.com';
        // age, balance, active left as null
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(User::class, $user->id);
        $this->assertNull($found->age);
        $this->assertNull($found->balance);
    }

    public function testMultipleInserts(): void
    {
        $u1 = new User();
        $u1->name = 'User1';
        $u1->email = 'u1@test.com';

        $u2 = new User();
        $u2->name = 'User2';
        $u2->email = 'u2@test.com';

        $this->em->persist($u1);
        $this->em->persist($u2);
        $this->em->flush();

        $this->assertNotEquals($u1->id, $u2->id);
    }

    public function testDetach(): void
    {
        $user = new User();
        $user->name = 'Detach';
        $user->email = 'detach@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $id = $user->id;
        $this->em->detach($user);

        // After detach, find creates new instance
        $found = $this->em->find(User::class, $id);
        $this->assertNotSame($user, $found);
    }

    // ─── QueryBuilder ─────────────────────────────────────────────

    public function testQueryWhere(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $u = new User();
            $u->name = "User{$i}";
            $u->email = "qw{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->where('age', '>', 50)
            ->get();

        $this->assertCount(5, $results);
    }

    public function testQueryOrderByAndLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $u = new User();
            $u->name = "User{$i}";
            $u->email = "ol{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->orderBy('age', 'DESC')
            ->limit(3)
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(50, $results[0]->age);
    }

    public function testQueryCount(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $u = new User();
            $u->name = "Count{$i}";
            $u->email = "cnt{$i}@test.com";
            $this->em->persist($u);
        }
        $this->em->flush();

        $count = $this->em->query(User::class)->count();
        $this->assertEquals(7, $count);
    }

    public function testQueryFirst(): void
    {
        $u = new User();
        $u->name = 'First';
        $u->email = 'first@test.com';
        $this->em->persist($u);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->query(User::class)
            ->where('email', 'first@test.com')
            ->first();

        $this->assertNotNull($found);
        $this->assertEquals('First', $found->name);
    }

    public function testQueryExists(): void
    {
        $u = new User();
        $u->name = 'Exists';
        $u->email = 'exists@test.com';
        $this->em->persist($u);
        $this->em->flush();

        $this->assertTrue(
            $this->em->query(User::class)->where('email', 'exists@test.com')->exists()
        );
        $this->assertFalse(
            $this->em->query(User::class)->where('email', 'nope@test.com')->exists()
        );
    }

    public function testQueryWhereIn(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $u = new User();
            $u->name = "InUser{$i}";
            $u->email = "in{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->whereIn('age', [10, 30, 50])
            ->get();

        $this->assertCount(3, $results);
    }

    public function testQueryDelete(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $u = new User();
            $u->name = "Del{$i}";
            $u->email = "del{$i}@test.com";
            $u->age = ($i + 1) * 10;
            $this->em->persist($u);
        }
        $this->em->flush();

        $deleted = $this->em->query(User::class)
            ->where('age', '>', 30)
            ->delete();

        $this->assertEquals(2, $deleted);
        $this->assertEquals(3, $this->em->query(User::class)->count());
    }

    public function testQueryUpdate(): void
    {
        $u = new User();
        $u->name = 'OldName';
        $u->email = 'update@test.com';
        $this->em->persist($u);
        $this->em->flush();

        $this->em->query(User::class)
            ->where('email', 'update@test.com')
            ->update(['name' => 'NewName']);

        $this->em->clear();
        $found = $this->em->query(User::class)
            ->where('email', 'update@test.com')
            ->first();
        $this->assertEquals('NewName', $found->name);
    }

    public function testQueryToSql(): void
    {
        $sql = $this->em->query(User::class)
            ->where('age', '>', 18)
            ->orderBy('name')
            ->limit(10)
            ->toSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT', $sql);
    }

    // ─── Relations ────────────────────────────────────────────────

    public function testEagerLoadHasMany(): void
    {
        $user = new User();
        $user->name = 'Author';
        $user->email = 'author@test.com';
        $this->em->persist($user);
        $this->em->flush();

        for ($i = 0; $i < 3; $i++) {
            $post = new Post();
            $post->title = "Post {$i}";
            $post->body = "Body {$i}";
            $post->userId = $user->id;
            $this->em->persist($post);
        }
        $this->em->flush();
        $this->em->clear();

        $users = $this->em->query(User::class)
            ->with('posts')
            ->get();

        $this->assertCount(1, $users);
        $this->assertCount(3, $users[0]->posts);
        $this->assertInstanceOf(Post::class, $users[0]->posts[0]);
    }

    public function testEagerLoadBelongsTo(): void
    {
        $user = new User();
        $user->name = 'Owner';
        $user->email = 'owner@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $post = new Post();
        $post->title = 'My Post';
        $post->body = 'Content';
        $post->userId = $user->id;
        $this->em->persist($post);
        $this->em->flush();
        $this->em->clear();

        $posts = $this->em->query(Post::class)
            ->with('author')
            ->get();

        $this->assertCount(1, $posts);
        $this->assertNotNull($posts[0]->author);
        $this->assertEquals('Owner', $posts[0]->author->name);
    }

    // ─── Schema ───────────────────────────────────────────────────

    public function testCreateAndDropTable(): void
    {
        $this->em->dropTable(Category::class);
        $this->em->createTable(Category::class);

        $cat = new Category();
        $cat->name = 'Test';
        $cat->description = 'Category description';

        // Insert raw to verify table exists
        $this->em->getConnection()->insert(
            "INSERT INTO categories (name, description) VALUES (:n, :d)",
            ['n' => 'Test', 'd' => 'Desc']
        );

        $rows = $this->em->raw("SELECT * FROM categories");
        $this->assertCount(1, $rows);
    }

    // ─── Query Count ──────────────────────────────────────────────

    public function testQueryCountTracking(): void
    {
        $this->em->getConnection()->resetQueryCount();

        $user = new User();
        $user->name = 'QC';
        $user->email = 'qc@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $this->em->clear();
        $this->em->find(User::class, $user->id);

        $count = $this->em->getQueryCount();
        $this->assertGreaterThan(0, $count);
    }

    // ─── Aggregates ───────────────────────────────────────────────

    public function testAggregates(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $u = new User();
            $u->name = "Agg{$i}";
            $u->email = "agg{$i}@test.com";
            $u->age = $i * 10; // 10, 20, 30, 40
            $this->em->persist($u);
        }
        $this->em->flush();

        $sum = $this->em->query(User::class)->sum('age');
        $this->assertEquals(100.0, $sum);

        $avg = $this->em->query(User::class)->avg('age');
        $this->assertEquals(25.0, $avg);

        $max = $this->em->query(User::class)->max('age');
        $this->assertEquals(40, $max);

        $min = $this->em->query(User::class)->min('age');
        $this->assertEquals(10, $min);
    }
}
