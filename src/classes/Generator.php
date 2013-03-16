<?php
/**
 * @package Playlist
 */
namespace Playlist;

require_once(__DIR__ . '/Util/FileSystem.php');
require_once(__DIR__ . '/Util/Cli.php');
require_once(__DIR__ . '/Rules.php');

use Playlist\Util\FileSystem;
use Playlist\Util\Cli;
use Playlist\Rules;

/**
 * The generator
 */
class Generator
{
    const STATUS_SKIPPED    = '[skipped]';
    const STATUS_ERROR      = '[ error ]';
    const STATUS_OK         = '[ added ]';


    /**
     * Directory path of the configuration file
     *
     * @var string
     */
    protected $_configurationDirectory;

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    /**
     * Generate the playlist
     *
     * @param   string      $configurationPath      Configuration path
     */
    public function generate($configurationPath)
    {
        $this->_configurationDirectory = pathinfo($configurationPath, PATHINFO_DIRNAME);

        // Parse the configuration
        $configurationContent   = file_get_contents($configurationPath);
        $configuration          = json_decode($configurationContent);

        // Get the media directory
        if (!isset($configuration->mediaDirectoryPath)) {
            throw new \Exception('Parameter "mediaDirectoryPath" is undefined');
        }
        $mediaDirectoryPath = $this->_getRealPath($configuration->mediaDirectoryPath);

        // Get the playlist path
        if (!isset($configuration->playlistPath)) {
            throw new \Exception('Parameter "playlistPath" is undefined');
        }
        $playlistPath = $this->_getRealPath($configuration->playlistPath);

        // Get the media file pattern
        $mediaFilePattern = '*.mp3';
        if (isset($configuration->mediaFilePattern)) {
            $mediaFilePattern = $configuration->mediaFilePattern;
        }

        // Get the exiftool path
        $exiftoolPath = 'exiftool';
        if (isset($configuration->exiftoolPath)) {
            $exiftoolPath = $this->_getRealPath($configuration->exiftoolPath);
        }

        // Get the cache path
        $cachePath = null;
        if (isset($configuration->cachePath)) {
            $cachePath = $this->_getRealPath($configuration->cachePath);
            if (!is_dir($cachePath) || !is_writable($cachePath)) {
                throw new \Exception("Cache directory is not writable: $cachePath");
            }
        }

        // Initialize rules
        $rules = new Rules();
        if (isset($configuration->rules)) {
            $rules->initialize($configuration->rules);
        }

        // Get all media files
        echo Cli::getColoredString('Loading files ...', 'yellow'), "\n";
        $filePaths  = FileSystem::rglob($mediaFilePattern, $mediaDirectoryPath);
        $count      = count($filePaths);
        $index      = 0;
        $indexLength= strlen((string) $count);
        foreach ($filePaths as $filePath) {
            $index++;
            $indexString = str_pad($index, $indexLength, ' ', STR_PAD_LEFT);
            $lineNumber = "[$indexString/$count]";

            // Execute exiftool and ge the result
            // Use the cache if the path is provided
            $content = null;
            $fileHash = md5($filePath);
            if ($cachePath) {
                $fileCache = $cachePath . '/' . $fileHash;
                if (is_file($fileCache)) {
                    $content = file_get_contents($fileCache);
                }
            }
            if (is_null($content)) {
                $command = popen($exiftoolPath . ' -json "' . $filePath . '"', 'r');
                $content = stream_get_contents($command);
                pclose($command);

                if (isset($fileCache)) {
                    file_put_contents($fileCache, $content);
                }
            }

            // Parse the result
            $json       = json_decode($content);
            $metadata   = $json[0];
            $metadata   = $this->_normalizeMetadata($metadata);
            $artist     = null;
            $title      = null;
            if (isset($metadata->Artist)) {
                $artist = $metadata->Artist;
            }
            if (isset($metadata->Title)) {
                $title = $metadata->Title;
            }

            // If the artist or the title is empty, then the file cannot be filtered
            if (empty($artist) || empty($title)) {
                $status = self::STATUS_ERROR;
                $fileName = pathinfo($filePath, PATHINFO_BASENAME);
                echo Cli::getColoredString("$lineNumber $status Invalid file (empty title or artist): $filePath", 'red'), "\n";
                continue;
            }

            // Song description
            $description = "$artist - $title";

            // If the file matches the rules, then add it to the list
            if ($rules->match($metadata)) {
                $this->_addFile($filePath, $metadata);
                $status = self::STATUS_OK;
                echo Cli::getColoredString("$lineNumber $status $description", 'green'), "\n";
                continue;
            }

            // Skip the file
            $status = self::STATUS_SKIPPED;
            echo "$lineNumber $status $description\n";
        }
    }

    /**
     * Add a file to the playlist
     *
     * @param   string      $filePath       File path
     * @param   stdClass    $metadata       File metadata
     */
    protected function _addFile($filePath, $metadata)
    {
        // Add the file to the playlist
        
    }

    /**
     * Normalize metadata
     *
     * @param   \stdClass   $metadata       Metadata
     * @return  \stdClass                   Normalized metadata
     */
    public function _normalizeMetadata($metadata)
    {
        $normalized = clone $metadata;

        // Normalize popularimeter
        if (isset($normalized->Popularimeter)) {
            $popularimeter = $normalized->Popularimeter;

            if (preg_match('/Rating=([0-9]+)/', $popularimeter, $matches)) {
                $rating = (int) $matches[1];
                if ($rating >= 1 && $rating <= 31) {
                    $popularimeter = 1;
                } else if ($rating >= 32 && $rating <= 95) {
                    $popularimeter = 2;
                } else if ($rating >= 96 && $rating <= 159) {
                    $popularimeter = 3;
                } else if ($rating >= 160 && $rating <= 223) {
                    $popularimeter = 4;
                } else if ($rating >= 224 && $rating <= 255) {
                    $popularimeter = 5;
                } else {
                    $popularimeter = 0;
                }
            } else {
                $popularimeter = 0;
            }

            $normalized->Popularimeter = $popularimeter;
        }

        return $normalized;
    }

    /**
     * Get the real path
     *
     * @param   string      $path       Path
     * @return  string                  Real path
     */
    protected function _getRealPath($path)
    {
        if (strlen($path) > 0 && $path[0] !== '/') {
            $path = $this->_configurationDirectory . '/' . $path;
        }

        return $path;
    }
}
