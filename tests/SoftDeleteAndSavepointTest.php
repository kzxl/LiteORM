<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use PHPUnit\Framework\TestCase;
use LiteORM\EntityManager;

class SoftDeleteAndSavepointTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager('sqlite::memory:');
        $this->em->createTable(SoftArticle::class);
    }

    public function testSoftDeleteFiltersByDefault(): void
    {
        $a1 = new SoftArticle();
        $a1->title = 'Article 1';

        $a2 = new SoftArticle();
        $a2->title = 'Article 2';

        $this->em->persist($a1);
        $this->em->persist($a2);
        $this->em->flush();

        $this->assertCount(2, $this->em->findAll(SoftArticle::class));

        // Soft delete a1
        $this->em->remove($a1);
        $this->em->flush();

        // Standard find and findAll should not return a1
        $this->assertNull($this->em->find(SoftArticle::class, $a1->id));
        $all = $this->em->findAll(SoftArticle::class);
        $this->assertCount(1, $all);
        $this->assertSame('Article 2', $all[0]->title);

        // QueryBuilder default should exclude a1
        $qbResults = $this->em->createQueryBuilder(SoftArticle::class)->get();
        $this->assertCount(1, $qbResults);

        // withTrashed() should return both
        $withAll = $this->em->createQueryBuilder(SoftArticle::class)->withTrashed()->get();
        $this->assertCount(2, $withAll);

        // onlyTrashed() should return only a1
        $trashed = $this->em->createQueryBuilder(SoftArticle::class)->onlyTrashed()->get();
        $this->assertCount(1, $trashed);
        $this->assertSame($a1->id, $trashed[0]->id);
    }

    public function testRestoreSoftDeletedEntity(): void
    {
        $a = new SoftArticle();
        $a->title = 'Restorable Article';
        $this->em->persist($a);
        $this->em->flush();

        $this->em->remove($a);
        $this->em->flush();
        $this->assertNull($this->em->find(SoftArticle::class, $a->id));

        // Restore
        $this->em->restore($a);

        $found = $this->em->find(SoftArticle::class, $a->id);
        $this->assertNotNull($found);
        $this->assertSame('Restorable Article', $found->title);
    }

    public function testForceDeletePhysicallyRemovesRow(): void
    {
        $a = new SoftArticle();
        $a->title = 'Permanently Deleted';
        $this->em->persist($a);
        $this->em->flush();

        // Force delete
        $this->em->forceDelete($a);

        // Even withTrashed should not find it
        $found = $this->em->createQueryBuilder(SoftArticle::class)->withTrashed()->where('id', $a->id)->first();
        $this->assertNull($found);
    }

    public function testSavepointsInsideTransaction(): void
    {
        $this->em->getConnection()->beginTransaction();

        $a1 = new SoftArticle();
        $a1->title = 'Outer Tx';
        $this->em->persist($a1);
        $this->em->flush();

        // Create savepoint
        $this->em->savepoint('sp1');

        $a2 = new SoftArticle();
        $a2->title = 'Inside Savepoint';
        $this->em->persist($a2);
        $this->em->flush();

        $this->assertCount(2, $this->em->findAll(SoftArticle::class));

        // Rollback savepoint
        $this->em->rollbackToSavepoint('sp1');
        $this->em->clear();

        // Commit outer tx
        $this->em->getConnection()->commit();

        $remaining = $this->em->findAll(SoftArticle::class);
        $this->assertCount(1, $remaining);
        $this->assertSame('Outer Tx', $remaining[0]->title);
    }
}
