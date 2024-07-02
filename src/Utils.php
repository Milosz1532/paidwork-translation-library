<?php

namespace TranslationLibrary;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class Utils
{
    public static function countFilesRecursively($dir, &$filesList = [])
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filesList[] = $file->getPathname();
            }
        }
        return count($filesList);
    }

    public static function getFilesListWithPath($dir)
    {
        $basePath = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $dir);
        $filesList = [];
        self::countFilesRecursively($dir, $filesList);
        $filesWithPath = [];
        foreach ($filesList as $file) {
            $relativePath = str_replace($basePath, '', $file);
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            $filesWithPath[] = $relativePath;
        }
        return $filesWithPath;
    }
}
