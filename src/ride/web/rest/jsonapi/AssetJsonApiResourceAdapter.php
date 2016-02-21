<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\JsonApiDocument;
use ride\library\StringHelper;

use ride\service\AssetService;

/**
 * JSON API Resource adapter for the assets
 */
class AssetJsonApiResourceAdapter extends EntryJsonApiResourceAdapter {

    private $assetService;

    /**
     * Sets the asset service
     * @param \ride\service\AssetService $assetService
     * @return null
     */
    public function setAssetService(AssetService $assetService) {
        $this->assetService = $assetService;
    }

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

        $value = $resource->getAttribute('value');
        if ($value && !StringHelper::startsWith($value, array('http://', 'https://'))) {
            $dataUri = $this->web->getHttpFactory()->createDataUriFromFile($value);
            $resource->setAttribute('value', $dataUri->encode());
        }

        $query = $document->getQuery()->getParameter('url');
        if ($this->assetService && ($query == '1' || $query == 'true')) {
            $resource->setMeta('url', $this->assetService->getAssetUrl($data));
        }

        $query = $document->getQuery()->getParameter('images');
        if ($this->assetService && ($query == '1' || $query == 'true')) {
            $images = array();

            $imageStyles = $this->assetService->getImageStyles();
            foreach ($imageStyles as $imageStyle => $null) {
                $url = $this->assetService->getAssetUrl($data, $imageStyle, true);
                if ($url) {
                    $images[$imageStyle] = $url;
                }
            }

            $resource->setMeta('images', $images);
        }

        return $resource;
    }

}
