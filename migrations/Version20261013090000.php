<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow wallets to be soft-deleted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD `deleted_at` DATETIME NULL DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` DROP COLUMN `deleted_at`');
    }
}
