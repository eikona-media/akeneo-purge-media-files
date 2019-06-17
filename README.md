# Akeneo Purge Media Files Bundle

__IMPORTANT:__ Do not use this bundle in the Akeneo PIM Enterprise Edition ([why?](#why-not-to-use-in-the-akeneo-pim--enterprise-edition))

This bundle comes with a new command to remove unused media files.

## Requirements

| Version | Akeneo PIM Community Edition | Akeneo PIM Enterprise Edition |
|:-------:|:----------------------------:|:-----------------------------:|
| 1.0.*   | 2.3.*                        | __Do not use__                |


## Installation

```bash
    composer require eikona-media/akeneo-purge-media-files:~1.0
```

3) Enable the bundle in the `app/AppKernel.php` file in the `registerProjectBundles()` method:
```php
protected function registerProjectBundles()
{
    return [
        // ...
        new EikonaMedia\Akeneo\PurgeMediaFilesBundle\EikonaMediaAkeneoPurgeMediaFilesBundle(),
    ];
}
```

## Usage

To remove unused media files execute the command `eikona-media:media:purge-files`.  
The command has one option: `--force`. If you omit the option the command runs in safe mode (no files will be deleted).

The command searches for media files in the catalog storage directory (Akeneo parameter: `catalog_storage_dir`) for files, which:
- do not have an entry in `akeneo_file_storage_file_info`
- do have an entry in `akeneo_file_storage_file_info` but are not used in any product or product model (in this case the command also removes the entity)

## Why not to use in the Akeneo PIM Enterprise Edition

- The Akeneo PIM Enterprise Edition has the asset management (so we probably dont want to delete unsued files)
- The Akeneo PIM Enterprise Edition has proposals (which the command does not search through)
- The Akeneo PIM Enterprise Edition can restore old product versions (which the command does not search through)
