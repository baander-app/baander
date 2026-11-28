<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store each video library\'s metadata provider order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            CREATE TABLE library_provider_orders (
                library_id UUID NOT NULL,
                provider_ids JSONB NOT NULL,
                CONSTRAINT library_provider_orders_pkey PRIMARY KEY (library_id),
                CONSTRAINT chk_library_provider_orders_provider_ids_array CHECK (jsonb_typeof(provider_ids) = 'array')
            )
            SQL);
        $this->addSql('ALTER TABLE library_provider_orders ADD CONSTRAINT fk_library_provider_orders_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON library_provider_orders FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{library_id,provider_ids}', '{admin-metadata}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON library_provider_orders FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{library_id,provider_ids}', '{admin-metadata}', '{admin}')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing provider orders would discard administrator choices.');
    }
}
