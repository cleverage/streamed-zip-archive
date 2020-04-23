<?php

declare(strict_types=1);

namespace CleverAge\StreamedZipArchive;

use Symfony\Component\Process\Process;

/**
 * Handle encrypting native.
 */
class StreamedZipArchive
{
    const TMP_FOLDER_PREFIX = 'streamed-zip-archive-';
    const ZIP_BINARY = 'zip';

    /**
     * A temporary folder for all added files.
     *
     * @var string
     */
    protected $tmpFolder;

    /**
     * The list of streams to include in the ZIP file.
     *
     * @var array
     */
    protected $streams = [];

    /**
     * The timeout for archive creation
     * Note that if null, it's not limited, but a stuck process will be detected by an un-consumed input with tee
     * @see \CleverAge\StreamedZipArchive\StreamedZipArchive::pushStreamInFifo
     * @var int|null
     */
    protected $buildTimeout = null;

    public function __construct(string $tmpPath = '/tmp', int $buildTimeout = null)
    {
        if (!self::isSupported()) {
            throw new \RuntimeException('Some component are missing to use this library');
        }

        $this->tmpFolder = \rtrim($tmpPath, '/').'/'.\uniqid(static::TMP_FOLDER_PREFIX);
        $result = @\mkdir($this->tmpFolder);

        if (false === $result) {
            throw new \RuntimeException("Failed to create tmp directory {$this->tmpFolder}");
        }

        $this->buildTimeout = $buildTimeout;
    }

    /**
     * Register a new input for the ZIP
     *
     * @param resource|string $stream
     */
    public function addStream(string $relativePath, $stream)
    {
        if (!\is_resource($stream) && !\is_string($stream)) {
            throw new \InvalidArgumentException('Input must be a resource or a string');
        }

        if ($this->fileExists($relativePath) || \array_key_exists($relativePath, $this->streams)) {
            throw new \InvalidArgumentException("Path {$relativePath} is already registered");
        }

        // IMPORTANT : path MUST be included in correct tmp folder (leading / or .. can lead to a leak)
        $realpathProcess = $this->runProcess(['realpath', '-m', $relativePath]);
        if (0 !== \strpos($realpathProcess->getOutput(), $this->tmpFolder)) {
            throw new \LogicException("Path {$relativePath} is not inside {$this->tmpFolder}");
        }

        $this->streams[$relativePath] = $stream;
    }

    /**
     * Process the ZIP creation and return the zipped content
     */
    public function buildArchive(): string
    {
        foreach ($this->streams as $relativePath => $stream) {
            $this->createFifo($relativePath);
        }

        $archiveProcess = $this->addToArchive(\array_keys($this->streams), true);

        $fifoProcesses = [];
        foreach ($this->streams as $relativePath => $stream) {
            $fifoProcesses[$relativePath] = $this->pushStreamInFifo($relativePath, $stream);
        }

        $somethingRunning = true;
        while ($somethingRunning) {
            $somethingRunning = false;
            foreach ($fifoProcesses as $relativePath => $fifoProcess) {
                $somethingRunning = !$fifoProcess->isTerminated() || $somethingRunning;
            }
            // isTerminated trigger read/write for all process, so pipes should not block
            $somethingRunning = !$archiveProcess->isTerminated() || $somethingRunning;

            // A small wait to ensure data have been processed
            \usleep(1000);
        }

        $archiveProcess->wait();
        $this->assertProcessExecution($archiveProcess);

        return $archiveProcess->getOutput();
    }

    public static function isSupported(): bool
    {
        // Test if binary dependencies exists
        $zipExists = 0 === Process::fromShellCommandline('which zip')->run();
        $mkfifoExists = 0 === Process::fromShellCommandline('which mkfifo')->run();
        $teeExists = 0 === Process::fromShellCommandline('which tee')->run();
        $realpathWithOptionIsSupported = 0 === Process::fromShellCommandline('realpath -m --help')->run();

        return $zipExists && $teeExists && $mkfifoExists && $realpathWithOptionIsSupported;
    }

    /**
     * Cleanup the temporary folder
     */
    public function __destruct()
    {
        $rmProcess = new Process(['rm', '-r', $this->tmpFolder]);
        $rmProcess->run();

        $this->assertProcessExecution($rmProcess);
    }

    protected function createFifo(string $relativePath): Process
    {
        if (false !== \strpos($relativePath, '/')) {
            $this->runProcess(['mkdir', '-p', \dirname($relativePath)]);
        }

        return $this->runProcess(['mkfifo', $relativePath]);
    }

    /**
     * @param resource|string $stream
     *
     * @return Process
     */
    protected function pushStreamInFifo(string $relativePath, $stream)
    {
        $process = new Process(['tee', $relativePath], $this->tmpFolder);

        if (\is_resource($stream)) {
            \rewind($stream);
        }

        $process->setInput($stream);
        $process->setTimeout(null);
        // If tee do not show any output, something have stuck, no need to wait
        $process->setIdleTimeout(2);

        $process->start();

        return $process;
    }

    protected function addToArchive(array $relativePaths, bool $isFifo = false): Process
    {
        $arguments = [self::ZIP_BINARY];
        if ($isFifo) {
            $arguments[] = '-FI'; // Allow to use FIFO as input
        }
        $arguments[] = '-'; // STDOUT
        $arguments = \array_merge($arguments, $relativePaths); // Append file list
        $archiveProcess = new Process($arguments, $this->tmpFolder);
        $archiveProcess->setTimeout($this->buildTimeout);
        $archiveProcess->start();

        // With a FIFO we do not block thread because FIFO will be filled later
        if (!$isFifo) {
            $archiveProcess->wait();
            $this->assertProcessExecution($archiveProcess);
        }

        return $archiveProcess;
    }

    protected function assertProcessExecution(Process $process)
    {
        if ($process->isTerminated() && 0 !== $process->getExitCode()) {
            throw new \RuntimeException(
                "Process failed ('{$process->getCommandLine()}' returned {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Simple proxy method for simple use-cases.
     */
    protected function runProcess(array $arguments): Process
    {
        $process = new Process($arguments, $this->tmpFolder);
        $process->run();

        $this->assertProcessExecution($process);

        return $process;
    }

    protected function fileExists(string $relativePath): bool
    {
        $process = new Process(['ls', $relativePath], $this->tmpFolder);

        return 0 === $process->run();
    }
}
