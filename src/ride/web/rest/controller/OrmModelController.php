<?php

namespace ride\web\rest\controller;

use ride\library\config\exception\ConfigException;
use ride\library\config\parser\JsonParser;
use ride\library\http\jsonapi\exception\BadRequestJsonApiException;
use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\log\Log;
use ride\library\orm\definition\field\HasManyField;
use ride\library\orm\definition\field\ModelField;
use ride\library\orm\definition\field\PropertyField;
use ride\library\orm\definition\field\RelationField;
use ride\library\orm\model\Model;
use ride\library\validation\exception\ValidationException;

use ride\web\mvc\controller\AbstractController;
use ride\web\rest\jsonapi\OrmJsonApi;

/**
 * Controller to implement a JSON API out of a ORM model
 */
class OrmModelController extends AbstractController {

    /**
     * Constructs a new JSON API controller
     * @param \ride\web\rest\jsonapi\OrmJsonApi $api
     * @param \ride\library\log\Log $log
     * @return null
     */
    public function __construct(OrmJsonApi $api, Log $log) {
        $this->api = $api;
        $this->log = $log;
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
    public function indexAction($type) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        try {
            // creates a model query based on the document query
            $documentQuery = $this->document->getQuery();

            $meta = $model->getMeta();
            $modelQuery = $model->createQuery();

            $searchQuery = $documentQuery->getFilter('query');
            if ($searchQuery) {
                $model->applySearch($modelQuery, array('query' => $searchQuery));
            }

            $modelQuery->setLimit($documentQuery->getLimit(50), $documentQuery->getOffset());

            foreach ($documentQuery->getSort() as $orderField => $orderDirection) {
                if (!$meta->hasField($orderField)) {
                    $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.order', 'Order field does not exist');
                    $error->setDetail('Order field \'' . $orderField . '\' does not exist in resource type \'' . $type . '\'');
                    $error->setSourceParameter(JsonApiQuery::PARAMETER_SORT);

                    $this->document->addError($error);
                } else {
                    $modelQuery->addOrderBy('{' . $orderField . '} ' . $orderDirection);
                }
            }

            // no errors, assign the query result to the document
            if (!$this->document->getErrors()) {
                $this->document->setLink('self', $this->request->getUrl());
                $this->document->setResourceCollection($type, $modelQuery->query());
                $this->document->setMeta('total', $modelQuery->count());
            }
        } catch (BadRequestJsonApiException $exception) {
            $this->log->logException($exception);

            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.input', $exception->getMessage());
            $error->setSourceParameter($exception->getParameter());

            $this->document->addError($error);
        }
    }

    /**
     * Action to get the details of the provided resource
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function detailAction($type, $id) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        // retrieve the entry
        $entry = $this->getEntry($model, $type, $id);
        if (!$entry) {
            return;
        }

        // assign the entry to the document
        $this->document->setLink('self', $this->request->getUrl());
        $this->document->setResourceData($type, $entry);
    }

    /**
     * Action to get the details of the provided resource
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function relatedAction($type, $id, $relationship) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        // retrieve the entry
        $entry = $this->getEntry($model, $type, $id);
        if (!$entry) {
            return;
        }

        $field = $model->getMeta()->getField($relationship);
        if (!$field instanceof RelationField) {
            // invalid relationship
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship', 'Could not set relationship');
            $error->setDetail('Relationship \'' . $relationship . '\' does not exist for type \'' . $type . '\'');
            $error->setSourcePointer('/data/relationships/' . $relationship);

            $this->document->addError($error);

            return;
        }

        $type = $this->api->getModelType($field->getRelationModelName());
        $value = $model->getReflectionHelper()->getProperty($entry, $relationship);

        // assign the entry to the document
        $this->document->setLink('self', $this->request->getUrl());
        if (is_array($value)) {
            $this->document->setResourceCollection($type, $value);
        } else {
            $this->document->setResourceData($type, $value);
        }
    }

    /**
     * Saves the incoming resource being a create or an update
     * @param \ride\library\config\parser\JsonParser $jsonParser
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function saveAction(JsonParser $jsonParser, $type, $id = null) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        $entry = null;
        if ($id) {
            // retrieve the requested entry
            $entry = $this->getEntry($model, $type, $id);
        }

        if (!$entry) {
            // create a new entry
            $entry = $model->createEntry();
        }

        // validate incoming body
        $entry = $this->getEntryBody($jsonParser, $model, $entry, $type);
        if ($this->document->getErrors()) {
            // errors occured, stop processing
            return;
        }

        // save the entry
        $isNew = $entry->getId() ? false : true;

        try {
            $model->save($entry);

            // update response document with the entry
            $url = $this->getUrl('api.orm.detail', array('type' => $type, 'id' => $entry->getId()));

            $this->document->setLink('self', $url);
            $this->document->setResourceData($type, $entry);

            if ($isNew) {
                $this->document->setStatusCode(Response::STATUS_CODE_CREATED);
                $this->response->setHeader(Header::HEADER_LOCATION, $url);
            }
        } catch (ValidationException $exception) {
            $this->handleValidationException($exception);
        }
    }

    /**
     * Action to delete an entry
     * @param string $type Name of the resource type
     * @param string $id Id of the resource
     * @return null
     */
    public function deleteAction($type, $id) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        // fetch the entry
        $entry = $this->getEntry($model, $type, $id);
        if (!$entry) {
            return;
        }

        // delete the entry
        $model->delete($entry);
    }

    /**
     * Action to get the relationship details
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @param string $relationship Name of the relationship
     * @return null
     */
    public function relationshipAction($type, $id, $relationship) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        // retrieve the entry
        $entry = $this->getEntry($model, $type, $id);
        if (!$entry) {
            return;
        }

        $field = $this->getField($model, $relationship, $type);
        if ($this->document->getErrors()) {
            return;
        }

        $relationshipType = $this->api->getModelType($field->getRelationModelName());
        $value = $model->getReflectionHelper()->getProperty($entry, $relationship);

        // create relationship
        $resource = $this->api->createRelationship();
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->api->createResource($relationshipType, $item->getId());
            }

            $resource->setResourceCollection($value);
        } else {
            if ($value) {
                $value = $this->api->createResource($relationshipType, $value->getId());
            }

            $resource->setResource($value);
        }

        $this->document->setLink('self', $this->request->getUrl());
        $this->document->setLink('related', $this->getUrl('api.orm.related', array('type' => $type, 'id' => $id, 'relationship' => $relationship)));
        $this->document->setRelationshipData($resource);
    }

    /**
     * Saves the incoming resource being a create or an update
     * @param \ride\library\config\parser\JsonParser $jsonParser
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function relationshipSaveAction(JsonParser $jsonParser, $type, $id, $relationship) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        // retrieve the entry
        $entry = $this->getEntry($model, $type, $id);
        if (!$entry) {
            return;
        }

        // check the relationship
        $field = $this->getField($model, $relationship, $type);
        if ($this->document->getErrors()) {
            return;
        }

        // validate incoming body
        $data = $this->getRelationshipBody($jsonParser, $model, $field, $relationship);
        if ($this->document->getErrors()) {
            // errors occured, stop processing
            return;
        }

        if (!$this->request->isPatch() && $field instanceof HasManyField) {
            if ($data === null) {
                $data = array();
            } elseif (!is_array($data)) {
                $data = array($data);
            }

            if ($this->request->isPost()) {
                $methodName = 'addTo' . ucfirst($relationship);
            } elseif ($this->request->isDelete()) {
                $methodName = 'removeFrom' . ucfirst($relationship);
            }

            foreach ($data as $relationshipEntry) {
                $entry->$methodName($relationshipEntry);
            }
        } else {
            // simply overwrite value
            $model->getReflectionHelper()->setProperty($entry, $relationship, $data);
        }

        try {
            $model->save($entry);
        } catch (ValidationException $exception) {
            $this->handleValidationException($exception);
        }
    }

    /**
     * Adds the errors of the validation exception to the document
     * @param \ride\library\validation\exception\ValidationException $exception
     * @return null
     */
    private function handleValidationException(ValidationException $exception) {
        foreach ($exception->getAllErrors() as $fieldName => $fieldErrors) {
            $field = $meta->getField($fieldName);
            if ($field instanceof PropertyField) {
                $source = '/data/attributes/' . $fieldName;
            } else {
                $source = '/data/relationships/' . $fieldName;
            }

            foreach ($fieldErrors as $error) {
                $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, $error->getCode(), $error->getMessage(), (string) $error);
                $error->setSourcePointer($source);

                $this->document->addError($error);
            }
        }
    }

    /**
     * Gets an entry out of the submitted body
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param string $type Name of the resource type
     * @return mixed
     */
    private function getEntryBody(JsonParser $jsonParser, Model $model, $entry, $type) {
        $json = $this->getJsonBody($jsonParser);

        // check the submitted type
        if (!isset($json['data']['type'])) {
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.type', 'No resource type submitted');
            $error->setDetail('No resource type found in the submitted body');
            $error->setSourcePointer('/data/type');

            $this->document->addError($error);

            return;
        } elseif ($json['data']['type'] != $type) {
            $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.type.match', 'Submitted resource type does not match the URL resource type');
            $error->setDetail('Submitted resource type \'' . $json['data']['type'] . '\' does not match the URL type \'' . $type . '\'');
            $error->setSourcePointer('/data/type');

            $this->document->addError($error);

            return;
        }

        // check the id of the entry
        if (!$entry->getId() && isset($json['data']['id'])) {
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.id', 'Could not create a resource with a client generated id');
            $error->setDetail('Client generated id \'' . $json['data']['id'] . '\' cannot be used by the resource backend');
            $error->setSourcePointer('/data/id');

            $this->document->addError($error);

            return;
        } elseif ($entry->getId() && (!isset($json['data']['id']) || $entry->getId() != $json['data']['id'])) {
            $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.type.match', 'Submitted resource id does not match the URL resource id');
            $error->setDetail('Submitted resource id \'' . $json['data']['id'] . '\' does not match the URL id \'' . $id . '\'');
            $error->setSourcePointer('/data/id');

            $this->document->addError($error);

            return;
        }

        // set submitted values to the entry
        $reflectionHelper = $model->getReflectionHelper();
        $meta = $model->getMeta();
        $fields = $meta->getFields();

        // handle submitted attributes
        if (isset($json['data']['attributes'])) {
            foreach ($json['data']['attributes'] as $attribute => $value) {
                if (!isset($fields[$attribute]) || !$fields[$attribute] instanceof PropertyField) {
                    // invalid attribute
                    $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.attribute', 'Could not set attribute');
                    $error->setDetail('Attribute \'' . $attribute . '\' does not exist for type \'' . $type . '\'');
                    $error->setSourcePointer('/data/attrîbutes/' . $attribute);

                    $this->document->addError($error);
                } else {
                    // valid attribute
                    $reflectionHelper->setProperty($entry, $attribute, $value);
                }
            }
        }

        // handle submitted relationships
        if (isset($json['data']['relationships'])) {
            foreach ($json['data']['relationships'] as $relationship => $value) {
                if (!isset($fields[$relationship]) || !$fields[$relationship] instanceof RelationField) {
                    // invalid relationship
                    $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship', 'Could not set relationship');
                    $error->setDetail('Relationship \'' . $relationship . '\' does not exist for type \'' . $type . '\'');
                    $error->setSourcePointer('/data/relationships/' . $relationship);

                    $this->document->addError($error);
                } elseif (!isset($value['data'])) {
                    // invalid data
                    $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship.data', 'Invalid relationship data');
                    $error->setDetail('Submitted relationship \'' . $relationship . '\' does not contain a data member');
                    $error->setSourcePointer('/data/relationships/' . $relationship);

                    $this->document->addError($error);
                } elseif (isset($value['data'][0])) {
                    // collection submitted
                    $data = array();
                    foreach ($value['data'] as $reference) {
                        $relationEntry = $this->getRelationship($reference, '/data/relationships/' . $relationship);
                        if ($relationEntry) {
                            $data[$relationEntry->getId()] = $relationEntry;
                        }
                    }

                    $reflectionHelper->setProperty($entry, $relationship, $data);
                } else {
                    // single value submitted
                    $data = $this->getRelationship($value['data'], '/data/relationships/' . $relationship);
                    if ($data) {
                        $reflectionHelper->setProperty($entry, $relationship, $data);
                    }
                }
            }
        }

        return $entry;
    }

    /**
     * Gets the body of a relationship and translate into entries
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param string $relationship Name of the relationship
     * @return mixed|array
     */
    private function getRelationshipBody(JsonParser $jsonParser, Model $model, ModelField $field, $relationship) {
        $json = $this->getJsonBody($jsonParser);
        $relationModelName = $field->getRelationModelName();

        // check the submitted type
        if (!array_key_exists('data', $json)) {
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.data', 'No data submitted');
            $error->setDetail('No data member found in the submitted body');
            $error->setSourcePointer('/data');

            $this->document->addError($error);
        } elseif ($json['data'] === null) {
            $data = null;
        } elseif (isset($json['data']['type']) && isset($json['data']['id'])) {
            $model = $this->getModel($json['data']['type'], '/data/type');

            $data = $this->getEntry($model, $json['data']['type'], $json['data']['id']);
        } elseif (is_array($json['data'])) {
            if ($field instanceof HasManyField) {
                $data = array();

                foreach ($json['data'] as $index => $resource) {
                    if (!isset($resource['type']) || !isset($resource['id'])) {
                        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.data', 'Invalid data submitted');
                        $error->setDetail('No type or id member found in the submitted data');
                        $error->setSourcePointer('/data/' . $index);

                        $this->document->addError($error);
                    } else {
                        $model = $this->getModel($resource['type'], '/data/' . $index . '/type');
                        if ($model->getName() !== $relationModelName) {
                            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.data', 'Invalid data submitted');
                            $error->setDetail('No type or id member found in the submitted data');
                            $error->setSourcePointer('/data/' . $index);

                            $this->document->addError($error);
                        } else {
                            $resourceEntry = $this->getEntry($model, $resource['type'], $resource['id'], '/data/' . $index);
                            if ($resourceEntry) {
                                $data[$index] = $resourceEntry;
                            }
                        }
                    }
                }
            } else {
                $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.data.array', 'Unexpected array data received');
                $error->setDetail('Data member in the submitted body cannot be an array for the ' . $relationship . ' relationship.');
                $error->setSourcePointer('/data');

                $this->document->addError($error);
            }
        } else {
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.data', 'Invalid data submitted');
            $error->setDetail('No valid data member found in the submitted body');
            $error->setSourcePointer('/data');

            $this->document->addError($error);
        }

        return $data;
    }

    /**
     * Gets the body from the request and parses the JSON into PHP
     * @return array
     */
    private function getJsonBody(JsonParser $jsonParser) {
        try {
            return $jsonParser->parseToPhp($this->request->getBody());
        } catch (ConfigException $exception) {
            list($title, $description) = explode(':', $exception->getMessage());

            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.body', $title, ucfirst(trim($description)));

            $this->document->addError($error);

            return false;
        }
    }

    /**
     * Gets an entry
     * @param \ride\library\orm\model\Model $model Instance of the model
     * @param string $type Name of the resource type
     * @param string $id Id of the resource
     * @param string $source Source pointer for this entry
     * @return mixed Instance of the entry if found, false otherwise and an
     * error is added to the document
     */
    private function getEntry(Model $model, $type, $id, $source = null) {
        $entry = $model->getById($id);
        if ($entry) {
            return $entry;
        }

        $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'resource.found', 'Resource does not exist');
        $error->setDetail('Resource with \'' . $type . '\' and id \'' . $id . '\' does not exist');
        if ($source) {
            $error->setSourcePointer($source);
        }

        $this->document->addError($error);

        return false;
    }

    /**
     * Gets a relationship
     * @param mixed $reference Array containing type and id
     * @param string $source Source pointer for this relationship
     * @return mixed Model entry of the relationship if found, false otherwise
     * and an error is added to the document
     */
    private function getRelationship($reference, $source) {
        // check relationship
        $detail = null;
        if (!is_array($reference)) {
            $code = 'relationship';
            $detail = var_export($reference, true);
        } elseif (!isset($reference['type'])) {
            $code = 'relationship.type';
            $detail = 'No type provided';
        } elseif (!isset($reference['id'])) {
            $code = 'relationship.id';
            $detail = 'No id provided';
        }

        if ($detail) {
            // invalid relationship
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, $code, 'Invalid relationship received', $detail);

            $this->document->addError($error);

            return false;
        }

        // lookup relationship
        $type = $reference['type'];
        $id = $reference['id'];

        $model = $this->getModel($type, $source);
        if (!$model) {
            return false;
        }

        $entry = $model->getById($id);
        if (!$entry) {
            return $entry;
        }

        $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'relationship.found', 'Resource does not exist');
        $error->setDetail('Resource with type \'' . $type . '\' and id \'' . $id . '\' does not exist');
        if ($source) {
            $error->setSourcePointer($source);
        }

        $this->document->addError($error);

        return false;
    }

    /**
     * Resolves the provided relationship field
     * @param \ride\library\orm\model\Model $model
     * @param string $relationship
     * @param string $type
     * @return \ride\library\orm\definition\field\Field
     */
    private function getField(Model $model, $relationship, $type) {
        $field = $model->getMeta()->getField($relationship);
        if (!$field instanceof RelationField) {
            // invalid relationship
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship', 'Could not set relationship');
            $error->setDetail('Relationship \'' . $relationship . '\' does not exist for type \'' . $type . '\'');
            $error->setSourcePointer('/data/relationships/' . $relationship);

            $this->document->addError($error);
        }

        return $field;
    }

    /**
     * Gets the model for the provided API type
     * @param string $type Name of the resource type
     * @param string $source Name of the source property
     * @return \ride\library\orm\model\Model|boolean Instance of the model if
     * valid type, false otherwise and an error is added to the document
     */
    private function getModel($type, $source = null) {
        $modelName = $this->api->getModelName($type);
        if ($modelName) {
            return $this->api->getOrmManager()->getModel($modelName);
        }

        $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'resource.invalid', 'Resource type does not exist');
        $error->setDetail('Resource type \'' . $type . '\' does not exist');
        if ($source) {
            $error->setSourcePointer($source);
        }

        $this->document->addError($error);

        return false;
    }

}
