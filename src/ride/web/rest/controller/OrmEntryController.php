<?php

namespace ride\web\rest\controller;

use ride\library\http\jsonapi\exception\BadRequestJsonApiException;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\orm\definition\field\HasManyField;
use ride\library\orm\definition\field\ModelField;
use ride\library\orm\definition\field\PropertyField;
use ride\library\orm\definition\field\RelationField;
use ride\library\orm\meta\ModelMeta;
use ride\library\orm\model\Model;
use ride\library\validation\exception\ValidationException;

use ride\web\rest\controller\AbstractJsonApiController;

/**
 * Controller to implement a JSON API out of a ORM model
 */
class OrmEntryController extends AbstractJsonApiController {

    /**
     * Field processors
     * @var array
     */
    private $fieldProcessors;

    /**
     * Hook to perform extra initializing
     * @return null
     */
    protected function initialize() {
        $this->addSupportedExtension(self::EXTENSION_BULK);

        $this->fieldProcessors = array();
    }

    /**
     * Sets the field processors to this controller
     * @param array $fieldProcessors Array with FieldProcessor instances
     * @return null
     * @see \ride\web\rest\jsonapi\processor\FieldProcessor
     */
    public function setFieldProcessors(array $fieldProcessors) {
        $this->fieldProcessors = $fieldProcessors;
    }

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

            // applies the filters
            $filterStrategies = $this->api->getResourceAdapter($type)->getFilterStrategies();
            foreach ($filterStrategies as $filterStrategy) {
                $filterStrategy->applyFilter($documentQuery, $modelQuery);
            }

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
                $entries = $modelQuery->query();

                $this->document->setLink('self', $this->request->getUrl());
                $this->document->setResourceCollection($type, $entries);
                $this->document->setMeta('total', $modelQuery->count());

                if ($this->request->getQueryParameter('list') == 1) {
                    $this->document->setMeta('list', $model->getOptionsFromEntries($entries));
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
            $this->addRelationshipNotFoundError($type, $relationship);

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
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @return null
     */
    public function saveAction($type, $id = null) {
        // check the resource type
        $model = $this->getModel($type);
        if (!$model) {
            return;
        }

        $json = $this->getBody();

        // validate incoming body
        if (isset($json['data'][0])) {
            $this->useExtension(self::EXTENSION_BULK);

            // bulk operation
            $entries = array();

            foreach ($json['data'] as $index => $entry) {
                // @todo too many dependencies in method call, this should be a separate service.
                $entries[] = $this->getEntryFromStructure($model, $entry, $type, $id, $index);
            }
        } elseif (isset($json['data'])) {
            // single entry
            $entries = $this->getEntryFromStructure($model, $json['data'], $type, $id);
        } else {
            $this->addDataNotFoundError();

            return;
        }

        if ($this->document->getErrors()) {
            // errors occured, stop processing
            return;
        }

        try {
            if (is_array($entries)) {
                $url = $this->getUrl('api.orm.entry.index', array('type' => $type));
                $filter = array();

                foreach ($entries as $entry) {
                    $model->save($entry);

                    $filter[] = 'filter[exact][id][]=' . $entry->getId();
                }

                $url .= '?' . implode('&', $filter);

                // update response document with the entries
                $this->document->setLink('self', $url);
                $this->document->setResourceCollection($type, $entries);

                $this->response->setHeader(Header::HEADER_LOCATION, $url);
                $this->document->setStatusCode(Response::STATUS_CODE_OK);
            } else {
                $entry = $entries;

                // save the entry
                $isNew = $entry->getId() ? false : true;

                $model->save($entry);

                // update response document with the entry
                $url = $this->getUrl('api.orm.entry.detail', array('type' => $type, 'id' => $entry->getId()));

                $this->document->setLink('self', $url);
                $this->document->setResourceData($type, $entry);

                if ($isNew) {
                    $this->response->setHeader(Header::HEADER_LOCATION, $url);
                    $this->document->setStatusCode(Response::STATUS_CODE_CREATED);
                }
            }
        } catch (ValidationException $exception) {
            $this->handleValidationException($exception, $model->getMeta());
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
        $this->document->setLink('related', $this->getUrl('api.orm.entry.related', array('type' => $type, 'id' => $id, 'relationship' => $relationship)));
        $this->document->setRelationshipData($resource);
    }

    /**
     * Saves the incoming resource being a create or an update
     * @param string $type Type of the resource
     * @param string $id Id of the resource
     * @param string $relationship Name of the relationship
     * @return null
     */
    public function relationshipSaveAction($type, $id, $relationship) {
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
        $data = $this->getRelationshipBody($model, $field, $relationship);
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
            $this->handleValidationException($exception, $model->getMeta());
        }
    }

    /**
     * Adds the errors of the validation exception to the document
     * @param \ride\library\validation\exception\ValidationException $exception
     * @return null
     */
    private function handleValidationException(ValidationException $exception, ModelMeta $meta) {
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
    private function getEntryFromStructure(Model $model, $json, $type, $id = null, $index = null) {
        if ($index) {
            $index .= '/';
        }

        // check the submitted type
        if (!isset($json['type'])) {
            $this->addTypeNotFoundError($index);

            return;
        } elseif ($json['type'] != $type) {
            $this->addTypeMatchError($type, $json['type'], $index);

            return;
        }

        $entry = null;
        if ($id) {
            // retrieve the requested entry
            $entry = $this->getEntry($model, $type, $id);
            if (!$entry) {
                return;
            }
        } else {
            // create a new entry
            $entry = $model->createEntry();
        }

        // check the id of the entry
        if (!$entry->getId() && isset($json['id'])) {
            $this->addIdInputError($json['id'], $index);

            return;
        } elseif ($entry->getId() && (!isset($json['id']) || $entry->getId() != $json['id']) || ($id !== null && $entry->getId() != $id)) {
            $this->addIdMatchError($id, $json['id'], $index);

            return;
        }

        // set submitted values to the entry
        $reflectionHelper = $model->getReflectionHelper();
        $meta = $model->getMeta();
        $fields = $meta->getFields();

        // handle submitted attributes
        if (isset($json['attributes'])) {
            foreach ($json['attributes'] as $attribute => $value) {
                if (!isset($fields[$attribute]) || !$fields[$attribute] instanceof PropertyField) {
                    // invalid attribute
                    $this->addAttributeInputError($type, $attribute, $index);

                    continue;
                }

                $field = $fields[$attribute];

                foreach ($this->fieldProcessors as $fieldProcessor) {
                    try {
                        $value = $fieldProcessor->processInputValue($model, $field, $entry, $value);
                    } catch (Exception $exception) {
                        // invalid attribute
                        $this->addAttributeError($attribute, 'input.attribute.processor', 'Attribute \'' . $attribute . '\' generated an error', $exception->getMessage(), $index);

                        $this->getLog()->logException($exception);

                        $value = null;
                    }
                }

                // valid attribute
                $reflectionHelper->setProperty($entry, $attribute, $value);
            }
        }

        // handle submitted relationships
        if (isset($json['relationships'])) {
            foreach ($json['relationships'] as $relationship => $value) {
                if (!isset($fields[$relationship]) || !$fields[$relationship] instanceof RelationField) {
                    // invalid relationship
                    $this->addRelationshipNotFoundError($type, $relationship, $index);
                } elseif (!isset($value['data'])) {
                    // invalid data
                    $this->addRelationshipDataError($relationship, $index);
                } elseif (isset($value['data'][0]) || !$value['data']) {
                    // collection submitted
                    $data = array();
                    foreach ($value['data'] as $reference) {
                        $relationEntry = $this->getRelationship($reference, '/data/' . $index . 'relationships/' . $relationship);
                        if ($relationEntry) {
                            $data[$relationEntry->getId()] = $relationEntry;
                        }
                    }

                    $reflectionHelper->setProperty($entry, $relationship, $data);
                } else {
                    // single value submitted
                    $data = $this->getRelationship($value['data'], '/data/' . $index . 'relationships/' . $relationship);
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
    private function getRelationshipBody(Model $model, ModelField $field, $relationship) {
        $json = $this->getBody();
        $relationModelName = $field->getRelationModelName();

        // check the submitted type
        if (!array_key_exists('data', $json)) {
            $this->addDataNotFoundError();
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
                $this->addDataValidationError('can not be an array');
            }
        } else {
            $this->addDataValidationError('is invalid');
        }

        return $data;
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

        $this->addResourceNotFoundError($type, $id, $source);

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
        if ($entry) {
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
