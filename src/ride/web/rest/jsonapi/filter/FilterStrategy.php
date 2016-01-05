<?php

namespace ride\web\rest\jsonapi\filter;

use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\orm\query\ModelQuery;

/**
 * Interface for a filter strategy
 */
interface FilterStrategy {

    /**
     * Applies the API query on the model query through this strategy
     * @param \ride\library\http\jsonapi\JsonApiQuery $jsonApiQuery
     * @param \ride\library\orm\query\ModelQuery $modelQuery
     * @return null
     */
    public function applyFilter(JsonApiQuery $apiQuery, ModelQuery $modelQuery);

}
