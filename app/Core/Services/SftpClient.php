<?php

namespace App\Core\Services;

use Exception;
use Swoole\Coroutine;

class SftpClient
{
    protected $connection = null;
    protected $sftp = null;

    // Authentication and Server Properties
    public $ftpUrl = null;
    public $username = null;
    public $password = null;
    public $port = 22;
    public $timeout = 30;

    public function __construct(string $ftpUrl, string $username, string $password, int $port = 22, int $timeout = 30)
    {
        $this->ftpUrl = $ftpUrl;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * Establish an SFTP Connection
     */
    public function connect(int $retryInterval = 2, int $maxRetries = 5)
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                if ($this->connection) {
                    ssh2_disconnect($this->connection);
                    $this->connection = null;
                }
    
                $this->connection = ssh2_connect($this->ftpUrl, $this->port);
                if (!$this->connection) {
                    throw new Exception("SFTP Connection failed");
                }
    
                if (!ssh2_auth_password($this->connection, $this->username, $this->password)) {
                    throw new Exception("SFTP Authentication failed");
                }
    
                $this->sftp = ssh2_sftp($this->connection);
                if (!$this->sftp) {
                    throw new Exception("SFTP initialization failed");
                }
    
                output("SFTP Connection successful.");
                return true;
            } catch (Exception $e) {
                output("SFTP Connection error (Attempt $attempt): " . $e->getMessage());
                sleep($retryInterval);
                $attempt++;
            }
        }
        
        throw new Exception("SFTP connection failed after $maxRetries retries.");
    }           

    /**
     * List Files in a Remote Directory Using SFTP
     */
    public function remoteFilesList($remoteDir)
    {
        // Todo: incase of no connection or sftp then it should connect and list the remoteDir
        if (!$this->sftp) {
            $this->connect();  // Ensure connection is active
        }
        
        // Format path for SFTP
        $sftpPath = "ssh2.sftp://{$this->sftp}$remoteDir";
        $handle = opendir($sftpPath);
    
        if (!$handle) {
            throw new Exception("Failed to open remote directory: $remoteDir");
        }
    
        $zipFiles = [];
        while (false !== ($file = readdir($handle))) {
            // Only add files with .zip extension
            if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $zipFiles[] = $file;
            }
        }
        closedir($handle);
    
        return $zipFiles;
    }

    /**
     * Download a File from the Remote SFTP Server
     */
    public function download(string $remoteFile, string $localPath): bool
    {
        try {
            if (!$this->sftp) {
                output("Reconnecting to SFTP...");
                $this->connect();
            }
    
            $sftpPath = "ssh2.sftp://{$this->sftp}/$remoteFile";
            $stream = fopen($sftpPath, 'r');
    
            if (!$stream) {
                output("SFTP Error: Unable to open remote file: $remoteFile");
                return false;
            }
    
            $localFile = fopen($localPath, 'w');
            if (!$localFile) {
                fclose($stream);
                output("Error: Unable to open local file: $localPath for writing");
                return false;
            }
    
            $bytesCopied = stream_copy_to_stream($stream, $localFile);
            fclose($stream);
            fclose($localFile);
    
            if ($bytesCopied === false || $bytesCopied <= 0) {
                output("Error: Failed to download $remoteFile properly.");
                return false;
            }
    
            return file_exists($localPath);
        } finally {
            // Always disconnect after file transfer
            $this->disconnect();
        }
    }
      
    /**
     * Disconnect from the SFTP Server
     */
    public function disconnect()
    {
        if ($this->connection) {
            // Close the SSH2 session manually
            if (function_exists('ssh2_disconnect')) {
                ssh2_disconnect($this->connection);
            }
    
            $this->connection = null;
        }
    
        if ($this->sftp) {
            $this->sftp = null;
        }
    
        output("SFTP connection closed.");
    }
}
