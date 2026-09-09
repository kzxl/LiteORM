<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use PHPUnit\Framework\TestCase;
use LiteORM\EntityManager;
use LiteORM\Query\Paginator;

class AgentOptionStandardsTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager('sqlite::memory:');
        $this->em->createTable(User::class);

        // Seed test data: 5 users
        for ($i = 1; $i <= 5; $i++) {
            $user = new User();
            $user->name = "User {$i}";
            $user->email = "user{$i}@example.com";
            $user->age = 20 + $i;
            $this->em->persist($user);
        }
        $this->em->flush();
    }

    // ─── 1. Memory Optimization: Generator Cursor Tests ──────────────

    public function testCursorReturnsGeneratorAndStreamsAllEntities(): void
    {
        $generator = $this->em->query(User::class)->orderBy('id', 'ASC')->cursor();

        $this->assertInstanceOf(\Generator::class, $generator);

        $count = 0;
        $names = [];
        foreach ($generator as $index => $user) {
            $this->assertSame($count, $index);
            $this->assertInstanceOf(User::class, $user);
            $names[] = $user->name;
            $count++;
        }

        $this->assertSame(5, $count);
        $this->assertSame(['User 1', 'User 2', 'User 3', 'User 4', 'User 5'], $names);
    }

    public function testChunkProcessesInBatches(): void
    {
        $batches = [];
        $pages = [];

        $success = $this->em->query(User::class)
            ->orderBy('id', 'ASC')
            ->chunk(2, function (array $users, int $page) use (&$batches, &$pages) {
                $batches[] = count($users);
                $pages[] = $page;
            });

        $this->assertTrue($success);
        $this->assertSame([2, 2, 1], $batches); // 5 users in chunks of 2: 2, 2, 1
        $this->assertSame([1, 2, 3], $pages);
    }

    public function testChunkStopsWhenCallbackReturnsFalse(): void
    {
        $batches = [];

        $success = $this->em->query(User::class)
            ->orderBy('id', 'ASC')
            ->chunk(2, function (array $users, int $page) use (&$batches) {
                $batches[] = count($users);
                if ($page === 1) {
                    return false; // Stop after first batch
                }
            });

        $this->assertFalse($success);
        $this->assertSame([2], $batches);
    }

    // ─── 2. Pagination Tests ─────────────────────────────────────────

    public function testPaginateFirstPage(): void
    {
        $paginator = $this->em->query(User::class)
            ->orderBy('id', 'ASC')
            ->paginate(page: 1, perPage: 2);

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertSame(5, $paginator->total);
        $this->assertSame(1, $paginator->currentPage);
        $this->assertSame(2, $paginator->perPage);
        $this->assertSame(3, $paginator->lastPage);
        $this->assertTrue($paginator->hasMorePages());
        $this->assertCount(2, $paginator->items);
        $this->assertSame('User 1', $paginator->items[0]->name);
        $this->assertSame('User 2', $paginator->items[1]->name);
    }

    public function testPaginateLastPage(): void
    {
        $paginator = $this->em->query(User::class)
            ->orderBy('id', 'ASC')
            ->paginate(page: 3, perPage: 2);

        $this->assertSame(3, $paginator->currentPage);
        $this->assertFalse($paginator->hasMorePages());
        $this->assertCount(1, $paginator->items);
        $this->assertSame('User 5', $paginator->items[0]->name);
    }

    public function testPaginatorJsonSerialization(): void
    {
        $paginator = $this->em->query(User::class)
            ->orderBy('id', 'ASC')
            ->paginate(page: 1, perPage: 2);

        $array = $paginator->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('meta', $array);
        $this->assertSame(5, $array['meta']['total']);
        $this->assertSame(1, $array['meta']['current_page']);
        $this->assertSame(2, $array['meta']['per_page']);
        $this->assertSame(3, $array['meta']['last_page']);
        $this->assertTrue($array['meta']['has_more']);

        $json = json_encode($paginator);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertSame(5, $decoded['meta']['total']);
    }

    // ─── 3. Database Transactions Tests ──────────────────────────────

    public function testTransactionCommitsSuccessfully(): void
    {
        $result = $this->em->transaction(function (EntityManager $em) {
            $user = new User();
            $user->name = 'Transaction User';
            $user->email = 'tx@example.com';
            $user->age = 30;
            $em->persist($user);
            return 'success_payload';
        });

        $this->assertSame('success_payload', $result);

        $user = $this->em->query(User::class)->where('email', 'tx@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Transaction User', $user->name);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $exceptionThrown = false;

        try {
            $this->em->transaction(function (EntityManager $em) {
                $user = new User();
                $user->name = 'Rolled Back User';
                $user->email = 'rollback@example.com';
                $user->age = 30;
                $em->persist($user);
                $em->flush();

                throw new \RuntimeException('Intentional transaction failure');
            });
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertSame('Intentional transaction failure', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown);

        // Verify entity was NOT committed
        $user = $this->em->query(User::class)->where('email', 'rollback@example.com')->first();
        $this->assertNull($user);
    }
}
