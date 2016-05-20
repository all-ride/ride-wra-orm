<?php

namespace ride\web\rest\controller;

use ride\library\http\jsonapi\exception\BadRequestJsonApiException;
use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\orm\model\Model;
use ride\library\orm\OrmManager;

use ride\web\rest\controller\AbstractJsonApiController;

/**
 * Controller to implement a JSON API out of a ORM model
 */
class GeoLocationEntryController extends AbstractJsonApiController {

    /**
     * Sets the response after every action based on the document
     * @return null
     */
    public function postAction() {
        parent::postAction();
        if (!$this->document->hasContent()) {
            return;
        }

        $this->response->setHeader(Header::HEADER_CONTENT_LANGUAGE, strtolower(str_replace('_', '-', $this->api->getOrmManager()->getLocale())));
    }

    /**
     * Action to get a collection of the provided resource type
     * @param string $type Type of the resource
     * @return null
     */
    public function searchAction(OrmManager $orm) {
        $type = 'geo-locations';
        $geoLocationModel = $orm->getGeoLocationModel();

        try {
            // creates a model query based on the document query
            $documentQuery = $this->document->getQuery();

            $geoLocations = $this->search($orm, $documentQuery);

            $modelQuery = $geoLocationModel->createQuery();
            $modelQuery->addCondition('{id} IN %1%', array_keys($geoLocations));
            $modelQuery->setLimit($documentQuery->getLimit(50), $documentQuery->getOffset());

            foreach ($documentQuery->getSort() as $orderField => $orderDirection) {
                if (!$meta->hasField($orderField)) {
                    $this->addSortFieldNotFoundError($type, $orderField);
                } else {
                    $modelQuery->addOrderBy('{' . $orderField . '} ' . $orderDirection);
                }
            }

            // no errors, assign the query result to the document
            if (!$this->document->getErrors()) {
                if ($geoLocations) {
                    $entries = $modelQuery->query();
                    $count = $modelQuery->count();
                } else {
                    $entries = array();
                    $count = 0;
                }

                $this->document->setLink('self', $this->request->getUrl());
                $this->document->setResourceCollection($type, $entries);
                $this->document->setMeta('total', $count);

                if ($this->request->getQueryParameter('list') == 1) {
                    $this->document->setMeta('list', $geoLocationModel->getOptionsFromEntries($entries));
                }
            }
        } catch (BadRequestJsonApiException $exception) {
            $this->getLog()->logException($exception);

            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.input', $exception->getMessage());
            $error->setSourceParameter($exception->getParameter());

            $this->document->addError($error);
        }
    }

    private function search(OrmManager $orm, JsonApiQuery $documentQuery) {
        $term = $documentQuery->getFilter('term');
        if ($term) {
            $term = '%' . $term . '%';
        }

        $geoLocationModel = $orm->getGeoLocationModel();
        $geoLocationLocalizedModel = $orm->getGeoLocationLocalizedModel();

        // query localized table
        if ($term) {
            $query = $geoLocationLocalizedModel->createQuery();
            $query->setFields('{entry}, {name}');
            $query->setLimit(100);

            $query->addCondition('{locale} = %1%', $orm->getLocale());
            $query->addCondition('{name} LIKE %1%', $term);

            $geoLocationLocalizedResult = $query->query('entry');
        }

        // query main table
        $query = $geoLocationModel->createQuery();
        $query->setFields('{id}, {path}, {code}');
        $query->setLimit(100);

        if ($term) {
            if (!$geoLocationLocalizedResult) {
                $query->addCondition('{code} LIKE %1%', $term);
            } else {
                $query->addCondition('{id} IN %1%', array_keys($geoLocationLocalizedResult));
            }
        }

        $type = $documentQuery->getFilter('type');
        if ($type) {
            if (is_array($type)) {
                $query->addCondition('{type} IN %1%', $type);
            } elseif (strpos($type, ',')) {
                $query->addCondition('{type} IN %1%', explode(',', $type));
            } else {
                $query->addCondition('{type} = %1%', $type);
            }
        }

        $path = $documentQuery->getFilter('path');
        if ($path) {
            if (strpos($path, '~') == false) {
                $path = '~' . $path . '~';
            }

            $query->addCondition('{path} LIKE %1%', '%' . $path . '%');
        }

        $geoLocationResult = $query->query();
        // foreach ($geoLocationResult as $index => $geoLocation) {
            // $result[$index] = $index;
        // }

        // merge result

        return $geoLocationResult;
    }

}
