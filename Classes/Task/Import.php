<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Events2\Task;

use JWeiland\Events2\Importer\ImporterInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/*
 * Task to import events by various file formats like XML
 */
class Import extends AbstractTask
{
    /**
     * Full path from document root. F.e. /fileadmin/events_import/Import.xml
     *
     * @var string
     */
    public $path = '';

    /**
     * @var int
     */
    public $storagePid = 0;

    /**
     * This is the main method that is called when a task is executed
     * Note that there is no error handling, errors and failures are expected
     * to be handled and logged by the client implementations.
     * Should return TRUE on successful execution, FALSE on error.
     *
     * @return bool Returns TRUE on successful execution, FALSE on error
     * @throws \Exception
     */
    public function execute(): bool
    {
        try {
            $file = GeneralUtility::makeInstance(ResourceFactory::class)
                ->retrieveFileOrFolderObject($this->path);
            if ($file instanceof File) {
                if ($file->isMissing()) {
                    $this->addMessage('The defined file seems to be missing. Please check, if file is still at its place', FlashMessage::ERROR);
                    return false;
                }
                // File can be updated by (S)FTP. So we have to update its properties first.
                $indexer = GeneralUtility::makeInstance(Indexer::class, $file->getStorage());
                $indexer->updateIndexEntry($file);
            } else {
                $this->addMessage('The defined file is not a valid file. Maybe you have defined a folder. Please re-check file path', FlashMessage::ERROR);
                return false;
            }
        } catch (\Exception $e) {
            $this->addMessage('Currently no file for import found.', FlashMessage::INFO);
            return true;
        }

        try {
            if ($this->importFile($file)) {
                $file->delete();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Import file
     *
     * @param File $file
     * @return bool
     * @throws \Exception
     */
    protected function importFile(File $file): bool
    {
        $className = sprintf(
            'JWeiland\\Events2\\Importer\\%sImporter',
            ucfirst(strtolower($file->getExtension()))
        );
        if (!class_exists($className)) {
            $this->addMessage('There is no class to handler files of type: ' . $file->getExtension());
            return false;
        }

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $importer = $objectManager->get($className);
        if (!$importer instanceof ImporterInterface) {
            $this->addMessage('Importer has to implement ImporterInterface');
            return false;
        }
        $importer->setStoragePid($this->storagePid);
        $importer->setFile($file);
        if (!$importer->checkFile()) {
            return false;
        }
        return $importer->import();
    }

    /**
     * This method is used to add a message to the internal queue
     *
     * @param string $message The message itself
     * @param int $severity Message level (according to \TYPO3\CMS\Core\Messaging\FlashMessage class constants)
     * @throws \Exception
     */
    public function addMessage(string $message, int $severity = FlashMessage::OK): void
    {
        $flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $message, '', $severity);
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }
}
