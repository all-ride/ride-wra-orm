<?php

namespace ride\web\rest\jsonapi;

use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApiDocument;
use ride\library\http\jsonapi\JsonApiResourceAdapter;
use ride\library\orm\model\Model;
use ride\library\orm\OrmManager;

use ride\web\WebApplication;

/**
 * JSON API Resource adapter for the models of the ORM
 */
class ModelJsonApiResourceAdapter implements JsonApiResourceAdapter {

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
            $type = 'models';
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
    public function getResource($model, JsonApiDocument $document, $relationshipPath = null) {
        if ($model === null) {
            return null;
        } elseif (!$model instanceof Model) {
            throw new JsonApiException('Could not get resource: provided data is not an ORM model');
        }

        $query = $document->getQuery();
        $api = $document->getApi();
        $meta = $model->getMeta();
        $id = $meta->getName();

        $resource = $api->createResource($this->type, $meta->getName(), $relationshipPath);
        $resource->setLink('self', $this->web->getUrl('api.orm.model.detail', array('type' => $this->type, 'id' => $id)));

        if ($query->isFieldRequested($this->type, 'name')) {
            $resource->setAttribute('name', $meta->getName());
        }
        if ($query->isFieldRequested($this->type, 'isLocalized')) {
            $resource->setAttribute('isLocalized', $meta->isLocalized() ? true : false);
        }
        if ($query->isFieldRequested($this->type, 'localizedModelName')) {
            $resource->setAttribute('localizedModelName', $meta->getLocalizedModelName());
        }
        if ($query->isFieldRequested($this->type, 'willBlockDeleteWhenUser')) {
            $resource->setAttribute('willBlockDeleteWhenUsed', $meta->willBlockDeleteWhenUsed() ? true : false);
        }
        if ($query->isFieldRequested($this->type, 'entryClassName')) {
            $resource->setAttribute('entryClassName', $meta->getEntryClassName());
        }
        if ($query->isFieldRequested($this->type, 'proxyClassName')) {
            $resource->setAttribute('proxyClassName', $meta->getProxyClassName());
        }
        if ($query->isFieldRequested($this->type, 'modelClassName')) {
            $resource->setAttribute('modelClassName', get_class($model));
        }
        if ($query->isFieldRequested($this->type, 'options')) {
            $resource->setAttribute('options', $meta->getOptions());
        }

        if ($query->isFieldRequested($this->type, 'fields') && $query->isIncluded($relationshipPath)) {
            $adapter = $api->getResourceAdapter('model-fields');

            $value = array();

            $fieldRelationshipPath = ($relationshipPath ? $relationshipPath . '.' : '') . 'fields';

            $fields = $meta->getFields();
            foreach ($fields as $fieldName => $field) {
                $field->model = $model;
                $fieldId = $id . '-' . $fieldName;

                $value[] = $adapter->getResource($field, $document, $fieldRelationshipPath);
            }

            $relationship = $api->createRelationship();
            $relationship->setLink('self', $this->web->getUrl('api.orm.model.relationship', array('type' => $this->type, 'id' => $id, 'relationship' => 'fields')));
            // $relationship->setLink('related', $this->web->getUrl('api.orm.related', array('type' => $this->type, 'id' => $data->getId(), 'relationship' => $fieldName)));
            $relationship->setResourceCollection($value);

            $resource->setRelationship('fields', $relationship);
        }

        return $resource;
    }

}
