<?php
namespace Josbeir\Filesystem;

use Cake\Core\InstanceConfigTrait;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use InvalidArgumentException;
use Josbeir\Filesystem\Exception\FilesystemException;
use Josbeir\Filesystem\FileEntity;
use Josbeir\Filesystem\FileEntityCollection;
use Josbeir\Filesystem\FileSourceNormalizer;
use Josbeir\Filesystem\FormatterInterface;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem as FlysystemDisk;

/**
 * Filesystem abstraction for flysystem
 */
class Filesystem implements EventDispatcherInterface
{
    use EventDispatcherTrait;
    use InstanceConfigTrait;

    /**
     * Default configuration identifier
     *
     * @var string
     */
    const DEFAULT_FS_CONFIG = 'default';

    /**
     * Holds configured instances of this class
     *
     * @var Filesystem[]
     */
    protected static $_instances = [];

    /**
     * Default configuration
     *
     * `adapter` Default flysystem adapter to use
     * `adapterArguments' Arguments to pass to the flystem adapter
     * `filesystemArguments` Arguments passed to the Filesystem options array
     * `formatter` Formatter to be used, can also be a FQCN to a formatter class
     * `entityClass` => File entity class to use, defaults to 'FileEntity'
     */
    protected $_defaultConfig = [
        'adapter' => '\League\Flysystem\Adapter\Local',
        'adapterArguments' => [ WWW_ROOT . 'files' ],
        'filesystemArguments' => [
            'visibility' => 'public'
        ],
        'formatter' => 'Default',
        'entityClass' => 'Josbeir\Filesystem\FileEntity'
    ];

    /**
     * Holds instance of the flysystem adapter
     *
     * @var \League\Flysystem\AdapterInterface
     */
    protected $_adapter;

    /**
     * Holds the filesystem instance
     *
     * @var \League\Flysystem\Filesystem
     */
    protected $_disk;

    /**
     * Current formatter classname
     *
     * @var string
     */
    protected $_formatter;

    /**
     * Constructor
     * @param  array $config Configuration
     *
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->configShallow($config);
    }

    /**
     * Set the adapter interface
     * @param \League\Flysystem\AdapterInterface $adapter Adapter interface
     *
     * @return $this
     */
    public function setAdapter(AdapterInterface $adapter) : self
    {
        $this->_adapter = $adapter;

        return $this;
    }

    /**
     * Get current adapter
     *
     * @throws \InvalidArgumentException When adapter could not be located
     *
     * @return AdapterInterface
     */
    public function getAdapter() : AdapterInterface
    {
        if ($this->_adapter === null) {
            $adapter = $this->getConfig('adapter');

            if (!class_exists($adapter)) {
                $adapter = '\\League\\Flysystem\\Adapter\\' . $adapter;
            }

            if (!class_exists($adapter)) {
                throw new InvalidArgumentException(sprintf('Adapter "%s" could not be loaded', $adapter));
            }

            $adapterArguments = $this->getConfig('adapterArguments');

            $this->_adapter = new $adapter(...$adapterArguments);
        }

        return $this->_adapter;
    }

    /**
     * Return the flysystem disk
     *
     * @return \League\Flysystem\Filesystem
     */
    public function getDisk() : FlysystemDisk
    {
        if ($this->_disk === null) {
            $this->_disk = new FlysystemDisk(
                $this->getAdapter(),
                $this->getConfig('filesystem')
            );
        }

        return $this->_disk;
    }

    /**
     * Set the current formatter classname
     *
     * @param string $formatter Name or formatter class
     * @param array $config Config parameters passed to the formatter on creation
     *
     * @return $this
     */
    public function setFormatter($formatter, array $config = []) : self
    {
        $this->_formatter = $this->getFormatterClass($formatter);

        return $this;
    }

    /**
     * Returns a new configured formatter instance
     * @see \Josbeir\Filesystem\FormatterInterface::__construct
     *
     * @param string $filename Original filename
     * @param array $config Configuration settings passed to formatter
     *
     * @return \Josbeir\Filesystem\FormatterInterface
     */
    public function newFormatter($filename, array $config = []) : FormatterInterface
    {
        $config = $config + [
            'formatter' => null,
            'data' => null
        ];

        if (isset($config['formatter'])) {
            $this->setFormatter($config['formatter']);
        }

        $data = null;
        if (isset($config['data'])) {
            $data = $config['data'];
        }

        unset($config['data']);
        unset($config['formatter']);

        if ($this->_formatter === null) {
            $this->setFormatter($this->getFormatterClass());
        }

        return new $this->_formatter($filename, $data, $config);
    }

    /**
     * Return formatter className
     *
     * @param string $name Name of formatter, can be a shortname or FQCN
     *
     * @throws \InvalidArgumentException When formatter could not be found
     *
     * @return string
     */
    public function getFormatterClass($name = null) : string
    {
        $formatter = $name;
        if ($formatter === null) {
            $formatter = $this->_formatter ?: $this->getConfig('formatter');
        }

        if (!class_exists($formatter)) {
            $formatter = '\\Josbeir\\Filesystem\\Formatter\\' . $formatter . 'Formatter';
        }

        if (!class_exists($formatter)) {
            throw new InvalidArgumentException(sprintf('Formatter class "%s" could not be found', $formatter));
        }

        return $formatter;
    }

    /**
     * Upload a file
     *
     * @param string|array|\Zend\Diactoros\UploadedFile $file Uploaded file
     * @param array $config Configuration
     *
     * Configuration options
     * -------
     * `formatter` name/classname of the formatter to use
     * `data` data to be passed to the formatter
     * *All other options are passed to the formatter configuration instance*
     *
     * @throws \Josbeir\Filesystem\Exception\FilesystemException When uploading failed somehow
     *
     * @return FileEntity|null Either the destination path or null
     */
    public function upload($file, array $config = []) : FileEntity
    {
        $filedata = new FileSourceNormalizer($file);
        $formatted = $this->newFormatter($filedata->filename, $config);

        $this->dispatchEvent('Filesystem.beforeUpload', compact('filedata', 'destPath'));

        if ($this->getDisk()->putStream($formatted->getPath(), $filedata->resource)) {
            $entity = $this->newEntity([
                'path' => $formatted->getPath(),
                'filename' => $formatted->getBaseName(),
                'size' => $filedata->size,
                'mime' => $filedata->mime,
                'hash' => $filedata->hash
            ]);

            $this->dispatchEvent('Filesystem.afterUpload', compact('entity', 'filedata'));

            $filedata->shutdown();

            return $entity;
        }

        throw new FilesystemException('Upload failed');
    }

    /**
     * Upload multiple files from an array
     *
     * @param array $data List of files to be uploaded
     * @param array $config Formatter Arguments
     *
     * @return \Josbeir\Filesystem\FileEntityCollection List of files uploaded
     */
    public function uploadMany(array $data, array $config = []) : FileEntityCollection
    {
        $entities = [];
        foreach ($data as $file) {
            $entities[] = $this->upload($file, $config);
        }

        return new FileEntityCollection($entities);
    }

    /**
     * Build an entity
     *
     * @param array $data Entity data
     *
     * @return \Josbeir\Filesystem\FileEntityInterface
     */
    public function newEntity(array $data) : FileEntityInterface
    {
        $entityClass = $this->getConfig('entityClass');
        $entity = new $entityClass($data);

        return $entity;
    }

    /**
     * Convencie method for Filesystem::has
     *
     * @param \Josbeir\Filesystem\FileEntityInterface $entity File enttity class
     *
     * @return bool
     */
    public function exists(FileEntityInterface $entity) : bool
    {
        return $this->getDisk()->has($entity->getPath());
    }

    /**
     * Convenciece method for FilesystemInterface::delete
     *
     * @param \Josbeir\Filesystem\FileEntity $entity File enttity class
     *
     * @return bool
     */
    public function delete(FileEntityInterface $entity)
    {
        $event = $this->dispatchEvent('Filesystem.beforeDelete', compact('entity'));

        if ($event->isStopped()) {
            return $event->getResult();
        }

        if ($this->exists($entity) && $this->getDisk()->delete($entity->getPath())) {
            $this->dispatchEvent('Filesystem.afterDelete', compact('entity'));

            return true;
        }

        return false;
    }

    /**
     * Convencie method for Filesystem::rename
     * Will also update the internal path of the entity, please make sure that information is presisted afterwards if needed!
     * Returns modified entity on successfull rename.
     *
     * @param \Josbeir\Filesystem\FileEntityInterface $entity File enttity class
     * @param array|string $options Formatter configuration or new path to rename file to or string
     *
     * @return \Josbeir\Filesystem\FileEntityInterface|bool
     */
    public function rename(FileEntityInterface $entity, $options)
    {
        $newPath = $options;

        if (is_array($options)) {
            $newPath = $this->newFormatter($entity->getPath(), $options)->getPath();
        }

        $event = $this->dispatchEvent('Filesystem.beforeRename', compact('entity', 'newPath'));

        if ($event->isStopped()) {
            return $event->getResult();
        }

        if ($this->getDisk()->rename($entity->getPath(), $newPath)) {
            $entity->setPath($newPath);
            $this->dispatchEvent('Filesystem.afterRename', compact('entity'));

            return $entity;
        }

        return false;
    }

    /**
     * Reset to defaults
     *
     * @return $this
     */
    public function reset() : self
    {
        $this->_formatter = null;
        $this->_adapter = null;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param string $method Method to call
     * @param array $parameters Paramters to pass
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->getDisk()->$method(...$parameters);
    }
}
