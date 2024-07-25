<?php

namespace ProductImporter\Components;

use Doctrine\DBAL\Connection;
use phpseclib\Net\SFTP;

class Importer extends \Enlight_Class
{
    //Skip these categories
    const BLACKLISTED_CATEGORIES = [];

    //also skip articles that have $item->Material or $item->Material2 from materialarray:
    const BLACKLISTED_MATERIALS = [];

    //these articles must be imported anyway
    const WHITELISTED_ARTICLES = [];

    private $container;
    private $config;
    private $pluginDir;
    private $mediaPath;
    private $logs = [];

    private $sftp;

    public function __construct()
    {
        $this->container = Shopware()->Container();
        $this->config = $this->container->get('shopware.plugin.config_reader')->getByPluginName('ProductImporter');
        $this->pluginDir = Shopware()->DocPath('custom_plugins_ProductImporter');
        $this->mediaPath = Shopware()->DocPath('media_articles');

        $config = $this->config;
    $this->sftp = new SFTP($config['ftphost']);
    if (!$this->sftp->login($config['ftpuser'], $config['ftpsecret'])) {
        die("Could not connect to " . $config['ftphost']);
    } else {
        $this->log('SFTP', 'Connection successful');
    }
}

public function log(string $group, $data)
{
    if (!isset($this->logs[$group])) {
        $this->logs[$group] = [$data];
    } else {
        $this->logs[$group][] = $data;
    }

    // Additionally, write to a file or error log for debugging purposes
    error_log("[$group] " . print_r($data, true), 3, $this->pluginDir . '/logs/importer.log');
}


    public function isSet($val = null) {
        return (bool) (isset($val) && !empty(trim($val)));
    }

    public function strFloat($str)
    {
        return (float) floatval(str_replace(',', '.', (string) $str));
    }

    /**
     * @return array
     */
    public function import()
    {
        $imported = 0;
        $failed = [];
        $data = $this->cookXmlData();

        $items = [0];
        foreach ($data as $key => $item) {
            $items[] = $item['ordernumber'];
            $importStatus = $this->importArticle($item);
            if ($importStatus) $imported++;
            else array_push($failed, (string) $item->Item_No);
        }

        // find old articles
        $articles = Shopware()->Db()->fetchAll(
            'SELECT articleID, ordernumber FROM s_articles_details WHERE ordernumber NOT IN (' . implode(',', $items) . ')'
        );

        // deactivate old articles
        $deactivate = [];
        $whitelist = ['996433','996422','1492'];
        foreach ($articles as $article) {
            if (in_array($article['articleID'], $whitelist)) {
                continue;
            }
            $deactivate[] = [
                'articleID' => $article['articleID'],
                'ordernumber' => $article['ordernumber'],
            ];
            Shopware()->Db()->query(
                'UPDATE s_articles SET active = 0 WHERE id = ' . $article['articleID']
            );


        }

        // log deactivated articles
        $this->log('deactivate', $deactivate);

        // rebuild category
        $this->rebuildCategory();

        // rebuild seoIndex
        // $this->rebuildSeoIndex();

        return [
            'imported' => $imported,
            'failed' => $failed,
            'logs' => $this->logs,
        ];
    }

    /**
     * @return array
     */
    public function importImages()
    {

    }

    protected function importMedia($articleID, $name)
    {
  

    }

    protected function cookXmlData()
    {
        $output = [];
        $data = $this->getImportData();
        // take only range pure
        foreach ($data as $key => $item) {
            $SubCat = intval($item->Sub_ProductGroup_ID);
            $Material = (string)$item->Material;
            $Material2 = (string)$item->Material2;
            $range = trim(strtolower((string)$item->Base_ProductGroup));
            if (
                (
                    in_array($SubCat, self::BLACKLISTED_CATEGORIES) ||
                    in_array($Material, self::BLACKLISTED_MATERIALS) ||
                    in_array($Material2, self::BLACKLISTED_MATERIALS)
                ) && !in_array((string) $item->Item_No, self::WHITELISTED_ARTICLES)
            ) {
            } else {
                if(strstr($range,'biologisch abbaubar') || strstr($range,'mehrmaligen gebrauch') || in_array((string) $item->Item_No, self::WHITELISTED_ARTICLES)) {
                    $materials = [];
                    if (isset($item->Material)) array_push($materials, (string) $item->Material);
                    if (isset($item->Material2)) array_push($materials, (string) $item->Material2);

                    $instock = 0;
                    if ((string) $item->StockAvailabilityPallet == 1) {
                        $instock = 1 * (string) $item->BoxesPallet;
                    } elseif ((string) $item->StockAvailabilityCarton == 1) {
                        $instock = 1 * (string) $item->CartonsPerPaletteTier;
                    }

                    $pseudoPrice = $this->strFloat($item->RecommendedRetailPrice_Carton) * $this->strFloat($item->PacksPerDispatchUnit);
                    $purchasePrice = $this->strFloat($item->InvoicePrice_PAPSTAR_Carton) * $this->strFloat($item->PacksPerDispatchUnit);
                   $purchasePrice = $purchasePrice + ( ( $purchasePrice / 100 ) * 19 );
                    $related = [];
                    if ($item->RelatedProducts) {
                        foreach ($item->RelatedProducts as $product) {
                            $related[] = (string) $product->ReferenceItem;
                        }
                    }

                    $output[] = [
                        // default value
                        'taxId' => 1,
                        'supplierID' => 1,
                        'unitID' => 9,
                        'referenceunit' => 1,
                        // data from xml
                        'ordernumber' => (string) $item->Item_No,
                        'name' => $item->PacksPerDispatchUnit . ' x ' . $item->Item_Description,
                        'description' => (string) $item->Item_Description,
                        'description_long' => (string) $item->AdvertisingText,
                        'categoryID' => (string) $item->Sub_ProductGroup_ID,
                        'weight' => $this->strFloat($item->Weight_Pack) / 1000, // weight in kilos
                        'height' => $this->strFloat($item->Height),
                        'width' => $this->strFloat($item->Width),
                        'length' => $this->strFloat($item->Diameter),
                        'stock' => $instock,
                        'price' => round($pseudoPrice * $this->strFloat($this->config['pricemath']), 4),
                        'pseudoprice' => $pseudoPrice,
                        'purchaseprice' => $purchasePrice,
                        'purchaseunit' => (string) $item->PacksPerDispatchUnit,
                        'contentperpack' => (string) $item->ContentPerPack,
                        'contenttotal' => (string) $item->PacksPerDispatchUnit * (string) $item->ContentPerPack,
                        'unit' => (string) $item->ContentPerPack_UnitOfMeasure,
                        'date' => (string) $item->first_availability_date,
                        'newstock' => (string) $item->NewStockAvailability? :(string)$item->first_availability_date,
                        'materials' => $materials,
                        'attr1' => (string) $item->GTIN_Pack,
                        'attr2' => (string) $item->GTIN_Carton,
                        'related' => $related,
                    ];
                }
            }
        }

        return $output;
    }

protected function getImportData()
{
    $localFilePath = $this->pluginDir . '/data/80792_Items.xml'; // Ensure this path is writable
    $remoteFilePath = './articles-data/80792_Items.xml';

    try {
        // Ensure the local directory exists
        if (!is_dir(dirname($localFilePath))) {
            mkdir(dirname($localFilePath), 0755, true);
        }

        // Download the file to the local server
        if (!$this->sftp->get($remoteFilePath, $localFilePath)) {
            $this->log('SFTP', 'Failed to download file: ' . $remoteFilePath);
            return [];
        }

        $this->log('SFTP', 'Downloaded file: ' . $remoteFilePath);

        // Read the downloaded file
        $xmlString = file_get_contents($localFilePath);
        if ($xmlString === false) {
            $this->log('SFTP', 'Failed to read the downloaded file: ' . $localFilePath);
            return [];
        }

        // Log the beginning of the XML string for verification
        $this->log('SFTP', 'XML Content: ' . substr($xmlString, 0, 500));

        // Parse the XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);

        if ($xml === false) {
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                $this->log('SFTP', 'XML Error: ' . $error->message);
            }
            libxml_clear_errors();
            return [];
        }

        return $xml->Items->Item;
    } catch (Exception $e) {
        $this->log('SFTP', 'Error: ' . $e->getMessage());
        echo 'Message: ' . $e->getMessage();
        return [];
    }
}






    protected function rebuildCategory()
    {
        $progress = 0;
        $limit = 1000;

        /** @var CategoryDenormalization $component */
        $component = $this->container->get('categorydenormalization');

        $component->removeOrphanedAssignments();
        $component->rebuildCategoryPath();
        $component->removeAllAssignments();

        // Get total number of assignments to build
        $count = $component->rebuildAllAssignmentsCount();

        // create the assignments
        while ($progress < $count) {
            $component->rebuildAllAssignments($limit, $progress);
            $progress += $limit;
        }
    }

    protected function rebuildSeoIndex()
    {
        $database = $this->container->get('dbal_connection');
        $modules = $this->container->get('modules');
        $modelManager = $this->container->get('models');
        $seoIndex = $this->container->get('SeoIndex');
        $rewriteTable = $modules->RewriteTable();

        /** @var \Doctrine\DBAL\Query\QueryBuilder $query */
        $query = $database->createQueryBuilder();
        $shops = $query->select('id')
            ->from('s_core_shops', 'shops')
            ->where('active', 1)
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);

        $currentTime = new \DateTime();

        $rewriteTable->sCreateRewriteTableCleanup();

        foreach ($shops as $shopId) {
            /** @var $repository \Shopware\Models\Shop\Repository */
            $repository = $modelManager->getRepository(\Shopware\Models\Shop\Shop::class);
            $shop = $repository->getActiveById($shopId);

            if ($shop != null) {
                $shop->registerResources();

                $modules->Categories()->baseId = $shop->getCategory()->getId();

                list($cachedTime, $elementId, $shopId) = $seoIndex->getCachedTime();

                $seoIndex->setCachedTime($currentTime->format('Y-m-d h:m:i'), $elementId, $shopId);
                $rewriteTable->baseSetup();

                $limit = 10000;
                $lastId = null;
                $lastUpdateVal = '0000-00-00 00:00:00';

                while ($lastId !== null) {
                    $lastUpdateVal = $rewriteTable->sCreateRewriteTableArticles($lastUpdateVal, $limit);
                    $lastId = $rewriteTable->getRewriteArticleslastId();
                }

                $seoIndex->setCachedTime($currentTime->format('Y-m-d h:m:i'), $elementId, $shopId);

                // $context = $this->container->get('shopware_storefront.context_service')->createShopContext($shopId);

                $rewriteTable->sCreateRewriteTableCategories();
                // $rewriteTable->sCreateRewriteTableCampaigns();
                $rewriteTable->sCreateRewriteTableContent();
                // $rewriteTable->sCreateRewriteTableBlog();
                // $rewriteTable->createManufacturerUrls($context);
                // $rewriteTable->sCreateRewriteTableStatic();
            } else {
                $this->log('seoIndex', 'No valid shop id passed');
            }
        }
    }

    protected function importArticle($data)
    {
        if (!isset($data['unitID'])) $data['unitID'] = $this->getUnitID($data['unit']);

        $article = Shopware()->Db()->fetchAll('SELECT id, articleID FROM s_articles_details WHERE ordernumber = ? LIMIT 1', [$data['ordernumber']]);
        if (!empty($article) && is_array($article) && count($article)) {
            $data['import_status'] = 'update';
            $data['articleID'] = $article[0]['articleID'];
            $data['articledetailsID'] = $article[0]['id'];

            $this->updateArticle($data);
            $this->updateDetails($data);
        } else {
            $data['import_status'] = 'insert';
            $data['articleID'] = $this->insertArticle($data);
            $data['articledetailsID'] = $this->insertDetails($data);
        }

        $data['attributes'] = $this->insertAttribute($data);
        $data['category_status'] = $this->insertArticleCategory($data);
        $data['price_status'] = $this->insertPrice($data);
        $data['similar'] = $this->insertUpdateSimilar($data);
        $this->insertProductAttributes($data);

        return $this->isSet($data['articleID']) && $this->isSet($data['articledetailsID']);
    }

    protected function getUnitID($name)
    {
        $unitID = 0;
        $units = Shopware()->Db()->fetchAll('SELECT * FROM s_core_units');
        foreach ($units as $unit) {
            if (strtolower($unit['description']) == strtolower($name)) {
                $unitID = $unit['id'];
            }
        }

        if ($unitID == 0) {
            Shopware()->Db()->query(
                'INSERT INTO s_core_units SET unit = ?, description = ?',
                [ strtolower($name), $name, ]
            );

            return Shopware()->Db()->lastInsertId();
        }

        return $unitID ?? 1;
    }

    protected function insertArticle($data) {
        if (
            $this->isSet($data['name']) &&
            $this->isSet($data['description']) &&
            $this->isSet($data['description_long']) &&
            $this->isSet($data['date'])
        ) {
            $article = Shopware()->Db()->fetchAll('SELECT id FROM s_articles WHERE main_detail_id IS NULL AND name = ? LIMIT 1', [ $data['name'] ]);
            if (!empty($article) && is_array($article) && count($article)) {
                return $article[0]['id'];
            } else {
                Shopware()->Db()->query(
                    'INSERT INTO s_articles SET supplierID = ?, name = ?, description = ?, description_long = ?, datum = ?, changetime = ?, pricegroupActive = ?, laststock = 0, active = "1", taxID = "1", pricegroupID = "1", crossbundlelook = "0", notification = "0", template = "", mode = "0", filtergroupID = "4"',
                    [ $data['supplierID'], $data['name'], $data['description'], $data['description_long'], $data['date'],  date('Y-m-d H:i:s', strtotime('now')), 1, ]
                );

                return Shopware()->Db()->lastInsertId();
            }
        } else {
            $details = [
                'name'		  => (string) $data['name'],
                'description' => (string) $data['description'],
                'date'		  => (string) $data['date'],
                'stock'		  => (string) $data['stock'],
            ];
            $this->log($data['ordernumber'], [
                'error'   => 'unable to insert article due not enough data',
                'details' => $details,
            ]);
        }
    }

    protected function updateArticle($data)
    {
        // deactivate these Articles no matter what:
        $black = [87317,87316,481,456,288,482];

        if (
            $this->isSet($data['articleID']) &&
            $this->isSet($data['name']) &&
            $this->isSet($data['description']) &&
            $this->isSet($data['description_long']) &&
            $this->isSet($data['date']) &&
            !in_array($data['articleID'], $black)
        ) {
            Shopware()->Db()->query(
                //Mit Artikelnamen Update
               // 'UPDATE s_articles SET active = "1", name = ?, description = ?, description_long = ?, changetime = ?, pricegroupActive = ?, main_detail_id = ? WHERE id = ?',
               // [ $data['name'], $data['description'], $data['description_long'], date('Y-m-d H:i:s', strtotime('now')), 1, $data['articledetailsID'], $data['articleID'], ]
               // ohne Artikelnamen Update
                'UPDATE s_articles SET active = "1", description = ?, description_long = ?, changetime = ?, pricegroupActive = ?, main_detail_id = ? WHERE id = ?',
              [  $data['description'], $data['description_long'], date('Y-m-d H:i:s', strtotime('now')), 1, $data['articledetailsID'], $data['articleID'], ]
            );
        } else {
            $this->log($data['ordernumber'], 'unable to update article due not enough data');
        }
    }

    protected function insertDetails($data) {
        try {
            if ( $this->isSet($data['articleID']) ) {
                Shopware()->Db()->query(
                    'INSERT INTO s_articles_details SET articleID = ?, ordernumber = ?, active = "1", kind = "1", weight = ?, height = ?, width = ?, length = ?, unitID = ?, purchaseprice = ?, purchaseunit = ?, instock = ?',
                    [ $data['articleID'], $data['ordernumber'], $data['weight'], $data['height'], $data['width'], $data['length'], $data['unitID'], $data['purchaseprice'], $data['contenttotal'], $data['stock'], ]
                );

                $detailsID = Shopware()->Db()->lastInsertId();

                Shopware()->Db()->query(
                    'UPDATE s_articles SET main_detail_id = ? WHERE id = ?',
                    [ $detailsID, $data['articleID'], ]
                );

                return $detailsID;
            } else {
                $this->log($data['ordernumber'], 'unable to insert article details due not enough data');
            }
        }
        catch(Exception $e) {
            echo 'Message: ' . $data['articleID'] . ' -- ' .$e->getMessage();
            $this->log($data['ordernumber'], 'unable to insert article details due ' . $e->getMessage());
        }
    }

    protected function updateDetails($data) {
        if (
            $this->isSet($data['articleID']) &&
            $this->isSet($data['ordernumber'])
        ) {
            Shopware()->Db()->query(
                'UPDATE s_articles_details SET releasedate = ?, weight = ?, height = ?, width = ?, length = ?, unitID = ?, purchaseprice = ?, purchaseunit = ?, referenceunit = ?, instock = ? WHERE ordernumber = ?',
                [ $data['newstock'], $data['weight'], $data['height'], $data['width'], $data['length'], $data['unitID'], $data['purchaseprice'], $data['contenttotal'], $data['referenceunit'], $data['stock'], $data['ordernumber'], ]
            );
        } else {
            $details = [
                'ordernumber'	=> (string) $data['ordernumber'],
                'weight'		=> (string) $data['weight'],
                'height'		=> (string) $data['height'],
                'unitID'		=> (string) $data['unitID'],
                'purchaseprice' => (string) $data['purchaseprice'],
                'purchaseunit'	=> (string) $data['contenttotal'],
                'referenceunit' => (string) $data['referenceunit'],
                'releasedate' 	=> (string) $data['newstock'],
            ];
            $this->log($data['ordernumber'], [
                'error' => 'unable to update article details due not enough data',
                'details' => $details,
            ]);
        }
    }

    protected function insertAttribute($data) {
        if ($this->isSet($data['articleID'])) {
            $result = Shopware()->Db()->fetchAll(
                'SELECT * FROM s_articles_attributes WHERE articledetailsID = ? LIMIT 1',
                [ $data['articledetailsID'] ]
            );

            if (!empty($result) && is_array($result) && count($result)) {
                Shopware()->Db()->query(
                    'UPDATE s_articles_attributes SET articleID = ?, attr1 = ?, attr2 = ? WHERE articledetailsID = ?',
                    [ $data['articleID'], $data['attr1'], $data['attr2'], $data['articledetailsID'], ]
                );

                return $result[0]['id'];
            } else {
                Shopware()->Db()->query(
                    'INSERT INTO s_articles_attributes SET articleID = ?, articledetailsID = ?, attr1 = ?, attr2 = ?',
                    [ $data['articleID'], $data['articledetailsID'], $data['attr1'], $data['attr2'], ]
                );

                return Shopware()->Db()->lastInsertId();
            }
        } else {
            $this->log($data['ordernumber'], 'unable to insert article attribute due not enough data');
        }
    }

    public function getCategoriesRef() {
        $categoriesCsv = $this->pluginDir . 'Components/categories.csv';
        $categories  = array_map('str_getcsv', file($categoriesCsv));
        return array_reduce($categories, function ($result, $item) {
            $result[$item[0]] = $item[1];
            return $result;
        }, array());
    }

    protected function insertArticleCategory($data) {
    }

   protected function insertPrice($data)
{
    if ($this->isSet($data['articleID']) && $this->isSet($data['articledetailsID'])) {
        $result = Shopware()->Db()->fetchAll(
            'SELECT id FROM s_articles_prices WHERE articledetailsID = ? LIMIT 1',
            [$data['articledetailsID']]
        );

        // Add multiple price for one article: 3-5 -3%, 5-beliebig. : -5%
        $price3Percent = $data['price'] - ($data['price'] * 0.03);
        $price5Percent = $data['price'] - ($data['price'] * 0.05);
        $price3PercentPseudo = $data['pseudoprice'] - ($data['pseudoprice'] * 0.03);
        $price5PercentPseudo = $data['pseudoprice'] - ($data['pseudoprice'] * 0.05);

        if (!empty($result) && is_array($result) && count($result)) {
            $resultPrice2 = Shopware()->Db()->fetchAll(
                'SELECT id FROM s_articles_prices WHERE articledetailsID = ? AND `from` = "4"',
                [$data['articledetailsID']]
            );

            $resultPrice3 = Shopware()->Db()->fetchAll(
                'SELECT id FROM s_articles_prices WHERE articledetailsID = ? AND `from` = "10"',
                [$data['articledetailsID']]
            );

            Shopware()->Db()->query(
                'UPDATE s_articles_prices SET `from` = "1", `to` = "3", price = ?, pseudoprice = ? WHERE id = ?',
                [$data['price'], $data['pseudoprice'], $result[0]['id']]
            );

            if (empty($resultPrice2)) {
                Shopware()->Db()->query(
                    'INSERT INTO s_articles_prices SET pricegroup = "EK", `from` = "4", `to` = "9", articleID = ?, articledetailsID = ?, price = ?, pseudoprice = ?, baseprice = "0", percent = "3.00"',
                    [$data['articleID'], $data['articledetailsID'], $price3Percent, $price3PercentPseudo]
                );
            } else {
                Shopware()->Db()->query(
                    'UPDATE s_articles_prices SET `from` = "4", `to` = "9", price = ?, pseudoprice = ? WHERE articledetailsID = ? AND `from` = "4"',
                    [$price3Percent, $price3PercentPseudo, $data['articledetailsID']]
                );
            }

            if (empty($resultPrice3)) {
                Shopware()->Db()->query(
                    'INSERT INTO s_articles_prices SET pricegroup = "EK", `from` = "10", `to` = "beliebig", articleID = ?, articledetailsID = ?, price = ?, pseudoprice = ?, baseprice = "0", percent = "5.00"',
                    [$data['articleID'], $data['articledetailsID'], $price5Percent, $price5PercentPseudo]
                );
            } else {
                Shopware()->Db()->query(
                    'UPDATE s_articles_prices SET `from` = "10", `to` = "beliebig", price = ?, pseudoprice = ? WHERE articledetailsID = ? AND `from` = "10"',
                    [$price5Percent, $price5PercentPseudo, $data['articledetailsID']]
                );
            }

            return $result[0]['id'];
        } else {
            Shopware()->Db()->query(
                'INSERT INTO s_articles_prices SET pricegroup = "EK", `from` = "1", `to` = "3", articleID = ?, articledetailsID = ?, price = ?, pseudoprice = ?, baseprice = "0", percent = "0.00"',
                [$data['articleID'], $data['articledetailsID'], $data['price'], $data['pseudoprice']]
            );

            Shopware()->Db()->query(
                'INSERT INTO s_articles_prices SET pricegroup = "EK", `from` = "4", `to` = "9", articleID = ?, articledetailsID = ?, price = ?, pseudoprice = ?, baseprice = "0", percent = "3.00"',
                [$data['articleID'], $data['articledetailsID'], $price3Percent, $price3PercentPseudo]
            );

            Shopware()->Db()->query(
                'INSERT INTO s_articles_prices SET pricegroup = "EK", `from` = "10", `to` = "beliebig", articleID = ?, articledetailsID = ?, price = ?, pseudoprice = ?, baseprice = "0", percent = "5.00"',
                [$data['articleID'], $data['articledetailsID'], $price5Percent, $price5PercentPseudo]
            );

            return Shopware()->Db()->lastInsertId();
        }
    } else {
        $this->log($data['ordernumber'], 'unable to insert article price due not enough data');
    }
}


    protected function insertProductAttributes($data) {
        if ($this->isSet($data['articleID'])) {
            for ($i = 0; $i < count($data['materials']); $i++) {
                if (!isset($data['materials'][$i]) || $data['materials'][$i] == '') continue;

                $result = Shopware()->Db()->fetchAll(
                    'SELECT id FROM s_filter_values WHERE LOWER(value) = ? LIMIT 1',
                    [ strtolower($data['materials'][$i]) ]
                );

                if (!empty($result) && is_array($result) && count($result)) {
                    $valueId = $result[0]['id'];
                } else {
                    Shopware()->Db()->query(
                        'INSERT INTO s_filter_values SET optionID = ?, value = ?, position = ?',
                        [ 3, $data['materials'][$i], 0, ]
                    );

                    $valueId = Shopware()->Db()->lastInsertId();
                }

                if (isset($valueId) && $valueId > 0) {
                    $hasFilter = Shopware()->Db()->fetchAll(
                        'SELECT * FROM s_filter_articles WHERE articleID = ? AND valueID = ?',
                        [ $data['articleID'], $valueId, ]
                    );

                    if (!(!empty($hasFilter) && is_array($hasFilter) && count($hasFilter))) {
                        Shopware()->Db()->query(
                            'INSERT INTO s_filter_articles SET articleID = ?, valueID = ?',
                            [ $data['articleID'], $valueId, ]
                        );
                    }
                }
            }
        } else {
            $this->log($data['ordernumber'], 'unable to insert article properties due not enough data');
        }
    }

    protected function insertUpdateSimilar($data) {
        if ($this->isSet($data['articleID']) && count($data['related']) > 0) {
            $similar = Shopware()->Db()->fetchAll(
                'SELECT relatedarticle FROM s_articles_similar WHERE articleID = ?',
                [ $data['articleID'] ]
            );

            $relatedarticles = [];
            foreach ($similar as $item) {
                $relatedarticles[] = $item['relatedarticle'];
            }

            foreach ($data['related'] as $item) {
                $article = Shopware()->Db()->fetchAll(
                    'SELECT articleID FROM s_articles_details WHERE ordernumber = ? LIMIT 1',
                    [ $item ]
                );
                if (!empty($article) && is_array($article) && count($article)) {
                    $relatedarticle = (string) $article[0]['articleID'];

                    $result = Shopware()->Db()->fetchAll(
                        'SELECT * FROM s_articles_similar WHERE articleID = ? AND relatedarticle = ?',
                        [ $data['articleID'], $relatedarticle, ]
                    );
                    if (empty($result) && !in_array($relatedarticle, $relatedarticles)) {
                        Shopware()->Db()->query(
                            'INSERT INTO s_articles_similar SET articleID = ?, relatedarticle = ?',
                            [ $data['articleID'], $relatedarticle, ]
                        );
                        Shopware()->Db()->query(
                            'INSERT INTO s_articles_similar_shown_ro SET article_id = ?, related_article_id  = ?, viewed = "1", init_date = ?',
                            [ $data['articleID'], $relatedarticle, date('Y-m-d H:i:s'), ]
                        );
                    }
                }
            }
        }
    }
}
