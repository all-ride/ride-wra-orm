<?php

namespace ride\web\rest\controller;

use ride\library\http\jsonapi\exception\BadRequestJsonApiException;
use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\http\jsonapi\JsonApi;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\orm\exception\OrmException;
use ride\library\orm\model\Model;
use ride\library\orm\OrmManager;

use ride\web\mvc\controller\AbstractController;
use ride\web\rest\jsonapi\OrmJsonApi;

/**
 * Controller to implement a JSON API out of a ORM model
 */
class OrmModelController extends AbstractController {
    protected $orm;
    protected $api;
    protected $document;

    /**
     * Constructs a new JSON API controller
     * @param \ride\library\orm\OrmManager $orm
     * @param \ride\library\http\jsonapi\JsonApi $api
     * @return null
     */
    public function __construct(OrmManager $orm, JsonApi $api) {
        $this->orm = $orm;
        $this->api = $api;
    }

    /**
     * Checks the content type before every action and creates a document
     * @return boolean True when the action is allowed, false otherwise
     */
    public function preAction() {
        // Servers MUST respond with a 415 Unsupported Media Type status code if
        // a request specifies the header Content-Type: application/vnd.api+json
        // with any media type parameters.
        $contentType = $this->request->getHeader(Header::HEADER_CONTENT_TYPE);
        if (strpos($contentType, OrmJsonApi::CONTENT_TYPE) === 0 && $contentType != OrmJsonApi::CONTENT_TYPE) {
            $this->response->setStatusCode(Response::STATUS_CODE_UNSUPPORTED_MEDIA_TYPE);

            return false;
        }

        // creates a document for the incoming request
        $query = $this->api->createQuery($this->request->getQueryParameters());
        $this->document = $this->api->createDocument($query);

        return true;
    }

    /**
     * Sets the response after every action
     * @return null
     */
    public function postAction() {
        $this->response->setStatusCode($this->document->getStatusCode());

        if ($this->document->hasContent()) {
            $this->setJsonView($this->document);

            $this->response->setHeader(Header::HEADER_CONTENT_TYPE, OrmJsonApi::CONTENT_TYPE);
        }
    }

    /**
     * Action to get a collection of the provided resource type
     * @param string $type Type of the resource
     * @return null
     */
    public function indexAction() {
        // check the resource type
        $models = $this->orm->getModels();

        try {
            // creates a model query based on the document query
            $documentQuery = $this->document->getQuery();

            // apply search
            $searchQuery = $documentQuery->getFilter('query', array());
            if ($searchQuery) {
                foreach ($models as $index => $model) {
                    if (strpos($model->getName(), $searchQuery) === false) {
                        unset($models[$index]);
                    }
                }
            }

            // get the total count
            $numModels = count($models);

            // apply order
            $orderMethod = null;
            foreach ($documentQuery->getSort() as $orderField => $orderDirection) {
                if ($orderField != 'name') {
                    $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.order', 'Order field does not exist');
                    $error->setDetail('Order field \'' . $orderField . '\' does not exist in resource type \'models\'');
                    $error->setSourceParameter(JsonApiQuery::PARAMETER_SORT);

                    $this->document->addError($error);
                } elseif ($orderDirection == 'ASC') {
                    $orderMethod = array($this, 'sortModelAscending');
                } elseif ($orderDirection == 'DESC') {
                    $orderMethod = array($this, 'sortModelDescending');
                }
            }

            if ($orderMethod) {
                usort($models, $orderMethod);
            }

            // apply limit
            $limit = $documentQuery->getLimit(50);
            $offset = $documentQuery->getOffset();

            $models = array_slice($models, $offset, $limit, true);

            // no errors, assign the result to the document
            if (!$this->document->getErrors()) {
                $this->document->setLink('self', $this->request->getUrl());
                $this->document->setResourceCollection('models', $models);
                $this->document->setMeta('total', $numModels);

                if ($this->request->getQueryParameter('list') == 1) {
                    $list = array();
                    foreach ($models as $model) {
                        $list[$model->getName()] = $model->getName();
                    }

                    $this->document->setMeta('list', $list);
                }
            }
        } catch (BadRequestJsonApiException $exception) {
            $this->getLog()->logException($exception);

            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.input', $exception->getMessage());
            $error->setSourceParameter($exception->getParameter());

            $this->document->addError($error);
        }
    }

    /**
     * Helper to sort the models by name
     * @param mixed $a
     * @param mixed $b
     * @return integer
     */
    private function sortModelAscending($a, $b) {
        return strcmp($a->getName(), $b->getName());
    }

    /**
     * Helper to sort the models by name
     * @param mixed $a
     * @param mixed $b
     * @return integer
     */
    private function sortModelDescending($a, $b) {
        return $this->sortModelAscending($b, $a);
    }

    /**
     * Action to get the details of the provided resource
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function detailModelAction($id) {
        // check the resource type
        try {
            $model = $this->orm->getModel($id);

            $this->document->setLink('self', $this->request->getUrl());
            $this->document->setResourceData('models', $model);
        } catch (OrmException $exception) {
            $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'resource.found', 'Resource does not exist');
            $error->setDetail('Resource with type \'models\' and id \'' . $id . '\' does not exist');

            $this->document->addError($error);

            return;
        }
    }

    /**
     * Action to get the details of the provided resource
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function detailFieldAction($id) {
        // check the resource type
        try {
            $model = $this->orm->getModel($id);

            $this->document->setLink('self', $this->request->getUrl());
            $this->document->setResourceData('models', $model);
        } catch (OrmException $exception) {
            $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'resource.found', 'Resource does not exist');
            $error->setDetail('Resource with type \'models\' and id \'' . $id . '\' does not exist');

            $this->document->addError($error);

            return;
        }
    }

}
