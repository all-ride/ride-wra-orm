<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApiDocument;
use ride\library\http\jsonapi\JsonApiResourceAdapter;
use ride\library\http\DataUri;
use ride\library\orm\definition\field\PropertyField;
use ride\library\orm\definition\field\HasManyField;
use ride\library\orm\definition\field\ModelField;
use ride\library\orm\entry\Entry;
use ride\library\orm\model\Model;

use ride\web\rest\jsonapi\filter\FilterStrategy;
use ride\web\WebApplication;

/**
 * JSON API Resource adapter for the entries of a ORM model
 */
class EntryJsonApiResourceAdapter implements JsonApiResourceAdapter {

    protected $web;

    protected $model;

    protected $reflectionHelper;

    protected $type;

    protected $filterStrategies;

    /**
     * Constructs a new model resource adapter
     * @param \ride\web\WebApplication $web Instance of the web application
     * @param \ride\library\orm\model\Model $model Instance of the model
     * @param string $type Resource type for this model, defaults to model name
     * @return null
     */
    public function __construct(WebApplication $web, Model $model, $type = null) {
        if ($type === null) {
            $type = $model->getName();
        }

        $this->web = $web;
        $this->model = $model;
        $this->reflectionHelper = $model->getReflectionHelper();
        $this->type = $type;
        $this->filterStrategies = array();
    }

    /**
     * Sets a filter strategy
     * @param string $name Name of the filter strategy, also used as token of
     * the filter query parameter
     * @param \ride\web\rest\jsonapi\filter\FilterStrategy $filterStrategy
     * Instance of the filter strategy
     * @return null
     */
    public function setFilterStrategy($name, FilterStrategy $filterStrategy) {
        $this->filterStrategies[$name] = $filterStrategy;
    }

    /**
     * Sets the filter strategies
     * @param array $filterStrategies Array with the name of the strategy as key
     * and an instance as value
     * @return null
     * @see setFilterStrategy
     */
    public function setFilterStrategies(array $filterStrategies) {
        foreach ($filterStrategies as $name => $filterStrategy) {
            $this->setFilterStrategy($name, $filterStrategy);
        }
    }

    /**
     * Gets the filter strategies
     * @return array Array with the name of the strategy as key and an instance
     * as value
     */
    public function getFilterStrategies() {
        return $this->filterStrategies;
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
        if ($data === null) {
            return null;
        } elseif (!$data instanceof Entry) {
            throw new JsonApiException('Could not get resource: provided data is not an ORM entry');
        }

        $query = $document->getQuery();
        $api = $document->getApi();

        $resource = $api->createResource($this->type, $data->getId(), $relationshipPath);
        $resource->setLink('self', $this->web->getUrl('api.orm.entry.detail', array('type' => $this->type, 'id' => $data->getId())));

        $fields = $this->model->getMeta()->getFields();
        foreach ($fields as $fieldName => $field) {
            if ($fieldName == 'id' || $fieldName == 'type' || !$query->isFieldRequested($this->type, $fieldName) || $field->getOption('json.api.omit')) {
                continue;
            } elseif ($field instanceof PropertyField) {
                $value = $this->reflectionHelper->getProperty($data, $fieldName);

                $value = $this->decorateValue($document, $field, $data, $value);

                $resource->setAttribute($fieldName, $value);

                continue;
            }

            $fieldType = $api->getModelType($field->getRelationModelName());
            if ($fieldType === false || !$query->isIncluded($relationshipPath)) {
                continue;
            }

            $fieldRelationshipPath = ($relationshipPath ? $relationshipPath . '.' : '') . $fieldName;

            $relationship = $api->createRelationship();
            $relationship->setLink('self', $this->web->getUrl('api.orm.entry.relationship', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));
            $relationship->setLink('related', $this->web->getUrl('api.orm.entry.related', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));

            $adapter = $api->getResourceAdapter($fieldType);
            $value = $this->reflectionHelper->getProperty($data, $fieldName);

            if ($field instanceof HasManyField) {
                if ($value) {
                    foreach ($value as $id => $entry) {
                        $value[$id] = $adapter->getResource($entry, $document, $fieldRelationshipPath);
                    }
                } else {
                    $value = array();
                }

                $relationship->setResourceCollection($value);
            } else {
                if ($value) {
                    $relationshipResource = $adapter->getResource($value, $document, $fieldRelationshipPath);
                } else {
                    $relationshipResource = null;
                }

                $relationship->setResource($relationshipResource);
            }

            $resource->setRelationship($fieldName, $relationship);
        }

        return $resource;
    }

    /**
     * Decorates the provided value for the resulting resource
     * @param \ride\library\http\jsonapi\JsonApiDocument $document Document
     * which is requested
     * @param \ride\library\orm\definition\field\ModelField $field Meta of the
     * field
     * @param mixed $data Data to adapt, an ORM entry
     * @param mixed $value Value to decorate
     * @return mixed Decorated value
     */
    protected function decorateValue(JsonApiDocument $document, ModelField $field, $data, $value) {
        if ($field->getType() === 'boolean') {
            $value = (bool) $value;
        } elseif (($field->getType() === 'datetime' || $field->getType() === 'date' || $field->getType() === 'time') && $value) {
            $value = (integer) $value;
        } elseif (($field->getType() == 'file' || $field->getType() == 'image') && $value) {
            $dataUri = $this->web->getHttpFactory()->createDataUriFromFile($value);
            if ($dataUri) {
                $value = $dataUri->encode();
            } else {
                $value = null;
            }
        }

        return $value;
    }

}
