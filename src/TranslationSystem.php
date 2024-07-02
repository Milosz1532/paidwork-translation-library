<?php

namespace TranslationLibrary;

use Google\Cloud\Translate\TranslateClient;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TranslationSystem
{
    private $keyFilePath;
    private $baseDir;
    private $standardLangDir;
    private $translateLanguages;

    public function __construct($keyFilePath, $baseDir, $standardLangDir, $translateLanguages)
    {
        $this->keyFilePath = $keyFilePath;
        $this->baseDir = $baseDir;
        $this->standardLangDir = $standardLangDir;
        $this->translateLanguages = $translateLanguages;
    }

    public function translateVariables()
    {
        try {
            $translate = new TranslateClient(['keyFilePath' => $this->keyFilePath]);
            $model = 'base';
            $ignoredWords = ["Apple", "Google", "Microsoft", "Facebook", "Discord", "Instagram", "YouTube", "Paidwork", "paidwork"];

            $languageCodes = [
                'en-us' => 'en',
                'en-gb' => 'en',
                'es' => 'es',
                'de' => 'de',
                'fr' => 'fr',
                'it' => 'it',
                'nl' => 'nl',
                'da' => 'da',
                'sv' => 'sv',
                'pl' => 'pl',
                'pt-br' => 'pt',
                'pt-pt' => 'pt',
                'ro' => 'ro',
                'vi' => 'vi',
                'tr' => 'tr',
                'id' => 'id',
                'ru' => 'ru',
                'uk' => 'uk',
                'th' => 'th',
                'ur' => 'ur',
                'ar' => 'ar',
                'fa' => 'fa',
                'bn' => 'bn',
                'hi' => 'hi',
                'pa' => 'pa',
                'jp' => 'ja',
                'zh-cn' => 'zh-CN',
                'zh-tw' => 'zh-TW',
                'ko' => 'ko',
                'hu' => 'hu',
                'cz' => 'cs',
            ];

            $standardFilesList = $this->getFilesListWithPath($this->standardLangDir);
            $comparisonResults = [];

            $langDirs = glob($this->baseDir . $this->translateLanguages, GLOB_ONLYDIR);

            foreach ($langDirs as $langDir) {
                $langCode = basename($langDir);
                $langFilesList = $this->getFilesListWithPath($langDir);

                foreach ($standardFilesList as $standardFile) {
                    $standardFilePath = $this->standardLangDir . '/' . $standardFile;
                    $langFilePath = $langDir . '/' . $standardFile;

                    $standardContents = file_get_contents($standardFilePath);
                    preg_match_all('/\$lang\[\'(.*?)\'\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $standardContents, $standardMatches);
                    $standardVariables = array_combine($standardMatches[1], $standardMatches[2]);

                    if (!file_exists($langFilePath)) {
                        if (!is_dir(dirname($langFilePath))) {
                            mkdir(dirname($langFilePath), 0777, true);
                        }
                        if (!isset($comparisonResults[$langCode])) {
                            $comparisonResults[$langCode] = [];
                        }
                        $comparisonResults[$langCode][$standardFile] = [
                            'type' => 'Failed',
                            'filePath' => $langFilePath,
                            'langCode' => $langCode,
                            'error' => 'File does not exist',
                        ];
                        continue;
                    }

                    $langContents = file_get_contents($langFilePath);
                    preg_match_all('/\$lang\[\'(.*?)\'\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $langContents, $langMatches);
                    $langVariables = array_combine($langMatches[1], $langMatches[2]);

                    $missingVariables = array_diff_key($standardVariables, $langVariables);

                    $relativePath = str_replace($this->baseDir, '', $standardFile);
                    $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                    if (!empty($missingVariables)) {
                        if (!isset($comparisonResults[$langCode])) {
                            $comparisonResults[$langCode] = [];
                        }

                        $result = $this->translateVariablesToFile($langFilePath, $langCode, $missingVariables, $translate, $model, $ignoredWords, $languageCodes);
                        $comparisonResults[$langCode][$relativePath] = $result;
                    }
                }
            }

            return $comparisonResults;
        } catch (\Exception $e) {
            echo "An error occurred: " . $e->getMessage();
            return [];
        }
    }

    private function translateVariablesToFile($filePath, $langCode, $variables, $translate, $model, $ignoredWords, $languageCodes)
    {
        try {
            $maxTextsPerRequest = 80;
            $numTexts = count($variables);
            $numRequests = ceil($numTexts / $maxTextsPerRequest);

            $content = file_exists($filePath) ? file_get_contents($filePath) : "<?php\n\n";
            $targetLanguageCode = $languageCodes[$langCode];

            for ($i = 0; $i < $numRequests; $i++) {
                $startIdx = $i * $maxTextsPerRequest;
                $endIdx = min(($i + 1) * $maxTextsPerRequest, $numTexts);
                $chunkVariables = array_slice($variables, $startIdx, $endIdx - $startIdx);
                $chunkTexts = array_map(function($text) use ($ignoredWords) {
                    $text = $this->replaceWordsWithSpan($text, $ignoredWords);
                    $text = $this->replaceSpecialTagsWithSpan($text);
                    return $text;
                }, array_values($chunkVariables));

                $translations = $translate->translateBatch($chunkTexts, [
                    'target' => $targetLanguageCode,
                    'model' => $model,
                    'format' => "html",
                ]);

                foreach ($translations as $index => $translation) {
                    $key = array_keys($chunkVariables)[$index];
                    $translatedText = $this->removeSpanTags($translation['text']);
                    $translatedText = addslashes($translatedText);
                    $translatedText = preg_replace('/\s+(:)/', '$1', $translatedText);

                    $content .= "\n\$lang['$key'] = \"$translatedText\";";
                }
            }

            file_put_contents($filePath, $content);

            return [
                'type' => 'Success',
                'filePath' => $filePath,
                'langCode' => $langCode,
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'Failed',
                'filePath' => $filePath,
                'langCode' => $langCode,
                'error' => $e->getMessage(),
            ];
        }
    }


    private function replaceWordsWithSpan($text, $words)
    {
        foreach ($words as $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/', "<span translate=\"no\">$word</span>", $text);
        }
        return $text;
    }

    private function removeSpanTags($text)
    {
        return preg_replace('/<span translate="no">(.*?)<\/span>/', '$1', $text);
    }

    private function replaceSpecialTagsWithSpan($text)
    {
        return preg_replace('/(<[^>]*>)(-[A-Za-z0-9_-]+-)(<[^>]*>)/', '$1<span translate="no">$2</span>$3', $text);
    }

    private function getFilesListWithPath($dir)
    {
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

    private function countFilesRecursively($dir, &$filesList = [])
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filesList[] = $file->getPathname();
            }
        }
        return count($filesList);
    }
}

?>
