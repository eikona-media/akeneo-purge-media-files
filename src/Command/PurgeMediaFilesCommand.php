<?php
/**
 * PurgeMediaFilesCommand.php
 *
 * @author      Timo Müller <t.mueller@eikona-media.de>
 * @copyright   2019 EIKONA Media (https://eikona-media.de)
 */

namespace EikonaMedia\Akeneo\PurgeMediaFilesBundle\Command;

use Akeneo\Component\FileStorage\FilesystemProvider;
use Akeneo\Component\FileStorage\Model\FileInfoInterface;
use Akeneo\Component\FileStorage\Repository\FileInfoRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use Pim\Component\Catalog\FileStorage;
use Pim\Component\Catalog\Model\Product;
use Pim\Component\Catalog\Model\ProductModel;
use Pim\Component\Catalog\Repository\ProductModelRepositoryInterface;
use Pim\Component\Catalog\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PurgeMediaFilesCommand extends Command
{
    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var FileInfoRepositoryInterface */
    protected $fileInfoRepository;

    /** @var string */
    protected $catalogStorageDir;

    /** @var FilesystemProvider */
    protected $filesystemProvider;

    /** @var FilesystemInterface */
    protected $fs;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var ProductModelRepositoryInterface */
    protected $productModelRepository;

    /**
     * @param EntityManagerInterface $entityManager
     * @param FileInfoRepositoryInterface $fileInfoRepository
     * @param string $catalogStorageDir
     * @param FilesystemProvider $filesystemProvider
     * @param ProductRepositoryInterface $productRepository
     * @param ProductModelRepositoryInterface $productModelRepository
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        FileInfoRepositoryInterface $fileInfoRepository,
        string $catalogStorageDir,
        FilesystemProvider $filesystemProvider,
        ProductRepositoryInterface $productRepository,
        ProductModelRepositoryInterface $productModelRepository
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->fileInfoRepository = $fileInfoRepository;
        $this->catalogStorageDir = $catalogStorageDir;
        $this->filesystemProvider = $filesystemProvider;
        $this->productRepository = $productRepository;
        $this->productModelRepository = $productModelRepository;
    }

    protected function configure()
    {
        $this
            ->setName('eikona-media:media:purge-files')
            ->setDescription('Remove unsued product media files')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force deletion');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws FileNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $this->fs = $this->filesystemProvider->getFilesystem(FileStorage::CATALOG_STORAGE_ALIAS);

        if (!$force) {
            $io->warning('Command running in safe mode. Use --force to delete files.');
        }

        $io->writeln('Searching media files...');
        $catalogMediaFiles = $this->getCatalogMediaFiles();
        $io->writeln(sprintf('Found %d media files', count($catalogMediaFiles)));
        $io->newLine();

        $removedFilesCount = 0;

        // Dateien löschen, zu denen es keinen Eintrag in "akeneo_file_storage_file_info" gibt
        $io->writeln('Removing media files without database entry...');
        $filesWithoutDbEntry = array_filter($catalogMediaFiles, function($file) {
            return $file['info'] === null;
        });

        foreach ($filesWithoutDbEntry as $file) {
            if ($force) {
                $this->fs->delete($file['file']['path']);
            }
            $removedFilesCount++;
            $io->writeln(sprintf('Removed file "%s"', $file['path']));
        }
        $io->writeln(sprintf('Removed %d files without database entry', count($filesWithoutDbEntry)));
        $io->newLine();

        // Dateien löschen, die nicht mit einem Produkt(modell) verknüpft sind
        $io->writeln('Removing media files which are not linked to products anymore...');
        $filesNotLinkedToProducts = array_filter($catalogMediaFiles, function($file) {
            /** @var FileInfoInterface $fileInfo */
            $fileInfo = $file['info'];
            return $fileInfo !== null &&
                $fileInfo->getStorage() === FileStorage::CATALOG_STORAGE_ALIAS &&
                !$this->isFileUsedInProducts($fileInfo->getKey()) &&
                !$this->isFileUsedInProductModels($fileInfo->getKey());
        });

        foreach ($filesNotLinkedToProducts as $file) {
            if ($force) {
                $this->entityManager->remove($file['info']);
                $this->fs->delete($file['file']['path']);
                $this->entityManager->flush();
            }
            $removedFilesCount++;
            $io->writeln(sprintf('Removed file "%s"', $file['path']));
        }

        $io->writeln(sprintf('Removed %d files which are not linked to products anymore', count($filesNotLinkedToProducts)));
        $io->newLine();

        $io->success(sprintf('Removed %d files', $removedFilesCount));
    }

    /**
     * @return array
     */
    protected function getCatalogMediaFiles()
    {
        $dirContent = $this->fs->listContents('', true);

        $files = array_filter($dirContent, function ($item) {
            return $item['type'] === 'file';
        });

        /** @var Filesystem $fs */
        $fs = $this->fs;
        /** @var Local $fsAdapter */
        $fsAdapter = $fs->getAdapter();

        $filesWithFileInfo = array_map(function ($file) use($fsAdapter) {
            return [
                'file' => $file,
                'path' => $fsAdapter->applyPathPrefix($file['path']),
                'info' => $this->fileInfoRepository->findOneByIdentifier($file['path'])
            ];
        }, $files);

        return $filesWithFileInfo;
    }

    protected function isFileUsedInProducts($fileKey)
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->entityManager
                ->getRepository(Product::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.rawValues LIKE :filekey')
                ->setParameter('filekey', '%' . $fileKey . '%')
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    protected function isFileUsedInProductModels($fileKey)
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->entityManager
                ->getRepository(ProductModel::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.rawValues LIKE :filekey')
                ->setParameter('filekey', '%' . $fileKey . '%')
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }
}
