<?php

namespace ride\web\rest\jsonapi\filter;

use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\orm\query\ModelQuery;

/**
 * Match filter strategy
 */
class MatchFilterStrategy implements FilterStrategy {

    /**
     * Applies the API query on the model query through this strategy
     * @param \ride\library\http\jsonapi\JsonApiQuery $jsonApiQuery
     * @param \ride\library\orm\query\ModelQuery $modelQuery
     * @return null
     */
    public function applyFilter(JsonApiQuery $jsonApiQuery, ModelQuery $modelQuery) {
        $query = $jsonApiQuery->getFilter('match', null);
        if ($query) {
            $modelQuery->getModel()->applySearch($modelQuery, array(
                'match' => $query,
            ));
        }
    }

}
