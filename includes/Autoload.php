<?php

// Autoload classes
// Note: In nginx file systems, folder name is case sensitive, therefore use file/path names exactly...
// ... as class names (in our case exception is for ../app/ folder)

// Converts namespace App/Core/Service/ClassName to path ...
// ... [Project_Folder_Path]/app/Core/Service/ClassName.php; note /app in all small cased letters.
spl_autoload_register(function ($fullyQualifiedClassName) {

    // fullyQualifiedClassName is classname prefixed with namespace
    // Replace backslashes in namespace with directory separators (/)
    $filePath = str_replace('\\', DIRECTORY_SEPARATOR, $fullyQualifiedClassName);

    // Split the namespace into two parts so we lowercase the first part
    $pathParts = explode(DIRECTORY_SEPARATOR, $filePath, 2);

    // Lowercase the first part and concatenate the rest of the path
    $path = strtolower($pathParts[0]) . DIRECTORY_SEPARATOR . $pathParts[1];

    // Make the full file path and include the file
    $rootDir = dirname(__DIR__, 1);
    $fullFilePath = $rootDir . DIRECTORY_SEPARATOR . $path . '.php';

    if (file_exists($fullFilePath)) {
        require_once $fullFilePath;
    } else {
        throw new \RuntimeException("File not found: $fullFilePath");
    }
});
