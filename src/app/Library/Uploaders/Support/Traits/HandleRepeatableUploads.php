<?php

namespace Backpack\CRUD\app\Library\Uploaders\Support\Traits;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Backpack\CRUD\app\Library\Uploaders\Support\Interfaces\UploaderInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait HandleRepeatableUploads
{
    public bool $handleRepeatableFiles = false;

    public ?string $repeatableContainerName = null;

    /*******************************
     * Setters - fluently configure the uploader
     *******************************/
    public function repeats(string $repeatableContainerName): self
    {
        $this->handleRepeatableFiles = true;

        $this->repeatableContainerName = $repeatableContainerName;

        return $this;
    }

    /*******************************
     * Getters
     *******************************/
    public function getRepeatableContainerName(): ?string
    {
        return $this->repeatableContainerName;
    }

    /*******************************
     * Default implementation methods
     *******************************/
    protected function uploadRepeatableFiles($values, $previousValues, $entry = null)
    {
    }

    protected function handleRepeatableFiles(Model $entry): Model
    {
        $values = collect(CRUD::getRequest()->get($this->getRepeatableContainerName()));
        $files = collect(CRUD::getRequest()->file($this->getRepeatableContainerName()));
        $value = $this->mergeValuesRecursive($values, $files);

        if ($this->isRelationship) {
            return $this->uploadRelationshipFiles($entry, $value);
        }

        $entry->{$this->getRepeatableContainerName()} = json_encode($this->processRepeatableUploads($entry, $value));

        return $entry;
    }

    private function uploadRelationshipFiles(Model $entry, mixed $value): Model
    {
        $modelCount = CRUD::get('uploaded_'.$this->getRepeatableContainerName().'_count');
        $value = $value->slice($modelCount, 1)->toArray();

        foreach (app('UploadersRepository')->getRepeatableUploadersFor($this->getRepeatableContainerName()) as $uploader) {
            if (array_key_exists($modelCount, $value) && array_key_exists($uploader->getAttributeName(), $value[$modelCount])) {
                $entry->{$uploader->getAttributeName()} = $uploader->uploadFiles($entry, $value[$modelCount][$uploader->getAttributeName()] ?? null);
            }
        }

        return $entry;
    }

    protected function processRepeatableUploads(Model $entry, Collection $values): Collection
    {
        foreach (app('UploadersRepository')->getRepeatableUploadersFor($this->getRepeatableContainerName()) as $uploader) {
            $uploadedValues = $uploader->uploadRepeatableFiles($values->pluck($uploader->getAttributeName())->toArray(), $this->getPreviousRepeatableValues($entry, $uploader));

            $values = $values->map(function ($item, $key) use ($uploadedValues, $uploader) {
                $item[$uploader->getAttributeName()] = $uploadedValues[$key] ?? null;

                return $item;
            });
        }

        return $values;
    }

    private function retrieveRepeatableFiles(Model $entry): Model
    {
        if ($this->isRelationship) {
            return $this->retrieveRepeatableRelationFiles($entry);
        }

        $repeatableUploaders = app('UploadersRepository')->getRepeatableUploadersFor($this->getRepeatableContainerName());

        $values = $entry->{$this->getRepeatableContainerName()};
        $values = is_string($values) ? json_decode($values, true) : $values;
        $values = array_map(function ($item) use ($repeatableUploaders) {
            foreach ($repeatableUploaders as $upload) {
                $item[$upload->getAttributeName()] = $this->getValuesWithPathStripped($item, $upload);
            }

            return $item;
        }, $values);

        $entry->{$this->getRepeatableContainerName()} = $values;

        return $entry;
    }

    private function retrieveRepeatableRelationFiles(Model $entry)
    {
        switch($this->getRepeatableRelationType()) {
            case 'BelongsToMany':
            case 'MorphToMany':
                $pivotClass = app('crud')->getModel()->{$this->getUploaderSubfield()['baseEntity']}()->getPivotClass();
                $pivotFieldName = 'pivot_'.$this->getAttributeName();
                $connectedEntry = new $pivotClass([$this->getAttributeName() => $entry->$pivotFieldName]);
                $entry->{$pivotFieldName} = $this->retrieveFiles($connectedEntry)->{$this->getAttributeName()};

                break;
            default:
                $entry = $this->retrieveFiles($entry);
        }

        return $entry;
    }

    private function getRepeatableRelationType()
    {
        return $this->getUploaderField()->getAttributes()['relation_type'];
    }

    private function getUploaderField()
    {
        return app('crud')->field($this->getRepeatableContainerName());
    }

    private function getUploaderSubfield()
    {
        return collect($this->getUploaderFieldSubfields())->where('name', '===', $this->getName())->first();
    }

    private function getUploaderFieldSubfields()
    {
        return $this->getUploaderField()->getAttributes()['subfields'];
    }

    private function deleteRepeatableFiles(Model $entry): void
    {
        if ($this->isRelationship) {
            $this->deleteRelationshipFiles($entry);

            return;
        }

        $repeatableValues = collect($entry->{$this->getName()});
        foreach (app('UploadersRepository')->getRepeatableUploadersFor($this->getRepeatableContainerName()) as $upload) {
            if (! $upload->shouldDeleteFiles()) {
                continue;
            }
            $values = $repeatableValues->pluck($upload->getName())->toArray();
            foreach ($values as $value) {
                if (! $value) {
                    continue;
                }

                if (is_array($value)) {
                    foreach ($value as $subvalue) {
                        Storage::disk($upload->getDisk())->delete($upload->getPath().$subvalue);
                    }

                    continue;
                }

                Storage::disk($upload->getDisk())->delete($upload->getPath().$value);
            }
        }
    }
    /*******************************
     * Helper methods
     *******************************/

    /**
     * Given two multidimensional arrays/collections, merge them recursively.
     */
    protected function mergeValuesRecursive(array|Collection $array1, array|Collection $array2): array|Collection
    {
        $merged = $array1;
        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeValuesRecursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Repeatable items send `_order_` parameter in the request.
     * This holds the order of the items in the repeatable container.
     */
    protected function getFileOrderFromRequest(): array
    {
        $items = CRUD::getRequest()->input('_order_'.$this->getRepeatableContainerName()) ?? [];

        array_walk($items, function (&$key, $value) {
            $requestValue = $key[$this->getName()] ?? null;
            $key = $this->handleMultipleFiles ? (is_string($requestValue) ? explode(',', $requestValue) : $requestValue) : $requestValue;
        });

        return $items;
    }

    private function getPreviousRepeatableValues(Model $entry, UploaderInterface $uploader): array
    {
        $previousValues = json_decode($entry->getOriginal($uploader->getRepeatableContainerName()), true);

        if (! empty($previousValues)) {
            $previousValues = array_column($previousValues, $uploader->getName());
        }

        return $previousValues ?? [];
    }

    private function getValuesWithPathStripped(array|string|null $item, UploaderInterface $upload)
    {
        $uploadedValues = $item[$upload->getName()] ?? null;
        if (is_array($uploadedValues)) {
            return array_map(function ($value) use ($upload) {
                return Str::after($value, $upload->getPath());
            }, $uploadedValues);
        }

        return isset($uploadedValues) ? Str::after($uploadedValues, $upload->getPath()) : null;
    }

    private function deleteRelationshipFiles(Model $entry): void
    {
        if (in_array($this->getRepeatableRelationType(), ['BelongsToMany', 'MorphToMany'])) {
            $pivotAttributes = $entry->getAttributes();
            $connectedPivot = $entry->pivotParent->{$this->getRepeatableContainerName()}->where(function ($item) use ($pivotAttributes) {
                $itemPivotAttributes = $item->pivot->only(array_keys($pivotAttributes));

                return $itemPivotAttributes === $pivotAttributes;
            })->first();

            if (! $connectedPivot) {
                return;
            }

            $files = $connectedPivot->getOriginal()['pivot_'.$this->getAttributeName()];

            if (! $files) {
                return;
            }

            if (is_array($files)) {
                foreach ($files as $value) {
                    $value = Str::start($value, $this->getPath());
                    Storage::disk($this->getDisk())->delete($value);
                }

                return;
            }

            $value = Str::start($files, $this->getPath());
            Storage::disk($this->getDisk())->delete($value);

            return;
        }

        $this->deleteFiles($entry);
    }
}
