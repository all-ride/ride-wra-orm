<?php

namespace ride\web\rest\jsonapi;

use ride\library\cache\pool\CachePool;
use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApi;
use ride\library\orm\OrmManager;

use ride\web\WebApplication;

/**
 * JSON API implementation for the ORM
 */
class OrmJsonApi extends JsonApi {

    /**
     * Cache key for the model mapping
     * @var string
     */
    const CACHE_TYPES = 'json.api.resource.types';

    /**
     * Instance of the ORM manager
     * @var \ride\library\orm\OrmManager
     */
    protected $orm;

    /**
     * Instance of the web application
     * @var \ride\web\WebApplication
     */
    protected $web;

    /**
     * Cache pool for the model mapping
     * @var \ride\library\cache\pool\CachePool
     */
    protected $cache;

    /**
     * Maximum level of recursiveness
     * @var integer
     */
    protected $maxLevel;

    /**
     * Mapping between models and resource types
     * @var array
     */
    protected $modelTypes;

    /**
     * Constructs a new ORM JSON API
     * @param \ride\library\orm\OrmManager $orm
     * @param \ride\web\WebApplication $web
     * @param \ride\library\cache\pool\CachePool $cache
     * @return null
     */
    public function __construct(OrmManager $orm, WebApplication $web, CachePool $cache) {
        $this->orm = $orm;
        $this->web = $web;
        $this->cache = $cache;

        $this->modelTypes = array();
        $this->resourceAdapters = array();

        $this->loadModelMapping();
    }

    /**
     * Loads the mapping of the models with resource types
     * @return null
     */
    protected function loadModelMapping() {
        $modelTypesCacheItem = $this->cache->get(self::CACHE_TYPES);
        if ($modelTypesCacheItem->isValid()) {
            $this->modelTypes = $modelTypesCacheItem->getValue();

            return;
        }

        $this->modelTypes = array();

        $models = $this->orm->getModels();
        foreach ($models as $modelName => $model) {
            $meta = $model->getMeta();

            $type = $meta->getOption('json.api');
            if (!$type) {
                continue;
            }

            $this->modelTypes[$modelName] = $type;
        }

        $modelTypesCacheItem->setValue($this->modelTypes);

        $this->cache->set($modelTypesCacheItem);
    }

    /**
     * Gets the instance of the ORM manager
     * @return \ride\library\orm\OrmManager
     */
    public function getOrmManager() {
        return $this->orm;
    }

    /**
     * Gets the model name for the provided resource type
     * @param string $type Name of a resource type
     * @return string|boolean Name of a model or false if not found
     */
    public function getModelName($type) {
        return array_search($type, $this->modelTypes);
    }

    /**
     * Gets the name of the resource type for the provided model name
     * @param string $modelName Name of a model
     * @return string|boolean Name of the resource type if found, false otherwise
     */
    public function getModelType($modelName) {
        if (!isset($this->modelTypes[$modelName])) {
            return false;
        }

        return $this->modelTypes[$modelName];
    }

    /**
     * Gets the resource adapter for the provided type
     * @param string $type Name of the resource type
     * @return JsonApiResourceAdapter
     * @throws \ride\library\http\jsonapi\exception\JsonApiException when no
     * resource adapter is set for the provided type
     */
    public function getResourceAdapter($type) {
        if (!isset($this->resourceAdapters[$type])) {
            $modelName = $this->getModelName($type);
            if (!$modelName) {
                throw new JsonApiException('Could not get resource adapter: no adapter set for type ' . $type);
            }

            $model = $this->orm->getModel($modelName);

            $this->setResourceAdapter($type, new OrmJsonApiResourceAdapter($this->web, $model, $type));
        }

        return $this->resourceAdapters[$type];
    }

}
