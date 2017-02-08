<?php

namespace ride\web\rest\jsonapi\filter;

use ride\application\orm\elastic\ElasticSearch;

use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\log\Log;
use ride\library\orm\query\ModelQuery;

use \Exception;

/**
 * Elastic filter strategy
 */
class ElasticFilterStrategy implements FilterStrategy {

    /**
     * Instance of the log
     * @var \ride\library\log\Log
     */
    private $log;

    /**
     * Constructs a new Elastic filter strategy
     */
    public function __construct(ElasticSearch $search) {
        $this->search = $search;
    }

    /**
     * Sets an instance of the log
     * @param \ride\library\log\Log $log
     * @return null
     */
    public function setLog(Log $log) {
        $this->log = $log;
    }

    /**
     * Applies the API query on the model query through this strategy
     * @param \ride\library\http\jsonapi\JsonApiQuery $jsonApiQuery
     * @param \ride\library\orm\query\ModelQuery $modelQuery
     * @return null
     */
    public function applyFilter(JsonApiQuery $jsonApiQuery, ModelQuery $modelQuery) {
        $query = $jsonApiQuery->getFilter('elastic', null);
        if (!$query) {
            return;
        }

        $model = $modelQuery->getModel();

        $query = str_replace(array('%query%', '%25query%25'), $this->search->escapeReservedChars($jsonApiQuery->getParameter('query', '')), $query);
        if ($model->getMeta()->isLocalized()) {
            $query = str_replace(array('%locale%', '%25locale%25'), $modelQuery->getLocale(), $query);
        }

        $parameters = array(
            'query' => $query,
            'limit' => $jsonApiQuery->getLimit(50),
            'offset' => $jsonApiQuery->getOffset(),
        );

        try {
            $result = $this->search->searchByQueryString($model, $parameters);

            $this->search->applyResultToModelQuery($result, $modelQuery);
        } catch (Exception $exception) {
            if ($this->log) {
                $this->log->logException($exception);
            }

            // dummy condition to force no results
            $modelQuery->addCondition('{id} IS NULL');
        }
    }

}
