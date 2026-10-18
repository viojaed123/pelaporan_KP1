<?php
declare(strict_types=1);
namespace App\Services\Impor;
class ImporHelper
{
    public function dir(): string { return __DIR__ . '/../../../includes/impor'; }
    public function ensureDir(): void { if (!is_dir($this->dir())) { @mkdir($this->dir(), 0755, true); } }
}