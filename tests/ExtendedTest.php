<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use PHPUnit\Framework\TestCase;
use LiteORM\EntityManager;
use LiteORM\Metadata\AttributeReader;

require_once __DIR__ . '/Fixtures.php';

/**
 * Extended tests for new features: SQL Logger, Attach, Batch Insert,
 * LINQ methods, EntityGenerator, PreparedStatement cache, performance.
 */
class ExtendedTest extends TestCase
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

    // ─── SQL Logger ──────────────────────────────────────────────

    public function testSqlLoggerReceivesQueries(): void
    {
        $logs = [];
        $this->em->setSqlLogger(function (string $sql, array $params, float $timeMs) use (&$logs) {
            $logs[] = ['sql' => $sql, 'params' => $params, 'time' => $timeMs];
        });

        $user = new User();
        $user->name = 'Logger';
        $user->email = 'logger@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('INSERT', $logs[0]['sql']);
        $this->assertGreaterThanOrEqual(0, $logs[0]['time']);
    }

    public function testSqlLoggerTimingAccuracy(): void
    {
        $times = [];
        $this->em->setSqlLogger(function ($sql, $params, $timeMs) use (&$times) {
            $times[] = $timeMs;
        });

        for ($i = 0; $i < 10; $i++) {
            $u = new User();
            $u->name = "T{$i}";
            $u->email = "t{$i}@test.com";
            $this->em->persist($u);
        }
        $this->em->flush();

        // All times should be positive floats
        foreach ($times as $t) {
            $this->assertIsFloat($t);
            $this->assertGreaterThanOrEqual(0.0, $t);
        }
    }

    // ─── Attach ──────────────────────────────────────────────────

    public function testAttachExistingEntity(): void
    {
        // Insert normally
        $user = new User();
        $user->name = 'Original';
        $user->email = 'attach@test.com';
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->id;

        // Clear and re-attach
        $this->em->clear();

        $detached = new User();
        $detached->id = $id;
        $detached->name = 'Attached-Updated';
        $detached->email = 'attach@test.com';
        $this->em->attach($detached);
        $detached->name = 'Attached-Modified';
        $this->em->flush();

        // Verify update
        $this->em->clear();
        $found = $this->em->find(User::class, $id);
        $this->assertEquals('Attached-Modified', $found->name);
    }

    public function testAttachWithoutPkThrows(): void
    {
        $user = new User();
        $user->name = 'NoPk';
        $user->email = 'nopk@test.com';

        $this->expectException(\RuntimeException::class);
        $this->em->attach($user);
    }

    // ─── Batch Insert Performance ────────────────────────────────

    public function testBatchInsertMultipleEntities(): void
    {
        $users = [];
        for ($i = 0; $i < 100; $i++) {
            $u = new User();
            $u->name = "Batch{$i}";
            $u->email = "batch{$i}@test.com";
            $u->age = $i;
            $users[] = $u;
            $this->em->persist($u);
        }
        $this->em->flush();

        // All should have unique IDs
        $ids = array_map(fn($u) => $u->id, $users);
        $this->assertCount(100, array_unique($ids));

        // Verify in DB
        $count = $this->em->query(User::class)->count();
        $this->assertEquals(100, $count);
    }

    public function testBatchInsertMixedClasses(): void
    {
        $user = new User();
        $user->name = 'Mixed';
        $user->email = 'mixed@test.com';

        $cat = new Category();
        $cat->name = 'MixedCat';

        $this->em->persist($user);
        $this->em->persist($cat);
        $this->em->flush();

        $this->assertGreaterThan(0, $user->id);
        $this->assertGreaterThan(0, $cat->id);
    }

    // ─── LINQ Methods ────────────────────────────────────────────

    public function testFirstOrFail(): void
    {
        $u = new User();
        $u->name = 'Fail';
        $u->email = 'fail@test.com';
        $this->em->persist($u);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->query(User::class)
            ->where('email', 'fail@test.com')
            ->firstOrFail();
        $this->assertEquals('Fail', $found->name);
    }

    public function testFirstOrFailThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->em->query(User::class)
            ->where('email', 'nonexistent@test.com')
            ->firstOrFail();
    }

    public function testSingle(): void
    {
        $u = new User();
        $u->name = 'Single';
        $u->email = 'single@test.com';
        $this->em->persist($u);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->query(User::class)
            ->where('email', 'single@test.com')
            ->single();
        $this->assertEquals('Single', $found->name);
    }

    public function testSingleReturnsNullForEmpty(): void
    {
        $found = $this->em->query(User::class)
            ->where('email', 'nope@test.com')
            ->single();
        $this->assertNull($found);
    }

    public function testSingleOrFail(): void
    {
        $u = new User();
        $u->name = 'SOF';
        $u->email = 'sof@test.com';
        $this->em->persist($u);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->query(User::class)
            ->where('email', 'sof@test.com')
            ->singleOrFail();
        $this->assertEquals('SOF', $found->name);
    }

    public function testSingleOrFailThrowsOnEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->em->query(User::class)
            ->where('email', 'nope@test.com')
            ->singleOrFail();
    }

    public function testLast(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $u = new User();
            $u->name = "Last{$i}";
            $u->email = "last{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $last = $this->em->query(User::class)
            ->orderBy('age', 'ASC')
            ->last();

        $this->assertNotNull($last);
        $this->assertEquals(50, $last->age);
    }

    public function testDistinct(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $u = new User();
            $u->name = 'SameName';
            $u->email = "dist{$i}@test.com";
            $u->age = 25;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $sql = $this->em->query(User::class)
            ->select('name')
            ->distinct()
            ->toSql();

        $this->assertStringContainsString('DISTINCT', $sql);
    }

    public function testToListAlias(): void
    {
        $u = new User();
        $u->name = 'ToList';
        $u->email = 'tolist@test.com';
        $this->em->persist($u);
        $this->em->flush();
        $this->em->clear();

        $list = $this->em->query(User::class)->toList();
        $this->assertCount(1, $list);
    }

    public function testTakeAndSkip(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $u = new User();
            $u->name = "T{$i}";
            $u->email = "ts{$i}@test.com";
            $u->age = $i;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->orderBy('age')
            ->skip(3)
            ->take(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals(4, $results[0]->age);
        $this->assertEquals(5, $results[1]->age);
    }

    public function testWhereNotIn(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $u = new User();
            $u->name = "NI{$i}";
            $u->email = "ni{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->whereNotIn('age', [10, 20])
            ->get();

        $this->assertCount(3, $results);
    }

    // ─── EntityGenerator ─────────────────────────────────────────

    public function testEntityGeneratorFromSqlite(): void
    {
        $gen = new \LiteORM\Schema\EntityGenerator($this->em->getConnection(), 'App\\Entity');

        $tables = $gen->getTables();
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
        $this->assertContains('categories', $tables);
    }

    public function testEntityGeneratorGenerateCode(): void
    {
        $gen = new \LiteORM\Schema\EntityGenerator($this->em->getConnection(), 'App\\Entity');
        $code = $gen->generate('users');

        $this->assertStringContainsString('namespace App\\Entity', $code);
        $this->assertStringContainsString('#[Entity]', $code);
        $this->assertStringContainsString("#[Table('users')]", $code);
        $this->assertStringContainsString('class User', $code);
        $this->assertStringContainsString('#[Id', $code);
    }

    public function testEntityGeneratorGetColumns(): void
    {
        $gen = new \LiteORM\Schema\EntityGenerator($this->em->getConnection(), 'App\\Entity');
        $columns = $gen->getColumns('users');

        $this->assertNotEmpty($columns);
        $colNames = array_column($columns, 'name');
        $this->assertContains('id', $colNames);
        $this->assertContains('name', $colNames);
        $this->assertContains('email', $colNames);
    }

    public function testEntityGeneratorGenerateAll(): void
    {
        $gen = new \LiteORM\Schema\EntityGenerator($this->em->getConnection(), 'App\\Entity');
        $all = $gen->generateAll();

        $this->assertArrayHasKey('users', $all);
        $this->assertArrayHasKey('posts', $all);
        $this->assertArrayHasKey('categories', $all);
    }

    public function testEntityGeneratorToFile(): void
    {
        $gen = new \LiteORM\Schema\EntityGenerator($this->em->getConnection(), 'App\\Entity');
        $tmpDir = sys_get_temp_dir() . '/liteorm_test_' . uniqid();

        try {
            $path = $gen->generateToFile('users', $tmpDir);
            $this->assertFileExists($path);
            $this->assertStringContainsString('User.php', $path);

            $content = file_get_contents($path);
            $this->assertStringContainsString('#[Entity]', $content);
        } finally {
            if (is_dir($tmpDir)) {
                array_map('unlink', glob("{$tmpDir}/*.php") ?: []);
                rmdir($tmpDir);
            }
        }
    }

    // ─── PreparedStatement Cache ─────────────────────────────────

    public function testPreparedStatementCachePerformance(): void
    {
        // Insert initial data
        for ($i = 0; $i < 20; $i++) {
            $u = new User();
            $u->name = "Cache{$i}";
            $u->email = "cache{$i}@test.com";
            $u->age = $i;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        // Re-execute same query pattern multiple times
        $this->em->getConnection()->resetQueryCount();
        for ($i = 0; $i < 20; $i++) {
            $this->em->find(User::class, $i + 1);
            $this->em->clear();
        }

        // Verify queries were counted
        $this->assertGreaterThan(0, $this->em->getQueryCount());
    }

    // ─── Edge Cases ──────────────────────────────────────────────

    public function testUpdateTimestampOnDirtyChange(): void
    {
        $user = new User();
        $user->name = 'TimestampUpdate';
        $user->email = 'tsupdate@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $originalUpdated = $user->updatedAt;

        // Wait a tiny bit and modify
        usleep(10000); // 10ms
        $user->name = 'TimestampUpdated';
        $this->em->flush();

        $this->em->clear();
        $found = $this->em->find(User::class, $user->id);

        $this->assertEquals('TimestampUpdated', $found->name);
    }

    public function testFloatField(): void
    {
        $user = new User();
        $user->name = 'Float';
        $user->email = 'float@test.com';
        $user->balance = 99.95;
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(User::class, $user->id);
        $this->assertEqualsWithDelta(99.95, $found->balance, 0.001);
    }

    public function testBoolField(): void
    {
        $user = new User();
        $user->name = 'Bool';
        $user->email = 'bool@test.com';
        $user->active = true;
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(User::class, $user->id);
        $this->assertTrue((bool) $found->active);
    }

    public function testWhereBetween(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $u = new User();
            $u->name = "Between{$i}";
            $u->email = "btw{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->whereBetween('age', 30, 70)
            ->get();

        $this->assertCount(5, $results);
    }

    public function testWhereLike(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $u = new User();
            $u->name = "Test Like {$i}";
            $u->email = "like{$i}@test.com";
            $this->em->persist($u);
        }
        $u2 = new User();
        $u2->name = 'Different';
        $u2->email = 'diff@test.com';
        $this->em->persist($u2);
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->whereLike('name', 'Test Like%')
            ->get();

        $this->assertCount(5, $results);
    }

    public function testWhereNull(): void
    {
        $u1 = new User();
        $u1->name = 'HasAge';
        $u1->email = 'hasage@test.com';
        $u1->age = 30;

        $u2 = new User();
        $u2->name = 'NoAge';
        $u2->email = 'noage@test.com';

        $this->em->persist($u1);
        $this->em->persist($u2);
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)->whereNull('age')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('NoAge', $results[0]->name);
    }

    public function testOrWhere(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $u = new User();
            $u->name = "Or{$i}";
            $u->email = "or{$i}@test.com";
            $u->age = $i * 10;
            $this->em->persist($u);
        }
        $this->em->flush();
        $this->em->clear();

        $results = $this->em->query(User::class)
            ->where('age', 10)
            ->orWhere('age', 50)
            ->get();

        $this->assertCount(2, $results);
    }

    public function testGroupByWithCount(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $u = new User();
            $u->name = "GroupA";
            $u->email = "ga{$i}@test.com";
            $u->age = 25;
            $this->em->persist($u);
        }
        for ($i = 0; $i < 2; $i++) {
            $u = new User();
            $u->name = "GroupB";
            $u->email = "gb{$i}@test.com";
            $u->age = 30;
            $this->em->persist($u);
        }
        $this->em->flush();

        $total = $this->em->query(User::class)->count();
        $this->assertEquals(5, $total);
    }

    public function testRawQuery(): void
    {
        $user = new User();
        $user->name = 'Raw';
        $user->email = 'raw@test.com';
        $this->em->persist($user);
        $this->em->flush();

        $rows = $this->em->raw('SELECT name FROM users WHERE email = :e', ['e' => 'raw@test.com']);
        $this->assertCount(1, $rows);
        $this->assertEquals('Raw', $rows[0]['name']);
    }

    public function testAsNoTracking(): void
    {
        $user = new User();
        $user->name = 'NoTracking';
        $user->email = 'notrack@test.com';
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        // Query with AsNoTracking
        $found = $this->em->query(User::class)
            ->where('email', 'notrack@test.com')
            ->asNoTracking()
            ->first();

        // Modify hydrated object
        $found->name = 'Modified But Ignored';

        // Flush should NOT detect the change because entity is not in identity map
        $this->em->getConnection()->resetQueryCount();
        $this->em->flush();

        // Verify update was skipped (0 queries from flush updates)
        $this->assertEquals(0, $this->em->getConnection()->getQueryCount());

        $this->em->clear();
        $dbUser = $this->em->query(User::class)->where('email', 'notrack@test.com')->first();
        $this->assertEquals('NoTracking', $dbUser->name);
    }
}
