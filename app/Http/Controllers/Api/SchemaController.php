<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use SimpleXMLElement;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('/schemas')]
#[Middleware([

])]
class SchemaController extends Controller
{
    protected $schemaDefinitions = [];

    /**
     * @response array[]
     */
    #[Get('/musicbrainz')]
    public function fetchSchema()
    {
        $schemaFile = storage_path('app/public/schemas/musicbrainz_mmd-2.0.rng');

        if (!file_exists($schemaFile)) {
            return response()->json(['error' => "Schema file not found: $schemaFile"], 404);
        }

        $schemas = simplexml_load_file($schemaFile);
        if ($schemas === false) {
            return response()->json(['error' => 'Failed to load XML schema.'], 500);
        }

        // Load all schema definitions into a map for quick lookup
        foreach ($schemas->define as $define) {
            $this->schemaDefinitions[(string) $define['name']] = $define;
        }

        // Generate a structured JSON from the schema definitions
        $schemaData = [];
        foreach ($this->schemaDefinitions as $elementName => $define) {
            $schemaData[] = $this->processElement($define, $elementName);
        }

        return response()->json($schemaData);
    }

    protected function processElement(SimpleXMLElement $element, string $name, int $depth = 0, bool $isParentOptional = false)
    {
        if ($depth > 10) {
            return ['name' => 'Max depth reached'];
        }

        // Flatten optional elements by removing them and adding their children to the parent
        $children = $this->flattenOptionalElements($element);

        $attributes = [];
        foreach ($element->attributes() as $attrName => $attrValue) {
            $attributes[$attrName] = (string) $attrValue;
        }

        $childrenData = [];
        foreach ($children as [$childName, $childElement, $wasOptional]) {
            $childNameWithAsterisk = $wasOptional ? "{$childName}*" : $childName;
            $childrenData[] = $this->processElement($childElement, $childNameWithAsterisk, $depth + 1, $wasOptional);
        }

        return [
            'name' => $name,
            'attributes' => $attributes,
            'children' => $childrenData,
        ];
    }

    protected function flattenOptionalElements(SimpleXMLElement $element)
    {
        $result = [];
        foreach ($element->children() as $childName => $childElement) {
            if ($childName === 'optional') {
                foreach ($childElement->children() as $grandChildName => $grandChild) {
                    $result[] = [$grandChildName, $grandChild, true];
                }
            } else {
                $result[] = [$childName, $childElement, false];
            }
        }
        return $result;
    }
}