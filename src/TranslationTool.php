<?php

namespace TranslationLibrary;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

?>
<style>
    h5 {
        color: #333;
        font-size: 18px;
        margin-bottom: 5px;
    }
    ul {
        list-style-type: none;
        padding-left: 20px;
        margin-top: 0;
        margin-bottom: 10px;
    }
    ul ul {
        padding-left: 20px;
        margin-top: 0;
        margin-bottom: 0;
    }
    li {
        margin-bottom: 5px;
    }
    .missing {
        color: #FF6347;
    }
    .additional {
        color: #4169E1; 
    }
    .duplicate {
        color: #FFA500;
    }
    .pattern {
        color: #8A2BE2; 
    }
    .untranslated {
        color: #228B22; 
    }
</style>


<?php


class TranslationTool {
    private $baseDir;
    private $standardLangDir;
    private $excludedWords = [
        'Paidwork', 'Apple', 'Microsoft', 'Amazon', 'Facebook', 
        'Twitter', 'Instagram', 'LinkedIn', 'Pinterest', 'Reddit', 'TikTok'
    ];

    public function __construct($baseDir, $standardLangDir) {
        $this->baseDir = $baseDir;
        $this->standardLangDir = $standardLangDir;
    }

    private function countFilesRecursively($dir, &$filesList = []) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filesList[] = $file->getPathname();
            }
        }
        return count($filesList);
    }

    private function getFilesListWithPath($dir) {
        $basePath = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $dir);
        $filesList = [];
        $this->countFilesRecursively($dir, $filesList);
        $filesWithPath = [];
        foreach ($filesList as $file) {
            $relativePath = str_replace($basePath, '', $file);
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            $filesWithPath[] = $relativePath;
        }
        return $filesWithPath;
    }

    private function countFilesAndDirectoriesInDirectories($baseDir) {
        $subDirs = array_filter(glob($baseDir . '/*'), 'is_dir');

        $counts = [];

        foreach ($subDirs as $dir) {
            $files = glob($dir . '/*.php');
            $subDirsInDir = array_filter(glob($dir . '/*'), 'is_dir');

            $allFilesList = $this->getFilesListWithPath($dir);
            $allFilesCount = count($allFilesList);

            $counts[basename($dir)] = [
                'files' => count($files),
                'directories' => count($subDirsInDir),
                'all_files' => $allFilesCount,
                'file_list' => $allFilesList
            ];
        }

        return $counts;
    }

    private function findMissingFiles($counts, $standardFilesList) {
        $missingFiles = [];

        foreach ($counts as $langCode => $count) {
            $missing = array_diff($standardFilesList, $count['file_list']);
            if (!empty($missing)) {
                $missingFiles[$langCode] = $missing;
            }
        }

        return $missingFiles;
    }

    private function compareLangVariables() {
        $standardFilesList = $this->getFilesListWithPath($this->standardLangDir);
        $comparisonResults = [];

        $langDirs = glob($this->baseDir . '/*', GLOB_ONLYDIR);

        foreach ($langDirs as $langDir) {
            $langCode = basename($langDir);
            $langFilesList = $this->getFilesListWithPath($langDir);

            foreach ($standardFilesList as $standardFile) {
                $standardFilePath = $this->standardLangDir . '/' . $standardFile;
                $langFilePath = $langDir . '/' . $standardFile;

                if (!file_exists($langFilePath)) {
                    if (!isset($comparisonResults[$langCode])) {
                        $comparisonResults[$langCode] = [];
                    }
                    $comparisonResults[$langCode][$standardFile] = [
                        'matches' => false,
                        'missing_variables' => [],
                        'additional_variables' => [],
                        'duplicate_variables' => [],
                        'missing_pattern_values' => [],
                        'untranslated_words' => []
                    ];
                    continue;
                }

                $standardContents = file_get_contents($standardFilePath);
                preg_match_all('/\$lang\[\'(.*?)\'\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $standardContents, $standardMatches);
                $standardVariables = array_combine($standardMatches[1], $standardMatches[2]);

                $langContents = file_get_contents($langFilePath);
                preg_match_all('/\$lang\[\'(.*?)\'\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $langContents, $langMatches);
                $langVariables = array_combine($langMatches[1], $langMatches[2]);

                $missingVariables = array_diff_key($standardVariables, $langVariables);
                $additionalVariables = array_diff_key($langVariables, $standardVariables);
                $duplicateVariables = array_unique(array_diff_assoc($langMatches[1], array_unique($langMatches[1])));

                // Pattern matching
                $missingPatternValues = [];
                foreach ($standardVariables as $key => $value) {
                    if (preg_match_all('/-([A-Z_]+)-/', $value, $patterns)) {
                        foreach ($patterns[0] as $pattern) {
                            if (!isset($langVariables[$key]) || strpos($langVariables[$key], $pattern) === false) {
                                $missingPatternValues[] = "$key ($pattern)";
                            }
                        }
                    }
                }

                // Check untranslated words
                $untranslated_words = [];
                foreach ($this->excludedWords as $word) {
                    foreach ($standardVariables as $key => $value) {
                        if (strpos($value, $word) !== false && (!isset($langVariables[$key]) || strpos($langVariables[$key], $word) === false)) {
                            $untranslated_words[$key] = $word;
                        }
                    }
                }

                $relativePath = str_replace($this->baseDir, '', $standardFile);
                $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                if (!empty($missingVariables) || !empty($additionalVariables) || !empty($duplicateVariables) || !empty($missingPatternValues) || !empty($untranslated_words)) {
                    if (!isset($comparisonResults[$langCode])) {
                        $comparisonResults[$langCode] = [];
                    }

                    $formatted_untranslated_words = [];
                    foreach ($untranslated_words as $key => $word) {
                        $formatted_untranslated_words[] = "[ $key - $word ]";
                    }

                    $comparisonResults[$langCode][$relativePath] = [
                        'matches' => empty($missingVariables) && empty($additionalVariables) && empty($duplicateVariables) && empty($missingPatternValues) && empty($untranslated_words),
                        'missing_variables' => array_keys($missingVariables),
                        'additional_variables' => array_keys($additionalVariables),
                        'duplicate_variables' => $duplicateVariables,
                        'missing_pattern_values' => $missingPatternValues,
                        'untranslated_words' => $formatted_untranslated_words
                    ];
                }
            }
        }

        return $comparisonResults;
    }

    public function runComparison() {
        $standardFilesList = $this->getFilesListWithPath($this->standardLangDir);
        $counts = $this->countFilesAndDirectoriesInDirectories($this->baseDir);
        $missingFiles = $this->findMissingFiles($counts, $standardFilesList);
        $comparisonResults = $this->compareLangVariables();

        echo "<h3>Structure</h3>";

        if (empty($missingFiles)) {
            echo "<b><p style='color: #39BB00'>All subdirectories have the same number of files and directories as 'en-us'.</p></b>";
        } else {
            echo "<p style='color: tomato'>Mismatch found in counts:</p>";
        }

        foreach ($counts as $langCode => $count) {
            $color = isset($missingFiles[$langCode]) ? 'tomato' : '#39BB00';
            echo "<p style='color: $color'>[$langCode]: Files: {$count['files']}, Directories: {$count['directories']}, All Files (recursively): {$count['all_files']}</p>";
            if (isset($missingFiles[$langCode])) {
                echo "<p style='color: red'>Missing files:<br>";
                foreach ($missingFiles[$langCode] as $missingFile) {
                    echo "&emsp; $missingFile<br>";
                }
                echo "</p>";
            }
        }

        echo "<h3>Language Files Comparison Results</h3>";

        foreach ($comparisonResults as $langCode => $files) {
            $langCode = basename($langCode);
            echo "<h5>[$langCode]</h5>";
            foreach ($files as $fileName => $info) {
                echo "<p>&emsp;File: $fileName:</p>";
                if (!$info['matches']) {
                    echo "<ul>";
                    if (!empty($info['missing_variables'])) {
                        echo "<li class='missing'>Missing variables:";
                        echo "<ul>";
                        foreach ($info['missing_variables'] as $variable) {
                            echo "<li>$variable</li>";
                        }
                        echo "</ul>";
                        echo "</li>";
                    }
                    if (!empty($info['additional_variables'])) {
                        echo "<li class='additional'>Additional variables:";
                        echo "<ul>";
                        foreach ($info['additional_variables'] as $variable) {
                            echo "<li>$variable</li>";
                        }
                        echo "</ul>";
                        echo "</li>";
                    }
                    if (!empty($info['duplicate_variables'])) {
                        echo "<li class='duplicate'>Duplicate variables:";
                        echo "<ul>";
                        foreach ($info['duplicate_variables'] as $variable) {
                            echo "<li>$variable</li>";
                        }
                        echo "</ul>";
                        echo "</li>";
                    }
                    if (!empty($info['missing_pattern_values'])) {
                        echo "<li class='pattern'>Missing pattern values:";
                        echo "<ul>";
                        foreach ($info['missing_pattern_values'] as $pattern) {
                            echo "<li>$pattern</li>";
                        }
                        echo "</ul>";
                        echo "</li>";
                    }
                    if (!empty($info['untranslated_words'])) {
                        echo "<li class='untranslated'>Missing word:";
                        echo "<ul>";
                        foreach ($info['untranslated_words'] as $word) {
                            echo "<li>$word</li>";
                        }
                        echo "</ul>";
                        echo "</li>";
                    }
                    echo "</ul>";
                }
                echo "<hr>";
            }
            echo "<br>";
        }
    }
}

?>
