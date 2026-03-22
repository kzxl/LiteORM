# LiteORM 🔮

Lightweight, high-performance PHP 8.2+ ORM with native attribute mapping, LINQ-style query builder, and automatic entity generation from database.

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache_2.0-green.svg)](LICENSE)

---

## ✨ Features

| Feature | Description |
|---------|------------|
| **PHP 8.2 Attributes** | `#[Entity]`, `#[Column]`, `#[Id]`, `#[HasMany]`... — zero config |
| **LINQ-Style QueryBuilder** | `where()`, `firstOrFail()`, `single()`, `distinct()`, `toList()` |
| **Unit of Work** | Batch INSERT/UPDATE/DELETE in single transaction |
| **Dirty Checking** | Only UPDATE changed fields (snapshot pattern) |
| **Identity Map** | Same entity = same instance, no duplicate queries |
| **Eager Loading** | `with('posts')` — batch queries, avoid N+1 |
| **Read/Write Splitting** | Auto-route SELECT to read replicas |
| **Entity Generator** | Reverse-engineer DB tables → PHP entity classes |
| **Auto Timestamps** | `#[CreatedAt]`, `#[UpdatedAt]` auto-managed |
| **Aggregates** | `count()`, `sum()`, `avg()`, `max()`, `min()` |

---

## 📦 Installation

```bash
composer require kzxl/liteorm
```

## 🚀 Quick Start

### 1. Define Entity

```php
use LiteORM\Attribute\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt, HasMany};

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

    #[CreatedAt]
    public ?\DateTimeImmutable $createdAt = null;

    #[UpdatedAt]
    public ?\DateTimeImmutable $updatedAt = null;

    #[HasMany(target: Post::class, foreignKey: 'user_id')]
    public array $posts = [];
}
```

### 2. CRUD Operations

```php
$em = new EntityManager('mysql:host=localhost;dbname=myapp', 'root', 'pass');

// Create
$user = new User();
$user->name = 'John';
$user->email = 'john@example.com';
$em->persist($user);
$em->flush();  // INSERT + auto-set $user->id + timestamps

// Read
$user = $em->find(User::class, 1);

// Update (only dirty fields)
$user->name = 'Updated';
$em->flush();  // UPDATE users SET name='Updated' WHERE id=1

// Delete
$em->remove($user);
$em->flush();
```

### 3. LINQ-Style Queries

```php
// First (= FirstOrDefault)
$user = $em->query(User::class)
    ->where('email', 'john@example.com')
    ->first();  // returns null if not found

// FirstOrFail (= LINQ First)
$user = $em->query(User::class)
    ->where('id', 1)
    ->firstOrFail();  // throws RuntimeException if not found

// Single (= SingleOrDefault)
$user = $em->query(User::class)
    ->where('email', 'unique@test.com')
    ->single();  // throws if >1 result

// SingleOrFail (= LINQ Single)
$user = $em->query(User::class)
    ->where('id', 1)
    ->singleOrFail();  // throws if 0 or >1

// Complex queries
$users = $em->query(User::class)
    ->where('age', '>', 18)
    ->whereNotIn('name', ['Admin', 'System'])
    ->orderBy('name')
    ->distinct()
    ->take(10)           // alias for limit()
    ->skip(20)           // alias for offset()
    ->toList();          // alias for get()

// Last
$newest = $em->query(User::class)
    ->orderBy('created_at', 'ASC')
    ->last();  // auto-reverses to DESC, returns first

// Aggregates
$count = $em->query(User::class)->where('active', true)->count();
$avgAge = $em->query(User::class)->avg('age');
$maxAge = $em->query(User::class)->max('age');

// Exists / Any
if ($em->query(User::class)->where('email', $email)->exists()) { ... }

// Bulk operations
$em->query(User::class)->where('active', false)->delete();
$em->query(User::class)->where('role', 'guest')->update(['role' => 'user']);
```

### 4. Eager Loading (N+1 Prevention)

```php
// HasMany
$users = $em->query(User::class)
    ->with('posts')
    ->get();
// Only 2 queries: SELECT * FROM users + SELECT * FROM posts WHERE user_id IN (...)

// BelongsTo
$posts = $em->query(Post::class)
    ->with('author')
    ->get();
```

### 5. Read/Write Splitting

```php
$em = new EntityManager([
    'write' => 'mysql:host=master;dbname=app',
    'read'  => [
        'mysql:host=replica1;dbname=app',
        'mysql:host=replica2;dbname=app',
    ],
], 'user', 'pass');

// SELECT → auto-routed to replica (round-robin)
// INSERT/UPDATE/DELETE → master
```

### 6. Generate Entities from Database

```php
use LiteORM\Schema\EntityGenerator;

$gen = new EntityGenerator($em->getConnection(), 'App\\Entity');

// Generate single table
$code = $gen->generate('users');
echo $code;  // Full PHP entity class with attributes

// Generate to file
$gen->generateToFile('users', './src/Entity/');

// Generate ALL tables
$gen->generateAllToFiles('./src/Entity/');
```

---

## 📋 LINQ Mapping

| C# LINQ | LiteORM PHP | Note |
|---------|-------------|------|
| `Where(x => ...)` | `->where('col', '>', 10)` | Fluent, supports operator |
| `FirstOrDefault()` | `->first()` | Returns `null` |
| `First()` | `->firstOrFail()` | Throws |
| `SingleOrDefault()` | `->single()` | Throws if >1 |
| `Single()` | `->singleOrFail()` | Throws if ≠1 |
| `Last()` | `->last()` | Auto-reverse order |
| `Any()` | `->exists()` | Returns `bool` |
| `Count()` | `->count()` | |
| `Sum/Avg/Max/Min` | `->sum/avg/max/min()` | |
| `Take(n)` | `->take(n)` or `->limit(n)` | |
| `Skip(n)` | `->skip(n)` or `->offset(n)` | |
| `Distinct()` | `->distinct()` | |
| `ToList()` | `->toList()` or `->get()` | |
| `Contains()` | `->whereIn()` | |
| `OrderBy/Desc` | `->orderBy('col', 'DESC')` | |
| `GroupBy` | `->groupBy('col')` | |
| `Select()` | `->select('col1', 'col2')` | |

---

## 🏗️ Architecture

```
LiteORM/
├── src/
│   ├── Attribute/          # PHP 8.2 attributes (Entity, Column, Id, Relations...)
│   ├── Metadata/           # AttributeReader, EntityMetadata, ColumnMetadata
│   ├── Connection/         # ConnectionManager (R/W split, pooling)
│   ├── Query/              # QueryBuilder (LINQ-style fluent API)
│   ├── Schema/             # EntityGenerator (reverse DB → PHP)
│   └── EntityManager.php   # Main entry point (UoW, Identity Map)
├── tests/                  # PHPUnit test suite (34 tests)
├── composer.json
└── phpunit.xml
```

**Design Principles:**
- **Zero config** — PHP attributes replace XML/YAML mapping
- **Dirty checking** — snapshot pattern, only UPDATE changed fields
- **Identity map** — one entity instance per primary key
- **Batch eager loading** — `WHERE IN (...)` instead of N+1 queries
- **Type safety** — strict types, PHPDoc generics

---

## 🧪 Testing

```bash
composer install
vendor/bin/phpunit
```

---

## 📄 License

Apache License 2.0 — see [LICENSE](LICENSE).
