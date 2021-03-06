<?php


namespace EasySwoole\Mysqli\Export;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Utility;

class Table
{
    protected $client;
    protected $tableName;
    protected $config;

    function __construct(Client $client, string $tableName, Config $config)
    {
        $this->client = $client;
        $this->tableName = $tableName;
        $this->config = $config;
    }

    function createTableSql()
    {
        $result = $this->client->rawQuery("SHOW CREATE TABLE `{$this->tableName}`");
        $createTableSql = $result[0]['Create Table'];

        /** 创建表检测是否存在 */
        if ($this->config->isCreateTableIsNotExist()) {
            $createTableSql = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $createTableSql);
        }

        return $createTableSql;
    }


    function export(&$output)
    {
        $structure = '--' . PHP_EOL;
        $structure .= "-- Table structure for table `{$this->tableName}`" . PHP_EOL;
        $structure .= '--' . PHP_EOL . PHP_EOL;

        /** 是否生成dropTableSql */
        if ($this->config->isDrop()) {
            $structure .= "DROP TABLE IF EXISTS `{$this->tableName}`;" . PHP_EOL;
        }
        $structure .= $this->createTableSql() . ';' . PHP_EOL . PHP_EOL;

        $writeStructCallback = $this->config->getCallback(Event::onWriteTableStruct);
        is_callable($writeStructCallback) && $structure = call_user_func($writeStructCallback, $this->client, $this->tableName, $structure);
        Utility::writeSql($output, $structure);

        /** 仅导出表结构 */
        if ($this->config->isOnlyStruct()) {
            return;
        }

        // 是否存在数据
        $checkData = $this->getInsertSql(1, 1);
        if (!$checkData) {
            return;
        };

        $data = '--' . PHP_EOL;
        $data .= "-- Dumping data for table `{$this->tableName}`" . PHP_EOL;
        $data .= '--' . PHP_EOL . PHP_EOL;

        $isLockTablesWrite = $this->config->isLockTablesWrite();
        $startTransaction = $this->config->isStartTransaction();

        if ($isLockTablesWrite) {
            $data .= "LOCK TABLES `{$this->tableName}` WRITE;" . PHP_EOL;
        }

        if ($startTransaction) {
            $data .= 'BEGIN;' . PHP_EOL;
        }

        $callback = $this->config->getCallback(Event::onBeforeWriteTableDataNotes);
        is_callable($callback) && $data = call_user_func($callback, $this->client, $this->tableName, $data);
        Utility::writeSql($output, $data);

        $page = 1;
        $size = $this->config->getSize();

        // before dump data for table callback
        $beforeResult = null;
        $beforeCallback = $this->config->getCallback(Event::onBeforeExportTableData);
        is_callable($beforeCallback) && $beforeResult = call_user_func($beforeCallback, $this->client, $this->tableName);

        // dumping data table callback
        $exportingCallback = $this->config->getCallback(Event::onExportingTableData);

        while (true) {
            $insertSql = $this->getInsertSql($page, $size);
            is_callable($exportingCallback) && call_user_func($exportingCallback, $this->client, $this->tableName, $page, $size, $beforeResult);

            if (empty($insertSql)) break;

            Utility::writeSql($output, $insertSql);
            $page++;
        }

        $afterCallback = $this->config->getCallback(Event::onAfterExportTableData);
        is_callable($afterCallback) && call_user_func($afterCallback, $this->client, $this->tableName);

        $data = '';
        if ($startTransaction) {
            $data .= 'COMMIT;' . PHP_EOL;
        }

        if ($isLockTablesWrite) {
            $data .= 'UNLOCK TABLES;' . PHP_EOL . PHP_EOL;
        }

        $callback = $this->config->getCallback(Event::onAfterWriteTableDataNotes);
        is_callable($callback) && $data = call_user_func($callback, $this->client, $this->tableName, $data);
        Utility::writeSql($output, $data);
    }

    private function getInsertSql($page, $size): string
    {
        $limit = ($page - 1) * $size;
        $attempts = 0;
        $maxFails = $this->config->getMaxFails();

        // 异常重试
        while ($attempts <= $maxFails) {
            try {
                $this->client->queryBuilder()->limit($limit, $size)->get($this->tableName);
                $result = $this->client->execBuilder();
                break;
            } catch (Exception $exception) {
                if (++$attempts > $maxFails) {
                    throw $exception;
                }
            }
        }

        $data = '';
        $queryBuilder = new QueryBuilder();

        foreach ($result as $item) {

            $item = array_map(function ($v) {
                return str_replace(["\r", "\n"], ['\r', '\n'], addslashes($v));
            }, $item);

            $queryBuilder->insert($this->tableName, $item);
            $insertSql = $queryBuilder->getLastQuery();
            $queryBuilder->reset();

            $data .= "{$insertSql};" . PHP_EOL;
        }

        return $data;
    }
}