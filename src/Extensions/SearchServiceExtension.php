<?php

namespace SilverStripe\SearchService\Extensions;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\SearchService\Interfaces\SearchServiceInterface;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use SilverStripe\SearchService\Jobs\DeleteItemJob;
use SilverStripe\SearchService\Jobs\IndexItemJob;
use SilverStripe\SearchService\Service\Indexer;

/**
 * The extension that provides implicit indexing features to dataobjects
 *
 * @property DataObject|SearchServiceExtension $owner
 */
class SearchServiceExtension extends DataExtension
{
    use Configurable;
    use Injectable;

    /**
     * @var bool
     * @config
     */
    private static $enable_indexer = true;

    /**
     * @var bool
     * @config
     */
    private static $use_queued_indexing = false;

    private static $db = [
        'SearchIndexed' => 'Datetime'
    ];

    /**
     * @var SearchServiceInterface
     */
    private $searchService;

    /**
     * SearchServiceExtension constructor.
     * @param SearchServiceInterface $searchService
     */
    public function __construct(SearchServiceInterface $searchService)
    {
        parent::__construct();
        $this->setSearchService($searchService);
    }

    /**
     * @return bool
     */
    public function indexEnabled(): bool
    {
        return $this->config('enable_indexer') ? true : false;
    }

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->indexEnabled()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('SearchIndexed', _t(__CLASS__.'.LastIndexed', 'Last indexed in search'))
            ]);
        }
    }

    /**
     * On dev/build ensure that the indexer settings are up to date.
     */
    public function requireDefaultRecords()
    {
        $this->getSearchService()->configure();
    }

    /**
     * Returns whether this object should be indexed search.
     */
    public function canIndexInSearch(): bool
    {
        if ($this->owner->hasField('ShowInSearch')) {
            return $this->owner->ShowInSearch;
        }

        return true;
    }

    /**
     * When publishing the page, push this data to Indexer. The data
     * which is sent to search is the rendered template from the front end.
     */
    public function onAfterPublish()
    {
        $this->owner->indexInSearch();
    }

    /**
     * Update the indexed date for this object.
     */
    public function touchSearchIndexedDate()
    {
        $schema = DataObject::getSchema();
        $table = $schema->tableForField($this->owner->ClassName, 'SearchIndexed');

        if ($table) {
            DB::query(sprintf('UPDATE %s SET SearchIndexed = NOW() WHERE ID = %s', $table, $this->owner->ID));

            if ($this->owner->hasExtension(Versioned::class) && $this->owner->hasStages()) {
                DB::query(sprintf('UPDATE %s_Live SET SearchIndexed = NOW() WHERE ID = %s', $table, $this->owner->ID));
            }
        }
    }

    /**
     * Index this record into search or queue if configured to do so
     *
     * @return bool
     * @throws Exception
     */
    public function indexInSearch(): bool
    {
        if ($this->owner->indexEnabled() && min($this->owner->invokeWithExtensions('canIndexInSearch')) == false) {
            return false;
        }

        if ($this->config()->get('use_queued_indexing')) {
            $indexJob = new IndexItemJob(get_class($this->owner), $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexJob);

            return true;
        } else {
            return $this->doImmediateIndexInSearch();
        }
    }

    /**
     * Index this record into search
     *
     * @return bool
     */
    public function doImmediateIndexInSearch()
    {
        try {
            $this->getSearchService()->addDocument($this->owner);

            $this->touchSearchIndexedDate();

            return true;
        } catch (Exception $e) {
            Injector::inst()->create(LoggerInterface::class)->error($e);

            return false;
        }
    }

    /**
     * When unpublishing this item, remove from search
     */
    public function onAfterUnpublish()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromSearch();
        }
    }

    /**
     * Remove this item from search
     */
    public function removeFromSearch()
    {
        if ($this->config()->get('use_queued_indexing')) {
            $indexDeleteJob = new DeleteItemJob(get_class($this->owner), $this->owner->ID);
            QueuedJobService::singleton()->queueJob($indexDeleteJob);
        } else {
            try {
                $this->getSearchService()->removeDocument($this->owner);

                $this->touchSearchIndexedDate();
            } catch (Exception $e) {
                Injector::inst()->create(LoggerInterface::class)->error($e);
            }
        }
    }

    /**
     * Before deleting this record ensure that it is removed from search.
     */
    public function onBeforeDelete()
    {
        if ($this->owner->indexEnabled()) {
            $this->removeFromSearch();
        }
    }

    /**
     * @return SearchServiceInterface
     */
    public function getSearchService(): SearchServiceInterface
    {
        return $this->searchService;
    }

    /**
     * @param SearchServiceInterface $searchService
     * @return SearchServiceExtension
     */
    public function setSearchService(SearchServiceInterface $searchService): SearchServiceExtension
    {
        $this->searchService = $searchService;
        return $this;
    }


}