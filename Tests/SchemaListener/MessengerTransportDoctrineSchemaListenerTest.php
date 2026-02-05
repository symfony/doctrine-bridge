<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\SchemaListener;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerTransportDoctrineSchemaListener;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

class MessengerTransportDoctrineSchemaListenerTest extends TestCase
{
    public function testPostGenerateSchema()
    {
        $schema = new Schema();
        $dbalConnection = $this->createStub(Connection::class);
        $dbalConnection->method('getConfiguration')->willReturn(new Configuration());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('getConnection')
            ->willReturn($dbalConnection);
        $event = new GenerateSchemaEventArgs($entityManager, $schema);

        $doctrineTransport = $this->createMock(DoctrineTransport::class);
        $doctrineTransport->expects($this->once())
            ->method('configureSchema')
            ->with($schema, $dbalConnection, $this->callback(static fn () => true));
        $otherTransport = $this->createMock(TransportInterface::class);
        $otherTransport->expects($this->never())
            ->method($this->anything());

        $subscriber = new MessengerTransportDoctrineSchemaListener([$doctrineTransport, $otherTransport]);
        $subscriber->postGenerateSchema($event);
    }

    public function testOnSchemaCreateTable()
    {
        if (!class_exists(SchemaCreateTableEventArgs::class)) {
            self::markTestSkipped('This test requires DBAL < 4.');
        }

        $platform = $this->createMock(AbstractPlatform::class);
        $table = new Table('queue_table');
        $event = new SchemaCreateTableEventArgs($table, [], [], $platform);

        $otherTransport = $this->createMock(TransportInterface::class);
        $otherTransport->expects($this->never())
            ->method($this->anything());

        $doctrineTransport = $this->createMock(DoctrineTransport::class);
        $doctrineTransport->expects($this->once())
            ->method('getExtraSetupSqlForTable')
            ->with($table)
            ->willReturn(['ALTER TABLE pizza ADD COLUMN extra_cheese boolean']);

        // we use the platform to generate the full create table sql
        $platform->expects($this->once())
            ->method('getCreateTableSQL')
            ->with($table)
            ->willReturn('CREATE TABLE pizza (id integer NOT NULL)');

        $subscriber = new MessengerTransportDoctrineSchemaListener([$otherTransport, $doctrineTransport]);

        $subscriber->onSchemaCreateTable($event);
        $this->assertTrue($event->isDefaultPrevented());
        $this->assertSame([
            'CREATE TABLE pizza (id integer NOT NULL)',
            'ALTER TABLE pizza ADD COLUMN extra_cheese boolean',
        ], $event->getSql());
    }

    public function testOnSchemaCreateTableNoExtraSql()
    {
        if (!class_exists(SchemaCreateTableEventArgs::class)) {
            self::markTestSkipped('This test requires DBAL < 4.');
        }

        $platform = $this->createMock(AbstractPlatform::class);
        $table = new Table('queue_table');
        $event = new SchemaCreateTableEventArgs($table, [], [], $platform);

        $doctrineTransport = $this->createMock(DoctrineTransport::class);
        $doctrineTransport->expects($this->once())
            ->method('getExtraSetupSqlForTable')
            ->willReturn([]);

        $platform->expects($this->never())
            ->method('getCreateTableSQL');

        $subscriber = new MessengerTransportDoctrineSchemaListener([$doctrineTransport]);

        $subscriber->onSchemaCreateTable($event);
        $this->assertFalse($event->isDefaultPrevented());
    }

    public function testPostGenerateSchemaRespectsSchemaFilter()
    {
        $schema = new Schema();

        $configuration = new Configuration();
        $configuration->setSchemaAssetsFilter(static fn (string $tableName) => 'messenger_messages' !== $tableName);

        $dbalConnection = $this->createStub(Connection::class);
        $dbalConnection->method('getConfiguration')->willReturn($configuration);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($dbalConnection);
        $event = new GenerateSchemaEventArgs($entityManager, $schema);

        $doctrineTransport = $this->createStub(DoctrineTransport::class);
        $doctrineTransport->method('configureSchema')
            ->willReturnCallback(static function (Schema $schema) {
                $table = $schema->createTable('messenger_messages');
                $table->addColumn('id', 'integer', ['autoincrement' => true]);
            });

        $listener = new MessengerTransportDoctrineSchemaListener([$doctrineTransport]);
        $listener->postGenerateSchema($event);

        $this->assertFalse($schema->hasTable('messenger_messages'));
    }

    public function testPostGenerateSchemaRespectsSchemaFilterIncludingSequences()
    {
        $schema = new Schema();

        $configuration = new Configuration();
        $excluded = ['messenger_messages', 'messenger_messages_seq'];
        $configuration->setSchemaAssetsFilter(static fn (string $assetName) => !\in_array($assetName, $excluded, true));

        $dbalConnection = $this->createStub(Connection::class);
        $dbalConnection->method('getConfiguration')->willReturn($configuration);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($dbalConnection);
        $event = new GenerateSchemaEventArgs($entityManager, $schema);

        $doctrineTransport = $this->createStub(DoctrineTransport::class);
        $doctrineTransport->method('configureSchema')
            ->willReturnCallback(static function (Schema $schema) {
                $table = $schema->createTable('messenger_messages');
                $table->addColumn('id', 'integer', ['autoincrement' => true]);
                $schema->createSequence('messenger_messages_seq');
            });

        $listener = new MessengerTransportDoctrineSchemaListener([$doctrineTransport]);
        $listener->postGenerateSchema($event);

        $this->assertFalse($schema->hasTable('messenger_messages'));
        $this->assertFalse($schema->hasSequence('messenger_messages_seq'));
    }

    public function testPostGenerateSchemaFilterDoesNotAffectPreExistingSequences()
    {
        $schema = new Schema();
        $schema->createSequence('existing_seq');

        $configuration = new Configuration();
        $excluded = ['messenger_messages', 'messenger_messages_seq', 'existing_seq'];
        $configuration->setSchemaAssetsFilter(static fn (string $assetName) => !\in_array($assetName, $excluded, true));

        $dbalConnection = $this->createStub(Connection::class);
        $dbalConnection->method('getConfiguration')->willReturn($configuration);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($dbalConnection);
        $event = new GenerateSchemaEventArgs($entityManager, $schema);

        $doctrineTransport = $this->createStub(DoctrineTransport::class);
        $doctrineTransport->method('configureSchema')
            ->willReturnCallback(static function (Schema $schema) {
                $table = $schema->createTable('messenger_messages');
                $table->addColumn('id', 'integer', ['autoincrement' => true]);
                $schema->createSequence('messenger_messages_seq');
            });

        $listener = new MessengerTransportDoctrineSchemaListener([$doctrineTransport]);
        $listener->postGenerateSchema($event);

        $this->assertFalse($schema->hasTable('messenger_messages'));
        $this->assertFalse($schema->hasSequence('messenger_messages_seq'));
        $this->assertTrue($schema->hasSequence('existing_seq'));
    }
}
