<?php

namespace App\Services;
use Throwable;
use DB\DbFacade;
use Swoole\Timer;
use DB\DBConnectionPool;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Channel;
use Bootstrap\SwooleTableFactory;
use App\Services\TranslateService;
use App\Core\Services\SftpClient;

class NewsService
{
    protected $server;
    protected $process;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;
    protected $localPath = null;

    protected $fileQueue;
    protected $unzipQueue;

    protected $activeSftpDownloads = 0; // Track active downloads
    protected $maxSftpDownloads; // Adjust this limit based on your server capacity

    public function __construct($server, $process, $objDbPool)
    {
        $this->server = $server;
        $this->process = $process;
        $this->worker_id = $process->id;
        $this->objDbPool = $objDbPool;
        $this->maxSftpDownloads = config('app_config.max_sftp_downloads');
        $this->dbFacade = new DbFacade();
        $this->localPath = dirname(__DIR__, 2) . '/storage/';
        
        // Create a queue (Channel) for unzipped files
        $this->fileQueue = new Channel(100); // Buffer size of 100 files
        $this->unzipQueue = new Channel(100); // Queue for files to be unzipped
        
        $this->startFileParsingConsumer();// Start the parsing consumer (runs in a separate coroutine)
        $this->unZipFile();// Start the unzip processing coroutin
    }

    public function handle()
    {
        output('PROCESS PID: ' . $this->process->pid);
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


        // Create necessary directories using Swoole-compatible logic
        $this->createDirectory('zip_KeyDevelopmentsPlusSpan');
        $this->createDirectory('KeyDevelopmentsPlusSpan');
        $this->createDirectory('zip_KeyDevelopmentsRefSpan');
        $this->createDirectory('KeyDevelopmentsRefSpan');
                
        // You can modify it according to business logic
        go(function() {
            try {
                $mainIterationCounter = 1;
                while(true) {
                    // Download and Process the Files
                    $this->downloadAndProcessFiles();
                    
                    // Clear the directories (replace File::cleanDirectory with native directory clean-up)
                    output(__CLASS__ . ' --> Before Cleaning the Directories');
                    //$this->cleanDirectory($this->localPath . 'KeyDevelopmentsPlusSpan');
                    //$this->cleanDirectory($this->localPath . 'KeyDevelopmentsRefSpan');
                    
                    ++$mainIterationCounter;
                    output('Sleep and run the next iteration --> ' . $mainIterationCounter);
                    Co::sleep(600); // 10 Minutes
                    // Co::sleep(150); // 2.5 Minutes
                }
            }
            catch(Throwable $e) {
                output('Closing News Process');
                output($e);
            }
        });

        // Timer::tick(600000, function() {
        //     $this->downloadAndProcessFiles();
        // });
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
                output("Directory created: " . $fullPath);
            }
        } catch (\Exception $e) {
            // Use echo or a custom logging system to handle errors in Swoole
            output('Failed to create directory: ' . $directoryName . '. Error: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    protected function getNewFilesForDownload(array $remoteFiles, string $fileType): array
    {
        $today = date('Ymd');
        $result = [];
        $latestRefSpanFull = null;

        try {
            // Filter remote files based on specific conditions
            foreach ($remoteFiles as $file) {
                if (strpos($file, $today) !== false) {
                    $result[] = $file;
                }
                // Track latest KeyDevelopmentsRefSpanFull file
                if (strpos($file, 'KeyDevelopmentsRefSpanFull') !== false) {
                    if (!$latestRefSpanFull || strcmp($file, $latestRefSpanFull) > 0) {
                        $latestRefSpanFull = $file;
                    }
                }
            }

            // Query downloaded files for the specified file type
            $downloadedFilesQuery = "SELECT file_name FROM downloaded_files WHERE file_type = '$fileType'";

            $channel = new Channel(1);
            go(function () use ($downloadedFilesQuery, $channel) {
                try {
                    $downloadedFiles = $this->dbFacade->query($downloadedFilesQuery, $this->objDbPool);
                    $channel->push($downloadedFiles);
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

            // Ensure KeyDevelopmentsRefSpanFull is included if not already downloaded
            if ($latestRefSpanFull && !in_array($latestRefSpanFull, $downloadedFileNames)) {
                $result[] = $latestRefSpanFull;
            }

            output('News Files To Download');
            output($result);

        } catch (\Exception $e) {
            output('Error while filtering files for download. Error: ' . $e->getMessage());
        }

        return $result;
    }
    
    /**
     * Unzip the news file
     *
     * @param  mixed $zipFileName The name of the File
     * @param  mixed $directory The name of the Folder
     * @return void
     */
    protected function unZipFile(): void
    {
        go(function () {
            while (true) {
                $fileInfo = $this->unzipQueue->pop();
                if (!$fileInfo) continue;
    
                go(function () use ($fileInfo) {
                    [$zipFileName, $directory] = $fileInfo;
                    $extractDirectory = $this->localPath . "$directory/";
                    $zipFilePath = $this->localPath . "zip_$directory/" . $zipFileName;

                    output("Start unzipped file: $zipFileName");
    
                    if (file_exists($zipFilePath)) {
                        try {
                            $zip = new \ZipArchive;
                            if ($zip->open($zipFilePath) === true) {
                                // Extract the contents into a folder named after the zip file
                                $zip->extractTo($extractDirectory . pathinfo($zipFileName, PATHINFO_FILENAME) . '/');
                                $zip->close();
                                output("Successfully unzipped file: $zipFileName");
    
                                // Push the extracted file into the queue for parsing
                                $this->fileQueue->push(['file' => $zipFileName, 'folder' => $directory]);
    
                                // Delete the .zip file after successful extraction
                                if (file_exists($zipFilePath) && unlink($zipFilePath)) {
                                    output("Deleted ZIP file: $zipFilePath");
                                } else {
                                    output("Failed to delete ZIP file: $zipFilePath");
                                }
                            } else {
                                throw new \Exception("Failed to open zip file: $zipFilePath");
                            }
                        } catch (\Exception $e) {
                            output("Error unzipping file: " . $e->getMessage());
                        }
                    } else {
                        output("Zip file does not exist: $zipFilePath");
                    }
                });
            }
        });
    }    
    
    protected function downloadAndProcessFiles()
    {
        try {
            $folders = ['KeyDevelopmentsPlusSpan','KeyDevelopmentsRefSpan'];
            // $folders = ['KeyDevelopmentsPlusSpan'];

            // Using wait-group so each next iteration only starts when current iteration is completed
            $fileListBarrier = Barrier::make();

            foreach ($folders as $folder) {

                go(function () use ($folder, $fileListBarrier) {
                    output('Coroutine ID --> ' . Co::getCid());
                    // Create the SFTP Service

                    $SftpForListing = new SftpClient(config('spg_config.spglobal_ftp_url'), config('spg_config.spglobal_username'), config('spg_config.spglobal_password'));
                    $SftpForListing->connect();

                    // Disconnect the SFTP Service when Coroutine Exits
                    Co::defer(function () use ($SftpForListing) {
                        output('Calling Defer remoteFilesList SFTP  ' . Co::getCid());
                        $SftpForListing->disconnect();

                        unset($SftpForListing);
                    });

                    output('Before --> remoteFilesList the Products Directory For --> ' . $folder);
                    $directory = $SftpForListing->remoteFilesList('/Products/' . $folder);

                    // ftp_nlist()
                    if ($directory === false) {
                        output("Failed to list remote directory: $folder");
                        return;
                        // continue;
                    }

                    output('After --> remoteFilesList the Products Directory For --> ' . $folder);

                    // Today's Files To be downloaded 
                    $todaysFiles = $this->getNewFilesForDownload($directory, $folder);
                    
                    // output('Today Files To Download --> Directory --> ' . $folder);
                    // output($todaysFiles);

                    $channel = new Channel(10);
                    foreach ($todaysFiles as $file) {

                        // **Wait for an available download slot**
                        while ($this->activeSftpDownloads >= $this->maxSftpDownloads) {
                            Co::sleep(1);
                        }

                        // Increment active downloads before starting
                        $this->activeSftpDownloads++;

                        go(function () use ($folder, $file, $channel, $fileListBarrier) {
                            $channel->push(1);
                            try {
                                $SftpForDownloading = new SftpClient(config('spg_config.spglobal_ftp_url'), config('spg_config.spglobal_username'), config('spg_config.spglobal_password'));
                                $SftpForDownloading->connect();

                                // Disconnect the SFTP Service when Coroutine Exits
                                Co::defer(function () use ($SftpForDownloading) {
                                    output('Calling Defer Downloading SFTP --> ' . Co::getCid());
                                    $SftpForDownloading->disconnect();

                                    unset($SftpForDownloading);
                                });

                                $localFilePath = $this->localPath . 'zip_' . $folder . '/' . $file;

                                output('Before Downloading File --> ' . $file);

                                $fileDownloaded = $SftpForDownloading->download('/Products/' . $folder . '/' . $file, $localFilePath);

                                if (!$fileDownloaded) {
                                    throw new \Exception('Failed to download the file --> '. $file);
                                }

                                output('After Downloading File --> ' . $file);

                                $this->unzipQueue->push([$file, $folder]);

                            } catch (\Exception $e) {
                                output('Error in Downloading: ' . $file . ' | Error: ' . $e->getMessage());
                            } finally {
                                $channel->pop();
                                Co::defer(function () {
                                    if ($this->activeSftpDownloads > 0) {
                                        $this->activeSftpDownloads--; // Ensure count updates safely
                                    }
                                });
                            }
                        });

                        if ($this->activeSftpDownloads >= $this->maxSftpDownloads) {
                            Co::sleep(1); // If max downloads reached, wait longer
                        } else {
                            Co::sleep(0.05); // Reduce sleep time if slots are available
                        }
                    }

                });
                // This Co Sleep is Important to avoid SFTP error
                Co::sleep(0.1);                
            }

            // Wait for current iteration to Complete
            Barrier::wait($fileListBarrier);
            output('After Barrier Wait');
            unset($fileListBarrier);
        } catch (\Exception $e) {
            echo 'Error during SFTP and file processing. Error: ' . $e->getMessage() . PHP_EOL;
        }

    }

    public function startFileParsingConsumer()
    {
        go(function () {
            while (true) {
                // Wait for a file to be added to the queue
                $data = $this->fileQueue->pop();
                if ($data === false) {
                    continue; // Skip if queue is empty
                }

                $file = $data['file'];
                $folder = $data['folder'];

                output("Starting parsing for: $file");

                // Remove ".zip" extension from file name
                $fileName = pathinfo($file, PATHINFO_FILENAME);

                try {
                    // Call all existing parsing functions asynchronously
                    if($folder == 'KeyDevelopmentsPlusSpan'){
                        go(function () use($fileName){ $this->processKeyDev($fileName); });
                        go(function () use($fileName){ $this->processKeyDevSplitInfo($fileName); });
                        go(function () use($fileName){ $this->processKeyDevToObjectToEventType($fileName); });
                    }elseif($folder == 'KeyDevelopmentsRefSpan'){
                        go(function () use($fileName){ $this->processKeyDevTimeZone($fileName); });
                        go(function () use($fileName){ $this->processSourceType($fileName); });
                        go(function () use($fileName){ $this->processKeyDevCategoryType($fileName); });
                        go(function () use($fileName){ $this->processKeyDevObjectRoleType($fileName); });
                        go(function () use($fileName){ $this->processKeyDevToSourceType($fileName); });
                    }

                    output("Parsing completed for: $file");

                    // After Parsing complete , save file name, type, and timestamp in the database
                    $insertQuery = "INSERT INTO downloaded_files (file_name, file_type, downloaded_at) VALUES ('$file', '$folder', '" . date('Y-m-d H:i:s') . "')";

                    go(function () use ($insertQuery, $file) {
                        try {
                            $this->dbFacade->query($insertQuery, $this->objDbPool);
                            output('Inserted File into downloaded_files Table --> ' . $file);
                        } catch (Throwable $e) {
                            output($e);
                        }
                    });

                } catch (\Exception $e) {
                    output("Parsing failed for $file: " . $e->getMessage());
                }
                Co::sleep(0.05);
            }
        });
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

    protected function processKeyDev($fileName): void
    {
        try {
                $keyDevelopmentsPlusSpanPath = $this->localPath . 'KeyDevelopmentsPlusSpan';
                $keyDevFilePath = $keyDevelopmentsPlusSpanPath .'/'.$fileName. '/KeyDev.txt';

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
                            $headline_ar = str_replace("'", "''", $translateService->translateToArabic($headline));
                            $situation = str_replace("'", "''", trim($columns[5], "'"));
                            $situation_ar = str_replace("'", "''", $translateService->translateToArabic($situation));
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

                                $data = [
                                    'topic' => 'news',
                                    'message_data' => [
                                        'keyDevId' => $keyDevId,
                                        'headline' => $headline,
                                        'announcedDate' => $announcedDate,
                                    ],
                                ];
                                
                                // Broadcast latest news
                                go(function() use($data){
                    
                                    for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
                                        $this->server->sendMessage($data, $worker_id);
                                    }
                                    
                                });

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
        

        } catch (\Exception $e) {
            // Output the query for debugging
            echo "Executing Query: $query" . PHP_EOL;
            echo 'Error processing KeyDev data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevSplitInfo($fileName): void
    {
        try {
                $keyDevSplitInfoPath = $this->localPath . 'KeyDevelopmentsPlusSpan';

                $keyDevSplitInfoFilePath = $keyDevSplitInfoPath .'/'.$fileName. '/KeyDevSplitInfo.txt';

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

        } catch (\Exception $e) {
            echo 'Error processing KeyDevSplitInfo data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevToObjectToEventType($fileName): void
    {
        try {
                $keyDevToObjectToEventTypePath = $this->localPath . 'KeyDevelopmentsPlusSpan';

                $keyDevToObjectToEventTypeFilePath = $keyDevToObjectToEventTypePath .'/'.$fileName. '/KeyDevToObjectToEventType.txt';

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

        } catch (\Exception $e) {
            echo 'Error processing KeyDevToObjectToEventType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevTimeZone($fileName): void
    {
        try {
                $keyDevTimeZonePath = $this->localPath . 'KeyDevelopmentsRefSpan';

                $keyDevTimeZoneFilePath = $keyDevTimeZonePath .'/'.$fileName. '/KeyDevTimeZone.txt';

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

                            //Construct the insert query and update if announcedDateTimeZoneId
                            $query = "INSERT INTO key_dev_time_zone (
                                \"announcedDateTimeZoneId\", \"announcedDateTimeZoneName\"
                            ) VALUES (
                                '$announcedDateTimeZoneId', '$announcedDateTimeZoneName'
                            ) ON CONFLICT (\"announcedDateTimeZoneId\") DO UPDATE SET
                                \"announcedDateTimeZoneName\" = EXCLUDED.\"announcedDateTimeZoneName\"";

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

        } catch (\Exception $e) {
            echo 'Error processing KeyDevTimeZone data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processSourceType($fileName): void
    {
        try {
                $sourceTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';

                $sourceTypeFilePath = $sourceTypePath .'/'.$fileName. '/SourceType.txt';

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

                            // Construct the insert query and update if sourceTypeId already exists
                            $query = "INSERT INTO source_type (
                                \"sourceTypeId\", \"sourceTypeName\"
                            ) VALUES (
                                '$sourceTypeId', '$sourceTypeName'
                            ) ON CONFLICT (\"sourceTypeId\") DO UPDATE SET \"sourceTypeName\" = EXCLUDED.\"sourceTypeName\"";                             

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

        } catch (\Exception $e) {
            echo 'Error processing SourceType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevCategoryType($fileName): void
    {
        try {
            $keyDevCategoryTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';
            $keyDevCategoryTypeFilePath = $keyDevCategoryTypePath . '/' . $fileName . '/KeyDevCategoryType.txt';
    
            if (file_exists($keyDevCategoryTypeFilePath)) {
                $content = file_get_contents($keyDevCategoryTypeFilePath);
    
                if (!empty($content)) {
                    $rows = explode('#@#@#', $content);
                    $rows = array_filter($rows, fn($value) => !is_null($value) && $value !== '');
    
                    foreach ($rows as $row) {
                        $columns = explode("'~'", $row);
    
                        // Escape values for PostgreSQL
                        $keyDevEventTypeId = str_replace("'", "''", trim($columns[0], "'"));
                        $keyDevCategoryId = str_replace("'", "''", trim($columns[1], "'"));
                        $keyDevCategoryName = str_replace("'", "''", trim($columns[2], "'"));
                        $keyDevEventTypeName = str_replace("'", "''", trim($columns[3], "'"));
    
                        // Compose the SELECT to check existence
                        $checkQuery = "SELECT 1 FROM key_dev_category_type
                            WHERE \"keyDevEventTypeId\" = '$keyDevEventTypeId'
                            AND \"keyDevCategoryId\" = '$keyDevCategoryId' LIMIT 1";
    
                        $channel = new Channel(1);
    
                        go(function () use ($checkQuery, $channel) {
                            try {
                                $exists = $this->dbFacade->query($checkQuery, $this->objDbPool);
                                $channel->push($exists);
                            } catch (Throwable $e) {
                                output("Check query error: $checkQuery");
                                output($e);
                                $channel->push(null);
                            }
                        });
    
                        $exists = $channel->pop();
    
                        if ($exists && count($exists) > 0) {
                            // Do an update
                            $updateQuery = "UPDATE key_dev_category_type SET
                                \"keyDevCategoryName\" = '$keyDevCategoryName',
                                \"keyDevEventTypeName\" = '$keyDevEventTypeName'
                                WHERE \"keyDevEventTypeId\" = '$keyDevEventTypeId'
                                AND \"keyDevCategoryId\" = '$keyDevCategoryId'";
    
                            go(function () use ($updateQuery) {
                                try {
                                    $this->dbFacade->query($updateQuery, $this->objDbPool);
                                } catch (Throwable $e) {
                                    output("Update failed: $updateQuery");
                                    output($e);
                                }
                            });
                        } else {
                            // Do an insert
                            $insertQuery = "INSERT INTO key_dev_category_type (
                                \"keyDevEventTypeId\", \"keyDevCategoryId\", \"keyDevCategoryName\", \"keyDevEventTypeName\"
                            ) VALUES (
                                '$keyDevEventTypeId', '$keyDevCategoryId', '$keyDevCategoryName', '$keyDevEventTypeName'
                            )";
    
                            go(function () use ($insertQuery) {
                                try {
                                    $this->dbFacade->query($insertQuery, $this->objDbPool);
                                } catch (Throwable $e) {
                                    output("Insert failed: $insertQuery");
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

    protected function processKeyDevObjectRoleType($fileName): void
    {
        try {
                $keyDevObjectRoleTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';

                $keyDevObjectRoleTypeFilePath = $keyDevObjectRoleTypePath .'/'.$fileName. '/KeyDevObjectRoleType.txt';

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

                            // Construct the insert query and update if keyDevToObjectRoleTypeId  already exists
                            $query = "INSERT INTO key_dev_object_role_type (
                                \"keyDevToObjectRoleTypeId\", \"keyDevToObjectRoleTypeName\"
                            ) VALUES (
                                '$keyDevToObjectRoleTypeId', '$keyDevToObjectRoleTypeName'
                            ) ON CONFLICT (\"keyDevToObjectRoleTypeId\") DO UPDATE SET
                                \"keyDevToObjectRoleTypeName\" = EXCLUDED.\"keyDevToObjectRoleTypeName\"";                            

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

        } catch (\Exception $e) {
            echo 'Error processing KeyDevObjectRoleType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

    protected function processKeyDevToSourceType($fileName): void
    {
        try {
                $keyDevToSourceTypePath = $this->localPath . 'KeyDevelopmentsRefSpan';

                $keyDevToSourceTypeFilePath = $keyDevToSourceTypePath .'/'.$fileName. '/KeyDevToSourceType.txt';

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

        } catch (\Exception $e) {
            echo 'Error processing KeyDevToSourceType data: ' . $e->getMessage() . PHP_EOL;
        }
    }

}
