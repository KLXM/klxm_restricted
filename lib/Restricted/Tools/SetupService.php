<?php

declare(strict_types=1);

namespace KLXM\Restricted\Tools;

use rex;
use rex_addon;
use rex_file;
use rex_sql;
use rex_sql_exception;

/**
 * Synchronizes frontend modules from install/modules/ into the REDAXO database.
 */
class SetupService
{
    /**
     * Synchronizes all modules.
     *
     * @return list<array{type: string, key: string, message: string, status: string}>
     */
    public static function syncModules(): array
    {
        $report = [];
        foreach (self::getModuleSpecs() as $spec) {
            $report[] = self::upsertModule(
                $spec['key'],
                $spec['name'],
                $spec['input'],
                $spec['output']
            );
        }
        return $report;
    }

    /**
     * Scans install/modules/ and returns specs for each valid module.
     *
     * @return list<array{key: string, name: string, input: string, output: string}>
     */
    private static function getModuleSpecs(): array
    {
        $addon = rex_addon::get('klxm_restricted');
        $basePath = $addon->getPath('install/modules');

        $specs = [];

        if (!is_dir($basePath)) {
            return $specs;
        }

        foreach (new \DirectoryIterator($basePath) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $metaFile = $entry->getPathname() . '/metadata.yml';
            $inputFile = $entry->getPathname() . '/input.php';
            $outputFile = $entry->getPathname() . '/output.php';

            if (!is_file($metaFile) || !is_file($inputFile) || !is_file($outputFile)) {
                continue;
            }

            $meta = rex_file::getConfig($metaFile);
            if (!isset($meta['key'], $meta['name'])) {
                continue;
            }

            $input = rex_file::get($inputFile) ?? '';
            $output = rex_file::get($outputFile) ?? '';

            $specs[] = [
                'key' => (string) $meta['key'],
                'name' => (string) $meta['name'],
                'input' => $input,
                'output' => $output,
            ];
        }

        return $specs;
    }

    /**
     * Inserts or updates a module identified by its key.
     *
     * @return array{type: string, key: string, message: string, status: string}
     */
    private static function upsertModule(string $key, string $name, string $input, string $output): array
    {
        $sql = rex_sql::factory();
        $table = rex::getTable('module');

        $sql->setQuery('SELECT id FROM ' . $table . ' WHERE `key` = ? LIMIT 1', [$key]);

        try {
            if ($sql->getRows() > 0) {
                $id = (int) $sql->getValue('id');
                $sql->setTable($table);
                $sql->setWhere(['id' => $id]);
                $sql->setValue('name', $name);
                $sql->setValue('input', $input);
                $sql->setValue('output', $output);
                $sql->update();

                self::clearModuleCache($id);

                return self::line('module', $key, $name, 'updated');
            }

            $sql->setTable($table);
            $sql->setValue('key', $key);
            $sql->setValue('name', $name);
            $sql->setValue('input', $input);
            $sql->setValue('output', $output);
            $sql->insert();

            $id = (int) $sql->getLastId();
            self::clearModuleCache($id);

            return self::line('module', $key, $name, 'created');
        } catch (rex_sql_exception $e) {
            return self::line('module', $key, $e->getMessage(), 'error');
        }
    }

    private static function clearModuleCache(int $id): void
    {
        if (class_exists('rex_module_cache')) {
            \rex_module_cache::delete($id);
        }
    }

    /**
     * @return array{type: string, key: string, message: string, status: string}
     */
    private static function line(string $type, string $key, string $message, string $status): array
    {
        return compact('type', 'key', 'message', 'status');
    }
}
