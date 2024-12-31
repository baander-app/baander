<?php

/**
 * Detects potential design issues in files within the provided directory.
 *
 * @param string $rootDir The root directory to start scanning
 *
 * @return array
 */
function detectDesignIssues(string $rootDir): array {
    $issues = [];
    $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));

    foreach ($directoryIterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $fileContent = file_get_contents($fileInfo->getPathname());
            $filePath = $fileInfo->getPathname();

            // Detect fat controllers
            if (str_contains($filePath, 'Controller')) {
                if (substr_count($fileContent, 'function') > 5) {
                    $issues[] = "Fat controller detected: $filePath";
                }
            }

            // Detect fat models
            if (str_contains($filePath, 'Model')) {
                if (substr_count($fileContent, 'function') > 15) { // Adjusted threshold to 15
                    $issues[] = "Fat model detected: $filePath";
                }
            }

            // Detect code duplication
            if (substr_count($fileContent, '->') > 20) {
                $issues[] = "Potential code duplication detected: $filePath";
            }

            // Detect high cyclomatic complexity
            $functionMatches = [];
            preg_match_all('/function\s+\w+\s*\(/', $fileContent, $functionMatches);
            foreach ($functionMatches[0] as $functionMatch) {
                $startPos = strpos($fileContent, $functionMatch);
                $functionBody = substr($fileContent, $startPos);
                if (preg_match_all('/\b(if|else|for|foreach|while|case|catch)\b/', $functionBody, $complexityMatches) > 10) {
                    $issues[] = "High cyclomatic complexity in function: $functionMatch in file: $filePath";
                }
            }
        }
    }

    return $issues;
}

$appDirectory = __DIR__ . '/../app';

// Detect design issues in the app directory
$designIssues = detectDesignIssues($appDirectory);

// Print detected issues
foreach ($designIssues as $issue) {
    echo $issue . "\n";
}