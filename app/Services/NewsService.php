<?php

namespace App\Services;
use DB\DBConnectionPool;
use Swoole\Timer;
use phpseclib3\Net\SFTP;
use DB\DbFacade;
use App\Services\TranslateService;
use Swoole\Coroutine\Channel;
use Throwable;

class NewsService
{
    protected $server;
    protected $process;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;
    protected $localPath = 'storage/';

    public function __construct($server, $process, $objDbPool)
    {
        $this->server = $server;
        $this->process = $process;
        $this->worker_id = $process->id;
        $this->objDbPool = $objDbPool;
        $this->dbFacade = new DbFacade();
    }

    public function handle()
    {
        echo PHP_EOL . 'PROCESS ID: '.$this->process->id . PHP_EOL;
        echo PHP_EOL . 'PROCESS PID: '.$this->process->pid . PHP_EOL;

        // Create the Process PID File
        // $rootDir = dirname(__DIR__, 2);
        // $pidFile = $rootDir . DIRECTORY_SEPARATOR . 'test_service.pid';
        // file_put_contents($pidFile, $process->pid);

        // This method also works due to autoload
        // $serviceTwo = new TestServiceTwo();
        // $serviceTwo->handle($process);

        // You can also reload the code using include statement directly in handle() or Timer function
        // include(__DIR__. '/echo.php');

        // The following timer is just to prevent the user process from continuously exiting and restarting as per documentation
        // In such cases we shutdown server, so its very important to have a Timer in Resident Processes
        // Reference: https://wiki.swoole.com/en/#/server/methods?id=addprocess

        // You can modify it according to business logic
        Timer::tick(600000, function() {
            $this->downloadAndProcessFiles();
        });
    }

    protected function createDirectory(string $directoryName): bool
    {
        try {
            // Use a predefined local path instead of storage_path
            $basePath = $this->localPath; // Assuming $this->localPath is defined in your Swoole class
            $fullPath = $basePath . $directoryName;

            // Check if directory exists, and create if it doesn't
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0774, true);
                echo "Directory created: " . $fullPath . PHP_EOL;
            }
        } catch (\Exception $e) {
            // Use echo or a custom logging system to handle errors in Swoole
            echo 'Failed to create directory: ' . $directoryName . '. Error: ' . $e->getMessage() . PHP_EOL;
            return false;
        }

        return true;
    }

    protected function getNewFilesForDownload(array $remoteFiles, string $fileType): array
    {
        $today = date('Ymd');
        $result = [];

        try {
            // Filter remote files based on specific conditions
            foreach ($remoteFiles as $file) {
                if (strpos($file, $today) !== false) {
                    $result[] = $file;
                }
            }

            // Query downloaded files for the specified file type
            $downloadedFilesQuery = "SELECT file_name FROM downloaded_files WHERE file_type = '$fileType'";

            $channel = new Channel(1);
            go(function () use ($downloadedFilesQuery, $channel) {
                try {
                    $result = $this->dbFacade->query($downloadedFilesQuery, $this->objDbPool);
                    $channel->push($result);
                } catch (Throwable $e) {
                    output($e);
                }
            });

            $downloadedFiles = $channel->pop();

            $downloadedFileNames = array_column($downloadedFiles, 'file_name');

            // Filter out files already downloaded
            $result = array_filter($result, function ($file) use ($downloadedFileNames) {
                return !in_array($file, $downloadedFileNames);
            });

            // Download each new file and store in the database
            foreach ($result as $file) {
                // Here you would implement the file download logic

                // After downloading, save file name, type, and timestamp in the database
                $insertQuery = "INSERT INTO downloaded_files (file_name, file_type, downloaded_at) VALUES ('$file', '$fileType', '" . date('Y-m-d H:i:s') . "')";

                go(function () use ($insertQuery) {
                    try {
                        $this->dbFacade->query($insertQuery, $this->objDbPool);
                    } catch (Throwable $e) {
                        output($e);
                    }
                });
            }

        } catch (\Exception $e) {
            echo 'Error while filtering files for download. Error: ' . $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    protected function unZipFiles(array $zipFileNames, string $directory): void
    {
        // Use the predefined local path for the directory
        $zipDirectory = $this->localPath . "zip_$directory/";
        $extractDirectory = $this->localPath . "$directory/";

        foreach ($zipFileNames as $zipFile) {
            $zipPath = $zipDirectory . $zipFile;

            if (file_exists($zipPath)) {
                try {
                    $zip = new \ZipArchive;
                    if ($zip->open($zipPath) === true) {
                        // Extract the contents into a folder named after the zip file (without extension)
                        $zip->extractTo($extractDirectory . pathinfo($zipFile, PATHINFO_FILENAME) . '/');
                        $zip->close();
                        echo "Successfully unzipped file: $zipFile" . PHP_EOL;
                    } else {
                        echo "Failed to open ZIP file: $zipFile" . PHP_EOL;
                    }
                } catch (\Exception $e) {
                    // Use echo or a custom logging solution for error handling
                    echo 'Failed to unzip file: ' . $zipFile . '. Error: ' . $e->getMessage() . PHP_EOL;
                }
            } else {
                echo "ZIP file does not exist: $zipFile" . PHP_EOL;
            }
        }
    }

    protected function downloadAndProcessFiles()
    {
        try {
            // Establish SFTP connection (replace this with your Swoole-compatible SFTP logic)
            $sftp = new SFTP(config('spg_config.spglobal_ftp_url'));
            if (!$sftp->login(config('spg_config.spglobal_username'), config('spg_config.spglobal_password'))) {
                echo "SFTP login failed" . PHP_EOL;
                return;
            }
        } catch (\Exception $e) {
            echo 'Failed to connect to SFTP server. Error: ' . $e->getMessage() . PHP_EOL;
            return;
        }

        try {
            // Create necessary directories using Swoole-compatible logic
            $this->createDirectory('zip_KeyDevelopmentsPlusSpan');
            $this->createDirectory('KeyDevelopmentsPlusSpan');
            $this->createDirectory('zip_KeyDevelopmentsRefSpan');
            $this->createDirectory('KeyDevelopmentsRefSpan');

            // Clear the directories (replace File::cleanDirectory with native directory clean-up)
            $this->cleanDirectory($this->localPath . 'KeyDevelopmentsPlusSpan');
            $this->cleanDirectory($this->localPath . 'KeyDevelopmentsRefSpan');
            $this->cleanDirectory($this->localPath . 'zip_KeyDevelopmentsPlusSpan');
            $this->cleanDirectory($this->localPath . 'zip_KeyDevelopmentsRefSpan');

            $folders = ['KeyDevelopmentsPlusSpan', 'KeyDevelopmentsRefSpan'];

            foreach ($folders as $folder) {
                $directory = $sftp->nlist('Products/' . $folder);
                if ($directory === false) {
                    echo "Failed to list remote directory: $folder" . PHP_EOL;
                    continue;
                }

                $todaysFiles = $this->getNewFilesForDownload($directory, $folder);

                foreach ($todaysFiles as $file) {
                    try {
                        $localFilePath = $this->localPath . 'zip_' . $folder . '/' . $file;
                        $sftp->get('Products/' . $folder . '/' . $file, $localFilePath);
                        //echo "Downloaded file: $file" . PHP_EOL;
                    } catch (\Exception $e) {
                        //echo 'Failed to download file: ' . $file . '. Error: ' . $e->getMessage() . PHP_EOL;
                    }
                }

                // Unzip the downloaded files
                $this->unZipFiles($todaysFiles, $folder);
            }
        } catch (\Exception $e) {
            echo 'Error during SFTP and file processing. Error: ' . $e->getMessage() . PHP_EOL;
        }

        // Process all files using batch inserts (similar to your original logic)
        $this->processKeyDev();
        $this->processKeyDevSplitInfo();
        $this->processKeyDevToObjectToEventType();
        $this->processKeyDevTimeZone();
        $this->processSourceType();
        $this->processKeyDevCategoryType();
        $this->processKeyDevObjectRoleType();
        $this->processKeyDevToSourceType();
    }

    // Helper function to clean directories in Swoole (replaces Laravel's File::cleanDirectory)
    protected function cleanDirectory($directoryPath)
    {
        $files = glob($directoryPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file); // Delete file
            }
        }
    }

    protected function processKeyDev(): void
    {
        try {
            $keyDevelopmentsPlusSpanPath = $this->localPath . 'KeyDevelopmentsPlusSpan';
            $directories = glob($keyDevelopmentsPlusSpanPath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevFilePath = $directory . '/KeyDev.txt';

                if (file_exists($keyDevFilePath)) {
                    $content = file_get_contents($keyDevFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        $translateService = new TranslateService();

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, replacing single quotes with doubled single quotes for PostgreSQL compatibility
                            $keyDevId = str_replace("'", "''", trim($columns[1], "'"));
                            $spEffectiveDate = date('Y-m-d H:i:s', strtotime(trim($columns[2], "'")));
                            $spToDate = date('Y-m-d H:i:s', strtotime(trim($columns[3], "'")));
                            $headline = str_replace("'", "''", trim($columns[4], "'"));
                            $headline_ar = $translateService->translateToArabic($headline);
                            $situation = str_replace("'", "''", trim($columns[5], "'"));
                            $situation_ar = $translateService->translateToArabic($situation);
                            $announcedDate = date('Y-m-d H:i:s', strtotime(trim($columns[6], "'")));
                            $announcedDateTimeZoneId = is_numeric(trim($columns[7], "'")) ? trim($columns[7], "'") : 'NULL';
                            $announceddateUTC = date('Y-m-d H:i:s', strtotime(trim($columns[8], "'")));
                            $enteredDate = date('Y-m-d H:i:s', strtotime(trim($columns[9], "'")));
                            $enteredDateUTC = date('Y-m-d H:i:s', strtotime(trim($columns[10], "'")));
                            $lastModifiedDate = date('Y-m-d H:i:s', strtotime(trim($columns[11], "'")));
                            $lastModifiedDateUTC = date('Y-m-d H:i:s', strtotime(trim($columns[12], "'")));
                            $mostImportantDateUTC = date('Y-m-d H:i:s', strtotime(trim($columns[13], "'")));

                            // Format the NULL values correctly
                            $announcedDateTimeZoneId = $announcedDateTimeZoneId === 'NULL' ? 'NULL' : "'$announcedDateTimeZoneId'";

                            // Check if the row already exists
                            $checkQuery = "SELECT 1 FROM key_dev WHERE \"keyDevId\" = '$keyDevId' LIMIT 1";

                            $channel = new Channel(1);
                            go(function () use ($checkQuery, $channel) {
                                try {
                                    $result = $this->dbFacade->query($checkQuery, $this->objDbPool);
                                    $channel->push($result);
                                } catch (Throwable $e) {
                                    output($e);
                                }
                            });

                            $exists = $channel->pop();

                            if (!$exists) {
                                // Construct the insert query with all columns included

                                $query = "INSERT INTO key_dev (
                                    \"keyDevId\", \"spEffectiveDate\", \"spToDate\", \"headline\", \"situation\", \"headline_ar\", \"situation_ar\",
                                    \"announcedDate\", \"announcedDateTimeZoneId\", \"announceddateUTC\", \"enteredDate\", \"enteredDateUTC\",
                                    \"lastModifiedDate\", \"lastModifiedDateUTC\", \"mostImportantDateUTC\"
                                ) VALUES (
                                    '$keyDevId', '$spEffectiveDate', '$spToDate', '$headline', '$situation', '$headline_ar', '$situation_ar',
                                    '$announcedDate', $announcedDateTimeZoneId, '$announceddateUTC', '$enteredDate', '$enteredDateUTC',
                                    '$lastModifiedDate', '$lastModifiedDateUTC', '$mostImportantDateUTC'
                                )";


                                // Execute the insert query
                                go(function () use ($query) {
                                    try {
                                        $this->dbFacade->query($query, $this->objDbPool);
                                    } catch (Throwable $e) {
                                        output($e);
                                    }
                                });

                                //echo "Inserted row into key_dev with keyDevId: $keyDevId" . PHP_EOL;
                            } else {
                                //echo "Skipping row with duplicate keyDevId: $keyDevId" . PHP_EOL;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            // Output the query for debugging
            echo "Executing Query: $query" . PHP_EOL;
            echo 'Error processing KeyDev data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevSplitInfo(): void
    {
        try {
            $keyDevSplitInfoPath = $this->localPath . 'KeyDevelopmentsPlusSpan';
            $directories = glob($keyDevSplitInfoPath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevSplitInfoFilePath = $directory . '/KeyDevSplitInfo.txt';

                if (file_exists($keyDevSplitInfoFilePath)) {
                    $content = file_get_contents($keyDevSplitInfoFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $keyDevId = str_replace("'", "''", trim($columns[1], "'"));
                            $spEffectiveDate = date('Y-m-d H:i:s', strtotime(trim($columns[2], "'")));
                            $spToDate = date('Y-m-d H:i:s', strtotime(trim($columns[3], "'")));
                            $factor = str_replace("'", "''", trim($columns[7], "'"));

                            // Check if the combination of keyDevId and spEffectiveDate already exists
                            $checkQuery = "SELECT 1 FROM key_dev_split_info WHERE \"keyDevId\" = '$keyDevId' AND \"spEffectiveDate\" = '$spEffectiveDate' LIMIT 1";

                            $channel = new Channel(1);
                            go(function () use ($checkQuery, $channel) {
                                try {
                                    $result = $this->dbFacade->query($checkQuery, $this->objDbPool);
                                    $channel->push($result);
                                } catch (Throwable $e) {
                                    output($e);
                                }
                            });

                            $exists = $channel->pop();

                            if (is_array($exists) && empty($exists)) {
                                // Construct the insert query with all columns included
                                $query = "INSERT INTO key_dev_split_info (
                                    \"keyDevId\", \"spEffectiveDate\", \"spToDate\", \"factor\"
                                ) VALUES (
                                    '$keyDevId', '$spEffectiveDate', '$spToDate', '$factor'
                                )";

                                go(function () use ($query) {
                                    try {
                                        // Execute the insert query
                                        $this->dbFacade->query($query, $this->objDbPool);
                                        //echo "Inserted row into key_dev_split_info with keyDevId: $keyDevId and spEffectiveDate: $spEffectiveDate" . PHP_EOL;
                                    } catch (Throwable $e) {
                                        // Output the query and error message only if there's an exception
                                        echo "Error executing query: $query" . PHP_EOL;
                                        echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                        output($e);
                                    }
                                });
                            } else {
                                //echo "Skipping row with duplicate keyDevId: $keyDevId and spEffectiveDate: $spEffectiveDate" . PHP_EOL;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevSplitInfo data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevToObjectToEventType(): void
    {
        try {
            $keyDevToObjectToEventTypePath = $this->localPath . 'KeyDevelopmentsPlusSpan';
            $directories = glob($keyDevToObjectToEventTypePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevToObjectToEventTypeFilePath = $directory . '/KeyDevToObjectToEventType.txt';

                if (file_exists($keyDevToObjectToEventTypeFilePath)) {
                    $content = file_get_contents($keyDevToObjectToEventTypeFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $keyDevToObjectToEventTypeID = str_replace("'", "''", trim($columns[1], "'"));
                            $spEffectiveDate = date('Y-m-d H:i:s', strtotime(trim($columns[2], "'")));
                            $spToDate = date('Y-m-d H:i:s', strtotime(trim($columns[3], "'")));
                            $keyDevID = str_replace("'", "''", trim($columns[4], "'"));
                            $objectID = 'IQ' . str_replace("'", "''", trim($columns[5], "'"));
                            $keyDevEventTypeID = str_replace("'", "''", trim($columns[6], "'"));
                            $keyDevToObjectRoleTypeID = str_replace("'", "''", trim($columns[7], "'"));

                            // Check if the combination of keyDevToObjectToEventTypeID and spEffectiveDate already exists
                            $checkQuery = "SELECT COUNT(*) AS count FROM key_dev_to_object_to_event_type WHERE \"keyDevToObjectToEventTypeID\" = '$keyDevToObjectToEventTypeID' AND \"spEffectiveDate\" = '$spEffectiveDate'";

                            $channel = new Channel(1);
                            go(function () use ($checkQuery, $channel) {
                                try {
                                    $result = $this->dbFacade->query($checkQuery, $this->objDbPool);
                                    $channel->push($result);
                                } catch (Throwable $e) {
                                    output($e);
                                }
                            });

                            $exists = $channel->pop();

                            if (!$exists || (isset($exists[0]['count']) && $exists[0]['count'] == 0)) {
                                // Construct the insert query with all columns included
                                $query = "INSERT INTO key_dev_to_object_to_event_type (
                                    \"keyDevToObjectToEventTypeID\", \"spEffectiveDate\", \"spToDate\", \"keyDevID\", \"objectID\", \"keyDevEventTypeID\", \"keyDevToObjectRoleTypeID\"
                                ) VALUES (
                                    '$keyDevToObjectToEventTypeID', '$spEffectiveDate', '$spToDate', '$keyDevID', '$objectID', '$keyDevEventTypeID', '$keyDevToObjectRoleTypeID'
                                )";

                                go(function () use ($query) {
                                    try {
                                        // Execute the insert query
                                        $this->dbFacade->query($query, $this->objDbPool);
                                        //echo "Inserted row into key_dev_to_object_to_event_type with keyDevToObjectToEventTypeID: $keyDevToObjectToEventTypeID and spEffectiveDate: $spEffectiveDate" . PHP_EOL;
                                    } catch (Throwable $e) {
                                        // Output the query and error message only if there's an exception
                                        echo "Error executing query: $query" . PHP_EOL;
                                        echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                        output($e);
                                    }
                                });
                            } else {
                                //echo "Skipping row with duplicate keyDevToObjectToEventTypeID: $keyDevToObjectToEventTypeID and spEffectiveDate: $spEffectiveDate" . PHP_EOL;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevToObjectToEventType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevTimeZone(): void
    {
        try {
            $keyDevTimeZonePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $directories = glob($keyDevTimeZonePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevTimeZoneFilePath = $directory . '/KeyDevTimeZone.txt';

                if (file_exists($keyDevTimeZoneFilePath)) {
                    $content = file_get_contents($keyDevTimeZoneFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $announcedDateTimeZoneId = str_replace("'", "''", trim($columns[0], "'"));
                            $announcedDateTimeZoneName = str_replace("'", "''", trim($columns[1], "'"));

                            // Construct the insert query with all columns included
                            $query = "INSERT INTO key_dev_time_zone (
                                \"announcedDateTimeZoneId\", \"announcedDateTimeZoneName\"
                            ) VALUES (
                                '$announcedDateTimeZoneId', '$announcedDateTimeZoneName'
                            )";

                            go(function () use ($query) {
                                try {
                                    // Execute the insert query
                                    $this->dbFacade->query($query, $this->objDbPool);
                                    //echo "Inserted row into key_dev_time_zone with announcedDateTimeZoneId: $announcedDateTimeZoneId" . PHP_EOL;

                                } catch (Throwable $e) {
                                    // Output the query and error message only if there's an exception
                                    echo "Error executing query: $query" . PHP_EOL;
                                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                    output($e);
                                }
                            });
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevTimeZone data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processSourceType(): void
    {
        try {
            $sourceTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $directories = glob($sourceTypePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $sourceTypeFilePath = $directory . '/SourceType.txt';

                if (file_exists($sourceTypeFilePath)) {
                    $content = file_get_contents($sourceTypeFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $sourceTypeId = str_replace("'", "''", trim($columns[0], "'"));
                            $sourceTypeName = str_replace("'", "''", trim($columns[1], "'"));

                            // Construct the insert query with all columns included
                            $query = "INSERT INTO source_type (
                                \"sourceTypeId\", \"sourceTypeName\"
                            ) VALUES (
                                '$sourceTypeId', '$sourceTypeName'
                            )";

                            go(function () use ($query) {
                                try {
                                    // Execute the insert query
                                    $this->dbFacade->query($query, $this->objDbPool);
                                    //echo "Inserted row into source_type with sourceTypeId: $sourceTypeId" . PHP_EOL;
                                } catch (Throwable $e) {
                                    // Output the query and error message only if there's an exception
                                    echo "Error executing query: $query" . PHP_EOL;
                                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                    output($e);

                                }
                            });
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing SourceType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevCategoryType(): void
    {
        try {
            $keyDevCategoryTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $directories = glob($keyDevCategoryTypePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevCategoryTypeFilePath = $directory . '/KeyDevCategoryType.txt';

                if (file_exists($keyDevCategoryTypeFilePath)) {
                    $content = file_get_contents($keyDevCategoryTypeFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $keyDevEventTypeId = str_replace("'", "''", trim($columns[0], "'"));
                            $keyDevCategoryId = str_replace("'", "''", trim($columns[1], "'"));
                            $keyDevCategoryName = str_replace("'", "''", trim($columns[2], "'"));
                            $keyDevEventTypeName = str_replace("'", "''", trim($columns[3], "'"));

                            // Construct the insert query with all columns included
                            $query = "INSERT INTO key_dev_category_type (
                                \"keyDevEventTypeId\", \"keyDevCategoryId\", \"keyDevCategoryName\", \"keyDevEventTypeName\"
                            ) VALUES (
                                '$keyDevEventTypeId', '$keyDevCategoryId', '$keyDevCategoryName', '$keyDevEventTypeName'
                            )";

                            go(function () use ($query) {
                                try {
                                    // Execute the insert query
                                    $this->dbFacade->query($query, $this->objDbPool);
                                    //echo "Inserted row into key_dev_category_type with keyDevEventTypeId: $keyDevEventTypeId" . PHP_EOL;
                                } catch (Throwable $e) {
                                    // Output the query and error message only if there's an exception
                                    echo "Error executing query: $query" . PHP_EOL;
                                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                    output($e);
                                }
                            });
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevCategoryType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevObjectRoleType(): void
    {
        try {
            $keyDevObjectRoleTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $directories = glob($keyDevObjectRoleTypePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevObjectRoleTypeFilePath = $directory . '/KeyDevObjectRoleType.txt';

                if (file_exists($keyDevObjectRoleTypeFilePath)) {
                    $content = file_get_contents($keyDevObjectRoleTypeFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            // Prepare each value, handling single quotes for PostgreSQL compatibility
                            $keyDevToObjectRoleTypeId = str_replace("'", "''", trim($columns[0], "'"));
                            $keyDevToObjectRoleTypeName = str_replace("'", "''", trim($columns[1], "'"));

                            // Construct the insert query with all columns included
                            $query = "INSERT INTO key_dev_object_role_type (
                                \"keyDevToObjectRoleTypeId\", \"keyDevToObjectRoleTypeName\"
                            ) VALUES (
                                '$keyDevToObjectRoleTypeId', '$keyDevToObjectRoleTypeName'
                            )";

                            go(function () use ($query) {
                                try {
                                    // Execute the insert query
                                    $this->dbFacade->query($query, $this->objDbPool);
                                    //echo "Inserted row into key_dev_object_role_type with keyDevToObjectRoleTypeId: $keyDevToObjectRoleTypeId" . PHP_EOL;
                                } catch (Throwable $e) {
                                    // Output the query and error message only if there's an exception
                                    echo "Error executing query: $query" . PHP_EOL;
                                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                    output($e);
                                }
                            });
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevObjectRoleType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevToSourceType(): void
    {
        try {
            $keyDevToSourceTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $directories = glob($keyDevToSourceTypePath . '/*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $keyDevToSourceTypeFilePath = $directory . '/KeyDevToSourceType.txt';

                if (file_exists($keyDevToSourceTypeFilePath)) {
                    $content = file_get_contents($keyDevToSourceTypeFilePath);

                    if (!empty($content)) {
                        $rows = explode('#@#@#', $content);
                        $rows = array_filter($rows, function ($value) {
                            return !is_null($value) && $value !== '';
                        });

                        foreach ($rows as $row) {
                            $columns = explode("'~'", $row);

                            if (count($columns) < 2) {
                                echo 'Skipping row with insufficient columns: ' . $row . PHP_EOL;
                                continue;
                            }

                            if (count($columns) == 3) {
                                $keyDevId = preg_replace('/\D/', '', trim($columns[1], "'"));
                                $sourceTypeId = preg_replace('/\D/', '', trim($columns[2], "'"));
                            } else {
                                $keyDevId = preg_replace('/\D/', '', trim($columns[0], "'"));
                                $sourceTypeId = preg_replace('/\D/', '', trim($columns[1], "'"));
                            }

                            // Check if keyDevId is valid and sourceTypeId is valid
                            if (ctype_digit($keyDevId) && ctype_digit($sourceTypeId)) {
                                // Construct the insert query
                                $query = "INSERT INTO key_dev_to_source_type (
                                    \"keyDevId\", \"sourceTypeId\"
                                ) VALUES (
                                    $keyDevId, $sourceTypeId
                                )";

                                go(function () use ($query) {
                                    try {
                                        // Execute the insert query
                                        $this->dbFacade->query($query, $this->objDbPool);
                                       //echo "Inserted row into key_dev_to_source_type with keyDevId: $keyDevId and sourceTypeId: $sourceTypeId" . PHP_EOL;
                                    } catch (Throwable $e) {
                                        // Output the query and error message only if there's an exception
                                        echo "Error executing query: $query" . PHP_EOL;
                                        echo 'Error: ' . $e->getMessage() . PHP_EOL;
                                        output($e);
                                    }
                                });
                            } else {
                                echo 'Invalid or non-existent keyDevId: ' . $keyDevId . PHP_EOL;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            echo 'Error processing KeyDevToSourceType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

}
