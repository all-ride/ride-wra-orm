<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\JsonApiDocument;

/**
 * JSON API Resource adapter for the asset image styles
 */
class AssetImageStyleJsonApiResourceAdapter extends EntryJsonApiResourceAdapter {

    /**
     * Gets a resource instance for the provided model data
     * @param mixed $data Data to adapt
     * @param \ride\library\http\jsonapi\JsonApiDocument $document Document
     * which is requested
     * @param string $relationshipPath dot-separated list of relationship names
     * @return JsonApiResource|null
     */
    public function getResource($data, JsonApiDocument $document, $relationshipPath = null) {
        $resource = parent::getResource($data, $document, $relationshipPath);

        $value = $resource->getAttribute('image');

        if ($value) {
            $resource->setMeta('image', $data->getImage());
        }

        return $resource;
    }

}
