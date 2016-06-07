<?php

namespace ride\web\rest\jsonapi\filter;

use ride\application\orm\elastic\ElasticSearch;

use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\orm\query\ModelQuery;

/**
 * Elastic filter strategy
 */
class ElasticFilterStrategy implements FilterStrategy {

    /**
     * Constructs a new Elastic filter strategy
     */
    public function __construct(ElasticSearch $search) {
        $this->search = $search;
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
        $parameters = array(
            'query' => $query,
            'limit' => $jsonApiQuery->getLimit(50),
            'offset' => $jsonApiQuery->getOffset(),
        );

        $result = $this->search->searchByQueryString($model, $parameters);

        $this->search->applyResultToModelQuery($result, $modelQuery);
    }

}
