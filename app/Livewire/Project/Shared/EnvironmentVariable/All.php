<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class All extends Component
{
    public $resource;
    public string $resourceClass;
    public bool $showPreview = false;
    public ?string $modalId = null;
    public ?string $variables = null;
    public ?string $variablesPreview = null;
    public string $view = 'normal';

    protected $listeners = [
        'saveKey' => 'submit',
        'environmentVariableDeleted' => 'refreshEnvs',
    ];

    protected $rules = [
        'resource.settings.is_env_sorting_enabled' => 'required|boolean',
    ];

    public function mount()
    {
        $this->resourceClass = get_class($this->resource);
        $resourceWithPreviews = ['App\Models\Application'];
        $simpleDockerfile = !is_null(data_get($this->resource, 'dockerfile'));
        if (str($this->resourceClass)->contains($resourceWithPreviews) && !$simpleDockerfile) {
            $this->showPreview = true;
        }
        $this->modalId = new Cuid2;
        $this->sortMe();
    }

    public function sortMe()
    {
        $sortBy = 'key'; // Always sort by key
        $this->resource->load(['environment_variables', 'environment_variables_preview']);
        $this->resource->environment_variables = $this->sortEnvironmentVariables($this->resource->environment_variables, $sortBy);
        $this->resource->environment_variables_preview = $this->sortEnvironmentVariables($this->resource->environment_variables_preview, $sortBy);
        $this->getDevView();
    }

    private function sortEnvironmentVariables($variables, $sortBy)
    {
        return $variables->sortBy(function ($item) use ($sortBy) {
            return strtolower($item->key);
        }, SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function instantSave()
    {
        $this->resource->settings->save();
        $this->sortMe();
        $this->dispatch('success', 'Environment variable settings updated.');
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->resource->environment_variables);
        if ($this->showPreview) {
            $this->variablesPreview = $this->formatEnvironmentVariables($this->resource->environment_variables_preview);
        }
    }

    private function formatEnvironmentVariables($variables)
    {
        return $variables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(locked secret)";
            }
            if ($item->is_multiline) {
                return "$item->key=(multiline, edit in normal view)";
            }
            return "$item->key=$item->value";
        })->join("\n");
    }

    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->sortMe();
    }

    public function submit($data = null)
    {
        try {
            if ($data === null) {
                $this->handleBulkSubmit();
            } else {
                $this->handleSingleSubmit($data);
            }

            $this->sortMe();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function handleBulkSubmit()
    {
        $variables = parseEnvFormatToArray($this->variables);
        $this->deleteRemovedVariables(false, $variables);
        $this->updateOrCreateVariables(false, $variables);

        if ($this->showPreview) {
            $previewVariables = parseEnvFormatToArray($this->variablesPreview);
            $this->deleteRemovedVariables(true, $previewVariables);
            $this->updateOrCreateVariables(true, $previewVariables);
        }

        $this->dispatch('success', 'Environment variables updated.');
    }

    private function handleSingleSubmit($data)
    {
        $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
        if ($found) {
            $this->dispatch('error', 'Environment variable already exists.');
            return;
        }

        $environment = $this->createEnvironmentVariable($data);
        $environment->save();
    }

    private function createEnvironmentVariable($data)
    {
        $environment = new EnvironmentVariable;
        $environment->key = $data['key'];
        $environment->value = $data['value'];
        $environment->is_build_time = $data['is_build_time'] ?? false;
        $environment->is_multiline = $data['is_multiline'] ?? false;
        $environment->is_literal = $data['is_literal'] ?? false;
        $environment->is_preview = $data['is_preview'] ?? false;

        $resourceType = $this->resource->type();
        $resourceIdField = $this->getResourceIdField($resourceType);

        if ($resourceIdField) {
            $environment->$resourceIdField = $this->resource->id;
        }

        return $environment;
    }

    private function getResourceIdField($resourceType)
    {
        $resourceTypes = [
            'application' => 'application_id',
            'standalone-postgresql' => 'standalone_postgresql_id',
            'standalone-redis' => 'standalone_redis_id',
            'standalone-mongodb' => 'standalone_mongodb_id',
            'standalone-mysql' => 'standalone_mysql_id',
            'standalone-mariadb' => 'standalone_mariadb_id',
            'standalone-keydb' => 'standalone_keydb_id',
            'standalone-dragonfly' => 'standalone_dragonfly_id',
            'standalone-clickhouse' => 'standalone_clickhouse_id',
            'service' => 'service_id',
        ];

        return $resourceTypes[$resourceType] ?? null;
    }

    private function deleteRemovedVariables($isPreview, $variables)
    {
        $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
        $this->resource->$method()->whereNotIn('key', array_keys($variables))->delete();
    }

    private function updateOrCreateVariables($isPreview, $variables)
    {
        foreach ($variables as $key => $value) {
            $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
            $found = $this->resource->$method()->where('key', $key)->first();

            if ($found) {
                if (!$found->is_shown_once && !$found->is_multiline) {
                    $found->value = $value;
                    $found->save();
                }
            } else {
                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->is_build_time = false;
                $environment->is_multiline = false;
                $environment->is_preview = $isPreview;

                $this->setEnvironmentResourceId($environment);
                $environment->save();
            }
        }
    }

    private function setEnvironmentResourceId($environment)
    {
        $resourceTypes = [
            'application' => 'application_id',
            'standalone-postgresql' => 'standalone_postgresql_id',
            'standalone-redis' => 'standalone_redis_id',
            'standalone-mongodb' => 'standalone_mongodb_id',
            'standalone-mysql' => 'standalone_mysql_id',
            'standalone-mariadb' => 'standalone_mariadb_id',
            'standalone-keydb' => 'standalone_keydb_id',
            'standalone-dragonfly' => 'standalone_dragonfly_id',
            'standalone-clickhouse' => 'standalone_clickhouse_id',
            'service' => 'service_id',
        ];

        $resourceType = $this->resource->type();
        if (isset($resourceTypes[$resourceType])) {
            $environment->{$resourceTypes[$resourceType]} = $this->resource->id;
        }
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
        $this->getDevView();
    }
}
