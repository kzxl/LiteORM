<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use PHPUnit\Framework\TestCase;
use LiteORM\Metadata\AttributeReader;

require_once __DIR__ . '/Fixtures.php';

class MetadataTest extends TestCase
{
    protected function setUp(): void
    {
        AttributeReader::clearCache();
    }

    public function testReadUserEntity(): void
    {
        $meta = AttributeReader::read(User::class);

        $this->assertEquals('users', $meta->tableName);
        $this->assertEquals('id', $meta->primaryKey);
        $this->assertEquals('id', $meta->primaryKeyColumn);
        $this->assertTrue($meta->hasAutoIncrement);
        $this->assertNotNull($meta->createdAtColumn);
        $this->assertNotNull($meta->updatedAtColumn);
    }

    public function testColumnMapping(): void
    {
        $meta = AttributeReader::read(User::class);

        $nameCol = $meta->getColumnByProperty('name');
        $this->assertNotNull($nameCol);
        $this->assertEquals('name', $nameCol->columnName);
        $this->assertEquals(100, $nameCol->length);
        $this->assertFalse($nameCol->nullable);

        $emailCol = $meta->getColumnByProperty('email');
        $this->assertTrue($emailCol->unique);

        $ageCol = $meta->getColumnByProperty('age');
        $this->assertTrue($ageCol->nullable);
        $this->assertEquals('int', $ageCol->phpType);
    }

    public function testRelationDetection(): void
    {
        $meta = AttributeReader::read(User::class);

        $this->assertCount(1, $meta->relations);
        $rel = $meta->relations[0];
        $this->assertEquals('posts', $rel->propertyName);
        $this->assertEquals('hasMany', $rel->type);
        $this->assertEquals(Post::class, $rel->target);
        $this->assertEquals('user_id', $rel->foreignKey);
    }

    public function testBelongsToRelation(): void
    {
        $meta = AttributeReader::read(Post::class);

        $rel = null;
        foreach ($meta->relations as $r) {
            if ($r->propertyName === 'author') $rel = $r;
        }

        $this->assertNotNull($rel);
        $this->assertEquals('belongsTo', $rel->type);
        $this->assertEquals(User::class, $rel->target);
    }

    public function testAutoTableName(): void
    {
        // Category → categories (y → ies pluralization)
        $meta = AttributeReader::read(Category::class);
        $this->assertEquals('categories', $meta->tableName);
    }

    public function testExplicitColumnName(): void
    {
        $meta = AttributeReader::read(Post::class);
        $col = $meta->getColumnByProperty('userId');
        $this->assertNotNull($col);
        $this->assertEquals('user_id', $col->columnName);
    }

    public function testInsertColumns(): void
    {
        $meta = AttributeReader::read(User::class);
        $insertCols = $meta->getInsertColumns();

        // Should NOT include 'id' (auto-increment)
        $this->assertNotContains('id', $insertCols);
        $this->assertContains('name', $insertCols);
        $this->assertContains('email', $insertCols);
    }

    public function testMetadataCache(): void
    {
        $meta1 = AttributeReader::read(User::class);
        $meta2 = AttributeReader::read(User::class);

        // Same instance from cache
        $this->assertSame($meta1, $meta2);
    }

    public function testMissingEntityAttribute(): void
    {
        $this->expectException(\RuntimeException::class);
        AttributeReader::read(\stdClass::class);
    }
}
