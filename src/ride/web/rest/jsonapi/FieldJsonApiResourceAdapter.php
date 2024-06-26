<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApiDocument;
use ride\library\http\jsonapi\JsonApiResourceAdapter;
use ride\library\orm\definition\field\BelongsToField;
use ride\library\orm\definition\field\HasOneField;
use ride\library\orm\definition\field\HasManyField;
use ride\library\orm\definition\field\ModelField;
use ride\library\orm\definition\field\RelationField;
use ride\library\orm\model\Model;
use ride\library\orm\OrmManager;

use ride\web\WebApplication;

/**
 * JSON API Resource adapter for the fields of a ORM model
 */
class FieldJsonApiResourceAdapter implements JsonApiResourceAdapter {

    protected $web;

    protected $orm;

    protected $type;

    /**
     * Constructs a new model resource adapter
     * @param \ride\web\WebApplication $web Instance of the web application
     * @param \ride\library\orm\model\Model $model Instance of the model
     * @param string $type Resource type for this model, defaults to model name
     * @return null
     */
    public function __construct(WebApplication $web, OrmManager $orm, $type = null) {
        if ($type === null) {
            $type = 'model-fields';
        }

        $this->web = $web;
        $this->orm = $orm;
        $this->type = $type;
    }

    /**
     * Gets a resource instance for the provided model data
     * @param mixed $model Model to adapt
     * @param \ride\library\http\jsonapi\JsonApiDocument $document Document
     * which is requested
     * @param string $relationshipPath dot-separated list of relationship names
     * @return JsonApiResource|null
     */
    public function getResource($field, JsonApiDocument $document, $relationshipPath = null) {
        if ($field === null) {
            return null;
        } elseif (!$field instanceof ModelField) {
            throw new JsonApiException('Could not get resource: provided data is not an ORM field');
        } elseif (!isset($field->model)) {
            throw new JsonApiException('Could not get resource: provided field data has no model property');
        }

        $modelName = $field->model->getName();
        $query = $document->getQuery();
        $api = $document->getApi();
        $id = $modelName . '-' . $field->getName();

        $resource = $api->createResource($this->type, $id, $relationshipPath);
        $resource->setLink('self', $this->web->getUrl('api.orm.field.detail', array('type' => $this->type, 'id' => $id)));

        if ($query->isFieldRequested($this->type, 'name')) {
            $resource->setAttribute('name', $field->getName());
        }
        if ($query->isFieldRequested($this->type, 'isLocalized')) {
            $resource->setAttribute('isLocalized', $field->isLocalized() ? true : false);
        }
        if ($query->isFieldRequested($this->type, 'type')) {
            if ($field instanceof HasManyField) {
                $type = 'hasMany';
            } elseif ($field instanceof HasOneField) {
                $type = 'hasOne';
            } elseif ($field instanceof BelongsToField) {
                $type = 'belongsTo';
            } else {
                $type = $field->getType();
            }

            $resource->setAttribute('type', $type);
        }

        if ($query->isFieldRequested($this->type, 'model') && $query->isIncluded($relationshipPath)) {
            if (!$field instanceof RelationField) {
                $relationModel = null;
            } else {
                $adapter = $api->getResourceAdapter('models');
                $fieldRelationshipPath = ($relationshipPath ? $relationshipPath . '.' : '') . 'model';

                $relationModel = $adapter->getResource($this->orm->getModel($field->getRelationModelName()), $document, $fieldRelationshipPath);
            }

            $relationship = $api->createRelationship();
            $relationship->setResource($relationModel);

            $resource->setRelationship('model', $relationship);
        }

        // $fields = $meta->getFields();
        // foreach ($fields as $fieldName => $field) {
            // if ($fieldName == 'id' || $fieldName == 'type' || !$query->isFieldRequested($this->type, $fieldName)) {
                // continue;
            // } elseif ($field instanceof PropertyField) {
                // $resource->setAttribute($fieldName, $this->reflectionHelper->getProperty($data, $fieldName));

                // continue;
            // }

            // $fieldType = $api->getModelType($field->getRelationModelName());
            // if ($fieldType === false || !$query->isIncluded($relationshipPath)) {
                // continue;
            // }

            // $fieldRelationshipPath = ($relationshipPath ? $relationshipPath . '.' : '') . $fieldName;

            // $relationship = $api->createRelationship();
            // $relationship->setLink('self', $this->web->getUrl('api.orm.relationship', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));
            // $relationship->setLink('related', $this->web->getUrl('api.orm.related', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));

            // $adapter = $api->getResourceAdapter($fieldType);
            // $value = $this->reflectionHelper->getProperty($data, $fieldName);

            // if ($field instanceof HasManyField) {
                // foreach ($value as $id => $entry) {
                    // $value[$id] = $adapter->getResource($entry, $document, $fieldRelationshipPath);
                // }

                // $relationship->setResourceCollection($value);
            // } else {
                // if ($value) {
                    // $relationshipResource = $adapter->getResource($value, $document, $fieldRelationshipPath);
                // } else {
                    // $relationshipResource = null;
                // }

                // $relationship->setResource($relationshipResource);
            // }

            // $resource->setRelationship($fieldName, $relationship);
        // }

        return $resource;
    }

}
