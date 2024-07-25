<?php
namespace ProductImporter;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;

/**
 * Class ProductImporter
 *
 * @package ProductImporter
 */
class ProductImporter extends Plugin
{

  public function install(InstallContext $context)
  {
    $this->addCron();
    $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    parent::install($context);
  }

  public function uninstall(UninstallContext $context)
  {
    $this->removeCron();
    $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    parent::uninstall($context);
  }

  public function addCron()
  {
    $connection = $this->container->get('dbal_connection');
    try {
      $connection->insert(
        's_crontab',
        [
          'name'       => 'Product Importer Conjob',
          'action'     => 'ProductImporterCronjob',
          'next'       => new \DateTime(),
          'start'      => null,
          '`interval`' => '7200',
          'active'     => 1,
          'end'        => new \DateTime(),
          'pluginID'   => null,
        ],
        [
          'next' => 'datetime',
          'end'  => 'datetime',
        ]
      );
    } catch (\Exception $e) {
      // sorry
    }
  }

  public function removeCron()
  {
    $this->container->get('dbal_connection')->executeQuery('DELETE FROM s_crontab WHERE `name` = ?', [
      'Shopware_CronJob_ProductImporterCronjob',
    ]);
  }
}
