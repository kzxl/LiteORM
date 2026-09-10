<?php

declare(strict_types=1);

namespace LiteORM\Tests;

use LiteORM\Attribute\{AutoIncrement, BelongsTo, Column, Entity, HasMany, HasOne, Id, Table};
use LiteORM\EntityManager;
use PHPUnit\Framework\TestCase;

#[Entity, Table('test_customers')]
class TestCustomer
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column]
    public string $name;
}

#[Entity, Table('test_orders')]
class TestOrder
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(name: 'customer_id')]
    public int $customerId;

    #[Column]
    public string $orderNumber;

    #[BelongsTo(target: TestCustomer::class, foreignKey: 'customer_id')]
    public ?TestCustomer $customer = null;

    /** @var TestOrderItem[] */
    #[HasMany(target: TestOrderItem::class, foreignKey: 'order_id', orderBy: 'id ASC')]
    public array $items = [];

    #[HasOne(target: TestInvoice::class, foreignKey: 'order_id')]
    public ?TestInvoice $invoice = null;
}

#[Entity, Table('test_order_items')]
class TestOrderItem
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(name: 'order_id')]
    public int $orderId;

    #[Column]
    public string $product;

    #[Column]
    public float $price;
}

#[Entity, Table('test_invoices')]
class TestInvoice
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(name: 'order_id')]
    public int $orderId;

    #[Column(name: 'invoice_no')]
    public string $invoiceNo;
}

final class RelationEagerLoadingTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager('sqlite::memory:');

        // Create schema
        $this->em->createTable(TestCustomer::class);
        $this->em->createTable(TestOrder::class);
        $this->em->createTable(TestOrderItem::class);
        $this->em->createTable(TestInvoice::class);

        // Seed customer
        $c1 = new TestCustomer();
        $c1->name = 'Công ty ABC';
        $this->em->persist($c1);

        $c2 = new TestCustomer();
        $c2->name = 'Tập đoàn XYZ';
        $this->em->persist($c2);
        $this->em->flush();

        // Seed orders
        $o1 = new TestOrder();
        $o1->customerId = $c1->id;
        $o1->orderNumber = 'ORD-001';
        $this->em->persist($o1);

        $o2 = new TestOrder();
        $o2->customerId = $c2->id;
        $o2->orderNumber = 'ORD-002';
        $this->em->persist($o2);
        $this->em->flush();

        // Seed order items
        $i1 = new TestOrderItem();
        $i1->orderId = $o1->id;
        $i1->product = 'Bàn phím cơ';
        $i1->price = 150.0;
        $this->em->persist($i1);

        $i2 = new TestOrderItem();
        $i2->orderId = $o1->id;
        $i2->product = 'Chuột không dây';
        $i2->price = 50.0;
        $this->em->persist($i2);

        $i3 = new TestOrderItem();
        $i3->orderId = $o2->id;
        $i3->product = 'Màn hình 4K';
        $i3->price = 450.0;
        $this->em->persist($i3);

        // Seed invoices
        $inv1 = new TestInvoice();
        $inv1->orderId = $o1->id;
        $inv1->invoiceNo = 'INV-2026-001';
        $this->em->persist($inv1);

        $inv2 = new TestInvoice();
        $inv2->orderId = $o2->id;
        $inv2->invoiceNo = 'INV-2026-002';
        $this->em->persist($inv2);

        $this->em->flush();
    }

    public function testEagerLoadHasManyItems(): void
    {
        /** @var TestOrder[] $orders */
        $orders = $this->em->query(TestOrder::class)
            ->with('items')
            ->orderBy('id', 'ASC')
            ->get();

        $this->assertCount(2, $orders);

        // Order 1 has 2 items
        $this->assertCount(2, $orders[0]->items);
        $this->assertEquals('Bàn phím cơ', $orders[0]->items[0]->product);
        $this->assertEquals('Chuột không dây', $orders[0]->items[1]->product);

        // Order 2 has 1 item
        $this->assertCount(1, $orders[1]->items);
        $this->assertEquals('Màn hình 4K', $orders[1]->items[0]->product);
    }

    public function testEagerLoadBelongsToCustomer(): void
    {
        /** @var TestOrder[] $orders */
        $orders = $this->em->query(TestOrder::class)
            ->with('customer')
            ->orderBy('id', 'ASC')
            ->get();

        $this->assertCount(2, $orders);
        $this->assertNotNull($orders[0]->customer);
        $this->assertEquals('Công ty ABC', $orders[0]->customer->name);

        $this->assertNotNull($orders[1]->customer);
        $this->assertEquals('Tập đoàn XYZ', $orders[1]->customer->name);
    }

    public function testEagerLoadHasOneInvoice(): void
    {
        /** @var TestOrder[] $orders */
        $orders = $this->em->query(TestOrder::class)
            ->with('invoice')
            ->orderBy('id', 'ASC')
            ->get();

        $this->assertCount(2, $orders);
        $this->assertNotNull($orders[0]->invoice);
        $this->assertEquals('INV-2026-001', $orders[0]->invoice->invoiceNo);

        $this->assertNotNull($orders[1]->invoice);
        $this->assertEquals('INV-2026-002', $orders[1]->invoice->invoiceNo);
    }

    public function testEagerLoadAllRelationsTogether(): void
    {
        /** @var TestOrder $firstOrder */
        $firstOrder = $this->em->query(TestOrder::class)
            ->with('items', 'customer', 'invoice')
            ->where('order_number', 'ORD-001')
            ->first();

        $this->assertNotNull($firstOrder);
        $this->assertEquals('ORD-001', $firstOrder->orderNumber);

        // BelongsTo
        $this->assertNotNull($firstOrder->customer);
        $this->assertEquals('Công ty ABC', $firstOrder->customer->name);

        // HasMany
        $this->assertCount(2, $firstOrder->items);

        // HasOne
        $this->assertNotNull($firstOrder->invoice);
        $this->assertEquals('INV-2026-001', $firstOrder->invoice->invoiceNo);
    }

    public function testEagerLoadWithPagination(): void
    {
        $paginator = $this->em->query(TestOrder::class)
            ->with('items', 'customer')
            ->paginate(page: 1, perPage: 1);

        $this->assertEquals(2, $paginator->total);
        $this->assertCount(1, $paginator->items);

        /** @var TestOrder $order */
        $order = $paginator->items[0];
        $this->assertNotNull($order->customer);
        $this->assertCount(2, $order->items);
    }
}
