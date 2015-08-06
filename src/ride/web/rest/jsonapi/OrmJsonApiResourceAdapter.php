<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApiDocument;
use ride\library\http\jsonapi\JsonApiResourceAdapter;
use ride\library\orm\definition\field\PropertyField;
use ride\library\orm\definition\field\HasManyField;
use ride\library\orm\entry\Entry;
use ride\library\orm\model\Model;

use ride\web\WebApplication;

/**
 * JSON API Resource adapter for a ORM model
 */
class OrmJsonApiResourceAdapter implements JsonApiResourceAdapter {

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
    }

    /**
     * Gets a resource instance for the provided model data
     * @param mixed $data Data to adapt
     * @param \ride\library\http\jsonapi\JsonApiDocument $document Document which is requested
     * @return JsonApiResource|null
     */
    public function getResource($data, JsonApiDocument $document) {
        if ($data === null) {
            return null;
        } elseif (!$data instanceof Entry) {
            throw new JsonApiException('Could not get resource: provided data is not an ORM entry');
        }

        $query = $document->getQuery();
        $api = $document->getApi();

        $resource = $api->createResource($this->type, $data->getId());
        $resource->setLink('self', $this->web->getUrl('api.orm.detail', array('type' => $this->type, 'id' => $data->getId())));

        $fields = $this->model->getMeta()->getFields();
        foreach ($fields as $fieldName => $field) {
            if ($fieldName == 'id' || $fieldName == 'type' || !$query->isFieldRequested($this->type, $fieldName)) {
                continue;
            } elseif ($field instanceof PropertyField) {
                $resource->setAttribute($fieldName, $this->reflectionHelper->getProperty($data, $fieldName));

                continue;
            }

            $fieldType = $api->getModelType($field->getRelationModelName());
            if ($fieldType === false || !$query->isResourceRequested($fieldType) || !$api->increaseLevel()) {
                continue;
            }

            $relationship = $api->createRelationship();
            $relationship->setLink('self', $this->web->getUrl('api.orm.relationship', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));
            $relationship->setLink('related', $this->web->getUrl('api.orm.related', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));

            $adapter = $api->getResourceAdapter($fieldType);
            $value = $this->reflectionHelper->getProperty($data, $fieldName);

            if ($field instanceof HasManyField) {
                foreach ($value as $id => $entry) {
                    $value[$id] = $adapter->getResource($entry, $document);
                }

                $relationship->setResourceCollection($value);
            } else {
                if ($value) {
                    $relationshipResource = $adapter->getResource($value, $document);
                } else {
                    $relationshipResource = null;
                }

                $relationship->setResource($relationshipResource);
            }

            $resource->setRelationship($fieldName, $relationship);
            $api->decreaseLevel();
        }

        return $resource;
    }

}
