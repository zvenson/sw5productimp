<?php

use ProductImporter\Components\Importer;
use Shopware\Components\Plugin\ConfigReader;

class Shopware_Controllers_Frontend_Pimp extends Enlight_Controller_Action
{
    public function indexAction()
    {
        $importer = new Importer();
        $this->View()->assign('import', $importer->import());
    }

    public function imagesAction()
    {
     //   $importer = new Importer();
     //   $this->View()->assign('import', $importer->importImages());
    }
}
