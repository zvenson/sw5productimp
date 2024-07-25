<?php

namespace ProductImporter\Subscriber;

use Enlight\Event\SubscriberInterface;

class ServiceContainer implements SubscriberInterface
{
  /**
   * @var string
   */
  private $pluginDir;

  /**
   * @param $pluginDirectory
   */
  public function __construct($pluginDirectory)
  {
    $this->pluginDir = $pluginDirectory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents()
  {
    return [
      'Enlight_Bootstrap_InitResource_productimporter.frontend_query' => 'onCreateFrontendQuery'
    ];
  }

  /**
   * @return FrontendQuery
   */
  public function onCreateFrontendQuery()
  {
  }
}
