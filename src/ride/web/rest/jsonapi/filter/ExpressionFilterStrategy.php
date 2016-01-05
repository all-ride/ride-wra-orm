<?php

namespace ride\web\rest\jsonapi\filter;

use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\orm\query\ModelQuery;

/**
 * Syntax filter strategy
 */
class ExpressionFilterStrategy implements FilterStrategy {

    /**
     * Applies the API query on the model query through this strategy
     * @param \ride\library\http\jsonapi\JsonApiQuery $jsonApiQuery
     * @param \ride\library\orm\query\ModelQuery $modelQuery
     * @return null
     */
    public function applyFilter(JsonApiQuery $jsonApiQuery, ModelQuery $modelQuery) {
        $expression = $jsonApiQuery->getFilter('expression', null);
        if ($expression) {
            $modelQuery->addCondition($expression);
        }
    }

}
