<?php

namespace ProductImporter\Subscriber;

use Enlight\Event\SubscriberInterface;
use ProductImporter\Components\Importer;

class TemplateRegistration implements SubscriberInterface
{
  /**
   * @var string
   */
  private $pluginDir;

  /**
   * @var array
   */
  private $pluginConfig;

  /**
   * @var \Enlight_Template_Manager
   */
  private $templateManager;

  /**
   * @param $pluginDirectory
   * @param array $pluginConfig
   * @param \Enlight_Template_Manager $templateManager
   */
  public function __construct($pluginDirectory, array $pluginConfig, \Enlight_Template_Manager $templateManager)
  {
    $this->pluginDir       = $pluginDirectory;
    $this->pluginConfig    = $pluginConfig;
    $this->templateManager = $templateManager;
  }

  public static function getSubscribedEvents()
  {
    return [
      'Enlight_Controller_Dispatcher_ControllerPath_Frontend_Pimp' => 'frontendController',
      'Shopware_CronJob_ProductImporterCronjob'                    => 'productImport'
    ];
  }

  public function frontendController()
  {
    $this->templateManager->addTemplateDir($this->pluginDir . '/Resources/views');

    return $this->pluginDir . '/Controllers/Frontend/Pimp.php';
  }

 public function productImport() {
        $importer = new Importer();
        $results = [];
        $results['articles'] = $importer->import();
        // $results['images'] = $importer->importImages();

        // Connect to the SFTP server
        $sftp = new SFTP($this->pluginConfig['sftphost']);
        if (!$sftp->login($this->pluginConfig['ftpuser'], $this->pluginConfig['ftpsecret'])) {
            die('Login Failed');
        }

        // Get file list of current directory
        $file_list = $sftp->nlist('articles-data');
        if ($file_list === false || count($file_list) === 0) {
            die('No files found in articles-data directory');
        }

        // Download the first file
        $data = $sftp->get($file_list[0]);
        if ($data === false) {
            die('Failed to download file');
        }

        // Process the file content
        $xml = simplexml_load_string($data);
        if ($xml === false) {
            die('Failed to parse XML');
        }
        $json = json_encode($xml);
        $array = json_decode($json, true);

        // Close the SFTP connection
        $sftp->disconnect();

        return $results;
    }
}
