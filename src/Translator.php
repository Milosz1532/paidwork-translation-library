<?php

namespace TranslationLibrary;

use Google\Cloud\Translate\TranslateClient;

class Translator
{
    private $translate;
    private $model;
    private $ignoredWords;
    private $languageCodes;

    public function __construct($config)
    {
        $this->translate = new TranslateClient([
            'keyFilePath' => $config['keyFilePath']
        ]);
        $this->model = $config['model'];
        $this->ignoredWords = $config['ignoredWords'];
        $this->languageCodes = $config['languageCodes'];
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

    public function translateVariables($filePath, $langCode, $variables)
    {
        $maxTextsPerRequest = 80;
        $numTexts = count($variables);
        $numRequests = ceil($numTexts / $maxTextsPerRequest);
        $content = file_exists($filePath) ? file_get_contents($filePath) : "<?php\n\n";
        $targetLanguageCode = $this->languageCodes[$langCode];

        for ($i = 0; $i < $numRequests; $i++) {
            $startIdx = $i * $maxTextsPerRequest;
            $endIdx = min(($i + 1) * $maxTextsPerRequest, $numTexts);
            $chunkVariables = array_slice($variables, $startIdx, $endIdx - $startIdx);
            $chunkTexts = array_map(function($text) {
                $text = $this->replaceWordsWithSpan($text, $this->ignoredWords);
                $text = $this->replaceSpecialTagsWithSpan($text);
                return $text;
            }, array_values($chunkVariables));

            $translations = $this->translate->translateBatch($chunkTexts, [
                'target' => $targetLanguageCode,
                'model' => $this->model,
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
        echo "<br><span style='color:green;'>Variables in file <b>$filePath</b> have been successfully translated to language <b>$langCode</b>.</span>";
    }

    public function compareLangVariables($baseDir, $standardLangDir, $translateLanguages)
    {
        $standardFilesList = Utils::getFilesListWithPath($standardLangDir);
        $comparisonResults = [];
        $langDirs = glob($baseDir . $translateLanguages, GLOB_ONLYDIR);

        foreach ($langDirs as $langDir) {
            $langCode = basename($langDir);
            $langFilesList = Utils::getFilesListWithPath($langDir);

            foreach ($standardFilesList as $standardFile) {
                $standardFilePath = $standardLangDir . '/' . $standardFile;
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
                        'matches' => false,
                        'missing_variables' => array_keys($standardVariables),
                    ];
                    $this->translateVariables($langFilePath, $langCode, $standardVariables);
                    continue;
                }

                $langContents = file_get_contents($langFilePath);
                preg_match_all('/\$lang\[\'(.*?)\'\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $langContents, $langMatches);
                $langVariables = array_combine($langMatches[1], $langMatches[2]);

                $missingVariables = array_diff_key($standardVariables, $langVariables);
                $relativePath = str_replace($baseDir, '', $standardFile);
                $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                if (!empty($missingVariables)) {
                    if (!isset($comparisonResults[$langCode])) {
                        $comparisonResults[$langCode] = [];
                    }
                    $comparisonResults[$langCode][$relativePath] = [
                        'matches' => empty($missingVariables),
                        'missing_variables' => array_keys($missingVariables),
                    ];
                    $this->translateVariables($langFilePath, $langCode, $missingVariables);
                }
            }
        }

        return $comparisonResults;
    }
}
