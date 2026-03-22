<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use LiteORM\Attribute\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt, HasMany, BelongsTo};

// ─── Test Entities ────────────────────────────────────────────

#[Entity]
#[Table('users')]
class User
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(length: 100)]
    public string $name;

    #[Column(unique: true)]
    public string $email;

    #[Column(nullable: true)]
    public ?int $age = null;

    #[Column(nullable: true)]
    public ?float $balance = null;

    #[Column(nullable: true)]
    public ?bool $active = null;

    #[CreatedAt]
    public ?\DateTimeImmutable $createdAt = null;

    #[UpdatedAt]
    public ?\DateTimeImmutable $updatedAt = null;

    #[HasMany(target: Post::class, foreignKey: 'user_id')]
    public array $posts = [];
}

#[Entity]
#[Table('posts')]
class Post
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(length: 200)]
    public string $title;

    #[Column(type: 'TEXT')]
    public string $body;

    #[Column(name: 'user_id')]
    public int $userId;

    #[BelongsTo(target: User::class, foreignKey: 'user_id')]
    public ?User $author = null;
}

#[Entity]
class Category
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column]
    public string $name;

    #[Column(nullable: true)]
    public ?string $description = null;
}
