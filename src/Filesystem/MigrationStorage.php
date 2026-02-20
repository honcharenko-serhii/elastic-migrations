<?php declare(strict_types=1);

namespace Elastic\Migrations\Filesystem;

use const DIRECTORY_SEPARATOR;
use Elastic\Migrations\ReadinessInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

class MigrationStorage implements ReadinessInterface
{
    protected const DIRECTORY_PERMISSIONS = 0755;

    protected Filesystem $filesystem;
    protected string|null $defaultPath = null;
    protected Collection|null $paths = null;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function create(string $fileName, string $content): MigrationFile
    {
        if ($this->isPath($fileName)) {
            $this->filesystem->put($fileName, $content);
            return new MigrationFile($fileName);
        }

        if (is_null($this->getDefaultPath())) {
            throw new \Exception('Default migration path is not yet created');
        }

        if (!$this->filesystem->isDirectory($this->getDefaultPath())) {
            $this->filesystem->makeDirectory($this->getDefaultPath(), static::DIRECTORY_PERMISSIONS, true);
        }

        $filePath = $this->makeFilePath($this->getDefaultPath(), $fileName);
        $this->filesystem->put($filePath, $content);
        return new MigrationFile($filePath);
    }

    public function whereName(string $fileName): ?MigrationFile
    {
        if ($this->isPath($fileName)) {
            return $this->filesystem->exists($fileName) ? new MigrationFile($fileName) : null;
        }

        foreach ($this->getPaths() as $path) {
            $filePath = $this->makeFilePath($path, $fileName);

            if ($this->filesystem->exists($filePath)) {
                return new MigrationFile($filePath);
            }
        }

        return null;
    }

    public function all(): Collection
    {
        return $this->getPaths()->flatMap(
            fn (string $path) => $this->filesystem->glob($path . '/*_*' . MigrationFile::FILE_EXTENSION)
        )->filter()->mapWithKeys(
            static function (string $filePath) {
                $file = new MigrationFile($filePath);
                return [$file->name() => $file];
            }
        )->sortKeys()->values();
    }

    public function registerPaths(array $paths): self
    {
        $this->paths = $this->getPaths()->merge($paths)->filter()->unique()->values();
        return $this;
    }

    public function isReady(): bool
    {
        return $this->filesystem->isDirectory($this->getDefaultPath());
    }

    protected function getDefaultPath(): string|null
    {
        if (is_null($this->defaultPath)) {
            $path = config('elastic.migrations.storage.default_path', '');

            if (is_string($path)) {
                $this->defaultPath = $path;
            }
        }

        return $this->defaultPath;
    }

    protected function getPaths(): Collection
    {
        if (is_null($this->paths)) {
            $this->paths = collect();

            if ($defaultPath = $this->getDefaultPath()) {
                $this->paths->add($defaultPath);
            }
        }

        return $this->paths;
    }

    private function isPath(string $path): bool
    {
        return strpos($path, DIRECTORY_SEPARATOR) !== false;
    }

    private function makeFilePath(string $path, string $fileName): string
    {
        return $path . DIRECTORY_SEPARATOR . $fileName . MigrationFile::FILE_EXTENSION;
    }
}
