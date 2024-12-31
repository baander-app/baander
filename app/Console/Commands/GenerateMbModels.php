<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateMbModels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mbb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate PHP models from an XML schema (RNG)';

    /**
     * A map to keep track of schema definitions.
     *
     * @var array
     */
    protected $schemaDefinitions = [];

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $schemaFile = storage_path('app/schemas/musicbrainz_mmd-2.0.rng');
        $modelsDir = app_path('Http/Integrations/MusicBrainz/Models');

        if (!file_exists($schemaFile)) {
            $this->error("Schema file not found: $schemaFile");
            return;
        }

        if (!is_dir($modelsDir)) {
            mkdir($modelsDir, 0777, true);
        }

        $schemas = simplexml_load_file($schemaFile);
        if ($schemas === false) {
            $this->error('Failed to load XML schema.');
            return;
        }

        // Load all schema definitions into a map for quick lookup
        foreach ($schemas->define as $define) {
            $this->schemaDefinitions[(string) $define['name']] = $define;
        }

        // Generate models from schema definitions
        foreach ($this->schemaDefinitions as $elementName => $define) {
            $this->info("Processing element: $elementName");

            $properties = $this->extractProperties($define, $elementName);

            if (!empty($properties)) {
                $formattedElementName = $this->formatElementName($elementName);
                $formattedElementName = $this->removeDefPrefix($formattedElementName);

                // Ensure the class name and root attributes are not included in properties
                $properties = $this->filterInvalidProperties($formattedElementName, $properties);

                $modelClass = $this->generateModelClass($formattedElementName, $properties, $this->schemaDefinitions);
                $fileName = $modelsDir . '/' . $formattedElementName . '.php';
                file_put_contents($fileName, $modelClass);
                $this->info("Generated model: $fileName");
            } else {
                $this->info("No properties found for element: $elementName");
            }
        }

        $this->info("Model generation complete.");
    }

    /**
     * Extract properties (both elements and attributes) from the schema definition.
     *
     * @param \SimpleXMLElement $define
     * @param string $context
     * @param int $depth
     * @return array
     */
    protected function extractProperties($define, $context = '', $depth = 0)
    {
        $properties = [];
        $visited = [];

        // Avoid deep recursion
        $depthLimit = 10;
        if ($depth > $depthLimit) {
            $this->warn("Recursion depth limit reached for element: $context");
            return [];
        }

        $this->extractElementProperties($properties, $define, $visited, $depth);

        // Handle nested structures like <ref>, <optional>, etc.
        foreach ($define->children() as $child) {
            $this->extractElementProperties($properties, $child, $visited, $depth);
        }

        return array_unique($properties);
    }

    /**
     * Extracts properties from elements and attributes and adds them to the properties array.
     *
     * @param array $properties
     * @param \SimpleXMLElement $element
     * @param array $visited
     * @param int $depth
     * @return void
     */
    private function extractElementProperties(array &$properties, $element, array &$visited, $depth)
    {
        if (isset($element->element)) {
            foreach ($element->element as $childElement) {
                if (isset($childElement['name'])) {
                    $properties[] = (string) $childElement['name'];
                }
                $this->extractElementProperties($properties, $childElement, $visited, $depth + 1);
            }
        }

        if (isset($element->attribute)) {
            foreach ($element->attribute as $childAttribute) {
                if (isset($childAttribute['name'])) {
                    $properties[] = (string) $childAttribute['name'];
                }
            }
        }

        if (isset($element->ref)) {
            foreach ($element->ref as $ref) {
                $refName = (string) $ref['name'];

                // Avoid infinite recursion
                if (isset($visited[$refName])) {
                    continue;
                }
                $visited[$refName] = true;

                if (isset($this->schemaDefinitions[$refName])) {
                    $this->info("Resolving reference: $refName");
                    $this->extractElementProperties($properties, $this->schemaDefinitions[$refName], $visited, $depth + 1);
                } else {
                    $this->info("Reference not found: $refName");
                }
            }
        }

        if (isset($element->optional)) {
            foreach ($element->optional as $optional) {
                $this->extractElementProperties($properties, $optional, $visited, $depth + 1);
            }
        }

        if (isset($element->zeroOrMore)) {
            foreach ($element->zeroOrMore as $zeroOrMore) {
                $this->extractElementProperties($properties, $zeroOrMore, $visited, $depth + 1);
            }
        }
    }

    /**
     * Format the element name to create valid PHP class and file names.
     *
     * @param string $elementName
     * @return string
     */
    private function formatElementName(string $elementName): string
    {
        return Str::studly(str_replace(['_', '-'], ' ', $elementName));
    }

    /**
     * Remove the 'Def' prefix from the class name.
     *
     * @param string $className
     * @return string
     */
    private function removeDefPrefix(string $className): string
    {
        return Str::startsWith($className, 'Def') ? Str::replaceFirst('Def', '', $className) : $className;
    }

    /**
     * Format attribute names to create valid PHP variable names.
     *
     * @param string $attributeName
     * @return string
     */
    private function formatAttributeName(string $attributeName): string
    {
        return str_replace('-', '_', $attributeName);
    }

    /**
     * Filter out invalid properties (such as the root attribute) from the class properties.
     *
     * @param string $className
     * @param array $properties
     * @return array
     */
    private function filterInvalidProperties(string $className, array $properties): array
    {
        $formattedClassName = lcfirst($this->formatAttributeName($className));
        return array_filter($properties, function ($property) use ($formattedClassName) {
            return !str_ends_with($property, '-list') && $property !== $formattedClassName;
        });
    }

    /**
     * Generate the PHP model class content.
     *
     * @param string $className
     * @param array $properties
     * @param array $schemaDefinitions
     * @return string
     */
    private function generateModelClass(string $className, array $properties, array $schemaDefinitions): string
    {
        $constructorArgs = array_map(function ($attribute) use ($schemaDefinitions) {
            $formattedAttr = $this->formatAttributeName($attribute);
            // If there's a matching model for the property, use it as the type
            if (isset($schemaDefinitions[$attribute])) {
                $type = $this->formatElementName($attribute);
            } else {
                $type = 'mixed'; // Default type
            }
            return "public $type \$$formattedAttr";
        }, $properties);

        $fromApiDataArgs = array_map(function ($attribute) use ($schemaDefinitions) {
            $formattedAttr = $this->formatAttributeName($attribute);
            if (isset($schemaDefinitions[$attribute])) {
                $typeClass = $this->formatElementName($attribute);
                return "$formattedAttr: $typeClass::fromApiData(\$data['$attribute'])";
            }
            return "$formattedAttr: \$data['$attribute'] ?? null";
        }, $properties);

        $constructorArgsStr = implode(', ', $constructorArgs);
        $fromApiDataArgsStr = implode(",\n            ", $fromApiDataArgs);

        return <<<PHP
<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class $className extends Data
{
    public function __construct($constructorArgsStr)
    {
    }

    public static function fromApiData(array \$data): self
    {
        return new self(
            $fromApiDataArgsStr
        );
    }
}
PHP;
    }
}